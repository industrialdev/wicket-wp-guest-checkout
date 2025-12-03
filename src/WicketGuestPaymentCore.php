<?php

declare(strict_types=1);

/**
 * Guest Subscription Payment Flow for WooCommerce - Core Functionality.
 *
 * Handles core token generation, validation, storage, and payment initiation.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Core functionality for Guest Subscription Payment Flow.
 */
class WicketGuestPaymentCore extends WicketGuestPaymentComponent
{
    /**
     * Token expiration in days.
     *
     * @var int
     */
    private int $token_expiry_days = 7;

    /**
     * Constructor.
     */
    public function __construct()
    {
        // Core class can be instantiated directly without going through the singleton
        // This allows independent usage of the Core class

        // Set up hooks
        $this->init();
    }

    /**
     * Initializes the class.
     *
     * @return void
     */
    public function init(): void
    {
        // Hook into template_redirect to check for guest payment token
        add_action('template_redirect', [$this, 'handle_guest_token_request'], 10);

        // Hook into payment completion to invalidate the token
        add_action('woocommerce_payment_complete', [$this, 'handle_payment_completion']);
        // Also hook into status changes to processing/completed for robustness
        add_action('woocommerce_order_status_processing', [$this, 'handle_payment_completion']);
        add_action('woocommerce_order_status_completed', [$this, 'handle_payment_completion']);

        // Guard cart contents before other extensions manipulate pricing
        add_action('woocommerce_before_calculate_totals', [$this, 'guard_guest_cart_products'], 1, 1);
        // Ensure custom pricing is applied before totals are calculated
        add_action('woocommerce_before_calculate_totals', [$this, 'set_custom_cart_item_price'], 99, 1);

        // Set custom price when loading cart items from session for guest payments
        add_filter('woocommerce_get_cart_item_from_session', [$this, 'set_cart_item_custom_price_from_session'], 10, 3);

        // Prevent cart validation from running until we've had a chance to add items
        // This is a safeguard to ensure items are added before validation occurs
        add_action('wp_loaded', [$this, 'ensure_cart_items_loaded'], 1);
    }

    /**
     * Sets custom prices for cart items during guest payment sessions.
     * This ensures 0-price products display the custom price set in the order.
     *
     * @param WC_Cart $cart The WooCommerce cart object.
     * @return void
     */
    public function set_custom_cart_item_price(WC_Cart $cart): void
    {
        // Check if we're in a guest payment session
        if (!$this->has_guest_session_cookie()) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            // Validate product data object exists
            if (!isset($cart_item['data']) || !($cart_item['data'] instanceof \WC_Product)) {
                continue;
            }

            // Check if this item has a custom price stored
            if (isset($cart_item['custom_price']) && $cart_item['custom_price'] > 0) {
                // Set the product price to the custom price
                $cart_item['data']->set_price($cart_item['custom_price']);
                $this->log(
                    sprintf('Set custom price %s for product %d in cart item %s', $cart_item['custom_price'], $cart_item['product_id'], $cart_item_key),
                    'debug'
                );
            }
        }
    }

    /**
     * Sets custom price for cart items loaded from session during guest payment sessions.
     * This ensures 0-price products are considered purchasable during cart validation.
     *
     * @param array $cart_item Cart item data.
     * @param array $session_values Session values.
     * @param string $cart_item_key Cart item key.
     * @return array Modified cart item data.
     */
    public function set_cart_item_custom_price_from_session(array $cart_item, array $session_values, string $cart_item_key): array
    {
        // Check if we're in a guest payment session
        if (!$this->has_guest_session_cookie()) {
            return $cart_item;
        }

        // Validate product data object exists
        if (!isset($cart_item['data']) || !($cart_item['data'] instanceof \WC_Product)) {
            return $cart_item;
        }

        // Check if this cart item has a custom price stored
        if (isset($cart_item['custom_price']) && $cart_item['custom_price'] > 0) {
            // Set the product price to the custom price
            $cart_item['data']->set_price($cart_item['custom_price']);
            $this->log(
                sprintf('Set custom price %s for product %d from session in cart item %s', $cart_item['custom_price'], $cart_item['product_id'], $cart_item_key),
                'debug'
            );
        }

        return $cart_item;
    }

    /**
     * Handles incoming requests with a guest payment token.
     * Validates the token, populates the cart, and redirects to the cart page.
     *
     * Note: This is a fallback handler. The Auth class should handle the token first.
     *
     * @hooked template_redirect
     * @return void
     */
    public function handle_guest_token_request(): void
    {
        // Check if the token parameter exists in the URL
        if (empty($_GET['guest_payment_token'])) {
            return; // No token present, do nothing
        }

        // Check if the Auth class has already processed this token
        // We use the presence of the guest session cookie as an indicator
        if ($this->has_guest_session_cookie()) {
            // Auth class has already handled this, so we should not process it again
            $this->log('Token already processed by Auth class. Skipping duplicate processing.');

            return;
        }

        // Sanitize the token
        $token = sanitize_text_field(wp_unslash($_GET['guest_payment_token']));

        // Validate the token and get the associated order
        $order = $this->validate_token($token); // Logging added inside this method now

        if ($order instanceof WC_Order) {
            // Prepare the cart from the order
            if ($this->prepare_cart_from_order($order)) {
                // Redirect to the cart page
                wp_safe_redirect(wc_get_cart_url());
                exit;
            } else {
                // If cart preparation failed, log and redirect home

                $this->log(
                    sprintf('Failed to prepare cart for Order ID: %d, Token: %s', $order->get_id(), $token),
                    'error'
                );

                wc_add_notice(__('There was an error preparing your payment. Please contact support.', 'wicket-wgc'), 'error');
                wp_safe_redirect(home_url()); // Redirect home on critical error
                exit;
            }
        } else {
            // Invalid or expired token
            wc_add_notice(__('The payment link is invalid or has expired. Please request a new link.', 'wicket-wgc'), 'error');
            // Redirect to shop or home page
            wp_safe_redirect(wc_get_page_permalink('shop') ?: home_url());
            exit;
        }
    }

    /**
     * Clears the current cart and populates it with items from the specified order.
     * Includes validation for variable products to prevent invalid cart items.
     *
     * @param WC_Order $order The order to prepare the cart from.
     * @return bool True if the cart was successfully prepared, false otherwise.
     */
    public function prepare_cart_from_order(WC_Order $order): bool
    {
        // Ensure WooCommerce is loaded
        if (!function_exists('WC')) {

            $this->log('WooCommerce not loaded when preparing cart', 'error');

            return false;
        }

        // Force WooCommerce cart initialization if needed
        if (!isset(WC()->cart) || !isset(WC()->session)) {
            // This ensures WC is fully initialized

            $this->log('Initializing WooCommerce session and cart');

            // Make sure WooCommerce is fully loaded
            WC()->initialize_session();
            WC()->initialize_cart();

            // Make sure customer session is started
            if (!WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
        }

        // Force session save to ensure it's properly initialized
        WC()->session->save_data();

        // Double-check that WooCommerce session and cart are available
        if (!WC()->session || !WC()->cart) {

            $this->log(
                sprintf('WooCommerce session or cart not available when preparing cart for Order ID: %d', $order->get_id()),
                'error'
            );

            return false;
        }

        // Clear the current user's cart to ensure only the order items are present
        WC()->cart->empty_cart(true);


        // Add the order items to the cart
        $items_added = false;
        $item_count = count($order->get_items());

        // Log the order items for debugging

        $this->log(
            sprintf('Preparing cart for Order ID: %d with %d items', $order->get_id(), $item_count)
        );

        // Check if order has any items at all
        if ($item_count === 0) {

            $this->log(
                sprintf('Order ID: %d has no items to add to cart', $order->get_id()),
                'error'
            );

            wc_add_notice(__('This order has no items to purchase. Please contact support.', 'wicket-wgc'), 'error');

            return false;
        }

        foreach ($order->get_items() as $item_id => $item) {
            // Ensure we are only processing product line items
            if (!is_a($item, 'WC_Order_Item_Product')) {

                $this->log(
                    sprintf('Skipping non-product item in Order ID: %d, Item ID: %d', $order->get_id(), $item_id)
                );

                continue; // Skip fees, shipping, etc.
            }

            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            $variation_id = $item->get_variation_id();

            // Log raw order item data for debugging variable product issues
            $this->log(
                sprintf(
                    'Order item %d: product_id=%d, variation_id=%d, type=%s',
                    $item_id,
                    $product_id,
                    $variation_id,
                    $item->get_type()
                ),
                'debug'
            );

            // Determine which product to use (variation or parent)
            $target_product_id = $variation_id > 0 ? $variation_id : $product_id;
            $product = wc_get_product($target_product_id);

            // Verify product exists
            if (!$product || !$product->exists()) {
                $this->log(
                    sprintf('Product %d (variation: %d) in Order ID: %d no longer exists', $product_id, $variation_id, $order->get_id()),
                    'error'
                );
                wc_add_notice(sprintf(__('Product in this order is no longer available. Please contact support. (Reference: %d)', 'wicket-wgc'), $product_id), 'error');
                continue;
            }

            // CRITICAL: Check if this is a variable product without a variation ID
            // This prevents the Addify fatal error caused by invalid cart items
            if (($product->is_type('variable') || $product->is_type('variable-subscription')) && $variation_id <= 0) {
                $this->log(
                    sprintf('Skipping variable product %d: No variation ID specified in order item (Order ID: %d)', $product_id, $order->get_id()),
                    'error'
                );
                wc_add_notice(sprintf(__('A variable product in this order is missing required options. Please contact support. (Reference: %d)', 'wicket-wgc'), $product_id), 'error');
                continue;
            }

            // Log product details
            $this->log(
                sprintf(
                    'Product details - ID: %d, Type: %s, Purchasable: %s, Stock Status: %s',
                    $product_id,
                    $product->get_type(),
                    $product->is_purchasable() ? 'Yes' : 'No',
                    $product->get_stock_status()
                ),
                'debug'
            );

            try {
                // Log the attempt to add to cart

                $this->log(
                    sprintf('Adding product %d (variation: %d, quantity: %d) to cart for Order ID: %d', $product_id, $variation_id, $quantity, $order->get_id())
                );

                // Use a more direct approach to add to cart
                $cart_item_data = [];
                $variation_attributes = [];

                // For subscriptions or other complex products, try to get data from the order item
                if ($product->get_type() === 'subscription' || $product->get_type() === 'variable-subscription') {
                    // Get any meta data from the order item that might be needed
                    $item_metas = $item->get_meta_data();
                    foreach ($item_metas as $meta) {
                        $cart_item_data['meta_data'][$meta->key] = $meta->value;
                    }
                }

                // Capture variation attributes for variable products
                if ($variation_id > 0 && method_exists($item, 'get_variation_attributes')) {
                    $variation_attributes = $item->get_variation_attributes();
                }

                // Store custom price for 0/blank price products
                $product_price = $product->get_price();
                $order_quantity = (int) $item->get_quantity();
                $normalized_quantity = $order_quantity > 0 ? $order_quantity : 1;
                if ($product_price === '' || (float) $product_price === 0.0) {
                    $cart_item_data['custom_price'] = (float) $item->get_total() / $normalized_quantity;
                }

                // Get the cart item key to verify addition was successful
                $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_attributes, $cart_item_data);

                if ($cart_item_key) {
                    $items_added = true;

                    // Override the price with the order item's price for custom pricing
                    $cart = WC()->cart->get_cart();
                    if (isset($cart[$cart_item_key])) {
                        $order_item_total = (float) $item->get_total();
                        $cart[$cart_item_key]['line_total'] = $order_item_total;
                        $cart[$cart_item_key]['line_subtotal'] = $order_item_total;
                        if (!empty($variation_attributes)) {
                            $cart[$cart_item_key]['variation'] = $variation_attributes;
                        }
                        WC()->cart->set_cart_contents($cart);
                    }

                    $this->log(
                        sprintf('Successfully added product %d to cart with key %s', $product_id, $cart_item_key)
                    );
                } else {
                    // Try an alternative approach if the first one fails

                    $this->log(
                        sprintf('First attempt to add product %d failed, trying alternative approach', $product_id),
                        'warning'
                    );

                    // Try to manually create a cart item
                    $cart = WC()->cart->get_cart();
                    $cart_item_key = md5($product_id . time() . rand(1, 1000));
                    // Use order item's total for pricing instead of product price
                    $order_item_total = (float) $item->get_total();

                    // Ensure product is purchasable before adding to cart
                    if (!$product || !$product->exists() || !$product->is_purchasable()) {
                        $this->log(
                            sprintf('Product %d is not purchasable - skipping. Status: %s, Exists: %s',
                                $product_id,
                                $product ? $product->get_status() : 'N/A',
                                $product ? ($product->exists() ? 'Yes' : 'No') : 'N/A'
                            ),
                            'warning'
                        );
                        continue;
                    }

                    // Build cart item data with all required fields
                    $cart_item_data = [
                        'key' => $cart_item_key, // Required by WooCommerce
                        'product_id' => $product_id,
                        'variation_id' => $variation_id,
                        'variation' => !empty($variation_attributes) ? $variation_attributes : [],
                        'quantity' => $quantity,
                        'data' => $product,
                        'line_total' => $order_item_total,
                        'line_subtotal' => $order_item_total,
                        'line_tax' => 0, // Add tax if needed
                        'line_subtotal_tax' => 0,
                        'taxes' => [], // Add empty taxes array
                        'custom_price' => $order_item_total / ($quantity > 0 ? $quantity : 1), // Store unit price for before_calculate_totals
                    ];

                    // For subscription products, add WCS-specific cart item data
                    if (class_exists('WC_Subscription') && $product && $product->is_type('subscription')) {
                        $cart_item_data['_subscription_price'] = $order_item_total / ($quantity > 0 ? $quantity : 1);
                        $cart_item_data['_subscription_period'] = 'month'; // Default period - should match product settings
                        $cart_item_data['_subscription_period_interval'] = 1; // Default interval
                        $cart_item_data['_subscription_length'] = 0; // No fixed length
                        $this->log('Added WooCommerce Subscriptions-specific data to cart item', 'debug');
                    }

                    $cart[$cart_item_key] = $cart_item_data;

                    WC()->cart->set_cart_contents($cart);
                    // Force WooCommerce to recalculate totals and validate items
                    WC()->cart->calculate_totals();
                    WC()->cart->persistent_cart_update();
                    $items_added = true;

                    $this->log(
                        sprintf('Alternative approach used to add product %d to cart with validation', $product_id)
                    );
                }
            } catch (Exception $e) {

                $this->log(
                    sprintf('Error adding product %d to cart for Order ID: %d. Error: %s', $product_id, $order->get_id(), $e->getMessage()),
                    'error'
                );

                // Add notice but continue with other products instead of failing completely
                wc_add_notice(__('An error occurred while preparing your cart. Please try again or contact support.', 'wicket-wgc'), 'error');
            }
        } // End foreach loop

        // If items were successfully added, calculate totals and save the cart session ONCE.
        if ($items_added) {
            WC()->cart->calculate_totals();
            WC()->cart->persistent_cart_update(); // Ensure cart is written to DB/session
            WC()->session->set('cart', WC()->cart->get_cart_for_session());
            WC()->session->save_data(); // Ensure session is saved after all items + calc.

            $this->log(
                sprintf('Cart preparation completed for Order ID: %d. Final cart count: %d', $order->get_id(), WC()->cart->get_cart_contents_count())
            );
        }

        return $items_added;
    }

    /**
     * Generates a secure payment token.
     *
     * @return string|false The generated token, or false if generation fails.
     */
    public function generate_token(): string|false
    {
        try {
            $random_bytes = random_bytes(32);
            $token = bin2hex($random_bytes);

            $this->log(
                sprintf('Successfully generated secure payment token of length %d.', strlen($token))
            );

            return $token;
        } catch (Exception $e) {
            $this->log(
                sprintf(
                    'Failed to generate secure payment token. Error: %s. PHP Version: %s, OpenSSL Version: %s',
                    $e->getMessage(),
                    PHP_VERSION,
                    OPENSSL_VERSION_TEXT
                ),
                'error'
            );

            return false;
        }
    }

    /**
     * Stores the payment token and related data as order meta.
     * Uses WC_Order CRUD methods for HPOS compatibility.
     *
     * @param int    $order_id    The ID of the order.
     * @param string $token    The payment token.
     * @param int    $user_id  The ID of the user the subscription is for.
     * @param string $guest_email The email address of the guest payer.
     * @param string $generation_method How the link was generated ('email' or 'manual').
     * @return bool True on success, false on failure.
     */
    public function store_token_data(int $order_id, string $token, int $user_id, string $guest_email, string $generation_method): bool
    {
        // Fetch order and validate inputs
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log(sprintf('Failed to fetch order for Order ID: %d', $order_id), 'error');

            return false;
        }

        // Log order type for debugging
        $this->log(
            sprintf(
                'Order type for ID %d: %s (Class: %s)',
                $order_id,
                $order->get_type(),
                get_class($order)
            )
        );
        if (empty($token)) {
            $this->log(sprintf('Empty token provided for Order ID: %d', $order_id), 'error');

            return false;
        }
        if (!$user_id) {
            $this->log(sprintf('Invalid user ID provided for Order ID: %d - orders must be assigned to a user for guest checkout to work', $order_id), 'error');

            return false;
        }
        if (!is_email($guest_email)) {
            $this->log(sprintf('Invalid email address provided for Order ID: %d: %s', $order_id, $guest_email), 'error');

            return false;
        }

        // Encrypt token
        $encrypted_token = $this->encrypt_data($token);
        if ($encrypted_token === false) {
            $this->log(sprintf('Token encryption failed for Order ID: %d', $order_id), 'error');

            return false;
        }

        // Generate HMAC hash
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
            $this->log('WARNING: WICKET_GUEST_PAYMENT_ENCRYPTION_KEY not defined, using empty string for HMAC', 'warning');
        }
        $token_hash = hash_hmac('sha256', $token, defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY') ? WICKET_GUEST_PAYMENT_ENCRYPTION_KEY : '');

        $this->log(sprintf('Token encryption and HMAC hash generation successful for Order ID: %d', $order_id));

        // Store metadata
        $timestamp = time();

        // Check if this is a subscription
        $is_subscription = $order->get_type() === 'shop_subscription';
        $this->log(sprintf('Storing token data for %s ID: %d', $is_subscription ? 'Subscription' : 'Order', $order_id));

        // Meta data to store
        $meta_data = [
            '_wgp_guest_payment_token_encrypted' => $encrypted_token,
            '_wgp_guest_payment_token_hash' => $token_hash,
            '_wgp_guest_payment_token_created' => $timestamp,
            '_wgp_guest_payment_user_id' => $user_id,
            '_wgp_guest_payment_email' => $guest_email,
            '_wgp_guest_payment_generation_method' => $generation_method,
        ];

        // Try both update methods for compatibility
        foreach ($meta_data as $key => $value) {
            // Method 1: WC CRUD method
            $order->update_meta_data($key, $value);

            // Method 2: Direct update_post_meta for subscriptions
            if ($is_subscription) {
                update_post_meta($order_id, $key, $value);
            }
        }

        // Add an order note for tracking
        $order->add_order_note(sprintf(__('Guest payment token generated (encrypted) for user ID %d, sent to %s.', 'wicket-wgc'), $user_id, $guest_email));

        // Save the changes to the order
        $saved = $order->save();

        if ($saved) {
            // Verify stored meta data using both methods
            $stored_hash = $is_subscription
                ? get_post_meta($order_id, '_wgp_guest_payment_token_hash', true)
                : $order->get_meta('_wgp_guest_payment_token_hash');

            $stored_encrypted = $is_subscription
                ? get_post_meta($order_id, '_wgp_guest_payment_token_encrypted', true)
                : $order->get_meta('_wgp_guest_payment_token_encrypted');

            $stored_timestamp = $is_subscription
                ? get_post_meta($order_id, '_wgp_guest_payment_token_created', true)
                : $order->get_meta('_wgp_guest_payment_token_created');

            $this->log(
                sprintf(
                    'Stored token data - Order ID: %d, Type: %s, Hash: %s, Encrypted: %s, Timestamp: %d',
                    $order_id,
                    $order->get_type(),
                    $stored_hash,
                    $stored_encrypted ? 'present' : 'missing',
                    $stored_timestamp
                )
            );

            return true;
        } else {
            $this->log(sprintf('Failed to save order metadata for Order ID: %d', $order_id), 'error');

            return false;
        }
    }

    /**
     * Validates a guest payment token using wc_get_orders for HPOS compatibility.
     *
     * @param string $token The token to validate.
     * @return WC_Order|false The order object if valid, false otherwise.
     */
    public function validate_token(string $token)
    {
        if (empty($token)) {
            return false;
        }

        // Hash the input token using the dedicated encryption key
        $encryption_key = defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY') ? WICKET_GUEST_PAYMENT_ENCRYPTION_KEY : '';
        if (empty($encryption_key)) {
            $this->log('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY is not defined. Cannot validate token.', 'error');

            return false; // Stop validation if key is missing
        }
        $input_token_hash = hash_hmac('sha256', $token, $encryption_key);

        // Log token and hash for debugging
        $this->log(
            sprintf(
                'Token validation - Input token: %s, Input hash: %s, WICKET_GUEST_PAYMENT_ENCRYPTION_KEY defined: %s',
                $token,
                $input_token_hash,
                defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY') ? 'yes' : 'no'
            )
        );

        // First, try to find the order or subscription with any status to provide better debugging
        $meta_query = [
            [
                'key'     => '_wgp_guest_payment_token_hash',
                'value'   => $input_token_hash,
                'compare' => '=',
            ],
        ];

        // Try querying for subscriptions first using wcs_get_subscriptions
        $subscription_query_args = [
            'subscriptions_per_page' => 1,
            'subscription_status'    => 'any', // Look for subscriptions in any status
            'meta_query'             => $meta_query,
            'return'                 => 'ids',
        ];
        $this->log(
            sprintf(
                'Searching for subscriptions with wcs_get_subscriptions: %s',
                json_encode($subscription_query_args)
            )
        );
        $found_ids = function_exists('wcs_get_subscriptions') ? wcs_get_subscriptions($subscription_query_args) : [];
        $this->log(
            sprintf(
                'wcs_get_subscriptions result for hash %s: %s',
                $input_token_hash,
                json_encode($found_ids)
            )
        );

        // If a subscription was found by wcs_get_subscriptions, extract the ID from the array keys
        $is_subscription_check = false;
        if (!empty($found_ids) && function_exists('wcs_get_subscriptions')) {
            $subscription_keys = array_keys($found_ids);
            if (!empty($subscription_keys)) {
                $found_order_id = $subscription_keys[0]; // Get the first key, which is the ID
                $this->log(sprintf('Extracted subscription ID %d from wcs_get_subscriptions result.', $found_order_id));
                // Clear the $found_ids array to prevent falling into the order logic if we already have a subscription ID
                // We need $found_ids to be non-empty later, so we put the extracted ID into it
                $found_ids = [$found_order_id];
                $is_subscription_check = true; // Mark that we found a subscription
            } else {
                $found_ids = []; // Ensure it's empty if keys extraction failed
            }
        }

        // If no subscription found, try querying for regular orders using wc_get_orders
        if (empty($found_ids)) {
            $order_query_args = [
                'limit'      => 1,
                'type'       => 'shop_order',
                'meta_query' => $meta_query,
                'return'     => 'ids',
            ];
            $this->log(
                sprintf(
                    'Searching for orders with wc_get_orders: %s',
                    json_encode($order_query_args)
                )
            );
            $found_ids = wc_get_orders($order_query_args);
            $this->log(
                sprintf(
                    'wc_get_orders (orders only) result for hash %s: %s',
                    $input_token_hash,
                    json_encode($found_ids)
                )
            );
        }

        // Log if we found an order/subscription with any status
        if (!empty($found_ids)) {
            // If we are here, $found_ids should contain exactly one ID, either from subscription or order query
            $found_order_id = $found_ids[0]; // Get the first (and only) order ID
            $found_order = wc_get_order($found_order_id); // This works for both order and subscription IDs
            $status = $found_order ? $found_order->get_status() : 'unknown';

            // Define base allowed statuses
            $base_allowed_statuses = ['pending', 'failed', 'on-hold'];
            $allowed_statuses = apply_filters('wicket_guest_payment_allowed_order_statuses', $base_allowed_statuses);
            // $is_subscription = $found_order && $found_order->is_type('subscription'); // Removed this line

            // If it's a subscription (based on our flag), add 'active' to the allowed statuses
            if ($is_subscription_check) { // Use the flag instead of is_type()
                // Use apply_filters to allow modification specifically for subscriptions
                $subscription_allowed_statuses = apply_filters('wicket_guest_payment_allowed_subscription_statuses', array_merge($allowed_statuses, ['active']));
                // Ensure no duplicates
                $allowed_statuses = array_unique($subscription_allowed_statuses);
                $this->log(sprintf('Identified as subscription. Allowed statuses: %s', implode(', ', $allowed_statuses)));
            } else {
                $this->log(sprintf('Identified as order. Allowed statuses: %s', implode(', ', $allowed_statuses)));
            }

            $this->log(
                sprintf('Found order #%d with status "%s" for token hash.', $found_order_id, $status)
            );

            // Check if the found order/subscription has an allowed status
            $is_status_allowed = $found_order && $found_order->has_status($allowed_statuses);

            $this->log(sprintf('Result of has_status() check: %s', $is_status_allowed ? 'true' : 'false'));

            if ($is_status_allowed) {
                // Status is allowed, proceed with this order/subscription
                $this->log(
                    sprintf('Order #%d has allowed status "%s". Proceeding with validation.', $found_order_id, $status)
                );
                $order = $found_order;
                $order_id = $found_order_id;
                // Skip any later checks that might specifically look for 'pending' status
                goto validate_token_details;
            } else {
                // Status is not allowed
                $this->log(
                    sprintf(
                        'Order #%d has status "%s" which is not in allowed statuses list: [%s].',
                        $found_order_id,
                        $status,
                        implode(', ', $allowed_statuses)
                    )
                );
                // Explicitly clear order/order_id to prevent potential issues later
                $order = null;
                $order_id = 0;
            }
        } else {
            $this->log(
                sprintf('No order found for token hash: %s', $input_token_hash)
            );
        }

        // If we reached here without finding a suitable order/subscription via the hash above,
        // the original logic might have tried a different query (e.g., specifically for pending).
        // Since we used `goto` on success, this part will be skipped if we found a valid match.
        // If $order_id is still 0 or $order is null, it means no valid match was found by hash.
        if (empty($order_id) || !$order) {
            $this->log(
                sprintf(
                    'No order/subscription with an allowed status found for token hash matching token: %s (Input Token Hash: %s)',
                    $token,
                    $input_token_hash
                )
            );

            return 'invalid_token'; // Or 'invalid_order_status' if more appropriate
        }

        // Label for goto statement - execution jumps here if validation succeeded earlier.
        validate_token_details:

        // Check if the order object is valid before proceeding
        if (!$order instanceof WC_Order && !$order instanceof WC_Subscription) {
            $this->log(sprintf('Failed to retrieve a valid order/subscription object for ID: %d.', $order_id));

            return 'invalid_token';
        }

        // --- Existing Token Validation Logic (Expiry, Anti-Tampering) ---
        // Decrypt the stored token to verify it matches the input token (anti-tampering check)
        $encrypted_token = $order->get_meta('_wgp_guest_payment_token_encrypted');
        $decrypted_token = $encrypted_token ? $this->decrypt_data($encrypted_token) : false;

        if ($decrypted_token !== $token) {
            $this->log(
                sprintf('Token hash matched for Order ID %d, but decrypted token did NOT match input token %s. Possible tampering or key change.', $order_id, $token)
            );

            // Don't proceed if the full token doesn't match after decryption
            return false;
        }

        // Check token expiry
        $created_timestamp = $order->get_meta('_wgp_guest_payment_token_created');
        $expiry_timestamp = $this->get_token_expiry_timestamp((int) $created_timestamp);

        if (empty($created_timestamp) || time() > $expiry_timestamp) {
            $this->log(
                sprintf('Token expired or timestamp missing for Order ID %d. Token: %s', $order_id, $token)
            );

            // Optionally invalidate the token meta here
            // $this->invalidate_token_for_order($order_id);
            return false;
        }

        // Token is valid
        $this->log(
            sprintf('Token validation successful for Order ID %d. Token: %s', $order_id, $token)
        );

        return $order;
    }

    /**
     * Invalidates a token by removing its metadata from the order.
     *
     * @param int $order_id The order ID to invalidate the token for.
     * @return bool True if successful, false otherwise.
     */
    public function invalidate_token_for_order(int $order_id): bool
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Check if any token data exists before attempting to delete
        $has_token_data = $order->meta_exists('_wgp_guest_payment_token_hash')
            || $order->meta_exists('_wgp_guest_payment_token_encrypted')
            || $order->meta_exists('_wgp_guest_payment_token_created');

        if (!$has_token_data) {
            $this->log(
                sprintf('No token data found for Order ID: %d during invalidation attempt. Skipping.', $order_id)
            );

            return true; // Return true as there's nothing to invalidate
        }

        // Remove all token-related meta keys
        $order->delete_meta_data('_wgp_guest_payment_token_encrypted');
        $order->delete_meta_data('_wgp_guest_payment_token_hash');
        $order->delete_meta_data('_wgp_guest_payment_token_created');
        $order->delete_meta_data('_wgp_guest_payment_generation_method');
        // Keep guest email/user ID for potential future reference or support

        // Save the changes
        $saved = $order->save();

        // ADDED: Clean up secure cart key data associated with the user
        $user_id = $order->get_user_id();
        if ($user_id > 0) {
            global $wpdb;
            $map_prefix = '_transient_wgp_map_';
            // Find all transient option names mapping secure keys to this user_id
            $transient_option_names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value = %s",
                $wpdb->esc_like($map_prefix) . '%',
                (string) $user_id
            ));

            if (!empty($transient_option_names)) {
                $guest_payment_instance = WicketGuestPayment::get_instance();
                foreach ($transient_option_names as $option_name) {
                    // Extract the secure key from the option name
                    $secure_key = str_replace($map_prefix, '', $option_name);
                    // Delete the cart data and the map entry using the utility method
                    $deleted = $guest_payment_instance->delete_secure_cart_data($secure_key);
                    if ($deleted) {
                        $this->log(sprintf('Deleted secure cart data for key %s (User ID: %d) during token invalidation for Order ID %d.', $secure_key, $user_id, $order_id));
                    } else {
                        $this->log(sprintf('Failed to delete secure cart data for key %s (User ID: %d) during token invalidation for Order ID %d.', $secure_key, $user_id, $order_id), 'warning');
                    }
                }
            } else {
                $this->log(sprintf('No secure cart key mappings found for User ID %d during token invalidation for Order ID %d.', $user_id, $order_id));
            }
        } else {
            $this->log(sprintf('Cannot clean up secure cart data: User ID not found or invalid for Order ID %d.', $order_id));
        }
        // END ADDED

        // Verify deletion by checking the metadata after save
        $order_after_save = wc_get_order($order_id);
        if (!$order_after_save) {
            $this->log(sprintf('Failed to verify token deletion for Order ID: %d - could not reload order', $order_id), 'error');

            return false;
        }

        // Verify that all token-related meta keys were deleted
        $token_data_removed = !$order_after_save->meta_exists('_wgp_guest_payment_token_encrypted')
            && !$order_after_save->meta_exists('_wgp_guest_payment_token_hash')
            && !$order_after_save->meta_exists('_wgp_guest_payment_token_created')
            && !$order_after_save->meta_exists('_wgp_guest_payment_generation_method');

        if ($saved && $token_data_removed) {
            $this->log(
                sprintf('Token invalidation successful for Order ID: %d', $order_id)
            );

            return true;
        } else {
            $this->log(
                sprintf(
                    'Token invalidation failed for Order ID: %d. Save result: %s, Token data removed: %s',
                    $order_id,
                    $saved ? 'Success' : 'Failed',
                    $token_data_removed ? 'Yes' : 'No'
                ),
                'error'
            );

            return false;
        }
    }

    /**
     * Initiates the guest payment process for a given order.
     * Generates token, stores data, and prepares for email sending.
     *
     * @param int    $order_id    The ID of the pending order.
     * @param string $guest_email The email address of the guest who will pay.
     * @param int    $user_id     The ID of the user the subscription is for (optional).
     * @return array|false Array containing 'token', 'order_id', and 'user_id' on success, false on failure.
     */
    public function initiate_payment(int $order_id, string $guest_email, int $user_id = 0)
    {
        $order = wc_get_order($order_id);

        // Validate order and email
        if (!$order || !$order->has_status('pending') || !is_email($guest_email)) {
            $this->log(
                sprintf('Guest payment initiation failed for Order ID: %d. Invalid order status or invalid guest email.', $order_id)
            );

            return false;
        }

        // If user_id not provided, use the customer ID from the order
        if (0 === $user_id) {
            $user_id = $order->get_customer_id();

            // Validate the user ID exists
            if (!$user_id || !get_userdata($user_id)) {
                $this->log(
                    sprintf('Guest payment initiation failed for Order ID: %d. No valid customer ID associated with the order.', $order_id)
                );

                return false;
            }
        }

        $token = $this->generate_token();

        if ($this->store_token_data($order_id, $token, $user_id, $guest_email, 'email')) {
            return [
                'token' => $token,
                'order_id' => $order_id,
                'user_id' => $user_id,
            ];
        }

        return false;
    }

    /**
     * Gets the expiry timestamp for a token.
     *
     * @param int|null $created_timestamp Optional. The timestamp when the token was created. If null, uses current time.
     * @return int Timestamp when the token will expire.
     */
    public function get_token_expiry_timestamp(?int $created_timestamp = null): int
    {
        $base_time = $created_timestamp ?? time();
        return $base_time + ($this->token_expiry_days * DAY_IN_SECONDS);
    }

    /**
     * Gets the token expiry days setting.
     *
     * @return int Number of days until token expiry.
     */
    public function get_token_expiry_days(): int
    {
        return $this->token_expiry_days;
    }

    /**
     * Sets the token expiry days setting.
     *
     * @param int $days Number of days until token expiry.
     * @return void
     */
    public function set_token_expiry_days(int $days): void
    {
        if ($days >= 1) {
            $this->token_expiry_days = $days;
        }
    }

    /**
     * Generates and stores a payment token for a specific order and guest email.
     *
     * @param int    $order_id    The ID of the order.
     * @param string $guest_email The email address of the guest payer.
     * @param string $generation_method How the link was generated ('email' or 'manual').
     * @return string|false The generated token on success, false on failure.
     */
    public function generate_token_for_order(int $order_id, string $guest_email, string $generation_method = 'email'): string|false
    {
        $this->invalidate_token_for_order($order_id);

        $order = wc_get_order($order_id);
        if (!$order || !is_email($guest_email)) {
            $this->log(
                sprintf('Failed to generate guest token. Invalid order ID (%d) or email (%s).', $order_id, $guest_email)
            );

            return false;
        }

        // Determine the user ID associated with the order (customer or stored meta)
        $user_id = (int) $order->get_meta('_wgp_guest_payment_user_id', true) ?: $order->get_customer_id();

        // If user ID is still 0, maybe log a warning or handle as needed.
        // For now, we proceed even with user_id 0 if customer_id was 0.

        // Generate a new token
        $token = $this->generate_token();

        // Store the token data
        $stored = $this->store_token_data($order_id, $token, $user_id, $guest_email, $generation_method);

        if ($stored) {
            $this->log(
                sprintf('Generated guest payment token for Order ID: %d, Email: %s', $order_id, $guest_email)
            );

            return $token;
        } else {
            $this->log(
                sprintf('Failed to store guest payment token for Order ID: %d, Email: %s', $order_id, $guest_email)
            );

            return false;
        }
    }

    /**
     * Retrieves and validates the stored token data for an order.
     *
     * @param int $order_id The ID of the order.
     * @param ?WC_Order $order Optional. The order object. If provided, skips internal fetching.
     * @return array|null An array with token data ('token', 'guest_email', 'user_id', 'created_timestamp', 'generation_method') if valid and not expired, otherwise null.
     */
    public function get_valid_token_data(int $order_id, ?WC_Order $order = null): ?array
    {
        // If order object is not provided, fetch it.
        if (!$order) {
            $order = wc_get_order($order_id);
        }

        // Check if the order object is valid after potentially fetching it.
        if (!$order) {
            return null;
        }

        $encrypted_token = $order->get_meta('_wgp_guest_payment_token_encrypted', true);
        $created_timestamp = (int) $order->get_meta('_wgp_guest_payment_token_created', true);
        $guest_email = $order->get_meta('_wgp_guest_payment_email', true);
        $user_id = (int) $order->get_meta('_wgp_guest_payment_user_id', true);
        $generation_method = $order->get_meta('_wgp_guest_payment_generation_method', true) ?: 'email'; // Default to 'email' if not set

        if (empty($encrypted_token) || !$created_timestamp) {
            return null; // Essential data missing
        }

        // Decrypt the token
        $token = $encrypted_token ? $this->decrypt_data($encrypted_token) : false;

        if ($token === false) {
            $this->log(
                sprintf('Token decryption failed during validation for Order ID: %d.', $order_id)
            );

            return null; // Decryption failed
        }

        // Check expiry
        $expiry_timestamp = $created_timestamp + ($this->get_token_expiry_days() * DAY_IN_SECONDS);
        if (time() > $expiry_timestamp) {
            // Optional: Invalidate the token here if expired? Or rely on validate_token check.
            // $this->invalidate_token_for_order($order_id);
            return null; // Token expired
        }

        // Check if essential data for usage exists
        if (empty($guest_email) || !$user_id) {
            // Log this potential issue
            $this->log(
                sprintf('Token is valid but missing email or user ID for Order ID: %d.', $order_id)
            );

            // Decide if this should be null or return partial data. Returning null seems safer.
            return null;
        }

        return [
            'token'             => $token,
            'guest_email'       => $guest_email,
            'user_id'           => $user_id,
            'created_timestamp' => $created_timestamp,
            'generation_method' => $generation_method, // Add generation method
        ];
    }

    /**
     * Handles actions upon successful payment completion or status change to paid.
     *
     * Invalidates the guest payment token associated with the order.
     *
     * @hooked woocommerce_payment_complete
     * @hooked woocommerce_order_status_processing
     * @hooked woocommerce_order_status_completed
     * @param int $order_id The ID of the order.
     * @return void
     */
    public function handle_payment_completion(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log(
                sprintf('Payment completion: Order #%d not found. Cannot process.', $order_id),
                'error'
            );

            return;
        }

        $user_id = $order->get_user_id();

        $this->log(
            sprintf('Payment complete/Order status paid for order #%d. Attempting to invalidate token.', $order_id)
        );
        $this->invalidate_token_for_order($order_id); // Existing method handles logging success/failure
    }

    // Encryption/Decryption Helpers

    /**
     * Encrypts data using OpenSSL with defined key and method.
     *
     * Requires WICKET_GUEST_PAYMENT_ENCRYPTION_KEY and WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD to be defined.
     *
     * @param string $data Data to encrypt.
     * @return string|false Base64 encoded string (IV prepended) or false on failure.
     */
    private function encrypt_data(string $data): string|false
    {
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY') || !defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
            $this->log(
                sprintf('Encryption Error: WICKET Guest Payment Encryption Key or Method not defined in wp-config. Data: %s', $data),
                'error'
            );

            return false;
        }
        $key = WICKET_GUEST_PAYMENT_ENCRYPTION_KEY;
        $method = WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD;
        $iv_length = openssl_cipher_iv_length($method);
        if ($iv_length === false) {
            $this->log(
                sprintf('Encryption Error: Unsupported cipher method: %s.', $method),
                'error'
            );

            return false; // Method not supported
        }
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            $this->log(
                sprintf('Encryption Error: openssl_encrypt failed. Data: %s', $data),
                'error'
            );

            return false;
        }

        // Prepend IV for storage, use base64 encoding for safe storage
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypts data encrypted with encrypt_data.
     *
     * Requires WICKET_GUEST_PAYMENT_ENCRYPTION_KEY and WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD to be defined.
     *
     * @param string $data Base64 encoded encrypted string (IV prepended).
     * @return string|false Decrypted data or false on failure/tampering.
     */
    public function decrypt_data(string $data): string|false
    {
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY') || !defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
            $this->log(
                sprintf('Decryption Error: WICKET Guest Payment Encryption Key or Method not defined in wp-config. Data: %s', $data),
                'error'
            );

            return false;
        }
        $key = WICKET_GUEST_PAYMENT_ENCRYPTION_KEY;
        $method = WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD;
        $decoded_data = base64_decode($data, true);
        if ($decoded_data === false) {
            $this->log(
                sprintf('Decryption Error: Invalid base64 input. Data: %s', $data),
                'error'
            );

            return false; // Invalid base64
        }
        $iv_length = openssl_cipher_iv_length($method);
        if ($iv_length === false) {
            $this->log(
                sprintf('Decryption Error: Unsupported cipher method: %s.', $method),
                'error'
            );

            return false; // Method not supported
        }
        if (mb_strlen($decoded_data, '8bit') < $iv_length) {
            $this->log(
                sprintf('Decryption Error: Encrypted data too short. Data: %s', $data),
                'error'
            );

            return false; // Too short to contain IV
        }
        $iv = mb_substr($decoded_data, 0, $iv_length, '8bit');
        $ciphertext = mb_substr($decoded_data, $iv_length, null, '8bit');
        $decrypted = openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            $this->log(
                sprintf('Decryption Error: openssl_decrypt failed. Possible wrong key or tampered data. Data: %s', $data),
                'error'
            );

            return false;
        }

        return $decrypted; // Returns false on failure
    }

    /**
     * Removes cart items without valid products during guest sessions.
     * This prevents third-party pricing hooks from operating on false objects.
     *
     * @param WC_Cart $cart The WooCommerce cart object.
     * @return void
     */
    public function guard_guest_cart_products(WC_Cart $cart): void
    {
        if (!$this->is_guest_payment_session()) {
            return;
        }

        $this->log('Guard: Starting cart validation for guest payment session.', 'debug');

        $removed_items = false;
        $cart_contents = $cart->get_cart();

        $this->log(sprintf('Guard: Found %d items in cart to validate.', count($cart_contents)), 'debug');

        foreach ($cart_contents as $cart_item_key => $cart_item) {
            $product_id = isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
            $variation_id = isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0;

            $this->log(
                sprintf('Guard: Checking cart item %s - Product ID: %d, Variation ID: %d', $cart_item_key, $product_id, $variation_id),
                'debug'
            );

            // 1. Validate Product ID
            if ($product_id <= 0) {
                $cart->remove_cart_item($cart_item_key);
                $this->log(sprintf('Guard: REMOVED cart item %s: Missing product ID.', $cart_item_key), 'warning');
                $removed_items = true;
                continue;
            }

            // 2. Validate data object exists first
            if (!isset($cart_item['data']) || !($cart_item['data'] instanceof \WC_Product)) {
                $this->log(
                    sprintf('Guard: Cart item %s has invalid data object. Type: %s', $cart_item_key, isset($cart_item['data']) ? gettype($cart_item['data']) : 'not set'),
                    'warning'
                );

                // Try to load the correct product
                $target_id = $variation_id > 0 ? $variation_id : $product_id;
                $product = wc_get_product($target_id);

                if ($product && $product->exists()) {
                    $cart_contents[$cart_item_key]['data'] = $product;
                    $this->log(sprintf('Guard: Rehydrated data object for cart item %s with product %d', $cart_item_key, $target_id), 'debug');
                } else {
                    $cart->remove_cart_item($cart_item_key);
                    $this->log(sprintf('Guard: REMOVED cart item %s: Could not load product %d', $cart_item_key, $target_id), 'error');
                    $removed_items = true;
                    continue;
                }
            }

            // 3. Validate Parent Product Exists
            $parent_product = wc_get_product($product_id);
            if (!$parent_product || !$parent_product->exists()) {
                $cart->remove_cart_item($cart_item_key);
                $this->log(sprintf('Guard: REMOVED cart item %s: Parent product %d not found.', $cart_item_key, $product_id), 'warning');
                $removed_items = true;
                continue;
            }

            // 4. Strict Variable Product Validation
            if ($parent_product->is_type('variable') || $parent_product->is_type('variable-subscription')) {
                if ($variation_id <= 0) {
                    $cart->remove_cart_item($cart_item_key);
                    $this->log(sprintf('Guard: REMOVED cart item %s: Variable product %d has no variation ID.', $cart_item_key, $product_id), 'warning');
                    $removed_items = true;
                    continue;
                }

                $variation_product = wc_get_product($variation_id);
                if (!$variation_product || !$variation_product->exists()) {
                    $cart->remove_cart_item($cart_item_key);
                    $this->log(sprintf('Guard: REMOVED cart item %s: Variation %d not found for product %d.', $cart_item_key, $variation_id, $product_id), 'warning');
                    $removed_items = true;
                    continue;
                }

                // Ensure data object is the variation, not the parent
                if ($cart_contents[$cart_item_key]['data']->get_id() !== $variation_id) {
                    $cart_contents[$cart_item_key]['data'] = $variation_product;
                    $this->log(sprintf('Guard: Updated data object to variation %d for cart item %s', $variation_id, $cart_item_key), 'debug');
                }
            }

            $this->log(sprintf('Guard: Cart item %s validated successfully.', $cart_item_key), 'debug');
        }

        // Persist any data updates performed above (like rehydrating 'data')
        $cart->set_cart_contents($cart_contents);

        $this->log(sprintf('Guard: Validation complete. Removed items: %s', $removed_items ? 'Yes' : 'No'), 'debug');

        if ($removed_items) {
            wc_add_notice(__('One or more unavailable items were removed from your cart. Please review before continuing.', 'wicket-wgc'), 'error');
        }
    }

    /**
     * Determines whether the current request is part of an active guest payment session.
     *
     * @return bool True if in guest payment session, false otherwise.
     */
    public function is_guest_payment_session(): bool
    {
        if ($this->has_guest_session_cookie()) {
            return true;
        }

        $user_id = get_current_user_id();
        if ($user_id) {
            $session_flag = get_user_meta($user_id, '_wgp_guest_session_token_validation', true);
            if (!empty($session_flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the guest session cookie is set and valid.
     *
     * @return bool True if guest session cookie exists, false otherwise.
     */
    public function has_guest_session_cookie(): bool
    {
        return isset($_COOKIE['wordpress_logged_in_order']) &&
               !empty(sanitize_text_field(wp_unslash($_COOKIE['wordpress_logged_in_order'])));
    }

    /**
     * Ensures cart items are properly loaded and validated.
     * This runs after WordPress is loaded to ensure our cart items have been added
     * before any validation occurs.
     *
     * @return void
     */
    public function ensure_cart_items_loaded(): void
    {
        // Check if we're in a guest session
        if (!$this->has_guest_session_cookie()) {
            return;
        }

        // Disable WooCommerce's built-in cart validation that removes items
        // We handle validation in guard_guest_cart_products instead
        remove_action('woocommerce_check_cart_items', array(WC()->cart, 'check_cart_items'), 1);
        remove_action('woocommerce_check_cart_items', array(WC()->cart, 'check_cart_coupons'), 1);

        $this->log('Disabled WooCommerce native cart validation for guest session', 'debug');

        // If cart exists and is not empty, ensure items are valid
        if (WC()->cart && !WC()->cart->is_empty()) {
            // Log the current cart state
            $cart_count = WC()->cart->get_cart_contents_count();
            $this->log(sprintf('Guest session cart has %d items after disabling native validation', $cart_count), 'debug');

            // Check if there are manually-added items that might need validation
            $cart = WC()->cart->get_cart();
            foreach ($cart as $cart_item_key => $cart_item) {
                // Check if item has the 'key' field (indicating it was manually added)
                if (isset($cart_item['custom_price']) && !isset($cart_item['key'])) {
                    $this->log('Found cart item without key field - this might cause validation issues', 'warning');
                }
            }
        }
    }
}
