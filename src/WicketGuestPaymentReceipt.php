<?php

declare(strict_types=1);

/**
 * Guest Subscription Payment Flow for WooCommerce - Receipt Management.
 *
 * Handles receipt access and delivery for guest payers after payment completion.
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

        // Add AJAX handler for email receipt delivery
        add_action('wp_ajax_wicket_send_guest_receipt', [$this, 'ajax_send_receipt_email']);
        add_action('wp_ajax_nopriv_wicket_send_guest_receipt', [$this, 'ajax_send_receipt_email']);

        // Add post-payment receipt access section
        add_action('woocommerce_thankyou', [$this, 'add_receipt_access_section'], 20);
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

        $token = sanitize_text_field(get_query_var('guest_payment_token'));
        $order = $this->validate_receipt_token($token);

        if (!$order instanceof WC_Order) {
            wp_die(__('Invalid or expired receipt access link.', 'wicket-wgc'), __('Access Denied', 'wicket-wgc'), 403);
        }

        // Display receipt page
        $this->display_receipt_page($order, $token);
        exit;
    }

    /**
     * Generates a receipt access token for completed orders.
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

        // Check if this was a guest payment order
        $guest_email = $order->get_meta('_wgp_guest_payment_email', true);
        if (!$guest_email) {
            return;
        }

        // Check if receipt token already exists
        $existing_token = $order->get_meta('_wgp_receipt_access_token', true);
        if ($existing_token) {
            // Check if existing token is still valid
            $created_timestamp = (int) $order->get_meta('_wgp_receipt_token_created', true);
            $expiry_timestamp = $created_timestamp + ($this->receipt_token_expiry_days * DAY_IN_SECONDS);

            if (time() <= $expiry_timestamp) {
                $this->log(sprintf('Receipt token already exists and is valid for Order ID: %d', $order_id));

                return;
            }
        }

        // Generate new receipt token
        $token = $this->generate_receipt_token();
        if ($token && $this->store_receipt_token_data($order_id, $token)) {
            $this->log(sprintf('Generated receipt access token for Order ID: %d', $order_id));
        }
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

        // Load template
        include locate_template('guest-receipt-template.php');
    }

    /**
     * AJAX handler for sending receipt email.
     *
     * @return void
     */
    public function ajax_send_receipt_email(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wicket_send_receipt')) {
            wp_die(__('Security check failed.', 'wicket-wgc'));
        }

        $token = sanitize_text_field($_POST['token'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($token) || !is_email($email)) {
            wp_send_json_error([
                'message' => __('Invalid request. Please provide a valid email address.', 'wicket-wgc'),
            ]);
        }

        $order = $this->validate_receipt_token($token);
        if (!$order instanceof WC_Order) {
            wp_send_json_error([
                'message' => __('Invalid or expired receipt link.', 'wicket-wgc'),
            ]);
        }

        // Send receipt email
        $sent = $this->send_receipt_email($order, $email);

        if ($sent) {
            wp_send_json_success([
                'message' => __('Receipt has been sent to your email address.', 'wicket-wgc'),
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to send receipt. Please try again or contact support.', 'wicket-wgc'),
            ]);
        }
    }

    /**
     * Sends receipt email to specified address.
     *
     * @param WC_Order $order The order object.
     * @param string $email The email address to send to.
     * @return bool True on success, false on failure.
     */
    private function send_receipt_email(WC_Order $order, string $email): bool
    {
        $order_id = $order->get_id();
        $order_number = $order->get_order_number();

        $subject = sprintf(__('Receipt for Order #%s', 'wicket-wgc'), $order_number);

        // Build email content
        $message = $this->get_receipt_email_content($order, $email);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
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
     * Gets receipt email content.
     *
     * @param WC_Order $order The order object.
     * @param string $email The recipient email.
     * @return string The email content.
     */
    private function get_receipt_email_content(WC_Order $order, string $email): string
    {
        $order_id = $order->get_id();
        $order_number = $order->get_order_number();
        $order_date = $order->get_date_created();
        $order_total = $order->get_formatted_order_total();
        $receipt_token = $order->get_meta('_wgp_receipt_access_token', true);
        $receipt_url = home_url("/guest-receipt/{$receipt_token}/");

        // Check if PDF invoice plugin is available
        $invoice_url = '';
        if (class_exists('WooCommerce_PDF_Invoices')) {
            $invoice_url = admin_url('admin-ajax.php?action=generate_wpo_wcpdf&template_type=invoice&order_ids=' . $order_id);
        }

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
				<p style="color: #666; margin-bottom: 30px;"><?php echo esc_html(__('Thank you for your payment. Here is your receipt confirmation.', 'wicket-wgc')); ?></p>

				<div style="background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
					<h3 style="color: #333; margin-top: 0;"><?php echo esc_html(__('Order Details', 'wicket-wgc')); ?></h3>
					<p><strong><?php echo esc_html(__('Order Number:', 'wicket-wgc')); ?></strong> <?php echo esc_html($order_number); ?></p>
					<p><strong><?php echo esc_html(__('Date:', 'wicket-wgc')); ?></strong> <?php echo esc_html($order_date ? $order_date->format('F j, Y') : ''); ?></p>
					<p><strong><?php echo esc_html(__('Total Paid:', 'wicket-wgc')); ?></strong> <?php echo wp_kses_post($order_total); ?></p>
				</div>

				<div style="text-align: center; margin: 30px 0;">
					<?php if ($invoice_url): ?>
						<a href="<?php echo esc_url($invoice_url); ?>"
						   style="display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin-bottom: 10px;"
						   target="_blank">
							<?php echo esc_html(__('Download PDF Receipt', 'wicket-wgc')); ?>
						</a>
						<br>
					<?php endif; ?>
					<a href="<?php echo esc_url($receipt_url); ?>"
					   style="display: inline-block; background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
						<?php echo esc_html(__('View Receipt Online', 'wicket-wgc')); ?>
					</a>
				</div>

				<p style="color: #666; font-size: 14px; text-align: center;">
					<?php echo esc_html(__('This receipt link will remain accessible for 30 days.', 'wicket-wgc')); ?>
				</p>
			</div>
		</body>
		</html>
		<?php
        return ob_get_clean();
    }

    /**
     * Adds receipt access section to thank you page.
     *
     * @param int $order_id The order ID.
     * @return void
     */
    public function add_receipt_access_section(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if this was a guest payment
        $guest_email = $order->get_meta('_wgp_guest_payment_email', true);
        if (!$guest_email) {
            return;
        }

        // Get receipt token
        $receipt_token = $order->get_meta('_wgp_receipt_access_token', true);
        if (!$receipt_token) {
            return;
        }

        $receipt_url = home_url("/guest-receipt/{$receipt_token}/");

        // Check if PDF invoice plugin is available
        $invoice_url = '';
        if (class_exists('WooCommerce_PDF_Invoices')) {
            $invoice_url = admin_url('admin-ajax.php?action=generate_wpo_wcpdf&template_type=invoice&order_ids=' . $order_id);
        }

        include locate_template('guest-receipt-thankyou-section.php');
    }
}
