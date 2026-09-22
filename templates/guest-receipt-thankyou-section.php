<?php
/**
 * Guest Receipt Thank You Section.
 *
 * Section displayed on the WooCommerce order received (thank you) page for
 * guest payers. Links to the token-gated receipt page, where the receipt
 * can be printed or saved as PDF.
 */

// No direct access
defined('ABSPATH') || exit;

if (!isset($order) || !$order instanceof WC_Order) {
    return;
}

$receipt_url ??= '';

if (empty($receipt_url)) {
    return;
}
?>

<section class="wicket-guest-receipt-section" style="margin: 40px 0; padding: 30px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #0073aa;">
	<h2 style="color: #333; margin-top: 0; margin-bottom: 20px;">
		<?php echo esc_html__('Payment Receipt', 'wicket-wgc'); ?>
	</h2>

	<p style="color: #666; margin-bottom: 25px; font-size: 16px;">
		<?php echo esc_html__('Thank you for your payment! Print or save your receipt using the button below.', 'wicket-wgc'); ?>
	</p>

	<div class="wicket-guest-receipt-actions" style="display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
		<a href="<?php echo esc_url($receipt_url); ?>"
		   class="button button-primary"
		   target="_blank"
		   style="display: inline-block; padding: 12px 24px; background: #0073aa; color: white; text-decoration: none; border-radius: 4px; font-weight: 600; text-align: center;">
			<?php echo esc_html__('Print Receipt', 'wicket-wgc'); ?>
		</a>
	</div>

	<div class="wicket-guest-receipt-notice" style="margin-top: 20px; padding: 15px; background: #e7f3ff; border-left: 4px solid #0073aa; border-radius: 0 4px 4px 0;">
		<p style="margin: 0; color: #555; font-size: 14px;">
			<strong><?php echo esc_html__('Important:', 'wicket-wgc'); ?></strong>
			<?php echo esc_html__('This receipt link will remain accessible for 30 days. Save or print your receipt for future reference.', 'wicket-wgc'); ?>
		</p>
	</div>
</section>

<style>
@media (max-width: 768px) {
	.wicket-guest-receipt-section .wicket-guest-receipt-actions {
		flex-direction: column;
	}

	.wicket-guest-receipt-section .wicket-guest-receipt-actions a {
		width: 100%;
		text-align: center;
	}
}
</style>
