<?php

declare(strict_types=1);

namespace Wicket\GuestPayment;

use Exception;
use WC_Order;

/*
 * Guest Subscription Payment Flow for WooCommerce - Receipt Management.
 *
 * Handles receipt access for guest payers after payment completion.
 * The receipt page is token-gated and printable; no receipt emails are sent.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Receipt management for Guest Subscription Payment Flow.
 */
class WicketGuestPaymentReceipt extends WicketGuestPaymentComponent
{
    /**
     * Receipt token expiration in days.
     *
     * @var int
     */
    private int $receipt_token_expiry_days = 30;

    /**
     * Constructor.
     */
    public function __construct()
    {
        // Empty constructor - intentionally
    }

    /**
     * Initializes the class.
     *
     * @return void
     */
    public function init(): void
    {
        // Add receipt access endpoint
        add_action('init', [$this, 'add_receipt_endpoint']);
        add_action('template_redirect', [$this, 'handle_receipt_request'], 10);

        // Add receipt access after payment completion
        add_action('woocommerce_payment_complete', [$this, 'generate_receipt_access_token']);
        add_action('woocommerce_order_status_processing', [$this, 'generate_receipt_access_token']);
        add_action('woocommerce_order_status_completed', [$this, 'generate_receipt_access_token']);

        // Add post-payment receipt access section
        // Use woocommerce_order_details_after_order_table because it runs even for guest users who are logged out
        add_action('woocommerce_order_details_after_order_table', [$this, 'add_receipt_access_section'], 20);
    }

    /**
     * Adds receipt access endpoint rewrite rule.
     *
     * @return void
     */
    public function add_receipt_endpoint(): void
    {
        add_rewrite_rule(
            '^guest-receipt/([a-f0-9]{64})/?$',
            'index.php?guest_payment_token=$matches[1]&receipt_access=1',
            'top'
        );

        add_rewrite_tag('%guest_payment_token%', '([a-f0-9]{64})');
        add_rewrite_tag('%receipt_access%', '1');

        // Flush rewrite rules if needed
        if (get_option('wicket_guest_payment_receipt_rules_flushed') !== 'yes') {
            flush_rewrite_rules();
            update_option('wicket_guest_payment_receipt_rules_flushed', 'yes');
        }
    }

    /**
     * Handles receipt access requests.
     *
     * @return void
     */
    public function handle_receipt_request(): void
    {
        // Check if this is a receipt access request
        if (!get_query_var('receipt_access') || !get_query_var('guest_payment_token')) {
            return;
        }

        $token = sanitize_text_field((string) get_query_var('guest_payment_token'));
        $order = $this->validate_receipt_token($token);

        if (!$order instanceof WC_Order) {
            wp_die(__('Invalid or expired receipt access link.', 'wicket-wgc'), __('Access Denied', 'wicket-wgc'), 403);
        }

        // Display receipt page
        $this->display_receipt_page($order, $token);
        $this->maybe_exit();
    }

    /**
     * Checks whether an order was placed through the guest payment flow.
     *
     * The token hash is removed after payment, so completed orders are also
     * detected through the guest user id left behind by session cleanup.
     *
     * @param WC_Order $order The order object.
     * @return bool True when the order is a guest payment order.
     */
    private function is_guest_payment_order(WC_Order $order): bool
    {
        return !empty($order->get_meta('_wgp_guest_payment_token_hash', true))
            || !empty($order->get_meta('_wgp_guest_payment_user_id', true));
    }

    /**
     * Generates a receipt access token for completed guest payment orders.
     *
     * @param int $order_id The order ID.
     * @return void
     */
    public function generate_receipt_access_token(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if (!$this->is_guest_payment_order($order)) {
            $this->log(sprintf('Skipping receipt token generation for Order ID %d: Not a guest payment order.', $order_id));

            return;
        }

        $this->get_or_generate_receipt_token($order);
    }

    /**
     * Gets a valid receipt access token for an order, generating one when missing or expired.
     *
     * @param WC_Order $order The order object.
     * @return string|null The receipt token, or null on failure.
     */
    private function get_or_generate_receipt_token(WC_Order $order): ?string
    {
        $order_id = $order->get_id();

        // Reuse the existing token while it is still valid
        $existing_token = (string) $order->get_meta('_wgp_receipt_access_token', true);
        if ($existing_token !== '') {
            $created_timestamp = (int) $order->get_meta('_wgp_receipt_token_created', true);
            $expiry_timestamp = $created_timestamp + ($this->receipt_token_expiry_days * DAY_IN_SECONDS);

            if (time() <= $expiry_timestamp) {
                return $existing_token;
            }
        }

        $token = $this->generate_receipt_token();

        if ($token && $this->store_receipt_token_data($order_id, $token)) {
            $this->log(sprintf('Generated receipt access token for Order ID: %d', $order_id));

            return $token;
        }

        return null;
    }

    /**
     * Generates a secure receipt access token.
     *
     * @return string|false The generated token, or false on failure.
     */
    private function generate_receipt_token(): string|false
    {
        try {
            $random_bytes = random_bytes(32);
            $token = bin2hex($random_bytes);

            $this->log(sprintf('Successfully generated receipt access token of length %d.', strlen($token)));

            return $token;
        } catch (Exception $e) {
            $this->log(
                sprintf('Failed to generate receipt access token. Error: %s.', $e->getMessage()),
                'error'
            );

            return false;
        }
    }

    /**
     * Stores receipt token data as order meta.
     *
     * @param int $order_id The order ID.
     * @param string $token The receipt token.
     * @return bool True on success, false on failure.
     */
    private function store_receipt_token_data(int $order_id, string $token): bool
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log(sprintf('Failed to fetch order for receipt token storage. Order ID: %d', $order_id), 'error');

            return false;
        }

        $timestamp = time();

        $order->update_meta_data('_wgp_receipt_access_token', $token);
        $order->update_meta_data('_wgp_receipt_token_created', $timestamp);

        $saved = $order->save();

        if ($saved) {
            $this->log(sprintf('Receipt token stored successfully for Order ID: %d', $order_id));

            return true;
        } else {
            $this->log(sprintf('Failed to save receipt token for Order ID: %d', $order_id), 'error');

            return false;
        }
    }

    /**
     * Validates a receipt access token.
     *
     * @param string $token The token to validate.
     * @return WC_Order|false The order object if valid, false otherwise.
     */
    private function validate_receipt_token(string $token)
    {
        if (empty($token)) {
            return false;
        }

        // Search for order with receipt token
        $meta_query = [
            [
                'key'     => '_wgp_receipt_access_token',
                'value'   => $token,
                'compare' => '=',
            ],
        ];

        $order_query_args = [
            'limit'      => 1,
            'type'       => 'shop_order',
            'status'     => ['processing', 'completed'],
            'meta_query' => $meta_query,
            'return'     => 'ids',
        ];

        $found_ids = wc_get_orders($order_query_args);

        if (empty($found_ids)) {
            $this->log(sprintf('No order found for receipt token: %s', $token));

            return false;
        }

        $order_id = $found_ids[0];
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->log(sprintf('Failed to retrieve order for receipt token. Order ID: %d', $order_id), 'error');

            return false;
        }

        // Check token expiry
        $created_timestamp = (int) $order->get_meta('_wgp_receipt_token_created', true);
        $expiry_timestamp = $created_timestamp + ($this->receipt_token_expiry_days * DAY_IN_SECONDS);

        if (empty($created_timestamp) || time() > $expiry_timestamp) {
            $this->log(sprintf('Receipt token expired for Order ID: %d', $order_id));

            return false;
        }

        $this->log(sprintf('Receipt token validation successful for Order ID: %d', $order_id));

        return $order;
    }

    /**
     * Displays the receipt page.
     *
     * @param WC_Order $order The order object.
     * @param string $token The receipt token.
     * @return void
     */
    private function display_receipt_page(WC_Order $order, string $token): void
    {
        // Set up page data
        $order_id = $order->get_id();
        $order_number = $order->get_order_number();
        $order_date = $order->get_date_created();
        $order_total = $order->get_total();
        $billing_email = $order->get_billing_email();
        $guest_email = $order->get_meta('_wgp_guest_payment_email', true);

        $receipt_url = home_url("/guest-receipt/{$token}/");

        $template = $this->resolve_template('guest-receipt-template.php');

        if (!$template) {
            $this->log('Guest receipt template could not be located.', 'error');
            wp_die(__('Unable to load receipt template.', 'wicket-wgc'), __('Template Error', 'wicket-wgc'), 500);
        }

        include $template;
    }

    /**
     * Adds receipt access section to thank you page / order details.
     * Hooks into woocommerce_order_details_after_order_table which passes the order object.
     *
     * @param WC_Order|int $order_or_id The order object or ID.
     * @return void
     */
    public function add_receipt_access_section($order_or_id): void
    {
        $order = wc_get_order($order_or_id);
        if (!$order instanceof WC_Order) {
            $this->log('add_receipt_access_section: Invalid order provided.', 'error');

            return;
        }

        // Ensure we are on the Thank You page (Order Received endpoint)
        if (!is_wc_endpoint_url('order-received')) {
            return;
        }

        $order_id = $order->get_id();

        if (!$this->is_guest_payment_order($order)) {
            $this->log(sprintf('Skipping Order ID %d: Not a guest payment order (no token hash or guest user ID).', $order_id));

            return;
        }

        $receipt_token = $this->get_or_generate_receipt_token($order);

        if (!$receipt_token) {
            $this->log(sprintf('No receipt token available for Order ID: %d', $order_id));

            return;
        }

        $receipt_url = home_url("/guest-receipt/{$receipt_token}/");

        $template = $this->resolve_template('guest-receipt-thankyou-section.php');

        if (!$template) {
            $this->log('Guest receipt thank you template could not be located.', 'error');

            return;
        }

        include $template;
    }

    /**
     * Resolves a template path from the theme or plugin fallback.
     *
     * @param string $template_name Template filename.
     * @return string|null Absolute path if found, null otherwise.
     */
    private function resolve_template(string $template_name): ?string
    {
        $theme_template = locate_template($template_name);

        if (!empty($theme_template)) {
            return $theme_template;
        }

        $plugin_template = WICKET_GUEST_CHECKOUT_PATH . 'templates/' . $template_name;

        if (file_exists($plugin_template)) {
            return $plugin_template;
        }

        return null;
    }
}
