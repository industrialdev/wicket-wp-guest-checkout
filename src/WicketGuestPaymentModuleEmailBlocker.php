<?php

declare(strict_types=1);

// No direct access
defined('ABSPATH') || exit;

/**
 * Block WooCommerce customer emails during admin order updates unless explicitly sent.
 */
class WicketGuestPaymentModuleEmailBlocker extends WicketGuestPaymentComponent
{
    /**
     * Settings instance.
     *
     * @var WicketGuestPaymentModulesSettings
     */
    private WicketGuestPaymentModulesSettings $settings;

    /**
     * Allowed email ids for this request.
     *
     * @var array<string, bool>
     */
    private array $allowed_email_ids = [];

    /**
     * Constructor.
     *
     * @param WicketGuestPaymentModulesSettings $settings Settings instance.
     */
    public function __construct(WicketGuestPaymentModulesSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_init', [$this, 'register_email_filters']);
        add_action('woocommerce_before_resend_order_emails', [$this, 'allow_for_resend'], 5, 2);
        add_action('woocommerce_new_customer_note', [$this, 'allow_for_customer_note'], 5, 1);
    }

    /**
     * Register filters for all WooCommerce emails.
     *
     * @return void
     */
    public function register_email_filters(): void
    {
        $mailer = WC()->mailer();
        $emails = $mailer ? $mailer->get_emails() : [];

        foreach ($emails as $email) {
            if (!$email instanceof WC_Email) {
                continue;
            }

            add_filter('woocommerce_email_enabled_' . $email->id, [$this, 'maybe_block_email'], 20, 3);
        }
    }

    /**
     * Allow resend actions.
     *
     * @param WC_Order $order Order object.
     * @param string $email_id Email id.
     * @return void
     */
    public function allow_for_resend($order, string $email_id): void
    {
        $this->allowed_email_ids[$email_id] = true;
    }

    /**
     * Allow customer note email.
     *
     * @param array $args Note args.
     * @return void
     */
    public function allow_for_customer_note(array $args): void
    {
        $this->allowed_email_ids['customer_note'] = true;
    }

    /**
     * Block customer emails during admin order updates unless explicit.
     *
     * @param bool $enabled Email enabled.
     * @param mixed $object Email object context.
     * @param WC_Email $email Email instance.
     * @return bool
     */
    public function maybe_block_email(bool $enabled, $object, $email): bool
    {
        if (!$enabled || !$email instanceof WC_Email) {
            return $enabled;
        }

        if (!$email->is_customer_email()) {
            return $enabled;
        }

        if ($this->is_explicit_send_request($email)) {
            $this->log_decision('allow', 'explicit', $email, $object);
            return $enabled;
        }

        if (!$this->is_admin_order_update_request()) {
            return $enabled;
        }

        $this->log_decision('block', 'admin_update', $email, $object);
        return false;
    }

    /**
     * Check if a manual send was requested.
     *
     * @param WC_Email $email Email instance.
     * @return bool
     */
    private function is_explicit_send_request(WC_Email $email): bool
    {
        if (isset($this->allowed_email_ids[$email->id])) {
            return true;
        }

        if ($this->is_manual_order_action($email)) {
            return true;
        }

        if ($this->is_customer_note_request() && 'customer_note' === $email->id) {
            return true;
        }

        if ($this->is_refund_email($email->id) && $this->settings->allow_refund_emails()) {
            return true;
        }

        return (bool) apply_filters('wicket_woo_email_blocker_allow_send', false, $email);
    }

    /**
     * Determine if the current request is an admin order update.
     *
     * @return bool
     */
    private function is_admin_order_update_request(): bool
    {
        if (!is_admin()) {
            return false;
        }

        if (wp_doing_ajax()) {
            return $this->is_admin_order_ajax_action();
        }

        if (empty($_POST['post_ID']) || empty($_POST['post_type']) || empty($_POST['woocommerce_meta_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return false;
        }

        $post_id = absint($_POST['post_ID']);
        $post_type = sanitize_key(wp_unslash($_POST['post_type']));

        if (!$post_id || !in_array($post_type, wc_get_order_types('order-meta-boxes'), true)) {
            return false;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return false;
        }

        return true;
    }

    /**
     * Identify relevant admin-side AJAX order actions.
     *
     * @return bool
     */
    private function is_admin_order_ajax_action(): bool
    {
        if (empty($_POST['action'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return false;
        }

        $action = sanitize_key(wp_unslash($_POST['action']));

        return in_array(
            $action,
            [
                'woocommerce_refund_line_items',
                'woocommerce_mark_order_status',
            ],
            true
        );
    }

    /**
     * Detect customer note requests.
     *
     * @return bool
     */
    private function is_customer_note_request(): bool
    {
        if (!wp_doing_ajax()) {
            return false;
        }

        if (empty($_POST['action']) || empty($_POST['note_type'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return false;
        }

        $action = sanitize_key(wp_unslash($_POST['action']));
        $note_type = sanitize_key(wp_unslash($_POST['note_type']));

        return 'woocommerce_add_order_note' === $action && 'customer' === $note_type;
    }

    /**
     * Detect manual "send to customer" order action.
     *
     * @param WC_Email $email Email instance.
     * @return bool
     */
    private function is_manual_order_action(WC_Email $email): bool
    {
        return 'send_order_details' === $this->get_order_action() && 'customer_invoice' === $email->id;
    }

    /**
     * Get the current order action.
     *
     * @return string
     */
    private function get_order_action(): string
    {
        if (empty($_POST['wc_order_action'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return '';
        }

        return sanitize_key(wp_unslash($_POST['wc_order_action']));
    }

    /**
     * Check if the email id is a refund email.
     *
     * @param string $email_id Email id.
     * @return bool
     */
    private function is_refund_email(string $email_id): bool
    {
        return in_array(
            $email_id,
            [
                'customer_refunded_order',
                'customer_partially_refunded_order',
            ],
            true
        );
    }

    /**
     * Log allow/block decisions for UAT visibility.
     *
     * @param string $decision allow|block.
     * @param string $reason Reason key.
     * @param WC_Email $email Email instance.
     * @param mixed $object Email object context.
     * @return void
     */
    private function log_decision(string $decision, string $reason, WC_Email $email, $object): void
    {
        $order_id = null;
        if (is_object($object) && method_exists($object, 'get_id')) {
            $order_id = $object->get_id();
        }

        $context = [
            'email_id' => $email->id,
            'decision' => $decision,
            'reason' => $reason,
            'order_id' => $order_id,
            'order_action' => $this->get_order_action(),
        ];

        $this->log('Woo email blocker decision recorded.', 'info', $context);
    }
}
