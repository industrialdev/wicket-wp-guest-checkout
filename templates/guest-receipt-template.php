<?php
/**
 * Guest Receipt Template.
 *
 * Token-gated receipt page for guest payers. Printable via the
 * Print Receipt button or the browser print dialog (Save as PDF).
 */

// No direct access
defined('ABSPATH') || exit;

get_header();
?>

<div class="woocommerce">
	<div class="woocommerce-order">
		<div class="woocommerce-order__header">
			<h2 class="woocommerce-order__title">
				<?php echo esc_html__('Payment Receipt', 'wicket-wgc'); ?>
			</h2>
			<p class="woocommerce-order__date">
				<?php
                printf(
                    /* translators: 1: order number 2: order date */
                    esc_html__('Order #%1$s was placed on %2$s.', 'wicket-wgc'),
                    '<mark class="order-number">' . esc_html($order_number) . '</mark>',
                    '<mark class="order-date">' . esc_html($order_date ? $order_date->format('F j, Y') : '') . '</mark>'
                );
?>
			</p>
		</div>

		<div class="woocommerce-order__details">
			<h3><?php echo esc_html__('Order Details', 'wicket-wgc'); ?></h3>
			<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
				<thead>
					<tr>
						<th class="woocommerce-table__product-name product-name"><?php echo esc_html__('Product', 'wicket-wgc'); ?></th>
						<th class="woocommerce-table__product-table product-total"><?php echo esc_html__('Total', 'wicket-wgc'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        $product_name = $item->get_name();
        $quantity = $item->get_quantity();

        if (!$product) {
            continue;
        }
        ?>
						<tr class="<?php echo esc_attr(apply_filters('woocommerce_order_item_class', 'woocommerce-table__line-item order_item', $item, $order)); ?>">
							<td class="woocommerce-table__product-name product-name">
								<?php echo esc_html($product_name); ?>
								<strong class="product-quantity">&times; <?php echo esc_html($quantity); ?></strong>
							</td>
							<td class="woocommerce-table__product-total product-total">
								<?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?>
							</td>
						</tr>
						<?php
    }
?>
				</tbody>
				<tfoot>
					<?php
foreach ($order->get_order_item_totals() as $key => $total) {
    ?>
						<tr>
							<th scope="row"><?php echo esc_html($total['label']); ?></th>
							<td><?php echo wp_kses_post($total['value']); ?></td>
						</tr>
						<?php
}
?>
				</tfoot>
			</table>

			<div class="woocommerce-order__payment-method">
				<h3><?php echo esc_html__('Payment Information', 'wicket-wgc'); ?></h3>
				<p>
					<strong><?php echo esc_html__('Payment Method:', 'wicket-wgc'); ?></strong>
					<?php echo esc_html($order->get_payment_method_title()); ?>
				</p>
				<p>
					<strong><?php echo esc_html__('Billing Email:', 'wicket-wgc'); ?></strong>
					<?php echo esc_html($billing_email); ?>
				</p>
				<?php if (!empty($guest_email) && $guest_email !== $billing_email) : ?>
					<p>
						<strong><?php echo esc_html__('Guest Payer Email:', 'wicket-wgc'); ?></strong>
						<?php echo esc_html($guest_email); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="woocommerce-order__actions wicket-guest-receipt-actions">
			<button type="button" class="button button-primary" onclick="window.print();">
				<?php echo esc_html__('Print Receipt', 'wicket-wgc'); ?>
			</button>
		</div>

		<div class="woocommerce-order__notice">
			<p class="woocommerce-notice woocommerce-notice--info">
				<?php echo esc_html__('This receipt link will remain accessible for 30 days from the payment date.', 'wicket-wgc'); ?>
			</p>
		</div>
	</div>
</div>

<style>
@media print {
	.wicket-guest-receipt-actions,
	header,
	footer,
	.wp-block-template-part {
		display: none !important;
	}
}
</style>

<?php get_footer(); ?>
