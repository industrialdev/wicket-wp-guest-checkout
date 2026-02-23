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
     * Option keys for customizable email templates.
     */
    private const OPTION_EMAIL_SUBJECT_TEMPLATE = 'wicket_guest_payment_email_subject_template'; // legacy fallback
    private const OPTION_EMAIL_BODY_TEMPLATE = 'wicket_guest_payment_email_body_template'; // legacy fallback
    private const OPTION_WICKET_EMAIL_SUBJECT_TEMPLATE = 'wicket_admin_settings_guest_payment_email_subject_template';
    private const OPTION_WICKET_EMAIL_BODY_TEMPLATE = 'wicket_admin_settings_guest_payment_email_body_template';

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
        // Add filter to email headers
        add_filter('woocommerce_email_headers', [$this, 'add_cc_to_emails'], 10, 3);
    }

    /**
     * Adds CC to the customer completed/processing order emails if guest email is present.
     *
     * @param string $header Email headers.
     * @param string $email_id Email ID.
     * @param WC_Order $order Order object.
     * @return string Modified headers.
     */
    public function add_cc_to_emails($header, $email_id, $order)
    {
        // Only target specific emails
        if (!in_array($email_id, ['customer_completed_order', 'customer_processing_order'])) {
            return $header;
        }

        if (!$order) {
            return $header;
        }

        $guest_email = $order->get_meta('_wgp_guest_payment_email', true);

        // If we have a guest email, add it as CC
        if ($guest_email && is_email($guest_email)) {
            $header .= 'Cc: ' . $guest_email . "\r\n";
            $this->log(sprintf('Added CC: %s to email %s for Order ID %d', $guest_email, $email_id, $order->get_id()));
        }

        return $header;
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

        // Get the expiry timestamp
        $expiry_timestamp = $this->core->get_token_expiry_timestamp();
        $expiry_date = wp_date(get_option('date_format'), $expiry_timestamp);
        $placeholders = $this->build_email_placeholders($user_data, $order, $payment_link, $expiry_date, $order_id);

        $subject_template = $this->get_email_subject_template();
        $subject = trim(strip_tags(strtr($subject_template, $placeholders)));
        $subject = (string) apply_filters(
            'wicket_guest_payment_email_subject',
            $subject,
            $order,
            $token,
            $placeholders,
            $recipient_email,
            $user_data
        );

        $body_template = $this->get_email_body_template();
        $message = $this->render_message_from_template($body_template, $placeholders);
        $message = (string) apply_filters(
            'wicket_guest_payment_email_content',
            $message,
            $order,
            $token,
            $placeholders,
            $recipient_email,
            $user_data
        );

        // Full HTML is supported by default for implementers.
        // Optional sanitization can be enabled via filter when stricter policy is required.
        $sanitize_html = (bool) apply_filters(
            'wicket_guest_payment_email_sanitize_html',
            false,
            $order,
            $token,
            $placeholders,
            $recipient_email,
            $user_data
        );
        if ($sanitize_html) {
            $allowed_html = (array) apply_filters(
                'wicket_guest_payment_email_allowed_html',
                wp_kses_allowed_html('post'),
                $order,
                $token,
                $placeholders,
                $recipient_email,
                $user_data
            );
            $message = (string) wp_kses($message, $allowed_html);
        }
        $message = $this->get_styled_email_template($message);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];
        $headers = (array) apply_filters(
            'wicket_guest_payment_email_headers',
            $headers,
            $order,
            $token,
            $placeholders,
            $recipient_email,
            $user_data
        );

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
     * Build supported template placeholders and their values.
     *
     * @param object $user_data User data object.
     * @param object $order Order object.
     * @param string $payment_link Payment URL.
     * @param string $expiry_date Formatted token expiry date.
     * @param int    $order_id Order ID.
     * @return array
     */
    private function build_email_placeholders(
        object $user_data,
        object $order,
        string $payment_link,
        string $expiry_date,
        int $order_id
    ): array {
        $member_name = trim((string) ($user_data->first_name ?? '') . ' ' . (string) ($user_data->last_name ?? ''));
        if ('' === $member_name && !empty($user_data->display_name)) {
            $member_name = (string) $user_data->display_name;
        }
        if ('' === $member_name) {
            $member_name = __('the member', 'wicket-wgc');
        }

        $subscription_details = $this->get_subscription_details_html($order, $order_id);

        return [
            '{site_name}' => esc_html(get_bloginfo('name')),
            '{member_name}' => esc_html($member_name),
            '{order_number}' => esc_html((string) $order->get_order_number()),
            '{order_total}' => esc_html(strip_tags((string) $order->get_formatted_order_total())),
            '{payment_url}' => esc_url($payment_link),
            '{payment_link}' => sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url($payment_link),
                esc_html__('Complete Payment', 'wicket-wgc')
            ),
            '{expiry_date}' => esc_html($expiry_date),
            '{subscription_details}' => $subscription_details,
        ];
    }

    /**
     * Render HTML email body by replacing placeholders in the template.
     *
     * @param string $template Template content.
     * @param array  $placeholders Placeholder replacement map.
     * @return string
     */
    private function render_message_from_template(string $template, array $placeholders): string
    {
        return strtr($template, $placeholders);
    }

    /**
     * Return configured email subject template or fallback default.
     *
     * @return string
     */
    private function get_email_subject_template(): string
    {
        $subject_template = (string) $this->get_wicket_option(self::OPTION_WICKET_EMAIL_SUBJECT_TEMPLATE, '');

        // Backward compatibility with legacy standalone option.
        if ('' === trim($subject_template)) {
            $subject_template = (string) get_option(self::OPTION_EMAIL_SUBJECT_TEMPLATE, '');
        }

        if ('' === trim($subject_template)) {
            $subject_template = __('Payment Request for {site_name} Subscription', 'wicket-wgc');
        }

        return $subject_template;
    }

    /**
     * Return configured email body template or fallback default.
     *
     * @return string
     */
    private function get_email_body_template(): string
    {
        $body_template = (string) $this->get_wicket_option(self::OPTION_WICKET_EMAIL_BODY_TEMPLATE, '');

        // Backward compatibility with legacy standalone option.
        if ('' === trim($body_template)) {
            $body_template = (string) get_option(self::OPTION_EMAIL_BODY_TEMPLATE, '');
        }

        if ('' === trim($body_template)) {
            $body_template = __(
                "<p>Hello,</p>\n\n<p>\nYou have received a request to complete payment for a subscription on behalf of {member_name}.<br>\nOrder Number: {order_number}<br>\nOrder Total: {order_total}\n</p>\n\n{subscription_details}\n\n<p>\nPlease use the secure link below to complete the payment:<br>\n{payment_link}\n</p>\n\n<p>This payment link is valid until {expiry_date} and can only be used once.</p>\n\n<p>If you have any questions about this payment request, please contact us.</p>\n\n<p>Thank you,<br>\n{site_name}</p>",
                'wicket-wgc'
            );
        }

        return $body_template;
    }

    /**
     * Read a value from Wicket Settings (`wicket_settings` option array).
     *
     * @param string $key Option key.
     * @param mixed  $fallback Fallback value.
     * @return mixed
     */
    private function get_wicket_option(string $key, $fallback = null)
    {
        if (function_exists('wicket_get_option')) {
            return wicket_get_option($key, $fallback);
        }

        $options = get_option('wicket_settings', []);
        if (!is_array($options)) {
            return $fallback;
        }

        return $options[$key] ?? $fallback;
    }

    /**
     * Build subscription line-items HTML for template placeholder output.
     *
     * @param object $order Order object.
     * @param int    $order_id Order ID.
     * @return string
     */
    private function get_subscription_details_html(object $order, int $order_id): string
    {
        if (!function_exists('wcs_order_contains_subscription') || !wcs_order_contains_subscription($order_id)) {
            return '';
        }

        $lines = [];
        foreach ($order->get_items() as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $lines[] = sprintf('<li>%1$s x %2$d - %3$s</li>',
                    esc_html($item->get_name()),
                    (int) $item->get_quantity(),
                    esc_html(strip_tags((string) wc_price($item->get_total())))
                );
            }
        }

        if (empty($lines)) {
            return '';
        }

        return '<p>' . esc_html__('Subscription Details:', 'wicket-wgc') . '</p><ul>' . implode('', $lines) . '</ul>';
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
