<?php

declare(strict_types=1);

namespace Wicket\GuestPayment;

use WC_Order;

/*
 * Guest Subscription Payment Flow for WooCommerce - Card Guard.
 *
 * Keeps a guest payer's payment method from being saved to the member or used
 * for the member's future renewals.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Prevents card saving and saved-card use during a guest payment session.
 *
 * The payer is logged in as the member, so WooCommerce treats any card they
 * enter as the member's own. Everything here relies on WooCommerce and
 * WooCommerce Subscriptions conventions rather than a specific gateway, and
 * only applies while a guest payment session is active.
 */
class WicketGuestPaymentCardGuard extends WicketGuestPaymentComponent
{
    /**
     * WooCommerce Subscriptions options that together make every subscription renew manually.
     *
     * Newer Subscriptions versions only honor "turn off automatic payments" while
     * manual renewals are also accepted, so both must be forced.
     */
    private const MANUAL_RENEWAL_OPTIONS = [
        'woocommerce_subscriptions_accept_manual_renewals',
        'woocommerce_subscriptions_turn_off_automatic_payments',
    ];

    /**
     * Gateway features that let a gateway save or reuse a customer's card.
     */
    private const CARD_SAVING_FEATURES = ['tokenization', 'add_payment_method'];

    /**
     * Core functionality class.
     *
     * @var WicketGuestPaymentCore
     */
    private WicketGuestPaymentCore $core;

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
     * Register hooks.
     *
     * @return void
     */
    public function init_hooks(): void
    {
        // Subscriptions created or paid in a guest session never renew automatically.
        foreach (self::MANUAL_RENEWAL_OPTIONS as $option) {
            add_filter('pre_option_' . $option, [$this, 'force_manual_renewal']);
        }

        // Gateways built on WooCommerce tokenization hide the save box and saved cards without this.
        add_filter('woocommerce_payment_gateway_supports', [$this, 'disable_card_saving_support'], PHP_INT_MAX, 3);

        // The payer must not see or pick the member's saved cards.
        add_filter('woocommerce_get_customer_payment_tokens', [$this, 'hide_saved_tokens'], PHP_INT_MAX);

        // Classic checkout and order-pay: strip save requests, reject saved-card use.
        add_action('woocommerce_checkout_process', [$this, 'sanitize_payment_request'], 0);
        add_action('woocommerce_before_pay_action', [$this, 'sanitize_payment_request'], 0);

        // Existing subscriptions on the order must not stay automatic, or the gateway copies the payer's card onto them.
        add_action('woocommerce_checkout_order_processed', [$this, 'force_existing_subscriptions_manual'], 5);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'force_existing_subscriptions_manual'], 5);
        add_action('woocommerce_before_pay_action', [$this, 'force_existing_subscriptions_manual'], 1);
    }

    /**
     * Force manual renewal for subscriptions during a guest payment session.
     *
     * Short-circuits the Subscriptions manual-renewal options, which both Subscriptions
     * and card gateways read to decide whether to save a card and whether a new
     * subscription renews automatically.
     *
     * @param mixed $pre Value that short-circuits the option read, or false.
     * @return mixed 'yes' during a guest session, otherwise the unchanged value.
     */
    public function force_manual_renewal(mixed $pre): mixed
    {
        if (!$this->is_active_guest_session()) {
            return $pre;
        }

        return 'yes';
    }

    /**
     * Report card-saving gateway features as unsupported during a guest session.
     *
     * @param bool   $supports Whether the gateway supports the feature.
     * @param string $feature  Feature being checked.
     * @param mixed  $gateway  Gateway instance.
     * @return bool
     */
    public function disable_card_saving_support($supports, $feature, $gateway = null): bool
    {
        if (!in_array($feature, self::CARD_SAVING_FEATURES, true)) {
            return (bool) $supports;
        }

        if (!$this->is_active_guest_session()) {
            return (bool) $supports;
        }

        return false;
    }

    /**
     * Return no saved payment tokens during a guest session.
     *
     * @param array $tokens Payment tokens keyed by token ID.
     * @return array
     */
    public function hide_saved_tokens($tokens): array
    {
        if (!$this->is_active_guest_session()) {
            return is_array($tokens) ? $tokens : [];
        }

        return [];
    }

    /**
     * Strip save requests and block saved-card use on classic checkout and the order-pay page.
     *
     * Hooked to both `woocommerce_checkout_process` (no argument) and
     * `woocommerce_before_pay_action` (receives the order), which need identical handling.
     *
     * @param WC_Order|null $order Order being paid on the order-pay page, or null on classic checkout.
     * @return void
     */
    public function sanitize_payment_request($order = null): void
    {
        if (!$this->is_active_guest_session()) {
            return;
        }

        $this->sanitize_post_data($order instanceof WC_Order ? $order->get_id() : null);
    }

    /**
     * Set every subscription tied to the order being paid to manual renewal.
     *
     * Forcing the Subscriptions options only changes subscriptions created or re-gatewayed
     * during the request. A subscription that already renews automatically (for example one
     * left by the member's own earlier attempt, or the parent of a failed renewal) would
     * keep renewing and gateways would copy the payer's card onto it. Setting it manual
     * before payment also makes Subscriptions skip its "failed renewal paid" card update.
     *
     * @param WC_Order|int $order Order being paid, or its ID.
     * @return void
     */
    public function force_existing_subscriptions_manual($order): void
    {
        if (!$this->is_active_guest_session() || !function_exists('wcs_get_subscriptions_for_order')) {
            return;
        }

        if (!$order instanceof WC_Order) {
            $order = wc_get_order((int) $order);
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order($order, ['order_type' => 'any']);

        foreach ($subscriptions as $subscription) {
            if ($subscription->get_requires_manual_renewal()) {
                continue;
            }

            $subscription->set_requires_manual_renewal(true);
            $subscription->add_order_note(
                __('Set to manual renewal because the order was paid through a guest payment link.', 'wicket-wgc')
            );
            $subscription->save();
        }
    }

    /**
     * Remove "save this card" fields and reject saved-card selections from the request.
     *
     * Field names follow the WooCommerce tokenization convention, so this covers any
     * gateway that uses it: `wc-{gateway}-new-payment-method` and `wc-{gateway}-payment-token`.
     * A rejected saved-token attempt is logged so support can trace a payer who reports a blocked checkout.
     *
     * @param int|null $order_id Order being paid when known, otherwise null.
     * @return void
     */
    private function sanitize_post_data(?int $order_id = null): void
    {
        foreach (array_keys($_POST) as $key) {
            $key = (string) $key;

            if (1 === preg_match('/^wc-.+-new-payment-method$/', $key)) {
                unset($_POST[$key]);
                continue;
            }

            if (1 === preg_match('/^wc-.+-payment-token$/', $key)) {
                $value = is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : null;

                // Only 'new' means "enter a new card"; an empty, array, or other value points at a stored token.
                if ('new' !== $value) {
                    $this->log(
                        'Rejected a saved payment token during a guest payment session.',
                        'warning',
                        ['field' => $key, 'user_id' => get_current_user_id(), 'order_id' => $order_id]
                    );
                    wc_add_notice(
                        __('Saved payment methods cannot be used for a guest payment. Please enter a card.', 'wicket-wgc'),
                        'error'
                    );
                }
            }
        }
    }

    /**
     * Whether the guest session logic can be trusted to answer yet.
     *
     * The session check reads the current user, which is unknown before `init`.
     * Answering early would cache a wrong "not a guest" result for the request.
     *
     * @return bool
     */
    private function is_active_guest_session(): bool
    {
        if (!did_action('init')) {
            return false;
        }

        return $this->core->is_guest_payment_session();
    }
}
