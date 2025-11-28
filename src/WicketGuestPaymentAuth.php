<?php

declare(strict_types=1);

/**
 * Guest Subscription Payment Flow for WooCommerce - Auth and Restrictions
 *
 * Handles user authentication and page access restrictions for guest payments.
 *
 * @package Wicket
 * @subpackage GuestPayment
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Authentication and restriction handling for Guest Subscription Payment Flow
 */
class WicketGuestPaymentAuth extends WicketGuestPaymentComponent
{

	/**
	 * Core functionality class
	 *
	 * @var WicketGuestPaymentCore
	 */
	private WicketGuestPaymentCore $core;

	/**
	 * Cookie name to flag a guest payment session.
	 */
	private const GUEST_SESSION_COOKIE = 'wordpress_logged_in_order';

	/**
	 * Maximum number of failed token validation attempts before lockout.
	 */
	private const MAX_FAILED_ATTEMPTS = 5;

	/**
	 * Lockout duration in seconds after exceeding max failed attempts.
	 */
	private const FAILED_ATTEMPT_WINDOW_SECONDS = 15 * MINUTE_IN_SECONDS; // 15 minutes

	/**
	 * Constructor
	 *
	 * @param WicketGuestPaymentCore $core Core functionality class
	 */
	public function __construct(WicketGuestPaymentCore $core)
	{
		$this->core = $core;
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	public function init_hooks(): void
	{
		// Authentication and restriction on template_redirect
		add_action('template_redirect', [$this, 'handle_guest_authentication_and_restriction'], 9); // Reverted priority to 9

		// Hook to clear the guest session flag on standard logout
		add_action('wp_logout', [$this, 'clear_guest_session_flag']);

		// Ensure the woocommerce_thankyou hook for clear_guest_session_flag is removed
		// Cleanup after successful payment
		add_action('woocommerce_thankyou', [$this, 'cleanup_after_payment'], 5);

		// Display error notices
		add_action('wp_head', [$this, 'display_error_notices']);

		// Restore cart from transient if needed (early in the request)
		add_action('wp', [$this, 'maybe_restore_cart_from_transient'], 5);

		// Prevent guest users from accessing wp-admin
		add_action('admin_init', [$this, 'prevent_guest_admin_access']);

		// Hide admin bar for guest authenticated users (with high priority)
		add_filter('show_admin_bar', [$this, 'maybe_hide_admin_bar'], 99);

		// Force WooCommerce to reuse the original order during checkout (prevent duplicates)
		add_filter('woocommerce_create_order', [$this, 'force_reuse_guest_payment_order'], 5, 2);

		// HARD STOPPER: Validate order ID before checkout processing (last line of defense)
		add_action('woocommerce_checkout_process', [$this, 'validate_guest_payment_order_before_checkout'], 1); // Classic Checkout
		add_action('woocommerce_checkout_validate_order_before_payment', [$this, 'validate_guest_payment_order_before_payment_block'], 1, 2); // Block Checkout
	}

	/**
	 * Force WooCommerce to reuse the original guest payment order during checkout.
	 * This prevents duplicate order creation when the session's order_awaiting_payment is lost.
	 *
	 * @param int|null $order_id The order ID that WooCommerce is about to create/use.
	 * @param WC_Checkout $checkout The checkout object.
	 * @return int The order ID to use (original order ID or new order ID).
	 */
	public function force_reuse_guest_payment_order($order_id, $checkout): int
	{
		// DIAGNOSTIC: Log that the hook was called
		$wc_session_order = WC()->session ? WC()->session->get('order_awaiting_payment') : 'session_not_available';
		$this->log(sprintf('force_reuse_guest_payment_order hook called. Input order_id: %s, WC session order_awaiting_payment: %s', $order_id ?? 'null', $wc_session_order ?? 'not_set'));

		// Check if this is a guest payment session
		if (!isset($_COOKIE[self::GUEST_SESSION_COOKIE])) {
			$this->log('force_reuse_guest_payment_order: Guest session cookie not set. Allowing WC to create new order.');
			return $order_id;
		}

		$user_id = get_current_user_id();
		if (!$user_id) {
			$this->log('force_reuse_guest_payment_order: User not logged in. Allowing WC to create new order.');
			return $order_id;
		}

		// Get the original order ID that should be reused
		$original_order_id = get_user_meta($user_id, '_wgp_original_order_id', true);

		if (!$original_order_id || !is_numeric($original_order_id)) {
			$this->log(sprintf('force_reuse_guest_payment_order: No valid original_order_id found in user meta for user %d (value: %s). Allowing WC to create new order.', $user_id, $original_order_id ?: 'empty'));
			return $order_id;
		}

		// Verify the original order exists and is valid
		$original_order = wc_get_order($original_order_id);
		if (!$original_order) {
			$this->log(sprintf('force_reuse_guest_payment_order: Original order #%d not found for user %d. Allowing WC to create new order.', $original_order_id, $user_id), 'warning');
			return $order_id;
		}

		$this->log(sprintf('force_reuse_guest_payment_order: Found original order #%d with status "%s" for user %d', $original_order_id, $original_order->get_status(), $user_id));

		// Only reuse if order is in pending/failed/on-hold status
		if (!$original_order->has_status(['pending', 'failed', 'on-hold'])) {
			$this->log(sprintf('force_reuse_guest_payment_order: Original order #%d has status "%s" (not pending/failed/on-hold) for user %d. Allowing WC to create new order.', $original_order_id, $original_order->get_status(), $user_id), 'warning');
			return $order_id;
		}

		// Verify cart hash matches to ensure we're paying for the same items
		$current_cart_hash = WC()->cart->get_cart_hash();
		$order_cart_hash = $original_order->get_cart_hash();

		if ($current_cart_hash !== $order_cart_hash) {
			$this->log(sprintf('force_reuse_guest_payment_order: Cart hash mismatch for order #%d. Current: %s, Order: %s. Updating order cart hash.', $original_order_id, $current_cart_hash, $order_cart_hash), 'info');
			$original_order->set_cart_hash($current_cart_hash);
			$original_order->save();
		} else {
			$this->log(sprintf('force_reuse_guest_payment_order: Cart hash matches for order #%d: %s', $original_order_id, $current_cart_hash));
		}

		$this->log(sprintf('DUPLICATE PREVENTION: Forcing reuse of original order #%d for user %d (prevented creation of new order).', $original_order_id, $user_id));

		// Return the original order ID to prevent duplicate creation
		return (int) $original_order_id;
	}

	/**
	 * HARD STOPPER: Validates that the order being processed matches the expected guest payment order.
	 * This is the last line of defense to prevent duplicate order creation.
	 * Runs during checkout validation and throws errors to stop the process if mismatch detected.
	 *
	 * @hooked woocommerce_checkout_process (priority 1)
	 * @return void
	 */
	public function validate_guest_payment_order_before_checkout(): void
	{
		// Only run for guest payment sessions
		if (!isset($_COOKIE[self::GUEST_SESSION_COOKIE])) {
			return;
		}

		$user_id = get_current_user_id();
		if (!$user_id) {
			$this->log('HARD STOPPER: Guest session active but user not logged in during checkout validation.', 'error');
			wc_add_notice(__('Session error detected. Please use your guest payment link to try again.', 'wicket-wgc'), 'error');
			return;
		}

		// Get the expected original order ID
		$expected_order_id = get_user_meta($user_id, '_wgp_original_order_id', true);
		if (!$expected_order_id) {
			$this->log(sprintf('HARD STOPPER: No original order ID found in user meta for user %d during checkout validation.', $user_id), 'error');
			wc_add_notice(__('Session error detected. Please use your guest payment link to try again.', 'wicket-wgc'), 'error');
			return;
		}

		// Check WooCommerce session for order_awaiting_payment
		$session_order_id = WC()->session ? WC()->session->get('order_awaiting_payment') : null;

		$this->log(
			sprintf(
				'HARD STOPPER: Validating checkout - Expected order: %d, Session order: %s, User: %d',
				$expected_order_id,
				$session_order_id ?? 'NOT SET',
				$user_id
			)
		);

		// Validate that session order matches expected order
		if (!$session_order_id || (int)$session_order_id !== (int)$expected_order_id) {
			$this->log(
				sprintf(
					'HARD STOPPER TRIGGERED: Order mismatch detected! Expected: %d, Session: %s, User: %d. BLOCKING CHECKOUT.',
					$expected_order_id,
					$session_order_id ?? 'NOT SET',
					$user_id
				),
				'error'
			);

			// Clear the cart to prevent any further issues
			WC()->cart->empty_cart();

			// Clear session data
			WC()->session->set('order_awaiting_payment', null);

			// Throw validation error that stops checkout
			wc_add_notice(
				__('An error occurred during checkout. Your session has expired or become invalid. Please use your guest payment link to start over.', 'wicket-wgc'),
				'error'
			);

			// Redirect back to cart (WooCommerce will handle this via the notice)
			return;
		}

		// Verify the expected order still exists and has correct status
		$order = wc_get_order($expected_order_id);
		if (!$order || !$order->has_status(['pending', 'failed', 'on-hold'])) {
			$this->log(
				sprintf(
					'HARD STOPPER TRIGGERED: Order #%d not found or has invalid status (%s) for user %d. BLOCKING CHECKOUT.',
					$expected_order_id,
					$order ? $order->get_status() : 'NOT FOUND',
					$user_id
				),
				'error'
			);

			WC()->cart->empty_cart();
			WC()->session->set('order_awaiting_payment', null);

			wc_add_notice(
				__('The order you are trying to pay for is no longer available. Please use your guest payment link to start over.', 'wicket-wgc'),
				'error'
			);

			return;
		}

		// All validations passed
		$this->log(
			sprintf(
				'HARD STOPPER: Validation passed. Order #%d is correct for user %d. Allowing checkout to proceed.',
				$expected_order_id,
				$user_id
			)
		);
	}

	/**
	 * HARD STOPPER: Validates order for Block Checkout (Store API).
	 * This is the Block Checkout equivalent of validate_guest_payment_order_before_checkout.
	 *
	 * @hooked woocommerce_checkout_validate_order_before_payment (priority 1)
	 * @param WC_Order $order The order being processed.
	 * @param WP_Error $validation_errors WP_Error object to add errors to.
	 * @return void
	 */
	public function validate_guest_payment_order_before_payment_block(\WC_Order $order, \WP_Error $validation_errors): void
	{
		// Only run for guest payment sessions
		if (!isset($_COOKIE[self::GUEST_SESSION_COOKIE])) {
			return;
		}

		$user_id = get_current_user_id();
		if (!$user_id) {
			$this->log('HARD STOPPER (Block): Guest session active but user not logged in during checkout validation.', 'error');
			$validation_errors->add('guest_payment_session_error', __('Session error detected. Please use your guest payment link to try again.', 'wicket-wgc'));
			return;
		}

		// Get the expected original order ID
		$expected_order_id = get_user_meta($user_id, '_wgp_original_order_id', true);
		if (!$expected_order_id) {
			$this->log(sprintf('HARD STOPPER (Block): No original order ID found in user meta for user %d during checkout validation.', $user_id), 'error');
			$validation_errors->add('guest_payment_session_error', __('Session error detected. Please use your guest payment link to try again.', 'wicket-wgc'));
			return;
		}

		$order_id = $order->get_id();

		$this->log(
			sprintf(
				'HARD STOPPER (Block): Validating checkout - Expected order: %d, Current order: %d, User: %d',
				$expected_order_id,
				$order_id,
				$user_id
			)
		);

		// Validate that order ID matches expected order
		if ((int)$order_id !== (int)$expected_order_id) {
			$this->log(
				sprintf(
					'HARD STOPPER (Block) TRIGGERED: Order mismatch detected! Expected: %d, Current: %d, User: %d. BLOCKING CHECKOUT.',
					$expected_order_id,
					$order_id,
					$user_id
				),
				'error'
			);

			// Add error that will stop checkout
			$validation_errors->add(
				'guest_payment_order_mismatch',
				__('An error occurred during checkout. Your session has expired or become invalid. Please use your guest payment link to start over.', 'wicket-wgc')
			);

			return;
		}

		// Verify the expected order still has correct status
		if (!$order->has_status(['pending', 'failed', 'on-hold'])) {
			$this->log(
				sprintf(
					'HARD STOPPER (Block) TRIGGERED: Order #%d has invalid status (%s) for user %d. BLOCKING CHECKOUT.',
					$expected_order_id,
					$order->get_status(),
					$user_id
				),
				'error'
			);

			$validation_errors->add(
				'guest_payment_order_status',
				__('The order you are trying to pay for is no longer available. Please use your guest payment link to start over.', 'wicket-wgc')
			);

			return;
		}

		// All validations passed
		$this->log(
			sprintf(
				'HARD STOPPER (Block): Validation passed. Order #%d is correct for user %d. Allowing checkout to proceed.',
				$expected_order_id,
				$user_id
			)
		);
	}

	/**
	 * Clear the guest session flag cookie on logout.
	 */
	public function clear_guest_session_flag(): void
	{
		if (isset($_COOKIE[self::GUEST_SESSION_COOKIE])) {
			setcookie(self::GUEST_SESSION_COOKIE, '', [
				'expires' => time() - HOUR_IN_SECONDS,
				'path' => COOKIEPATH,
				'domain' => COOKIE_DOMAIN,
				'secure' => is_ssl(),
				'httponly' => true
			]);

			$this->log('Cleared guest session flag cookie on logout.');
		}
	}

	/**
	 * Restore cart from transient if the wgp_cart_key parameter is present.
	 * This ensures cart contents persist across the redirect.
	 *
	 * @return void
	 */
	public function maybe_restore_cart_from_transient(): void
	{
		// Only run this on the cart or checkout pages
		if (!is_cart() && !is_checkout()) {
			return;
		}

		// Check if we have a cart key in the URL
		if (isset($_GET['wgp_cart_key']) && !empty($_GET['wgp_cart_key'])) {
			$secure_cart_key = sanitize_key($_GET['wgp_cart_key']);
			$cart_data_transient_key = 'wgp_cart_' . $secure_cart_key;
			$cart_data = get_transient($cart_data_transient_key);

			$this->log(
				sprintf('Attempting to restore cart from transient: %s (Secure Key: %s)', $cart_data_transient_key, $secure_cart_key)
			);

			// If we have cart data in the transient, restore it
			if ($cart_data && is_array($cart_data)) {
				// Ensure WooCommerce is initialized
				if (function_exists('WC') && WC()->cart && WC()->session) {
					// Check if the cart is empty before restoring
					if (WC()->cart->is_empty()) {
						// Clear any existing cart session data just in case
						WC()->cart->empty_cart(true);

						// Set the cart contents from the transient
						// We need to re-fetch product objects to ensure they are valid
						$restored_cart_contents = [];
						foreach ($cart_data as $item_key => $item_data) {
							$product_id = !empty($item_data['product_id']) ? absint($item_data['product_id']) : 0;
							$variation_id = !empty($item_data['variation_id']) ? absint($item_data['variation_id']) : 0;
							$quantity = !empty($item_data['quantity']) ? absint($item_data['quantity']) : 0;

							if ($product_id > 0 && $quantity > 0) {
								$_product = wc_get_product($variation_id ?: $product_id);
								if (!empty($item_data['custom_price'])) {
									$_product->set_price($item_data['custom_price']);
								}
								if ($_product && $_product->exists() && ($_product->is_purchasable() || !empty($item_data['custom_price']))) {
									// For items with custom price, use manual cart addition to avoid add_to_cart validation issues
									if (!empty($item_data['custom_price'])) {
										$cart = WC()->cart->get_cart();
										$cart_item_key = md5($product_id . $variation_id . time() . rand(1, 1000));
										$cart[$cart_item_key] = $item_data;
										$cart[$cart_item_key]['data'] = $_product;
										WC()->cart->set_cart_contents($cart);
										$this->log(sprintf('Manually added product ID %d (Variation ID: %d) to cart during transient restore with custom price.', $product_id, $variation_id));
									} else {
										// Add to cart directly to let WooCommerce handle creating the cart item data structure
										$cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $item_data['variation'] ?? [], $item_data);
										if (!$cart_item_key) {
											$this->log(sprintf('Failed to add product ID %d (Variation ID: %d) to cart during transient restore.', $product_id, $variation_id), 'warning');
										}
									}
								} else {
									$this->log(sprintf('Product ID %d (Variation ID: %d) is not valid, purchasable, or has no custom price. Skipping from transient restore.', $product_id, $variation_id), 'warning');
								}
							}
						}

						if (!WC()->cart->is_empty()) {
							WC()->cart->calculate_totals(); // This is where the error was occurring

							$this->log(
								sprintf('Successfully restored and recalculated cart from transient. Secure key: %s', $secure_cart_key)
							);

							// Store cart data in user meta for potential re-add during validation
							$user_id = get_current_user_id();
							if ($user_id) {
								$has_custom_price = false;
								foreach (WC()->cart->get_cart() as $cart_item) {
									if (!empty($cart_item['custom_price'])) {
										$has_custom_price = true;
										break;
									}
								}
								if ($has_custom_price) {
									update_user_meta($user_id, '_wgp_cart_data', WC()->cart->get_cart_for_session());
									$this->log('Stored cart data in user meta for potential re-add');
								}
							}

							// Use the main class's method to delete both mapping and cart transient
							WicketGuestPayment::get_instance()->delete_secure_cart_data($secure_cart_key);

							// Redirect to remove the wgp_cart_key from URL and prevent reprocessing
							$redirect_url = is_cart() ? wc_get_cart_url() : wc_get_checkout_url();
							wp_safe_redirect(remove_query_arg('wgp_cart_key', $redirect_url));
							exit;
						} else {
							$this->log(sprintf('Cart is empty after attempting to restore from transient. Secure key: %s', $secure_cart_key), 'warning');
							// Also delete transient if cart ends up empty
							WicketGuestPayment::get_instance()->delete_secure_cart_data($secure_cart_key);
						}
					} else {
						$this->log('Cart was not empty. Skipping restore from transient.', 'info');
						// Delete the transient if the cart isn't empty, as it means we won't use it.
						WicketGuestPayment::get_instance()->delete_secure_cart_data($secure_cart_key);
					}
				} else {
					$this->log('WooCommerce Cart or Session not available during transient restore.', 'error');
				}
			} else {
				// Transient data not found or invalid
				$this->log(sprintf('Cart data not found in transient or invalid for secure key: %s. Transient key: %s', $secure_cart_key, $cart_data_transient_key), 'warning');
				// If transient was expected but not found, maybe it expired or was already used.
				// No need to delete if get_transient returned false.
			}
		}
	}

	/**
	 * Handles token validation, user authentication, and access restriction.
	 *
	 * This function performs two main tasks:
	 * 1. Authentication: If a guest_payment_token is present in the URL and the user is not logged in,
	 *    it validates the token, performs rate limiting, and attempts to log in the associated user.
	 * 2. Restriction: If the user is identified as being in a guest payment session (via the flag),
	 *    it restricts their access to only the checkout pages.
	 */
	public function handle_guest_authentication_and_restriction(): void
	{
		// Not on wp-login, not on admin
		global $pagenow;
		if ($pagenow === 'wp-login.php' || is_admin()) {
			return;
		}

		// Check if there's a guest payment token in the URL and the user is logged in
		if (isset($_GET['guest_payment_token']) && is_user_logged_in()) {
			// Get the token before logging out
			$token = sanitize_text_field($_GET['guest_payment_token']);

			// Clear auth cookies manually to ensure proper logout
			wp_clear_auth_cookie();

			// Clear the guest session flag if it exists
			$this->clear_guest_session_flag();

			// Reconstruct and redirect to the URL with the token
			$redirect_url = home_url('/?guest_payment_token=' . $token);
			wp_safe_redirect($redirect_url);
			exit;
		}

		// Check if there's a guest payment token in the URL and user is not logged in
		if (isset($_GET['guest_payment_token']) && !is_user_logged_in()) {
			// Ensure WooCommerce is fully initialized before proceeding
			if (function_exists('WC')) {
				// Force WooCommerce cart initialization if needed
				if (!isset(WC()->cart) || !isset(WC()->session)) {
					// This ensures WC is fully initialized
					$this->log(
						'Initializing WooCommerce session and cart before token validation'
					);
				}

				// Make sure customer session is started
				if (isset(WC()->session) && !WC()->session->has_session()) {
					WC()->session->set_customer_session_cookie(true);
				}
			}

			$token = sanitize_text_field($_GET['guest_payment_token']);

			$this->log('Guest payment token found in URL. Attempting validation.');

			// Rate Limiting Logic
			$ip_address = $this->get_user_ip_address();

			// Only apply rate limiting if the IP is not a local development IP (172.18.*.*)
			if (!str_starts_with($ip_address, '172.18.')) {
				$transient_key = 'guest_pay_limit_' . str_replace(['.', ':'], '_', $ip_address);  // Sanitize IP for transient key
				$failed_attempts = (int) get_transient($transient_key);

				// If limit exceeded, redirect and exit
				if ($failed_attempts >= self::MAX_FAILED_ATTEMPTS) {
					$this->log('Rate limit exceeded for token validation attempts from IP: ' . $ip_address);
					wp_safe_redirect(home_url('/?guest_payment_error=rate_limited'));
					exit;
				}
			} else {
				// Log that rate limiting is skipped for local IP range
				$this->log('Skipping rate limit check for local IP range (172.18.*.*): ' . $ip_address);
				$transient_key = null; // Ensure transient key is null if skipped
				$failed_attempts = 0;  // Ensure failed attempts is 0 if skipped
			}

			// Validate the token and retrieve the associated WC_Order object
			$order = $this->core->validate_token($token);

			if ($order instanceof \WC_Order) {
				// Log the found order status
				$this->log(
					sprintf('Token validated for Order ID: %d with Status: %s.', $order->get_id(), $order->get_status())
				);

				// Check if token is expired (using core method), passing the existing order object
				$token_data = $this->core->get_valid_token_data($order->get_id(), $order);
				if (is_null($token_data)) {
					$this->log('Token validation failed: Token data for order #' . $order->get_id() . ' is invalid or expired.');

					// Increment transient only if rate limiting is active for this IP
					if (!str_starts_with($ip_address, '172.18.') && $transient_key) {
						set_transient($transient_key, $failed_attempts + 1, self::FAILED_ATTEMPT_WINDOW_SECONDS);
					}
					wp_safe_redirect(home_url('/?guest_payment_error=expired'));
					exit;
				}

				// User Authentication
				$user_id = $order->get_user_id();
				if ($user_id && get_user_by('id', $user_id)) {
					$this->log('Attempting to authenticate user ID: ' . $user_id . ' via token for order #' . $order->get_id() . '.');

					// DUPLICATION PREVENTION: Check if user already has a processed guest payment order
					$existing_order_id = get_user_meta($user_id, '_wgp_original_order_id', true);
					if ($existing_order_id && $existing_order_id != $order->get_id()) {
						$this->log(
							sprintf(
								'DUPLICATE PREVENTED: User %d already has processed guest payment order #%d. Current token order is #%d. Redirecting to existing order.',
								$user_id,
								$existing_order_id,
								$order->get_id()
							)
						);

						// Clear the rate limit transient on prevention only if rate limiting was active
						if (!str_starts_with($ip_address, '172.18.') && $transient_key) {
							delete_transient($transient_key);
						}

						// Redirect to the existing order instead of creating a new one
						$existing_order = wc_get_order($existing_order_id);
						if ($existing_order) {
							// Check if existing order is completed/paid and redirect to appropriate page
							if ($existing_order->is_paid()) {
								wp_safe_redirect(home_url('/?guest_payment_success=1'));
							} else {
								// Redirect back to the existing order's payment page
								wp_safe_redirect($existing_order->get_checkout_payment_url());
							}
						} else {
							// Fallback redirect if order doesn't exist
							wp_safe_redirect(home_url('/?guest_payment_error=order_not_found'));
						}
						exit;
					}

					wp_set_current_user($user_id);
					// Set Remember Me to false - session only
					wp_set_auth_cookie($user_id, false, is_ssl());

					// Store the validated token hash in user meta for session validation
					$token_hash_for_cookie = hash('sha256', $token); // Use a simple hash for the cookie value
					update_user_meta($user_id, '_wgp_guest_session_token_validation', $token_hash_for_cookie);

					// Store the original order ID associated with this token for later invalidation
					update_user_meta($user_id, '_wgp_original_order_id', $order->get_id());

					// Set the temporary guest session flag cookie
					// Expires in 1 hour, secure if SSL, httpOnly
					setcookie(self::GUEST_SESSION_COOKIE, '1', time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
					// Ensure no WC session variable is set here for guest state tracking

					$this->log(
						sprintf('User ID: %d authenticated successfully via token. Original Order ID: %d stored. Guest session flag cookie set.', $user_id, $order->get_id())
					);

					// Clear the rate limit transient on success only if rate limiting was active
					if (!str_starts_with($ip_address, '172.18.') && $transient_key) {
						delete_transient($transient_key);
					}

					// Check WC cart state before preparation
					$cart_items_before = WC()->cart ? count(WC()->cart->get_cart()) : 0;
					$order_items = $order->get_items() ? count($order->get_items()) : 0;
					$this->log(
						sprintf(
							'Before cart preparation: Cart has %d items, Order has %d items (Order ID: %d)',
							$cart_items_before,
							$order_items,
							$order->get_id()
						)
					);

					// Ensure WooCommerce is fully initialized
					if (function_exists('WC') && isset(WC()->cart) && isset(WC()->session)) {
						// Force session initialization
						if (!WC()->session->has_session()) {
							WC()->session->set_customer_session_cookie(true);
						}
					}

					// Prepare the cart with items from the order
					$cart_prepared = $this->core->prepare_cart_from_order($order);

					// Double-check cart state and force save if needed
					if ($cart_prepared && WC()->cart && WC()->cart->get_cart_contents_count() > 0) {
						// Force cart calculation
						WC()->cart->calculate_totals();
						WC()->session->set('cart', WC()->cart->get_cart_for_session());

						// Ensure WooCommerce reuses the original pending order during checkout
						$cart_hash = WC()->cart->get_cart_hash();
						if (!empty($cart_hash)) {
							$order->set_cart_hash($cart_hash);
							$order->save();
							WC()->session->set('order_awaiting_payment', $order->get_id());
							$this->log(
								sprintf(
									'Reusing original order #%d for checkout. Cart hash synced to %s.',
									$order->get_id(),
									$cart_hash
								)
							);
						}

						// CRITICAL: Save session AFTER setting order_awaiting_payment
						WC()->session->save_data();

						// Verify session was saved correctly
						$saved_order_id = WC()->session->get('order_awaiting_payment');
						$this->log(
							sprintf(
								'Session saved. Verified order_awaiting_payment in session: %s (Expected: %d)',
								$saved_order_id ?? 'NOT SET',
								$order->get_id()
							)
						);

						$this->log(
							sprintf(
								'After cart preparation: Cart has %d items, Preparation result: %s (Order ID: %d)',
								WC()->cart->get_cart_contents_count(),
								($cart_prepared ? 'SUCCESS' : 'FAILED'),
								$order->get_id()
							)
						);

						if ($cart_prepared) {
							// Double-check cart is not empty before redirecting
							if (WC()->cart && WC()->cart->get_cart_contents_count() > 0) {
								// Force a final cart save before redirecting
								WC()->cart->calculate_totals();
								WC()->session->set('cart', WC()->cart->get_cart_for_session());

								// Get the current session ID and customer ID
								$session_cookie = WC()->session->get_session_cookie();
								$customer_id = WC()->session->get_customer_id();

								// Explicitly save the session data
								WC()->session->save_data();

								// Ensure the WC session cookie is set properly
								if ($session_cookie) {
									$this->log(
										sprintf('Setting WC session cookie for customer ID: %s', $customer_id)
									);

									// Manually set the WC session cookie to ensure it persists
									$session_expiration = time() + intval(apply_filters('wc_session_expiration', 60 * 60 * 48)); // 48 hours
									wc_setcookie('wp_woocommerce_session_' . COOKIEHASH, implode('||', $session_cookie), $session_expiration);
								}

								// Cart prepared successfully, redirect to cart page
								$this->log(
									sprintf(
										'Cart prepared successfully with %d items for Order ID: %d. Redirecting to cart.',
										WC()->cart->get_cart_contents_count(),
										$order->get_id()
									)
								);

								// Add cart contents to the transient to ensure it's available after redirect
								$customer_id = get_current_user_id(); // Re-confirm we have the user ID
								if ($customer_id) {
									// Generate a secure key instead of using user ID directly
									$secure_key = $this->get_plugin()->generate_secure_cart_key($customer_id);
									update_user_meta($customer_id, '_wgp_cart_key', $secure_key);
									$transient_key = 'wgp_cart_' . $secure_key;
									set_transient($transient_key, WC()->cart->get_cart_for_session(), DAY_IN_SECONDS); // Increase expiry to 1 day

									// Redirect to cart with the secure key
									wp_safe_redirect(add_query_arg('wgp_cart_key', $secure_key, wc_get_cart_url()));
									exit;
								} else {
									// Log error - couldn't get customer ID after authentication
									$this->log('Error: Could not retrieve customer ID after authentication to save cart transient.');
									// Redirect to cart anyway, maybe session cart is enough?
									wp_safe_redirect(wc_get_cart_url());
									exit;
								}
							} else {
								// Cart is empty despite prepare_cart_from_order returning true
								$this->log(
									sprintf('Cart preparation reported success but cart is empty for Order ID: %d', $order->get_id())
								);

								$cart_prepared = false; // Force it to go to the error path
							}
						} else {
							// Cart preparation failed
							$this->log(
								sprintf('Failed to prepare cart for Order ID: %d after user authentication. Redirecting home.', $order->get_id())
							);

							// If cart prep fails, log out the user we just logged in and redirect home with error
							// Use a safer approach to log out that won't trigger WP Cassify session callback issues
							wp_clear_auth_cookie();
							setcookie(self::GUEST_SESSION_COOKIE, '', [
								'expires' => time() - HOUR_IN_SECONDS,
								'path' => COOKIEPATH,
								'domain' => COOKIE_DOMAIN,
								'secure' => is_ssl(),
								'httponly' => true
							]); // Clear cookie

							// Log the error for debugging
							$this->log(
								sprintf('Cart preparation failed for Order ID: %d. Using safe logout method.', $order->get_id())
							);

							wp_safe_redirect(home_url('/?guest_payment_error=cart_prep_failed'));
							exit;
						}
					} else {
						// Cart preparation failed
						$this->log(
							sprintf('Failed to prepare cart for Order ID: %d after user authentication. Redirecting home.', $order->get_id())
						);

						// If cart prep fails, log out the user we just logged in and redirect home with error
						// Use a safer approach to log out that won't trigger WP Cassify session callback issues
						wp_clear_auth_cookie();
						setcookie(self::GUEST_SESSION_COOKIE, '', [
							'expires' => time() - HOUR_IN_SECONDS,
							'path' => COOKIEPATH,
							'domain' => COOKIE_DOMAIN,
							'secure' => is_ssl(),
							'httponly' => true
						]); // Clear cookie

						// Log the error for debugging
						$this->log(
							sprintf('Cart preparation failed for Order ID: %d. Using safe logout method.', $order->get_id())
						);

						wp_safe_redirect(home_url('/?guest_payment_error=cart_prep_failed'));
						exit;
					}
				} else {
					$this->log(
						sprintf('Guest Payment Auth: User authentication failed for User ID: %d, Order ID: %d.', $user_id, $order->get_id())
					);

					// Handle case where user ID is missing (maybe redirect with error?)
					wp_safe_redirect(home_url('/?guest_payment_error=no_user_id'));
					exit;
				}
			} elseif (is_wp_error($order) || $order === false) {
				// Increment failed attempts count
				// Increment transient only if rate limiting is active for this IP
				if (!str_starts_with($ip_address, '172.18.') && $transient_key) {
					set_transient($transient_key, $failed_attempts + 1, self::FAILED_ATTEMPT_WINDOW_SECONDS);
				}

				// Token validation failed (logged within validate_token), redirect to home with error
				wp_safe_redirect(home_url('/?guest_payment_error=invalid_token'));
				exit;
			}
		}

		// Restriction Part
		// Check if this is a guest payment session using only the cookie
		$is_guest_session = isset($_COOKIE[self::GUEST_SESSION_COOKIE]);

		if ($is_guest_session) {
			$this->log('Guest payment session active for user ID: ' . get_current_user_id() . '. Applying restrictions (checked cookie).');

			// Add Logging for Conditional Tags
			$log_is_checkout    = is_checkout() ? 'true' : 'false';
			$log_is_cart        = is_cart() ? 'true' : 'false';
			$log_is_order_pay = is_wc_endpoint_url('order-pay') ? 'true' : 'false';
			$log_is_ajax      = wp_doing_ajax() ? 'true' : 'false'; // Add AJAX check log
			$log_is_rest      = (defined('REST_REQUEST') && REST_REQUEST) ? 'true' : 'false'; // Add REST check log
			$this->log(
				sprintf('Restriction check - Conditional Tags: is_checkout=[%s], is_cart=[%s], is_order_pay=[%s], is_ajax=[%s], is_rest=[%s]', $log_is_checkout, $log_is_cart, $log_is_order_pay, $log_is_ajax, $log_is_rest)
			);
			// End Logging

			// Allow access only to checkout, order-pay endpoint, cart, AJAX requests, OR REST API requests
			$is_allowed_page = is_checkout()
				|| is_wc_endpoint_url('order-pay')
				|| is_cart()
				|| wp_doing_ajax()
				|| (defined('REST_REQUEST') && REST_REQUEST);

			if (!$is_allowed_page) {
				$this->log('Guest session user attempted to access a restricted page (URL: ' . (isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : 'N/A') . '). Redirecting the user back to the cart.');

				// Redirect to cart
				wp_safe_redirect(wc_get_cart_url());
				exit;
			} else {
				$this->log('Access allowed for guest session on current page (URL: ' . (isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : 'N/A') . ').');
			}
		}
	}

	/**
	 * Cleans up after successful guest payment.
	 * Hooks into woocommerce_thankyou.
	 *
	 * @param int $order_id The ID of the order being processed.
	 * @return void
	 */
	public function cleanup_after_payment(int $order_id): void
	{
		// Check if this was a guest payment session using the cookie
		if (isset($_COOKIE[self::GUEST_SESSION_COOKIE])) {
			// Ensure no session variable is checked or cleared here

			// The $order_id passed here is for the NEW order just created by the checkout.
			// We need the ORIGINAL order ID stored in user meta to invalidate the correct token.
			$current_user_id = get_current_user_id();
			$original_order_id_to_invalidate = !empty($current_user_id) ? get_user_meta($current_user_id, '_wgp_original_order_id', true) : null;

			// DUPLICATION PREVENTION: Verify this is the correct order for cleanup
			if (!empty($original_order_id_to_invalidate) && is_numeric($original_order_id_to_invalidate)) {
				// Check if the new order is valid and belongs to this user
				$new_order = wc_get_order($order_id);
				if ($new_order && $new_order->get_user_id() == $current_user_id) {
					$this->core->invalidate_token_for_order((int)$original_order_id_to_invalidate);

					$this->log(
						sprintf('Order verified cleanup: Invalidated token for original Order ID: %d (New Order ID was %d).', $original_order_id_to_invalidate, $order_id)
					);
				} else {
					$this->log(
						sprintf('DUPLICATE PREVENTION: Skipping cleanup for order #%d - order verification failed for user %d.', $order_id, $current_user_id),
						'warning'
					);
					return;
				}
			} else {
				$this->log(
					sprintf('Could not find original order ID in user meta for user %d during cleanup for new Order ID %d. Token may not be invalidated.', $current_user_id ?: 'N/A', $order_id)
				);
			}

			// Add note to the NEW order
			$order = wc_get_order($order_id); // Get the new order object
			if ($order) {
				// Add an order note to the *new* order
				$order->add_order_note(__('Guest payment completed using token link. User logged out.', 'wicket-wgc'));
				$order->save();

				$this->log(
					sprintf('Guest payment cleanup: Session ended for Order ID: %d.', $order_id)
				);
			}

			// Get user ID before clearing auth cookie, needed for user meta cleanup
			$user_id = get_current_user_id();

			// Immediate Logout and Cleanup
			// Clear the WordPress auth cookie immediately server-side
			wp_clear_auth_cookie();

			// Clear the guest session flag cookie immediately server-side
			setcookie(self::GUEST_SESSION_COOKIE, '', [
				'expires' => time() - HOUR_IN_SECONDS,
				'path' => COOKIEPATH,
				'domain' => COOKIE_DOMAIN,
				'secure' => is_ssl(),
				'httponly' => true
			]);

			// Also delete the validation token stored in user meta
			if (!empty($user_id)) {
				delete_user_meta($user_id, '_wgp_guest_session_token_validation');
				delete_user_meta($user_id, '_wgp_original_order_id');
				delete_user_meta($user_id, '_wgp_cart_data');

				// Find and clean up all cart transients related to this user
				global $wpdb;
				$transient_option_names = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s AND option_value = %s",
						$wpdb->esc_like('_transient_wgp_map_') . '%',
						$user_id
					)
				);

				$cleanup_count = 0;
				foreach ($transient_option_names as $option_name) {
					// Extract the secure key from the option name
					$secure_key = str_replace('_transient_wgp_map_', '', $option_name);

					// Delete both the cart data and the mapping
					if ($this->get_plugin()->delete_secure_cart_data($secure_key)) {
						$cleanup_count++;
					}
				}

				$this->log(sprintf('Cleared user meta and %d cart transients for user ID: %d', $cleanup_count, $user_id));
			}

			// Log the immediate logout action
			$this->log('Immediate logout: Auth and guest cookies cleared for Order ID: ' . $order_id);
		}
	}

	/**
	 * Display notices for guest payment errors on the homepage.
	 *
	 * @return void
	 */
	public function display_error_notices(): void
	{
		if (isset($_GET['guest_payment_error'])) {
			$error_code = sanitize_key($_GET['guest_payment_error']);
			$message = '';
			switch ($error_code) {
				case 'invalid_token':
					$message = __('The payment link is invalid or has expired. Please request a new link.', 'wicket-wgc');

					$this->log('Displaying error: invalid_token');

					break;
				case 'invalid_user':
					$message = __('There was an error processing your payment link. Please contact support.', 'wicket-wgc');

					$this->log('Displaying error: invalid_user');

					break;
				case 'rate_limited':
					$message = __('Too many failed attempts. Please try again later.', 'wicket-wgc');

					$this->log('Displaying error: rate_limited');

					break;
				case 'cart_prep_failed':
					$message = __('Failed to prepare cart for payment. Please try again or contact support.', 'wicket-wgc');

					$this->log('Displaying error: cart_prep_failed');

					break;
				case 'no_user_id':
					$message = __('No user ID found for payment link. Please contact support.', 'wicket-wgc');

					$this->log('Displaying error: no_user_id');

					break;
				case 'restricted_page':
					$message = __('You are not allowed to access this page during a guest payment session.', 'wicket-wgc');

					$this->log('Displaying error: restricted_page');

					break;
				case 'order_not_found':
					$message = __('The original order could not be found. Please contact support.', 'wicket-wgc');

					$this->log('Displaying error: order_not_found');

					break;
				case 'expired':
					$message = __('The payment link has expired. Please request a new link.', 'wicket-wgc');

					$this->log('Displaying error: expired');

					break;
			}
			if ($message && function_exists('wc_add_notice')) {
				wc_add_notice($message, 'error');
			}
		}

		// Handle success messages for guest payment
		if (isset($_GET['guest_payment_success'])) {
			$success_code = sanitize_key($_GET['guest_payment_success']);
			$message = '';
			switch ($success_code) {
				case '1':
					$message = __('Your payment has already been completed. Thank you!', 'wicket-wgc');
					$this->log('Displaying success: payment_already_completed');
					break;
			}
			if ($message && function_exists('wc_add_notice')) {
				wc_add_notice($message, 'success');
			}
		}
	}

	/**
	 * Get the user's IP address, handling proxies.
	 *
	 * @return string IP address.
	 */
	/**
	 * Prevent guest users from accessing wp-admin area.
	 * If a guest user tries to access wp-admin, they will be logged out and redirected to home.
	 *
	 * @return void
	 */
	public function prevent_guest_admin_access(): void
	{
		// Allow AJAX requests to proceed, even in admin context
		if (wp_doing_ajax()) {
			//$this->log('Guest user AJAX request to admin area allowed.'); // Optional: Log allowed AJAX
			return;
		}

		// Check if this is a guest session
		if (isset($_COOKIE[self::GUEST_SESSION_COOKIE])) {
			// Clear auth cookies
			wp_clear_auth_cookie();

			// Clear guest session flag
			$this->clear_guest_session_flag();

			// Log the attempt
			$this->log('Guest user attempted to access wp-admin. User logged out and redirected.');

			// Redirect to home
			wp_safe_redirect(home_url('/'));
			exit;
		}
	}

	/**
	 * Hide the admin bar for guest authenticated users.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool Modified visibility.
	 */
	public function maybe_hide_admin_bar(bool $show): bool
	{
		$cookie_set = isset($_COOKIE[self::GUEST_SESSION_COOKIE]);

		// If the guest session cookie is set, remove the theme's filter and hide the admin bar
		if ($cookie_set) {
			$this->log('maybe_hide_admin_bar: Guest cookie is set. Removing theme filter wicket_should_show_admin_bar and hiding admin bar.');
			remove_filter('show_admin_bar', 'wicket_should_show_admin_bar', PHP_INT_MAX);
			return false;
		}

		// Otherwise, return the default value
		return $show;
	}

	private function get_user_ip_address(): string
	{
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			//ip from share internet
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			//ip pass from proxy
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		}
		return sanitize_text_field($ip);
	}
}
