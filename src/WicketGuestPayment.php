<?php

declare(strict_types=1);

/**
 * Guest Subscription Payment Flow for WooCommerce - Main Class.
 *
 * Coordinates and initializes the entire guest payment system.
 *
 * System should work as follow:
 *
 * 1. Admins inside an order on WooCommerce (associated with an user), can generate a guest payment
 * 1B. Guest payment link also goes in the email sent to the user.
 * 2. Anyone can click that link, that comes with an special unique token (that can expire).
 * 3. When user (anyone) clicks that link, the system will log in the user associated with the order on WooCommerce.
 * 4. The system will prevent the user from escape ourside the cart, checkout and thank you pages.
 * 5. The user will be presented with the cart and the products associated with that order, with prices preserved from the order (including custom prices for 0-price products).
 * 6. The user will be able to complete the payment process, on behalf of the user associated with the order. Initial order ID will be preserved. No new order will be created.
 * 7. The user will be redirected to the thank you page.
 * 8. The user will be logged out at that thank you page, to avoid any security issues.
 * 9. Temp data cleanup will be performed at that thank you page.
 */

// No direct access
defined('ABSPATH') || exit;

// Define encryption keys if not already defined
// If you want to define them yourself to be different for security reasons, add them to wp-config.php
if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
    // Use wp-config.php SECURE_AUTH_KEY + AUTH_KEY for encryption
    define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', SECURE_AUTH_KEY . AUTH_KEY);
}
if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
    define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
}

/**
 * Main class for Guest Subscription Payment Flow.
 */
class WicketGuestPayment
{
    use WicketGuestPaymentLogger;

    /**
     * Plugin URL.
     *
     * @var string
     */
    public $plugin_url = '';

    /**
     * Plugin path.
     *
     * @var string
     */
    public $plugin_path = '';

    /**
     * Plugin version.
     *
     * @var string
     */
    public $version = '';

    /**
     * Core functionality class instance.
     *
     * @var WicketGuestPaymentCore|null
     */
    private $core;

    /**
     * Email functionality class instance.
     *
     * @var WicketGuestPaymentEmail|null
     */
    private $email;

    /**
     * Auth functionality class instance.
     *
     * @var WicketGuestPaymentAuth|null
     */
    private $auth;

    /**
     * Admin functionality class instance.
     *
     * @var WicketGuestPaymentAdmin|null
     */
    private $admin;

    /**
     * Notice functionality class instance.
     *
     * @var WicketGuestPaymentNotice|null
     */
    private $notice;

    /**
     * Invoice integration class instance.
     *
     * @var WicketGuestPaymentInvoice|null
     */
    private $invoice;

    /**
     * Receipt functionality class instance.
     *
     * @var WicketGuestPaymentReceipt|null
     */
    private $receipt;

    /**
     * Singleton instance.
     *
     * @var Wicket_Guest_Payment
     */
    private static $instance = null;

    /**
     * Constructor - Intentionally empty for testability.
     *
     * The actual initialization happens in plugin_setup() method,
     * which allows for unit testing without side effects.
     */
    public function __construct()
    {
        // Intentionally empty - no initialization here for testability
    }

    /**
     * Get the singleton instance.
     *
     * @return Wicket_Guest_Payment
     */
    public static function get_instance(): self
    {
        null === self::$instance and self::$instance = new self();

        return self::$instance;
    }

    /**
     * Plugin setup method - called via hooks after instantiation.
     *
     * This method performs all initialization that would normally
     * happen in the constructor, but can be controlled for testing.
     *
     * @return void
     */
    public function plugin_setup(): void
    {
        // Check for WooCommerce dependency
        if (!wicket_guest_checkout_is_woocommerce_active()) {
            add_action('admin_notices', 'wicket_guest_checkout_woocommerce_missing_notice');

            return;
        }

        // Set plugin properties
        $this->plugin_url = plugins_url('/', WICKET_GUEST_CHECKOUT_FILE);
        $this->plugin_path = plugin_dir_path(WICKET_GUEST_CHECKOUT_FILE);
        $this->version = WICKET_GUEST_CHECKOUT_VERSION;

        // Load translations
        $this->load_language('wicket-wgc');

        // Initialize all components
        $this->initialize_components();

        // Register product and cart filters
        $this->register_product_filters();

        // Log successful initialization
        $this->log('Guest Payment plugin initialized successfully', 'info');
    }

    /**
     * Load plugin text domain for translations.
     *
     * @param string $domain Text domain to load
     * @return void
     */
    public function load_language(string $domain): void
    {
        load_plugin_textdomain(
            $domain,
            false,
            dirname(WICKET_GUEST_CHECKOUT_BASENAME) . '/languages'
        );
    }

    /**
     * Initialize all component classes.
     *
     * @return void
     */
    private function initialize_components(): void
    {
        // Initialize core functionality
        $this->core = new WicketGuestPaymentCore();
        $this->core->init();

        // Initialize email functionality
        $this->email = new WicketGuestPaymentEmail($this->core);
        $this->email->init();

        // Initialize auth functionality
        $this->auth = new WicketGuestPaymentAuth($this->core);
        $this->auth->init_hooks();

        // Initialize admin functionality (only in admin area)
        if (is_admin()) {
            $this->admin = new WicketGuestPaymentAdmin($this->core, $this->email);
            $this->admin->init();
        }

        // Initialize invoice integration
        if (class_exists('WicketGuestPaymentInvoice')) {
            $this->invoice = new WicketGuestPaymentInvoice();
        }

        // Initialize receipt functionality
        if (class_exists('WicketGuestPaymentReceipt')) {
            $this->receipt = new WicketGuestPaymentReceipt();
            $this->receipt->init();
        }
    }

    /**
     * Register product and cart filters for guest payment sessions.
     *
     * These filters allow guest users to purchase items without normal restrictions.
     *
     * @return void
     */
    private function register_product_filters(): void
    {
        // Allow all products to be purchasable during guest payment sessions
        add_filter(
            'woocommerce_is_purchasable',
            function (bool $is_purchasable, WC_Product $product): bool {
                $user_id = get_current_user_id();
                $is_guest_session = $user_id && get_user_meta($user_id, '_wgp_guest_session_token_validation', true);

                if ($is_guest_session) {
                    return true;
                }

                return $is_purchasable;
            },
            PHP_INT_MAX,
            2
        );

        // Allow variations to be visible during guest payment sessions
        add_filter(
            'woocommerce_variation_is_visible',
            function (bool $is_visible, int $variation_id, int $parent_id, WC_Product_Variation $variation): bool {
                $user_id = get_current_user_id();
                $is_guest_session = $user_id && get_user_meta($user_id, '_wgp_guest_session_token_validation', true);

                if ($is_guest_session) {
                    return true;
                }

                return $is_visible;
            },
            PHP_INT_MAX,
            4
        );

        // Override stock checks for guest payment sessions
        add_filter(
            'woocommerce_cart_item_required_stock_is_not_enough',
            function (bool $not_enough, WC_Product $product, array $values): bool {
                $user_id = get_current_user_id();
                $is_guest_session = $user_id && get_user_meta($user_id, '_wgp_guest_session_token_validation', true);

                if ($is_guest_session) {
                    return false;
                }

                return $not_enough;
            },
            PHP_INT_MAX,
            3
        );

        // Re-add removed cart items during guest sessions
        add_action(
            'woocommerce_check_cart_items',
            function (): void {
                $user_id = get_current_user_id();
                $is_guest_session = $user_id && get_user_meta($user_id, '_wgp_guest_session_token_validation', true);

                if ($is_guest_session && WC()->cart) {
                    $cart = WC()->cart->get_cart();
                    $has_custom_price_item = false;
                    foreach ($cart as $cart_item) {
                        if (!empty($cart_item['custom_price'])) {
                            $has_custom_price_item = true;
                            break;
                        }
                    }

                    if (!$has_custom_price_item) {
                        // Try to re-add from user meta
                        $cart_data = get_user_meta($user_id, '_wgp_cart_data', true);
                        if ($cart_data && is_array($cart_data)) {
                            foreach ($cart_data as $item_key => $item_data) {
                                if (!empty($item_data['custom_price'])) {
                                    $product_id = !empty($item_data['product_id']) ? absint($item_data['product_id']) : 0;
                                    $variation_id = !empty($item_data['variation_id']) ? absint($item_data['variation_id']) : 0;
                                    $_product = wc_get_product($variation_id ?: $product_id);
                                    if ($_product && $_product->exists()) {
                                        if (!empty($item_data['custom_price'])) {
                                            $_product->set_price($item_data['custom_price']);
                                        }
                                        $cart = WC()->cart->get_cart();
                                        $cart_item_key = md5($product_id . $variation_id . time() . rand(1, 1000));
                                        $cart[$cart_item_key] = $item_data;
                                        $cart[$cart_item_key]['data'] = $_product;
                                        WC()->cart->set_cart_contents($cart);
                                    }
                                }
                            }
                        }
                    }
                }
            },
            PHP_INT_MAX
        );
    }

    /**
     * Get the core class.
     *
     * @return WicketGuestPaymentCore|null
     */
    public function get_core()
    {
        return $this->core;
    }

    /**
     * Get the email class.
     *
     * @return WicketGuestPaymentEmail|null
     */
    public function get_email()
    {
        return $this->email;
    }

    /**
     * Get the auth class.
     *
     * @return WicketGuestPaymentAuth|null
     */
    public function get_auth()
    {
        return $this->auth;
    }

    /**
     * Get the admin class.
     *
     * @return WicketGuestPaymentAdmin|null
     */
    public function get_admin()
    {
        return $this->admin;
    }

    /**
     * Get the notice class.
     *
     * @return WicketGuestPaymentNotice|null
     */
    public function get_notice()
    {
        return $this->notice;
    }

    /**
     * Get the invoice integration class.
     *
     * @return WicketGuestPaymentInvoice|null
     */
    public function get_invoice()
    {
        return $this->invoice;
    }

    /**
     * Get the receipt class.
     *
     * @return WicketGuestPaymentReceipt|null
     */
    public function get_receipt()
    {
        return $this->receipt;
    }

    /**
     * Generate a secure cart key that doesn't expose the user ID.
     *
     * @param int $user_id The user ID to generate a key for
     * @return string Random cart key
     */
    public function generate_secure_cart_key(int $user_id): string
    {
        // Generate a random string that's URL-safe - Increased to 24 chars
        $random_string = wp_generate_password(24, false);

        // Create a transient mapping from random string to user ID
        $map_key = 'wgp_map_' . $random_string;
        set_transient($map_key, $user_id, DAY_IN_SECONDS);

        $this->log(sprintf('Generated secure cart key mapping for user %d', $user_id));

        return $random_string;
    }

    /**
     * Get user ID from secure cart key.
     *
     * @param string $secure_key The secure key to look up
     * @return int|null User ID or null if not found
     */
    protected function get_user_id_from_secure_cart_key(string $secure_key): ?int
    {
        $map_key = 'wgp_map_' . $secure_key;
        $user_id = get_transient($map_key);

        if (false === $user_id) {
            $this->log('Could not find user ID for secure cart key: ' . $secure_key);

            return null;
        }

        return (int) $user_id;
    }

    /**
     * Delete secure cart key mapping and associated cart.
     *
     * @param string $secure_key The secure key to delete
     * @return bool Whether deletion was successful
     */
    public function delete_secure_cart_data(string $secure_key): bool
    {
        $map_key = 'wgp_map_' . $secure_key;
        $user_id = get_transient($map_key);

        $cart_key = 'wgp_cart_' . $secure_key;

        $result = delete_transient($cart_key) && delete_transient($map_key);

        if ($result) {
            $this->log(sprintf('Deleted secure cart data for key %s (user ID: %s)', $secure_key, $user_id ?? 'unknown'));
        }

        return $result;
    }

    /**
     * Programmatically initiate a guest payment request.
     *
     * Public API method for other parts of the system to use
     *
     * @param int    $order_id    The order ID to create a payment link for.
     * @param string $guest_email The email address of the guest payer.
     * @param bool   $send_email  Whether to send the email notification.
     * @return array|false Result array or false on failure.
     */
    public function initiate_guest_payment(int $order_id, string $guest_email, bool $send_email = true)
    {
        // Validate inputs
        if (!$order_id || !is_email($guest_email)) {
            return false;
        }

        // Initialize the payment
        $result = $this->core->initiate_payment($order_id, $guest_email);

        if (!$result) {
            return false;
        }

        // Send email if requested
        if ($send_email) {
            $this->email->send_payment_email(
                $guest_email,
                $result['token'],
                $result['order_id'],
                $result['user_id']
            );
        }

        return $result;
    }
}
