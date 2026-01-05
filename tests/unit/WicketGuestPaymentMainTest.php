<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPayment;
use WicketGuestPaymentAuth;
use WicketGuestPaymentConfig;
use WicketGuestPaymentCore;
use WicketGuestPaymentEmail;
use WicketGuestPaymentInvoice;
use WicketGuestPaymentReceipt;

class TestableWicketGuestPayment extends WicketGuestPayment
{
    public function get_user_id_from_secure_cart_key_public(string $key): ?int
    {
        return $this->get_user_id_from_secure_cart_key($key);
    }
}

#[CoversClass(WicketGuestPayment::class)]
class WicketGuestPaymentMainTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_plugin_setup_returns_early_when_woocommerce_inactive(): void
    {
        Monkey\Functions\when('wicket_guest_checkout_is_woocommerce_active')->justReturn(false);
        Monkey\Functions\when('add_action')->justReturn(null);

        $plugin = new WicketGuestPayment();
        $plugin->plugin_setup();

        $this->assertSame('', $plugin->plugin_url);
    }

    public function test_plugin_setup_initializes_components_and_properties(): void
    {
        Monkey\Functions\when('wicket_guest_checkout_is_woocommerce_active')->justReturn(true);
        Monkey\Functions\when('plugins_url')->justReturn('https://example.com/plugin/');
        Monkey\Functions\when('plugin_dir_path')->justReturn('/var/www/plugin/');
        Monkey\Functions\when('load_plugin_textdomain')->justReturn(true);
        Monkey\Functions\when('is_admin')->justReturn(false);

        $plugin = new WicketGuestPayment();
        $plugin->plugin_setup();

        $this->assertSame('https://example.com/plugin/', $plugin->plugin_url);
        $this->assertSame('/var/www/plugin/', $plugin->plugin_path);
        $this->assertSame('1.0.0-test', $plugin->version);
        $this->assertInstanceOf(WicketGuestPaymentCore::class, $plugin->get_core());
        $this->assertInstanceOf(WicketGuestPaymentConfig::class, $plugin->get_config());
        $this->assertInstanceOf(WicketGuestPaymentEmail::class, $plugin->get_email());
        $this->assertInstanceOf(WicketGuestPaymentAuth::class, $plugin->get_auth());
        $this->assertNull($plugin->get_admin());
        $this->assertInstanceOf(WicketGuestPaymentInvoice::class, $plugin->get_invoice());
        $this->assertInstanceOf(WicketGuestPaymentReceipt::class, $plugin->get_receipt());
    }

    public function test_generate_secure_cart_key_stores_mapping(): void
    {
        Monkey\Functions\when('wp_generate_password')->justReturn('securekey123');
        Monkey\Functions\expect('set_transient')
            ->once()
            ->with('wgp_map_securekey123', 42, DAY_IN_SECONDS)
            ->andReturn(true);

        $plugin = new TestableWicketGuestPayment();
        $key = $plugin->generate_secure_cart_key(42);

        $this->assertSame('securekey123', $key);
    }

    public function test_get_user_id_from_secure_cart_key_returns_null_when_missing(): void
    {
        Monkey\Functions\when('get_transient')->justReturn(false);

        $plugin = new TestableWicketGuestPayment();
        $this->assertNull($plugin->get_user_id_from_secure_cart_key_public('missing'));
    }

    public function test_get_user_id_from_secure_cart_key_returns_user_id_when_found(): void
    {
        Monkey\Functions\when('get_transient')->justReturn(77);

        $plugin = new TestableWicketGuestPayment();
        $this->assertSame(77, $plugin->get_user_id_from_secure_cart_key_public('exists'));
    }

    public function test_delete_secure_cart_data_removes_mapping_and_cart(): void
    {
        Monkey\Functions\when('get_transient')->justReturn(77);
        Monkey\Functions\expect('delete_transient')
            ->twice()
            ->andReturn(true);

        $plugin = new WicketGuestPayment();
        $this->assertTrue($plugin->delete_secure_cart_data('securekey123'));
    }
}
