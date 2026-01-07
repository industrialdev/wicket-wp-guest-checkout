<?php

declare(strict_types=1);

use Brain\Monkey;

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(string $code = '', string $message = '', $data = null) {}
    }
}

it('returns true when email integration option enabled', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
        if ($name === 'wicket_guest_payment_enable_email_integration') {
            return 'yes';
        }
        return $default;
    });

    expect($config->filter_email_integration_enabled(false))->toBeTrue();
});

it('returns default when email integration option disabled', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->justReturn(false);

    expect($config->filter_email_integration_enabled(false))->toBeFalse();
});

it('returns true when pdf integration option enabled', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
        if ($name === 'wicket_guest_payment_enable_pdf_integration') {
            return 'on';
        }
        return $default;
    });

    expect($config->filter_pdf_integration_enabled(false))->toBeTrue();
});

it('uses configured token expiry days when set', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = 0) {
        if ($name === 'wicket_guest_payment_token_expiry_days') {
            return 14;
        }
        return $default;
    });

    expect($config->filter_token_expiry_days(7))->toBe(14);
});

it('prepends settings link in plugin action links', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin.php');
    Monkey\Functions\when('esc_url')->alias(fn(string $url) => $url);
    Monkey\Functions\when('esc_html__')->justReturn('Settings');

    if (!defined('WICKET_GUEST_CHECKOUT_BASENAME')) {
        define('WICKET_GUEST_CHECKOUT_BASENAME', 'wicket-wp-guest-checkout/wicket-wp-guest-checkout.php');
    }

    $links = $config->add_plugin_action_links(['existing']);

    expect($links[0])->toContain('Settings');
    expect($links[1])->toBe('existing');
});

it('returns error for invalid configuration days', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('rest_sanitize_boolean')->alias(fn($value) => (bool) $value);

    $result = $config->validate_configuration([
        'email_integration' => true,
        'pdf_integration' => false,
        'token_expiry_days' => 400,
    ]);

    expect($result)->toBeInstanceOf(WP_Error::class);
});

it('updates options when saving configuration', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('rest_sanitize_boolean')->alias(fn($value) => (bool) $value);
    Monkey\Functions\when('is_wp_error')->alias(fn($value) => $value instanceof WP_Error);
    Monkey\Functions\when('update_option')->justReturn(true);

    $result = $config->save_configuration([
        'email_integration' => true,
        'pdf_integration' => false,
        'token_expiry_days' => 30,
    ]);

    expect($result)->toBeTrue();
});
