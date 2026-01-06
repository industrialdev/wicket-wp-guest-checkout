<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentConfig;

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(string $code = '', string $message = '', $data = null) {}
    }
}

#[CoversClass(WicketGuestPaymentConfig::class)]
class WicketGuestPaymentConfigTest extends AbstractTestCase
{
    private ?WicketGuestPaymentConfig $config = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new WicketGuestPaymentConfig();
    }

    protected function tearDown(): void
    {
        $this->config = null;
        parent::tearDown();
    }

    public function test_filter_email_integration_enabled_returns_true_when_option_enabled(): void
    {
        Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
            if ($name === 'wicket_guest_payment_enable_email_integration') {
                return 'yes';
            }
            return $default;
        });

        $this->assertTrue($this->config->filter_email_integration_enabled(false));
    }

    public function test_filter_email_integration_enabled_returns_default_when_disabled(): void
    {
        Monkey\Functions\when('get_option')->justReturn(false);

        $this->assertFalse($this->config->filter_email_integration_enabled(false));
    }

    public function test_filter_pdf_integration_enabled_returns_true_when_option_enabled(): void
    {
        Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
            if ($name === 'wicket_guest_payment_enable_pdf_integration') {
                return 'on';
            }
            return $default;
        });

        $this->assertTrue($this->config->filter_pdf_integration_enabled(false));
    }

    public function test_filter_token_expiry_days_uses_option_when_set(): void
    {
        Monkey\Functions\when('get_option')->alias(function (string $name, $default = 0) {
            if ($name === 'wicket_guest_payment_token_expiry_days') {
                return 14;
            }
            return $default;
        });

        $this->assertSame(14, $this->config->filter_token_expiry_days(7));
    }

    public function test_add_plugin_action_links_prepends_settings_link(): void
    {
        Monkey\Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin.php');
        Monkey\Functions\when('esc_url')->alias(fn(string $url) => $url);
        Monkey\Functions\when('esc_html__')->justReturn('Settings');

        if (!defined('WICKET_GUEST_CHECKOUT_BASENAME')) {
            define('WICKET_GUEST_CHECKOUT_BASENAME', 'wicket-wp-guest-checkout/wicket-wp-guest-checkout.php');
        }

        $links = $this->config->add_plugin_action_links(['existing']);

        $this->assertStringContainsString('Settings', $links[0]);
        $this->assertSame('existing', $links[1]);
    }

    public function test_validate_configuration_returns_error_for_invalid_days(): void
    {
        Monkey\Functions\when('rest_sanitize_boolean')->alias(fn($value) => (bool) $value);

        $result = $this->config->validate_configuration([
            'email_integration' => true,
            'pdf_integration' => false,
            'token_expiry_days' => 400,
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_save_configuration_updates_options(): void
    {
        Monkey\Functions\when('rest_sanitize_boolean')->alias(fn($value) => (bool) $value);
        Monkey\Functions\when('is_wp_error')->alias(fn($value) => $value instanceof \WP_Error);
        Monkey\Functions\when('update_option')->justReturn(true);

        $result = $this->config->save_configuration([
            'email_integration' => true,
            'pdf_integration' => false,
            'token_expiry_days' => 30,
        ]);

        $this->assertTrue($result);
    }
}
