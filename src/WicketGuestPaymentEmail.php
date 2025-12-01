<?php

declare(strict_types=1);

/**
 * Guest Subscription Payment Flow for WooCommerce - Email Notifications.
 *
 * Handles sending email notifications for guest payments.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Email handling for Guest Subscription Payment Flow.
 */
class WicketGuestPaymentEmail extends WicketGuestPaymentComponent
{
    /**
     * Core functionality class.
     *
     * @var WicketGuestPaymentCore
     */
    private $core;

    /**
     * Constructor.
     *
     * @param WicketGuestPaymentCore $core Core functionality class.
     */
    public function __construct(WicketGuestPaymentCore $core)
    {
        $this->core = $core;
    }

    /**
     * Initializes the class.
     *
     * @return void
     */
    public function init(): void
    {
        // Nothing needed at initialization
    }

    /**
     * Sends the guest payment email.
     *
     * @param string $recipient_email The email address to send the notification to.
     * @param string $token           The payment token.
     * @param int    $order_id        The ID of the related order.
     * @param int    $user_id         The ID of the user the subscription is for.
     * @return bool True if the email was sent successfully, false otherwise.
     */
    public function send_payment_email(string $recipient_email, string $token, int $order_id, int $user_id): bool
    {
        if (!is_email($recipient_email) || empty($token) || !$order_id || !$user_id) {
            return false;
        }

        $user_data = get_userdata($user_id);
        $order = wc_get_order($order_id);

        if (!$user_data || !$order) {
            return false;
        }

        $payment_link = add_query_arg('guest_payment_token', $token, wc_get_cart_url());
        $subject = sprintf(__('Payment Request for %s Subscription', 'wicket-wgc'), get_bloginfo('name'));

        // Get the expiry timestamp
        $expiry_timestamp = $this->core->get_token_expiry_timestamp();
        $expiry_date = wp_date(get_option('date_format'), $expiry_timestamp);

        // Basic email body - consider creating a WC Email template for better customization
        $message = sprintf(
            __('<p>Hello,</p><p>You have received a request to complete payment for a subscription on behalf of %1$s.</p>', 'wicket-wgc'),
            esc_html($user_data->first_name . ' ' . $user_data->last_name)
        );

        // Add order details
        $message .= '<p>' . __('Order Number:', 'wicket-wgc') . ' ' . $order->get_order_number() . '</p>';
        $message .= '<p>' . __('Order Total:', 'wicket-wgc') . ' ' . $order->get_formatted_order_total() . '</p>';

        // Add order items if this is a subscription
        if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id)) {
            $message .= '<p>' . __('Subscription Details:', 'wicket-wgc') . '</p>';
            $message .= '<ul>';

            foreach ($order->get_items() as $item_id => $item) {
                // Ensure we are dealing with a product item before accessing product methods
                if ($item instanceof WC_Order_Item_Product) {
                    $message .= '<li>' . $item->get_name() . ' × ' . $item->get_quantity() . ' - '
                        . wc_price($item->get_total()) . '</li>';
                }
            }

            $message .= '</ul>';
        }

        $message .= sprintf(
            __('<p>Please use the secure link below to complete the payment:</p><p><a href="%1$s">Complete Payment</a></p>', 'wicket-wgc'),
            esc_url($payment_link)
        );

        $message .= sprintf(
            __('<p>This payment link is valid until %1$s and can only be used once.</p>', 'wicket-wgc'),
            $expiry_date
        );

        $message .= sprintf(
            __('<p>If you have any questions about this payment request, please contact us.</p><p>Thank you,<br>%s</p>', 'wicket-wgc'),
            get_bloginfo('name')
        );

        // Add branding and styling
        $message = $this->get_styled_email_template($message);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        $sent = wp_mail($recipient_email, $subject, $message, $headers);

        if ($sent) {
            $order->add_order_note(sprintf(__('Guest payment email sent to %s.', 'wicket-wgc'), $recipient_email));
            $this->log(
                sprintf('Guest payment email sent to %s for Order ID: %d.', $recipient_email, $order_id)
            );
        } else {
            $order->add_order_note(sprintf(__('Failed to send guest payment email to %s.', 'wicket-wgc'), $recipient_email), true); // Log as error
            $this->log(
                sprintf('Failed to send guest payment email to %s for Order ID: %d.', $recipient_email, $order_id),
                'error'
            );
        }

        return $sent;
    }

    /**
     * Wraps content in a styled HTML email template.
     *
     * @param string $content The email content.
     * @return string The styled email.
     */
    private function get_styled_email_template(string $content): string
    {
        // Get site logo if available
        $logo = '';
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_url = wp_get_attachment_image_url($custom_logo_id, 'medium');
            if ($logo_url) {
                $logo = '<div style="text-align: center; margin-bottom: 30px;"><img src="' . esc_url($logo_url) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="max-width: 200px; height: auto;"></div>';
            }
        }

        // If no logo, use site name
        if (empty($logo)) {
            $logo = '<div style="text-align: center; margin-bottom: 30px;"><h1 style="color: #3c3c3c;">' . esc_html(get_bloginfo('name')) . '</h1></div>';
        }

        $template = '
		<!DOCTYPE html>
		<html>
		<head>
			<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
			<title>' . esc_html(get_bloginfo('name')) . '</title>
		</head>
		<body style="background-color: #f5f5f5; font-family: Arial, sans-serif; margin: 0; padding: 0;">
			<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
				<div style="background-color: #ffffff; padding: 40px; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
					' . $logo . '
					<div style="color: #555555; line-height: 1.6; font-size: 16px;">
						' . $content . '
					</div>
				</div>
				<div style="text-align: center; padding: 20px; color: #999999; font-size: 12px;">
					<p>&copy; ' . date('Y') . ' ' . esc_html(get_bloginfo('name')) . '. ' . __('All rights reserved.', 'wicket-wgc') . '</p>
				</div>
			</div>
		</body>
		</html>
		';

        return $template;
    }
}
