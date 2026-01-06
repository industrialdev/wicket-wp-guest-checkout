<?php

declare(strict_types=1);

/**
 * Guest Payment Invoice/Email Integration & Helper.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Guest Payment Invoice/Email Integration & Helper.
 */
class WicketGuestPaymentInvoice extends WicketGuestPaymentComponent
{
    /**
     * Initialize hooks for email and PDF invoice integration.
     */
    public function __construct()
    {
        // WooCommerce email: insert guest payment message just below 'Pay for this order' link
        add_action('woocommerce_email_before_order_table', [$this, 'insert_guest_payment_link_email'], 15, 4);

        // Check if PDF Invoices & Packing Slips plugin is active before registering PDF hooks
        if (class_exists('WPO_WCPDF') || class_exists('WPO\IPS\Main')) {
            // PDF Invoices & Packing Slips plugin - Move to footer
            add_action('wpo_wcpdf_after_footer', [$this, 'append_guest_payment_link_to_pdf'], 99, 2);
        }
    }

    /**
     * Get or generate a valid guest payment link for an order.
     *
     * @param int $order_id
     * @return string|false Guest payment URL or false on failure
     */
    public function get_or_generate_guest_payment_link(int $order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Use stored guest email if available, fallback to billing email
        $guest_email = $order->get_meta('_wgp_guest_payment_email');
        if (!$guest_email) {
            $guest_email = $order->get_billing_email();
        }
        if (!is_email($guest_email)) {
            return false;
        }

        // Check for valid, non-expired token
        $token_data = $this->get_valid_token_data($order_id, $order);
        if ($token_data && !empty($token_data['token'])) {
            $token = $token_data['token'];
        } else {
            // Generate new token
            $token = $this->generate_token_for_order($order_id, $guest_email, 'auto');
            if (!$token) {
                return false;
            }
        }

        // Build guest payment URL
        return add_query_arg('guest_payment_token', $token, wc_get_cart_url());
    }

    /**
     * Get valid token data for an order.
     *
     * @param int $order_id Order ID.
     * @param WC_Order $order Order object.
     * @return array|null Token data or null if invalid.
     */
    private function get_valid_token_data(int $order_id, WC_Order $order): ?array
    {
        // Get the Core component to validate token
        $main_plugin = WicketGuestPayment::get_instance();
        $core = $main_plugin->get_core();

        if (!$core) {
            return null;
        }

        return $core->get_valid_token_data($order_id, $order);
    }

    /**
     * Generate a new token for an order.
     *
     * @param int $order_id Order ID.
     * @param string $guest_email Guest email address.
     * @param string $generation_method Generation method ('auto', 'manual', 'email').
     * @return string|false Token or false on failure.
     */
    private function generate_token_for_order(int $order_id, string $guest_email, string $generation_method = 'auto')
    {
        // Get the main plugin instance to generate token
        $main_plugin = WicketGuestPayment::get_instance();

        // Generate guest payment without sending email (since this is for email integration)
        $result = $main_plugin->initiate_guest_payment($order_id, $guest_email, false);

        if ($result && !empty($result['token'])) {
            // Add order note about automatic generation for email
            $order = wc_get_order($order_id);
            if ($order) {
                $order->add_order_note(
                    sprintf(
                        __('Guest payment link automatically generated for email integration.', 'wicket-wgc')
                    )
                );
            }

            return $result['token'];
        }

        return false;
    }

    /**
     * Append guest payment link/message to WooCommerce emails for pending orders.
     *
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     * @param WC_Email $email
     */
    /**
     * Insert guest payment link/message at the top of WooCommerce emails (just below 'Pay for this order' link).
     *
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     * @param WC_Email $email
     */
    public function insert_guest_payment_link_email($order, $sent_to_admin, $plain_text, $email)
    {
        if (!($order instanceof WC_Order)) {
            return;
        }

        // Check if email integration is enabled (default: false - requires explicit activation)
        if (!apply_filters('wicket/wooguestpay/email_integration_enabled', false)) {
            return;
        }

        if ('pending' !== $order->get_status()) {
            return;
        }

        $link = $this->get_or_generate_guest_payment_link($order->get_id());

        if (!$link) {
            return;
        }

        $link_text = __('guest payment', 'wicket-wgc');
        $link_html = '<a href="' . esc_url($link) . '" style="color:#0073aa;text-decoration:underline;">' . esc_html($link_text) . '</a>';
        $message = sprintf(
            /* translators: %s: guest payment link */
            __('Will someone else be paying this invoice? Use our %s link to complete this transaction.', 'wicket-wgc'),
            $link_html
        );
        if ($plain_text) {
            $message = sprintf(
                __('Will someone else be paying this invoice? Use our %s link to complete this transaction: %s', 'wicket-wgc'),
                $link_text,
                $link
            );
            echo "\n\n" . $message . "\n\n";
        } else {
            echo '<div style="margin:16px 0 16px 0;font-size:1em;">' . $message . '</div>';
        }
    }

    /**
     * Append guest payment link/message to PDF invoices for pending orders.
     *
     * @param WC_Order $order
     * @param WPO_WCPDF_Invoice $document
     */
    public function append_guest_payment_link_to_pdf($document, $order)
    {
        // Check if PDF integration is enabled (default: true)
        if (!apply_filters('wicket/wooguestpay/pdf_integration_enabled', true)) {
            return;
        }

        // PDF plugin passes ($document, $order) or ($order, $document) depending on version, so normalize:
        if ($order instanceof WC_Order) {
            $order_obj = $order;
        } elseif ($document instanceof WC_Order) {
            $order_obj = $document;
        } elseif (is_object($document) && method_exists($document, 'get_order')) {
            $order_obj = $document->get_order();
        } else {
            return;
        }
        if (!($order_obj instanceof WC_Order)) {
            return;
        }
        if ('pending' !== $order_obj->get_status()) {
            return;
        }
        $link = $this->get_or_generate_guest_payment_link($order_obj->get_id());
        if (!$link) {
            return;
        }
        $link_text = __('guest payment', 'wicket-wgc');
        $link_html = '<a href="' . esc_url($link) . '" style="color:#0073aa;text-decoration:underline;">' . esc_html($link_text) . '</a>';
        $message = sprintf(
            /* translators: %s: guest payment link */
            __('Will someone else be paying this invoice? Use our %s link to complete this transaction.', 'wicket-wgc'),
            $link_html
        );
        echo '<div style="margin-top:10px;font-size:0.9em;font-style:italic;border-top:1px solid #eee;padding-top:10px;">' . $message . '</div>';
    }
}
