<?php

declare(strict_types=1);

/**
 * Admin pay flow for order payments with impersonation and auto-return.
 */

// No direct access
defined('ABSPATH') || exit;

class WicketGuestPaymentAdminPay extends WicketGuestPaymentComponent
{
    private const ADMIN_PAY_TRANSIENT_PREFIX = 'wgp_admin_pay_';
    private const ADMIN_PAY_COOKIE_TOKEN = 'wgp_admin_pay';
    private const ADMIN_PAY_COOKIE_SECRET = 'wgp_admin_pay_secret';
    private const ADMIN_PAY_TTL = 900;

    /**
     * Initialize hooks.
     *
     * @return void
     */
    public function init_hooks(): void
    {
        add_action('admin_post_wicket_admin_pay', [$this, 'handle_admin_pay_request']);
        add_action('admin_post_wicket_admin_pay_return', [$this, 'handle_admin_pay_return']);
        add_action('template_redirect', [$this, 'maybe_start_admin_pay_session'], 5);
        add_action('template_redirect', [$this, 'maybe_auto_return_admin'], 6);
        add_action('wp_footer', [$this, 'render_admin_pay_return_button']);
    }

    /**
     * Handle admin "Pay For Customer" action.
     *
     * @return void
     */
    public function handle_admin_pay_request(): void
    {
        $order_id = absint($_GET['order_id'] ?? 0);
        if (!$order_id) {
            wp_die(esc_html__('Invalid order ID.', 'wicket-wgc'));
        }

        $admin_id = get_current_user_id();
        if (!$this->is_admin_user($admin_id)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'wicket-wgc'));
        }

        check_admin_referer('wicket_admin_pay_' . $order_id);

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(esc_html__('Order not found.', 'wicket-wgc'));
        }

        if ('auto-draft' === $order->get_status()) {
            wp_die(esc_html__('Please create and save the order before paying on behalf of the customer.', 'wicket-wgc'));
        }

        if ($order->has_status(['completed', 'processing', 'refunded'])) {
            wp_die(esc_html__('This order has already been paid.', 'wicket-wgc'));
        }

        $customer_id = (int) $order->get_customer_id();
        if (!$customer_id) {
            wp_die(esc_html__('This order must be assigned to a customer before paying on their behalf.', 'wicket-wgc'));
        }

        $token = $this->generate_token();
        $return_secret = $this->generate_token();
        if (!$token || !$return_secret) {
            wp_die(esc_html__('Failed to prepare the admin pay session.', 'wicket-wgc'));
        }

        $admin_user = get_userdata($admin_id);
        $admin_label = $admin_user ? $admin_user->display_name : (string) $admin_id;

        $data = [
            'admin_id' => $admin_id,
            'customer_id' => $customer_id,
            'order_id' => $order_id,
            'return_url' => $this->get_order_edit_url($order_id),
            'return_secret' => $return_secret,
            'created' => time(),
        ];

        set_transient(self::ADMIN_PAY_TRANSIENT_PREFIX . $token, $data, self::ADMIN_PAY_TTL);

        $order->add_order_note(
            sprintf(
                __('Admin pay session started by %s.', 'wicket-wgc'),
                $admin_label
            )
        );

        $pay_url = add_query_arg('wgp_admin_pay', $token, $order->get_checkout_payment_url());
        wp_safe_redirect($pay_url);
        $this->maybe_exit();
    }

    /**
     * Start admin pay impersonation session.
     *
     * @return void
     */
    public function maybe_start_admin_pay_session(): void
    {
        $token = $this->get_admin_pay_token_from_request();
        if (!$token) {
            return;
        }

        $data = get_transient(self::ADMIN_PAY_TRANSIENT_PREFIX . $token);
        if (!is_array($data)) {
            wp_die(esc_html__('Admin pay link expired or invalid.', 'wicket-wgc'));
        }

        $admin_id = isset($data['admin_id']) ? (int) $data['admin_id'] : 0;
        $customer_id = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        $return_secret = isset($data['return_secret']) ? (string) $data['return_secret'] : '';

        if (!$admin_id || !$customer_id || !$return_secret) {
            wp_die(esc_html__('Admin pay data incomplete.', 'wicket-wgc'));
        }

        if (!$this->is_admin_user($admin_id) || get_current_user_id() !== $admin_id) {
            wp_die(esc_html__('You do not have permission to use this link.', 'wicket-wgc'));
        }

        $expires = time() + self::ADMIN_PAY_TTL;
        $this->set_admin_pay_cookie(self::ADMIN_PAY_COOKIE_TOKEN, $token, $expires);
        $this->set_admin_pay_cookie(self::ADMIN_PAY_COOKIE_SECRET, $return_secret, $expires);

        wp_clear_auth_cookie();
        wp_set_current_user($customer_id);
        wp_set_auth_cookie($customer_id, false, is_ssl());

        $redirect_url = remove_query_arg('wgp_admin_pay');
        wp_safe_redirect($redirect_url);
        $this->maybe_exit();
    }

    /**
     * Auto-return admin after payment on the thank you page.
     *
     * @return void
     */
    public function maybe_auto_return_admin(): void
    {
        if (!function_exists('is_order_received_page') || !is_order_received_page()) {
            return;
        }

        $data = $this->get_active_admin_pay_session();
        if (!$data) {
            return;
        }

        $token = $data['_token'] ?? '';
        if (!$token) {
            $this->clear_admin_pay_cookies();
            return;
        }

        $order_id = $this->get_order_id_from_request();
        $stored_order_id = isset($data['order_id']) ? (int) $data['order_id'] : 0;
        if (!$order_id || $order_id !== $stored_order_id) {
            return;
        }

        $customer_id = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        if (!$customer_id || get_current_user_id() !== $customer_id) {
            return;
        }

        $admin_id = isset($data['admin_id']) ? (int) $data['admin_id'] : 0;
        if (!$admin_id || !$this->is_admin_user($admin_id)) {
            $this->clear_admin_pay_cookies();
            return;
        }

        $admin_user = get_userdata($admin_id);
        $admin_label = $admin_user ? $admin_user->display_name : (string) $admin_id;

        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note(
                sprintf(
                    __('Admin pay session completed by %s.', 'wicket-wgc'),
                    $admin_label
                )
            );
        }

        wp_clear_auth_cookie();
        wp_set_current_user($admin_id);
        wp_set_auth_cookie($admin_id, true, is_ssl());

        delete_transient(self::ADMIN_PAY_TRANSIENT_PREFIX . $token);
        $this->clear_admin_pay_cookies();

        $return_url = isset($data['return_url']) ? esc_url_raw($data['return_url']) : '';
        if (!$return_url) {
            $return_url = $this->get_order_edit_url($order_id);
        }

        wp_safe_redirect($return_url);
        $this->maybe_exit();
    }

    /**
     * Render floating return button during admin pay flow.
     *
     * @return void
     */
    public function render_admin_pay_return_button(): void
    {
        if (is_admin()) {
            return;
        }

        $data = $this->get_active_admin_pay_session();
        if (!$data) {
            return;
        }

        $action_url = esc_url(admin_url('admin-post.php'));
        $label = esc_html__('Cancel and Return to Admin', 'wicket-wgc');
        $description = esc_html__('Abandon payment and return to your admin session.', 'wicket-wgc');

        echo '<div class="wgp-admin-pay-return">';
        echo '<form method="post" action="' . $action_url . '">';
        echo '<input type="hidden" name="action" value="wicket_admin_pay_return">';
        wp_nonce_field('wicket_admin_pay_return');
        echo '<button type="submit" aria-label="' . $description . '">' . $label . '</button>';
        echo '</form>';
        echo '</div>';
        echo '<style>
            .wgp-admin-pay-return { position: fixed; left: 50%; transform: translateX(-50%); bottom: 24px; z-index: 99999; }
            .wgp-admin-pay-return button { background: var(--wp--preset--color--primary, #2271b1); color: var(--wp--preset--color--base, #ffffff); border: 0; padding: 12px 20px; border-radius: 999px; font-weight: 600; box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18); cursor: pointer; }
            .wgp-admin-pay-return button:hover { filter: brightness(0.95); }
            .wgp-admin-pay-return button:focus { outline: 2px solid var(--wp--preset--color--secondary, #111111); outline-offset: 2px; }
        </style>';
    }

    /**
     * Handle manual return to admin.
     *
     * @return void
     */
    public function handle_admin_pay_return(): void
    {
        check_admin_referer('wicket_admin_pay_return');

        $data = $this->get_active_admin_pay_session();
        if (!$data) {
            wp_die(esc_html__('Admin pay session expired or invalid.', 'wicket-wgc'));
        }

        $token = $data['_token'] ?? '';
        $admin_id = isset($data['admin_id']) ? (int) $data['admin_id'] : 0;
        $customer_id = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        $order_id = isset($data['order_id']) ? (int) $data['order_id'] : 0;
        $return_url = isset($data['return_url']) ? esc_url_raw($data['return_url']) : '';

        if (!$token || !$admin_id || !$customer_id || !$order_id || !$return_url) {
            wp_die(esc_html__('Admin pay session data incomplete.', 'wicket-wgc'));
        }

        if (get_current_user_id() !== $customer_id) {
            wp_die(esc_html__('You do not have permission to return.', 'wicket-wgc'));
        }

        if (!$this->is_admin_user($admin_id)) {
            wp_die(esc_html__('Admin user not found.', 'wicket-wgc'));
        }

        $admin_user = get_userdata($admin_id);
        $admin_label = $admin_user ? $admin_user->display_name : (string) $admin_id;

        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note(
                sprintf(
                    __('Admin pay session abandoned by %s.', 'wicket-wgc'),
                    $admin_label
                )
            );
        }

        wp_clear_auth_cookie();
        wp_set_current_user($admin_id);
        wp_set_auth_cookie($admin_id, true, is_ssl());

        delete_transient(self::ADMIN_PAY_TRANSIENT_PREFIX . $token);
        $this->clear_admin_pay_cookies();

        wp_safe_redirect($return_url);
        $this->maybe_exit();
    }

    private function get_admin_pay_token_from_request(): string
    {
        if (!isset($_GET['wgp_admin_pay'])) {
            return '';
        }

        return sanitize_key(wp_unslash($_GET['wgp_admin_pay']));
    }

    private function set_admin_pay_cookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, $expires, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }

    private function get_admin_pay_cookie(string $name): string
    {
        return isset($_COOKIE[$name]) ? sanitize_key(wp_unslash($_COOKIE[$name])) : '';
    }

    private function clear_admin_pay_cookies(): void
    {
        $expire = time() - HOUR_IN_SECONDS;
        setcookie(self::ADMIN_PAY_COOKIE_TOKEN, '', $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        setcookie(self::ADMIN_PAY_COOKIE_SECRET, '', $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }

    private function get_order_edit_url(int $order_id): string
    {
        return function_exists('wc_get_order_edit_url')
            ? wc_get_order_edit_url($order_id)
            : admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    private function get_order_id_from_request(): int
    {
        if (isset($_GET['order_id'])) {
            return absint($_GET['order_id']);
        }

        if (isset($_GET['order-received'])) {
            return absint($_GET['order-received']);
        }

        $order_id = function_exists('get_query_var') ? absint(get_query_var('order-received')) : 0;
        if ($order_id) {
            return $order_id;
        }

        if (isset($_GET['key'])) {
            $order_key = sanitize_text_field(wp_unslash($_GET['key']));
            if (function_exists('wc_get_order_id_by_order_key')) {
                return absint(wc_get_order_id_by_order_key($order_key));
            }
        }

        return 0;
    }

    private function is_admin_user(int $user_id): bool
    {
        if (!$user_id) {
            return false;
        }

        $user = get_userdata($user_id);
        if (!$user || empty($user->roles) || !is_array($user->roles)) {
            return false;
        }

        return in_array('administrator', $user->roles, true);
    }

    private function generate_token(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $exception) {
            $this->log(sprintf('Admin pay token generation failed: %s', $exception->getMessage()), 'error');
            return '';
        }
    }

    /**
     * Get active admin pay session data from cookies/transient.
     *
     * @return array|null
     */
    private function get_active_admin_pay_session(): ?array
    {
        $token = $this->get_admin_pay_cookie(self::ADMIN_PAY_COOKIE_TOKEN);
        $secret = $this->get_admin_pay_cookie(self::ADMIN_PAY_COOKIE_SECRET);
        if (!$token || !$secret) {
            return null;
        }

        $data = get_transient(self::ADMIN_PAY_TRANSIENT_PREFIX . $token);
        if (!is_array($data)) {
            return null;
        }

        $return_secret = isset($data['return_secret']) ? (string) $data['return_secret'] : '';
        if (!$return_secret || !hash_equals($return_secret, $secret)) {
            return null;
        }

        $data['_token'] = $token;

        return $data;
    }
}
