<?php

declare(strict_types=1);

/**
 * Guest Payment Invoice/Email Integration & Helper
 *
 * @package Wicket
 * @subpackage GuestPayment
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Guest Payment Invoice/Email Integration & Helper
 *
 * @package Wicket
 * @subpackage GuestPayment
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
		// PDF Invoices & Packing Slips (Professional) plugin
		add_action('wpo_wcpdf_after_order_details', [$this, 'append_guest_payment_link_to_pdf'], 99, 2);
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
		if (!$order) return false;
		// Use stored guest email if available, fallback to billing email
		$guest_email = $order->get_meta('_wgp_guest_email');
		if (!$guest_email) {
			$guest_email = $order->get_billing_email();
		}
		if (!is_email($guest_email)) return false;

		// Check for valid, non-expired token
		$token_data = $this->get_valid_token_data($order_id, $order);
		if ($token_data && !empty($token_data['token'])) {
			$token = $token_data['token'];
		} else {
			// Generate new token
			$token = $this->generate_token_for_order($order_id, $guest_email, 'auto');
			if (!$token) return false;
		}
		// Build guest payment URL
		return add_query_arg('guest_payment_token', $token, wc_get_cart_url());
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
		if (!($order instanceof WC_Order)) return;

		if ('pending' !== $order->get_status()) return;

		$link = $this->get_or_generate_guest_payment_link($order->get_id());

		if (!$link) return;

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
		if (!($order_obj instanceof WC_Order)) return;
		if ('pending' !== $order_obj->get_status()) return;
		$link = $this->get_or_generate_guest_payment_link($order_obj->get_id());
		if (!$link) return;
		$link_text = __('guest payment', 'wicket-wgc');
		$link_html = '<a href="' . esc_url($link) . '" style="color:#0073aa;text-decoration:underline;">' . esc_html($link_text) . '</a>';
		$message = sprintf(
			/* translators: %s: guest payment link */
			__('Will someone else be paying this invoice? Use our %s link to complete this transaction.', 'wicket-wgc'),
			$link_html
		);
		echo '<div style="margin-top:24px;font-size:1em;">' . $message . '</div>';
	}
}
