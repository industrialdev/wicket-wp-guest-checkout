<?php

declare(strict_types=1);

namespace Wicket\GuestPayment;

use Exception;
use WC_Order;

/*
 * Guest Subscription Payment Flow for WooCommerce - Receipt Management.
 *
 * Handles receipt access for guest payers after payment completion.
 * The receipt page is token-gated and printable. The thank you page can
 * show a Print Receipt button and an email capture form, both controlled
 * by the site's Guest Checkout settings.
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

        // Receipt email capture (AJAX, works for logged-out guests)
        add_action('wp_ajax_wicket_set_guest_email_and_send_receipt', [$this, 'ajax_set_guest_email_and_send_receipt']);
        add_action('wp_ajax_nopriv_wicket_set_guest_email_and_send_receipt', [$this, 'ajax_set_guest_email_and_send_receipt']);
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
     * Renders the Print Receipt section and/or the email capture form,
     * depending on the site's Guest Checkout settings (both default on).
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

        $receipt_url = ('' !== $receipt_token) ? home_url("/guest-receipt/{$receipt_token}/") : '';

        $show_print = (bool) apply_filters('wicket/wooguestpay/receipt_print_enabled', true);
        $show_email = (bool) apply_filters('wicket/wooguestpay/receipt_email_enabled', true);

        if ($show_print && '' !== $receipt_url) {
            $template = $this->resolve_template('guest-receipt-thankyou-section.php');

            if ($template) {
                include $template;
            } else {
                $this->log('Guest receipt thank you template could not be located.', 'error');
            }
        }

        if ($show_email) {
            $this->render_email_capture_form($order_id);
        }
    }

    /**
     * Renders the email capture form for the thank you page.
     *
     * @param int $order_id The order ID.
     * @return void
     */
    private function render_email_capture_form(int $order_id): void
    {
        // Deterministic hash so the form survives the logout transition right after payment.
        $hash = wp_hash('wicket_guest_receipt_' . $order_id, 'nonce');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <section class="wicket-guest-receipt-email-section" style="margin: 40px 0; padding: 30px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #28a745;">
            <h2 style="color: #333; margin-top: 0; margin-bottom: 15px;">
                <?php echo esc_html__('Receive Your Payment Receipt', 'wicket-wgc'); ?>
            </h2>
            <p style="color: #666; margin-bottom: 20px; font-size: 16px;">
                <?php echo esc_html__('Enter your email address and we will send you a link to your payment receipt.', 'wicket-wgc'); ?>
            </p>
            <form id="wicket-guest-email-form" data-order-id="<?php echo esc_attr((string) $order_id); ?>">
                <input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order_id); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($hash); ?>">
                <input type="email" name="email" required placeholder="you@example.com"
                       style="padding: 10px 14px; border: 1px solid #ccc; border-radius: 4px; min-width: 260px;">
                <button type="submit"
                        style="padding: 11px 24px; background: #28a745; color: white; border: none; border-radius: 4px; font-weight: 600; cursor: pointer;">
                    <?php echo esc_html__('Send Receipt', 'wicket-wgc'); ?>
                </button>
            </form>
            <div class="wicket-guest-email-message" style="margin-top: 15px; font-size: 14px;"></div>
        </section>
        <script>
        jQuery(function ($) {
            $('#wicket-guest-email-form').on('submit', function (e) {
                e.preventDefault();
                var $form = $(this);
                var $message = $form.closest('.wicket-guest-receipt-email-section').find('.wicket-guest-email-message');
                $message.text('');
                $.post('<?php echo esc_js($ajax_url); ?>', {
                    action: 'wicket_set_guest_email_and_send_receipt',
                    order_id: $form.find('input[name="order_id"]').val(),
                    nonce: $form.find('input[name="nonce"]').val(),
                    email: $form.find('input[name="email"]').val()
                }).done(function (response) {
                    var ok = response && response.success;
                    var text = (response && response.data && response.data.message) || '';
                    $message.text(text).css('color', ok ? '#28a745' : '#dc3545');
                    if (ok) {
                        $form.find('input[name="email"]').val('');
                    }
                }).fail(function () {
                    $message.text('<?php echo esc_js(__('Something went wrong. Please try again.', 'wicket-wgc')); ?>').css('color', '#dc3545');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for setting the guest email and sending the receipt.
     *
     * @return void
     */
    public function ajax_set_guest_email_and_send_receipt(): void
    {
        $order_id = absint($_POST['order_id'] ?? 0);

        // Use a custom deterministic hash to avoid session/user context issues during the immediate logout transition.
        // This token depends only on the Order ID and the site's Nonce Salt, making it stable across the logout boundary.
        $expected_hash = wp_hash('wicket_guest_receipt_' . $order_id, 'nonce');
        $received_nonce = (string) ($_POST['nonce'] ?? '');

        if (!hash_equals($expected_hash, $received_nonce)) {
            $this->log(sprintf('Security token verification failed. Order ID: %d', $order_id));
            wp_send_json_error(['message' => __('Security check failed. Please reload the page.', 'wicket-wgc')], 403);
        }

        // Rate limit: max 3 receipt email sends per order per hour (prevents enumeration / email relay abuse).
        $rate_key = 'wgp_receipt_send_' . $order_id;
        $send_attempts = (int) get_transient($rate_key);
        if ($send_attempts >= 3) {
            wp_send_json_error(['message' => __('Too many attempts. Please try again later.', 'wicket-wgc')], 429);
        }
        set_transient($rate_key, $send_attempts + 1, HOUR_IN_SECONDS);

        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Invalid email address.', 'wicket-wgc')]);
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => __('Order not found.', 'wicket-wgc')]);
        }

        // Record the payer's email on the order for reference and admin displays.
        $order->update_meta_data('_wgp_guest_payment_email', $email);
        $order->save();

        $token = $this->get_or_generate_receipt_token($order);
        if (!$token) {
            wp_send_json_error(['message' => __('Could not create the receipt link. Please contact support.', 'wicket-wgc')]);
        }

        $sent = $this->send_receipt_email($order, $email, $token);

        if ($sent) {
            wp_send_json_success(['message' => __('Receipt sent successfully. Please check your inbox.', 'wicket-wgc')]);
        } else {
            wp_send_json_error(['message' => __('Failed to send receipt email. Please contact support.', 'wicket-wgc')]);
        }
    }

    /**
     * Sends the receipt email to a specified address.
     *
     * @param WC_Order $order The order object.
     * @param string $email The recipient email address.
     * @param string $token The receipt access token.
     * @return bool True on success, false on failure.
     */
    private function send_receipt_email(WC_Order $order, string $email, string $token): bool
    {
        $order_id = $order->get_id();
        $order_number = $order->get_order_number();

        $subject = sprintf(__('Receipt for Order #%s', 'wicket-wgc'), $order_number);
        $message = $this->get_receipt_email_content($order, $token);

        $from_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from_name . ' <' . get_option('admin_email') . '>',
        ];

        $sent = wp_mail($email, $subject, $message, $headers);

        if ($sent) {
            $this->log(sprintf('Receipt email sent successfully for Order ID: %d to %s', $order_id, $email));
        } else {
            $this->log(sprintf('Failed to send receipt email for Order ID: %d to %s', $order_id, $email), 'error');
        }

        return $sent;
    }

    /**
     * Builds the receipt email HTML content.
     *
     * @param WC_Order $order The order object.
     * @param string $token The receipt access token.
     * @return string The email HTML content.
     */
    private function get_receipt_email_content(WC_Order $order, string $token): string
    {
        $order_number = $order->get_order_number();
        $order_date = $order->get_date_created();
        $order_total = $order->get_formatted_order_total();
        $receipt_url = home_url("/guest-receipt/{$token}/");

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html(__('Receipt', 'wicket-wgc')); ?></title>
        </head>
        <body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: #f8f9fa; padding: 30px; border-radius: 8px;">
                <h2 style="color: #333; margin-bottom: 20px;"><?php echo esc_html(get_bloginfo('name')); ?></h2>
                <h1 style="color: #0073aa; margin-bottom: 10px;"><?php echo esc_html(__('Payment Receipt', 'wicket-wgc')); ?></h1>
                <p style="color: #666; margin-bottom: 30px;"><?php echo esc_html__('Thank you for your payment. Here is your receipt confirmation.', 'wicket-wgc'); ?></p>

                <div style="background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
                    <h3 style="color: #333; margin-top: 0;"><?php echo esc_html(__('Order Details', 'wicket-wgc')); ?></h3>
                    <p><strong><?php echo esc_html(__('Order Number:', 'wicket-wgc')); ?></strong> <?php echo esc_html($order_number); ?></p>
                    <p><strong><?php echo esc_html(__('Date:', 'wicket-wgc')); ?></strong> <?php echo esc_html($order_date ? $order_date->format('F j, Y') : ''); ?></p>
                    <p><strong><?php echo esc_html(__('Total Paid:', 'wicket-wgc')); ?></strong> <?php echo wp_kses_post($order_total); ?></p>
                </div>

                <div style="text-align: center; margin: 30px 0;">
                    <a href="<?php echo esc_url($receipt_url); ?>"
                       style="display: inline-block; background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;"
                       target="_blank">
                        <?php echo esc_html__('View Receipt Online', 'wicket-wgc'); ?>
                    </a>
                </div>

                <p style="color: #666; font-size: 14px; text-align: center;">
                    <?php echo esc_html__('This receipt link will remain accessible for 30 days.', 'wicket-wgc'); ?>
                </p>
            </div>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
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
