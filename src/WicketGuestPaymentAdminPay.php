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
        add_action('admin_post_nopriv_wicket_admin_pay_return', [$this, 'handle_admin_pay_return']);
        add_action('template_redirect', [$this, 'maybe_start_admin_pay_session'], 5);
        add_action('template_redirect', [$this, 'maybe_auto_return_admin'], 6);
        add_action('wp_footer', [$this, 'render_admin_pay_return_button']);
        add_action('woocommerce_pay_order_before_payment', [$this, 'render_order_pay_shipping_info']);
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

        $this->maybe_add_shipping_to_order($order);

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

        $session_key = 'wgp_admin_pay_session_' . $customer_id;
        set_transient($session_key, [
            'token' => $token,
            'secret' => $return_secret,
        ], self::ADMIN_PAY_TTL);

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

        $token_present = '' !== $this->get_admin_pay_cookie(self::ADMIN_PAY_COOKIE_TOKEN);
        $secret_present = '' !== $this->get_admin_pay_cookie(self::ADMIN_PAY_COOKIE_SECRET);
        $data = $this->get_active_admin_pay_session();

        if (!$data) {
            return;
        }

        $admin_id = isset($data['admin_id']) ? (int) $data['admin_id'] : 0;
        $customer_id = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        $order_id = isset($data['order_id']) ? (int) $data['order_id'] : 0;

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
            .wgp-admin-pay-return { position: fixed; left: 0; right: 0; bottom: 0; z-index: 99999; background: var(--wp--preset--color--primary, #2271b1); box-shadow: 0 -8px 18px rgba(0, 0, 0, 0.25); padding: 25px 16px calc(25px + env(safe-area-inset-bottom)); }
            .wgp-admin-pay-return form { max-width: 1200px; margin: 0 auto; }
            .wgp-admin-pay-return button { width: 100%; background: #ffffff; color: #1d2327; border: 0; padding: 14px 20px; border-radius: 8px; font-weight: 700; font-size: 16px; box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18); cursor: pointer; }
            .wgp-admin-pay-return button:hover { filter: brightness(0.97); }
            .wgp-admin-pay-return button:focus { outline: 3px solid #111111; outline-offset: 2px; }
        </style>';
    }

    /**
     * Render shipping address and method info on order-pay screen.
     *
     * @return void
     */
    public function render_order_pay_shipping_info(): void
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
            return;
        }

        $order_id = absint(get_query_var('order-pay'));
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $shipping_address = $order->get_formatted_shipping_address();
        $shipping_items = $order->get_items('shipping');

        if (!$shipping_address && empty($shipping_items)) {
            return;
        }

        echo '<style>
            body.woocommerce-checkout .wgp-order-pay-shipping { margin: 1.5rem 0 2rem; padding: 1.5rem; border: 1px solid #e5e7eb; border-radius: 6px; background: #ffffff; }
            body.woocommerce-checkout .wgp-order-pay-shipping__title { margin: 0 0 1rem; font-size: 1.25rem; font-weight: 700; line-height: 1.4; }
            body.woocommerce-checkout .wgp-order-pay-shipping__section { margin-top: 0.75rem; }
            body.woocommerce-checkout .wgp-order-pay-shipping__label { display: block; margin: 0 0 0.35rem; font-size: 0.95rem; font-weight: 700; color: #1d2327; }
            body.woocommerce-checkout .wgp-order-pay-shipping__address { margin: 0; font-size: 1rem; line-height: 1.5; }
            body.woocommerce-checkout .wgp-order-pay-shipping__details { margin: 0; }
            body.woocommerce-checkout .wgp-order-pay-shipping__details div { display: flex; gap: 0.5rem; margin: 0.25rem 0; }
            body.woocommerce-checkout .wgp-order-pay-shipping__details dt { min-width: 180px; font-weight: 700; color: #1d2327; }
            body.woocommerce-checkout .wgp-order-pay-shipping__details dd { margin: 0; }
            body.woocommerce-checkout .wgp-order-pay-shipping__methods { margin: 0; padding-left: 0; list-style: none; font-size: 1rem; line-height: 1.5; }
            body.woocommerce-checkout .wgp-order-pay-shipping__methods li { margin: 0.25rem 0; }
        </style>';

        echo '<section class="wgp-order-pay-shipping">';
        echo '<h3 class="wgp-order-pay-shipping__title">' . esc_html__('Shipping Information', 'wicket-wgc') . '</h3>';

        if ($shipping_address) {
            $shipping_company = $order->get_shipping_company();
            $shipping_name = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
            $shipping_address_1 = $order->get_shipping_address_1();
            $shipping_address_2 = $order->get_shipping_address_2();
            $shipping_city = $order->get_shipping_city();
            $shipping_state = $order->get_shipping_state();
            $shipping_postcode = $order->get_shipping_postcode();
            $shipping_country = $order->get_shipping_country();
            $shipping_country_name = $shipping_country ? WC()->countries->countries[$shipping_country] ?? $shipping_country : '';

            echo '<div class="wgp-order-pay-shipping__section">';
            echo '<div class="wgp-order-pay-shipping__address">';
            echo '<dl class="wgp-order-pay-shipping__details">';
            if ($shipping_company) {
                echo '<div><dt>' . esc_html__('Company', 'wicket-wgc') . '</dt><dd>' . esc_html($shipping_company) . '</dd></div>';
            }
            if ($shipping_name) {
                echo '<div><dt>' . esc_html__('Name', 'wicket-wgc') . '</dt><dd>' . esc_html($shipping_name) . '</dd></div>';
            }
            if ($shipping_address_1 || $shipping_address_2) {
                $address_lines = trim($shipping_address_1 . ($shipping_address_2 ? ', ' . $shipping_address_2 : ''));
                echo '<div><dt>' . esc_html__('Address', 'wicket-wgc') . '</dt><dd>' . esc_html($address_lines) . '</dd></div>';
            }
            if ($shipping_city || $shipping_state || $shipping_postcode) {
                $city_line = trim($shipping_city . ($shipping_state ? ', ' . $shipping_state : '') . ($shipping_postcode ? ' ' . $shipping_postcode : ''));
                echo '<div><dt>' . esc_html__('City/State/ZIP', 'wicket-wgc') . '</dt><dd>' . esc_html($city_line) . '</dd></div>';
            }
            if ($shipping_country_name) {
                echo '<div><dt>' . esc_html__('Country', 'wicket-wgc') . '</dt><dd>' . esc_html($shipping_country_name) . '</dd></div>';
            }
            echo '</dl>';
            echo '</div>';
            echo '</div>';
        }

        if (!empty($shipping_items)) {
            echo '<div class="wgp-order-pay-shipping__section">';
            echo '<span class="wgp-order-pay-shipping__label">' . esc_html__('Shipping Method', 'wicket-wgc') . '</span>';
            echo '<ul class="wgp-order-pay-shipping__methods">';
            foreach ($shipping_items as $shipping_item) {
                $label = $shipping_item->get_method_title();
                $total = wc_price((float) $shipping_item->get_total(), ['currency' => $order->get_currency()]);
                echo '<li>' . esc_html($label) . ': ' . wp_kses_post($total) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        echo '</section>';
    }

    /**
     * Auto-add cheapest shipping rate before pay link is generated.
     *
     * @param WC_Order $order
     * @return void
     */
    private function maybe_add_shipping_to_order(WC_Order $order): void
    {
        if (!$order->needs_shipping()) {
            return;
        }

        if (!empty($order->get_items('shipping'))) {
            return;
        }

        $destination = [
            'country' => $order->get_shipping_country(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'city' => $order->get_shipping_city(),
            'address' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2(),
        ];

        if (!$destination['country'] || !$destination['postcode']) {
            return;
        }

        $contents = [];
        $contents_cost = 0.0;

        foreach ($order->get_items() as $item_id => $item) {
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }

            $product = $item->get_product();
            if (!$product || !$product->needs_shipping()) {
                continue;
            }

            $contents[$item_id] = [
                'data' => $product,
                'quantity' => $item->get_quantity(),
                'line_total' => $item->get_total(),
                'line_tax' => $item->get_total_tax(),
                'line_subtotal' => $item->get_subtotal(),
                'line_subtotal_tax' => $item->get_subtotal_tax(),
            ];

            $contents_cost += (float) $item->get_total();
        }

        if (empty($contents)) {
            return;
        }

        $package = [
            'contents' => $contents,
            'contents_cost' => $contents_cost,
            'applied_coupons' => $order->get_coupon_codes(),
            'user' => ['ID' => $order->get_user_id()],
            'destination' => $destination,
        ];

        $zone = WC_Shipping_Zones::get_zone_matching_package($package);
        $methods = $zone ? $zone->get_shipping_methods(true) : [];

        if (empty($methods)) {
            return;
        }

        $rates = [];
        foreach ($methods as $method) {
            if (!$method->is_enabled()) {
                continue;
            }

            $method_rates = $method->get_rates_for_package($package);
            if (!empty($method_rates)) {
                foreach ($method_rates as $rate) {
                    $rates[] = $rate;
                }
            }
        }

        if (empty($rates)) {
            return;
        }

        $chosen_rate = null;
        $lowest_total = null;
        foreach ($rates as $rate) {
            $cost = (float) $rate->get_cost();
            $taxes = $rate->get_taxes();
            $tax_total = is_array($taxes) ? array_sum($taxes) : 0.0;
            $total = $cost + $tax_total;

            if (null === $lowest_total || $total < $lowest_total) {
                $lowest_total = $total;
                $chosen_rate = $rate;
            }
        }

        if (!$chosen_rate) {
            return;
        }

        $shipping_item = new WC_Order_Item_Shipping();
        $shipping_item->set_method_title($chosen_rate->get_label());
        $shipping_item->set_method_id($chosen_rate->get_method_id());
        if (method_exists($shipping_item, 'set_instance_id')) {
            $shipping_item->set_instance_id($chosen_rate->get_instance_id());
        }
        $shipping_item->set_total((float) $chosen_rate->get_cost());
        $shipping_item->set_taxes(['total' => $chosen_rate->get_taxes()]);

        $order->add_item($shipping_item);
        $order->calculate_totals(true);
        $order->add_order_note(sprintf(
            __('Shipping calculated automatically (method: %s).', 'wicket-wgc'),
            $chosen_rate->get_label()
        ));
        $order->save();
    }

    /**
     * Handle manual return to admin.
     *
     * @return void
     */
    public function handle_admin_pay_return(): void
    {
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
        $nonce_valid = $nonce ? wp_verify_nonce($nonce, 'wicket_admin_pay_return') : false;

        $data = $this->get_active_admin_pay_session();
        if (!$nonce_valid && !$data) {
            wp_die(esc_html__('Security check failed or session expired.', 'wicket-wgc'));
        }

        if (!$data) {
            $data = $this->get_active_admin_pay_session();
        }

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

        $current_user_id = get_current_user_id();
        if ($current_user_id && ($current_user_id !== $customer_id && $current_user_id !== $admin_id)) {
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
        $cookie_domain = COOKIE_DOMAIN;
        if ('' === $cookie_domain) {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $cookie_domain = preg_replace('/:\\d+$/', '', $host);
        }

        $same_site = is_ssl() ? 'None' : 'Lax';
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => COOKIEPATH,
            'domain' => $cookie_domain,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => $same_site,
        ]);
    }

    private function get_admin_pay_cookie(string $name): string
    {
        $raw = $_COOKIE[$name] ?? '';
        $sanitized = '' !== $raw ? sanitize_key(wp_unslash($raw)) : '';

        return $sanitized;
    }

    private function clear_admin_pay_cookies(): void
    {
        $expire = time() - HOUR_IN_SECONDS;
        setcookie(self::ADMIN_PAY_COOKIE_TOKEN, '', $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        setcookie(self::ADMIN_PAY_COOKIE_SECRET, '', $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

        $customer_id = get_current_user_id();
        if ($customer_id) {
            $session_key = 'wgp_admin_pay_session_' . $customer_id;
            delete_transient($session_key);
        }
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

        return user_can($user_id, 'manage_woocommerce') || user_can($user_id, 'manage_options');
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
            $customer_id = get_current_user_id();
            if ($customer_id) {
                $session_key = 'wgp_admin_pay_session_' . $customer_id;
                $session_data = get_transient($session_key);
                if (is_array($session_data)) {
                    $token = (string) ($session_data['token'] ?? '');
                    $secret = (string) ($session_data['secret'] ?? '');
                }
            }
        }
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
