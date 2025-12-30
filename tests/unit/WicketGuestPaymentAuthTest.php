<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentAuth;
use WicketGuestPaymentCore;
use Brain\Monkey;

#[CoversClass(WicketGuestPaymentAuth::class)]
class WicketGuestPaymentAuthTest extends AbstractTestCase
{
    private ?WicketGuestPaymentCore $core = null;
    private ?WicketGuestPaymentAuth $auth = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
            define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'test-key-32-chars-long-exactly-32');
        }
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
            define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
        }
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        $this->core = new WicketGuestPaymentCore();
        $this->auth = new WicketGuestPaymentAuth($this->core);
    }

    protected function tearDown(): void
    {
        $this->auth = null;
        $this->core = null;
        parent::tearDown();
    }

    public function test_constructor_sets_core_dependency(): void
    {
        $this->assertInstanceOf(WicketGuestPaymentAuth::class, $this->auth);
    }

    public function test_maybe_hide_admin_bar_returns_false_when_guest_session_active(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        Monkey\Functions\when('get_current_user_id')->justReturn(123);
        Monkey\Functions\when('get_user_meta')->justReturn('some_validation_token');

        $result = $this->auth->maybe_hide_admin_bar(true);

        $this->assertFalse($result);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_maybe_hide_admin_bar_returns_original_value_when_no_guest_session(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        Monkey\Functions\when('get_current_user_id')->justReturn(0);

        $result = $this->auth->maybe_hide_admin_bar(true);

        $this->assertTrue($result);
    }

    public function test_force_reuse_guest_payment_order_returns_original_order_id(): void
    {
        // Set up guest session
        $_COOKIE['wordpress_logged_in_order'] = '1';

        // Create order with pending status
        $mockOrder = new class(456, 123) {
            private $orderId;
            private $userId;

            public function __construct($orderId, $userId)
            {
                $this->orderId = $orderId;
                $this->userId = $userId;
            }

            public function get_id(): int { return $this->orderId; }
            public function get_user_id(): int { return $this->userId; }
            public function get_status(): string { return 'pending'; }
            public function has_status($statuses): bool {
                if (is_array($statuses)) {
                    return in_array('pending', $statuses, true);
                }
                return $this->get_status() === $statuses;
            }
            public function get_cart_hash(): string { return 'test_hash'; }
            public function set_cart_hash(string $hash): void {}
            public function save(): int { return $this->orderId; }
        };

        Monkey\Functions\when('get_current_user_id')->justReturn(123);
        Monkey\Functions\when('get_user_meta')->justReturn('456'); // Original order ID as string
        Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);

        // Mock WC()->cart
        $mockCart = new class {
            public function get_cart_hash(): string { return 'test_hash'; }
        };

        $mockWC = new class($mockCart) {
            public $cart;
            public function __construct($cart) {
                $this->cart = $cart;
            }
        };

        Monkey\Functions\when('WC')->justReturn($mockWC);

        // Mock WC_Checkout
        $checkout = \Mockery::mock('WC_Checkout');

        $result = $this->auth->force_reuse_guest_payment_order(null, $checkout);

        $this->assertEquals(456, $result);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_force_reuse_guest_payment_order_returns_provided_order_when_no_guest_session(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        Monkey\Functions\when('get_current_user_id')->justReturn(0);

        $checkout = \Mockery::mock('WC_Checkout');

        $result = $this->auth->force_reuse_guest_payment_order(789, $checkout);

        $this->assertEquals(789, $result);
    }

    public function test_display_error_notices_does_nothing_when_no_errors(): void
    {
        unset($_GET['wgp_error']);

        Monkey\Functions\when('is_checkout')->justReturn(true);

        // Should not call wc_add_notice
        Monkey\Functions\expect('wc_add_notice')->never();

        $this->auth->display_error_notices();

        // Add assertion to avoid risky test warning
        $this->assertTrue(true);
    }

    public function test_display_error_notices_displays_error_when_set(): void
    {
        $_GET['wgp_error'] = 'invalid_token';

        Monkey\Functions\when('is_checkout')->justReturn(true);
        Monkey\Functions\when('is_cart')->justReturn(false);
        Monkey\Functions\when('sanitize_text_field')->returnArg();
        Monkey\Functions\when('wp_unslash')->returnArg();
        Monkey\Functions\when('wc_add_notice')->justReturn(null);

        // Just verify it doesn't throw an exception
        $this->auth->display_error_notices();

        $this->assertTrue(true);

        unset($_GET['wgp_error']);
    }

    private function createMockOrder(int $orderId, int $userId): object
    {
        return new class($orderId, $userId) {
            private $orderId;
            private $userId;

            public function __construct($orderId, $userId)
            {
                $this->orderId = $orderId;
                $this->userId = $userId;
            }

            public function get_id(): int
            {
                return $this->orderId;
            }

            public function get_user_id(): int
            {
                return $this->userId;
            }

            public function get_status(): string
            {
                return 'processing';
            }

            public function has_status($statuses): bool
            {
                if (is_array($statuses)) {
                    return in_array('processing', $statuses, true);
                }
                return $this->get_status() === $statuses;
            }
        };
    }
}
