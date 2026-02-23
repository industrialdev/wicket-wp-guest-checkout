<?php

declare(strict_types=1);

use Brain\Monkey;

class TestableWicketGuestPayment extends WicketGuestPayment
{
    public function get_user_id_from_secure_cart_key_public(string $key): ?int
    {
        return $this->get_user_id_from_secure_cart_key($key);
    }
}

beforeEach(function (): void {
    if (!defined('WICKET_GUEST_CHECKOUT_FILE')) {
        define('WICKET_GUEST_CHECKOUT_FILE', __FILE__);
    }
    if (!defined('WICKET_GUEST_CHECKOUT_VERSION')) {
        define('WICKET_GUEST_CHECKOUT_VERSION', '1.0.0-test');
    }
    if (!defined('WICKET_GUEST_CHECKOUT_BASENAME')) {
        define('WICKET_GUEST_CHECKOUT_BASENAME', 'wicket-wp-guest-checkout/wicket-wp-guest-checkout.php');
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
});

it('returns early when WooCommerce inactive during setup', function (): void {
    Monkey\Functions\when('wicket_guest_checkout_is_woocommerce_active')->justReturn(false);
    Monkey\Functions\when('add_action')->justReturn(null);

    $plugin = new WicketGuestPayment();
    $plugin->plugin_setup();

    expect($plugin->plugin_url)->toBe('');
});

it('initializes components and properties during setup', function (): void {
    Monkey\Functions\when('wicket_guest_checkout_is_woocommerce_active')->justReturn(true);
    Monkey\Functions\when('plugins_url')->justReturn('https://example.com/plugin/');
    Monkey\Functions\when('plugin_dir_path')->justReturn('/var/www/plugin/');
    Monkey\Functions\when('load_plugin_textdomain')->justReturn(true);
    Monkey\Functions\when('is_admin')->justReturn(false);
    Monkey\Functions\when('apply_filters')->alias(fn (string $hook, $value, ...$args) => $value);

    $plugin = new WicketGuestPayment();
    $plugin->plugin_setup();

    expect($plugin->plugin_url)->toBe('https://example.com/plugin/');
    expect($plugin->plugin_path)->toBe('/var/www/plugin/');
    expect($plugin->version)->toBe('1.0.0-test');
    expect($plugin->get_core())->toBeInstanceOf(WicketGuestPaymentCore::class);
    expect($plugin->get_config())->toBeInstanceOf(WicketGuestPaymentConfig::class);
    expect($plugin->get_email())->toBeInstanceOf(WicketGuestPaymentEmail::class);
    expect($plugin->get_auth())->toBeInstanceOf(WicketGuestPaymentAuth::class);
    expect($plugin->get_admin())->toBeNull();
    expect($plugin->get_invoice())->toBeInstanceOf(WicketGuestPaymentInvoice::class);
    expect($plugin->get_receipt())->toBeInstanceOf(WicketGuestPaymentReceipt::class);
});

it('applies configured token expiry days during setup', function (): void {
    Monkey\Functions\when('wicket_guest_checkout_is_woocommerce_active')->justReturn(true);
    Monkey\Functions\when('plugins_url')->justReturn('https://example.com/plugin/');
    Monkey\Functions\when('plugin_dir_path')->justReturn('/var/www/plugin/');
    Monkey\Functions\when('load_plugin_textdomain')->justReturn(true);
    Monkey\Functions\when('is_admin')->justReturn(false);
    Monkey\Functions\when('apply_filters')->alias(function (string $hook, $value, ...$args) {
        if ($hook === 'wicket/wooguestpay/token_expiry_days') {
            return 21;
        }

        return $value;
    });

    $plugin = new WicketGuestPayment();
    $plugin->plugin_setup();

    expect($plugin->get_core())->toBeInstanceOf(WicketGuestPaymentCore::class);
    expect($plugin->get_core()->get_token_expiry_days())->toBe(21);
});

it('stores mapping when generating secure cart key', function (): void {
    Monkey\Functions\when('wp_generate_password')->justReturn('securekey123');
    Monkey\Functions\expect('set_transient')
        ->once()
        ->with('wgp_map_securekey123', 42, DAY_IN_SECONDS)
        ->andReturn(true);

    $plugin = new TestableWicketGuestPayment();
    $key = $plugin->generate_secure_cart_key(42);

    expect($key)->toBe('securekey123');
});

it('returns null when secure cart key missing', function (): void {
    Monkey\Functions\when('get_transient')->justReturn(false);

    $plugin = new TestableWicketGuestPayment();

    expect($plugin->get_user_id_from_secure_cart_key_public('missing'))->toBeNull();
});

it('returns user id when secure cart key found', function (): void {
    Monkey\Functions\when('get_transient')->justReturn(77);

    $plugin = new TestableWicketGuestPayment();

    expect($plugin->get_user_id_from_secure_cart_key_public('exists'))->toBe(77);
});

it('deletes secure cart data mapping and cart', function (): void {
    Monkey\Functions\when('get_transient')->justReturn(77);
    Monkey\Functions\expect('delete_transient')
        ->twice()
        ->andReturn(true);

    $plugin = new WicketGuestPayment();

    expect($plugin->delete_secure_cart_data('securekey123'))->toBeTrue();
});
