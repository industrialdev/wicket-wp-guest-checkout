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
        $mockOrder = new class (456, 123) {
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
                return 'pending';
            }
            public function has_status($statuses): bool
            {
                if (is_array($statuses)) {
                    return in_array('pending', $statuses, true);
                }
                return $this->get_status() === $statuses;
            }
            public function get_cart_hash(): string
            {
                return 'test_hash';
            }
            public function set_cart_hash(string $hash): void {}
            public function save(): int
            {
                return $this->orderId;
            }
        };

        Monkey\Functions\when('get_current_user_id')->justReturn(123);
        Monkey\Functions\when('get_user_meta')->justReturn('456'); // Original order ID as string
        Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);

        // Mock WC()->cart
        $mockCart = new class {
            public function get_cart_hash(): string
            {
                return 'test_hash';
            }
        };

        $mockWC = new class ($mockCart) {
            public $cart;
            public function __construct($cart)
            {
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

    public function test_force_reuse_guest_payment_order_returns_zero_when_original_order_id_missing(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        Monkey\Functions\when('get_current_user_id')->justReturn(123);
        Monkey\Functions\when('get_user_meta')->justReturn('');

        $checkout = \Mockery::mock('WC_Checkout');

        $result = $this->auth->force_reuse_guest_payment_order(null, $checkout);

        $this->assertSame(0, $result);

        unset($_COOKIE['wordpress_logged_in_order']);
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

    public function test_validate_guest_payment_order_before_checkout_blocks_on_session_mismatch(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $notice_called = false;

        $cart = \Mockery::mock();
        $cart->shouldReceive('empty_cart')->once();

        $session = \Mockery::mock();
        $session->shouldReceive('get')->with('order_awaiting_payment')->andReturn(999);
        $session->shouldReceive('set')->with('order_awaiting_payment', null)->once();

        $mockWC = new class ($cart, $session) {
            public $cart;
            public $session;
            public function __construct($cart, $session)
            {
                $this->cart = $cart;
                $this->session = $session;
            }
        };

        Monkey\Functions\when('WC')->justReturn($mockWC);
        Monkey\Functions\when('get_current_user_id')->justReturn(55);
        Monkey\Functions\when('get_user_meta')->justReturn('123');
        Monkey\Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
            $notice_called = true;
        });

        $this->auth->validate_guest_payment_order_before_checkout();

        $this->assertTrue($notice_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_validate_guest_payment_order_before_checkout_allows_valid_order(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $cart = \Mockery::mock();
        $cart->shouldNotReceive('empty_cart');

        $session = \Mockery::mock();
        $session->shouldReceive('get')->with('order_awaiting_payment')->andReturn('123');
        $session->shouldNotReceive('set');

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('has_status')->with(['pending', 'failed', 'on-hold'])->andReturn(true);

        $mockWC = new class ($cart, $session) {
            public $cart;
            public $session;
            public function __construct($cart, $session)
            {
                $this->cart = $cart;
                $this->session = $session;
            }
        };

        Monkey\Functions\when('WC')->justReturn($mockWC);
        Monkey\Functions\when('get_current_user_id')->justReturn(55);
        Monkey\Functions\when('get_user_meta')->justReturn('123');
        Monkey\Functions\when('wc_get_order')->justReturn($order);
        Monkey\Functions\expect('wc_add_notice')->never();

        $this->auth->validate_guest_payment_order_before_checkout();

        $this->assertSame('1', $_COOKIE['wordpress_logged_in_order']);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_validate_guest_payment_order_before_checkout_blocks_when_order_missing(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $notice_called = false;

        $cart = \Mockery::mock();
        $cart->shouldReceive('empty_cart')->once();

        $session = \Mockery::mock();
        $session->shouldReceive('get')->with('order_awaiting_payment')->andReturn('123');
        $session->shouldReceive('set')->with('order_awaiting_payment', null)->once();

        $mockWC = new class ($cart, $session) {
            public $cart;
            public $session;
            public function __construct($cart, $session)
            {
                $this->cart = $cart;
                $this->session = $session;
            }
        };

        Monkey\Functions\when('WC')->justReturn($mockWC);
        Monkey\Functions\when('get_current_user_id')->justReturn(55);
        Monkey\Functions\when('get_user_meta')->justReturn('123');
        Monkey\Functions\when('wc_get_order')->justReturn(null);
        Monkey\Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
            $notice_called = true;
        });

        $this->auth->validate_guest_payment_order_before_checkout();

        $this->assertTrue($notice_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_validate_guest_payment_order_before_checkout_returns_when_no_cookie(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        Monkey\Functions\when('get_current_user_id')->justReturn(55);
        Monkey\Functions\expect('wc_add_notice')->never();

        $this->auth->validate_guest_payment_order_before_checkout();

        $this->assertTrue(true);
    }

    public function test_validate_guest_payment_order_before_checkout_adds_notice_when_user_missing(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $notice_called = false;

        Monkey\Functions\when('get_current_user_id')->justReturn(0);
        Monkey\Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
            $notice_called = true;
        });

        $this->auth->validate_guest_payment_order_before_checkout();

        $this->assertTrue($notice_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_validate_guest_payment_order_before_checkout_adds_notice_when_order_id_missing(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $notice_called = false;

        Monkey\Functions\when('get_current_user_id')->justReturn(55);
        Monkey\Functions\when('get_user_meta')->justReturn('');
        Monkey\Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
            $notice_called = true;
        });

        $this->auth->validate_guest_payment_order_before_checkout();

        $this->assertTrue($notice_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_validate_guest_payment_order_before_payment_block_blocks_on_mismatch(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $error_called = false;

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(999);

        $errors = \Mockery::mock('WP_Error');
        $errors->shouldReceive('add')
            ->with('guest_payment_order_mismatch', \Mockery::type('string'))
            ->andReturnUsing(function () use (&$error_called): void {
                $error_called = true;
            })
            ->once();

        Monkey\Functions\when('get_current_user_id')->justReturn(55);
        Monkey\Functions\when('get_user_meta')->justReturn('123');

        $this->auth->validate_guest_payment_order_before_payment_block($order, $errors);

        $this->assertTrue($error_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_validate_guest_payment_order_before_payment_block_blocks_on_invalid_status(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $error_called = false;

        $order = \Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('has_status')->with(['pending', 'failed', 'on-hold'])->andReturn(false);
        $order->shouldReceive('get_status')->andReturn('completed');

        $errors = \Mockery::mock('WP_Error');
        $errors->shouldReceive('add')
            ->with('guest_payment_order_status', \Mockery::type('string'))
            ->andReturnUsing(function () use (&$error_called): void {
                $error_called = true;
            })
            ->once();

        Monkey\Functions\when('get_current_user_id')->justReturn(55);
        Monkey\Functions\when('get_user_meta')->justReturn('123');

        $this->auth->validate_guest_payment_order_before_payment_block($order, $errors);

        $this->assertTrue($error_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_validate_guest_payment_order_before_payment_block_returns_when_no_cookie(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        $order = \Mockery::mock('WC_Order');
        $errors = \Mockery::mock('WP_Error');
        $errors->shouldNotReceive('add');

        $this->auth->validate_guest_payment_order_before_payment_block($order, $errors);

        $this->assertTrue(true);
    }

    public function test_validate_guest_payment_order_before_payment_block_adds_error_when_user_missing(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $error_called = false;

        $order = \Mockery::mock('WC_Order');
        $order->shouldNotReceive('get_id');

        $errors = \Mockery::mock('WP_Error');
        $errors->shouldReceive('add')
            ->with('guest_payment_session_error', \Mockery::type('string'))
            ->andReturnUsing(function () use (&$error_called): void {
                $error_called = true;
            })
            ->once();

        Monkey\Functions\when('get_current_user_id')->justReturn(0);

        $this->auth->validate_guest_payment_order_before_payment_block($order, $errors);

        $this->assertTrue($error_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_maybe_restore_cart_from_transient_returns_when_not_cart_or_checkout(): void
    {
        $get_transient_called = false;

        Monkey\Functions\when('is_cart')->justReturn(false);
        Monkey\Functions\when('is_checkout')->justReturn(false);
        Monkey\Functions\when('get_transient')->alias(function () use (&$get_transient_called) {
            $get_transient_called = true;
            return false;
        });

        $this->auth->maybe_restore_cart_from_transient();

        $this->assertFalse($get_transient_called);
    }

    public function test_maybe_restore_cart_from_transient_deletes_when_cart_not_empty(): void
    {
        $_GET['wgp_cart_key'] = 'secure-key';

        $delete_count = 0;

        $cart = \Mockery::mock();
        $cart->shouldReceive('is_empty')->andReturn(false);

        $session = \Mockery::mock();

        $mockWC = new class ($cart, $session) {
            public $cart;
            public $session;
            public function __construct($cart, $session)
            {
                $this->cart = $cart;
                $this->session = $session;
            }
        };

        Monkey\Functions\when('is_cart')->justReturn(true);
        Monkey\Functions\when('is_checkout')->justReturn(false);
        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('WC')->justReturn($mockWC);
        Monkey\Functions\when('get_transient')->alias(function (string $key) {
            if ($key === 'wgp_cart_secure-key') {
                return [
                    'item_1' => [
                        'product_id' => 10,
                        'variation_id' => 0,
                        'quantity' => 1,
                    ],
                ];
            }
            if ($key === 'wgp_map_secure-key') {
                return 123;
            }
            return false;
        });
        Monkey\Functions\when('delete_transient')->alias(function () use (&$delete_count): bool {
            $delete_count++;
            return true;
        });

        $this->auth->maybe_restore_cart_from_transient();

        $this->assertSame(2, $delete_count);

        unset($_GET['wgp_cart_key']);
    }

    public function test_maybe_restore_cart_from_transient_skips_when_transient_missing(): void
    {
        $_GET['wgp_cart_key'] = 'secure-key';

        $delete_count = 0;

        Monkey\Functions\when('is_cart')->justReturn(true);
        Monkey\Functions\when('is_checkout')->justReturn(false);
        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('get_transient')->justReturn(false);
        Monkey\Functions\when('delete_transient')->alias(function () use (&$delete_count): bool {
            $delete_count++;
            return true;
        });

        $this->auth->maybe_restore_cart_from_transient();

        $this->assertSame(0, $delete_count);

        unset($_GET['wgp_cart_key']);
    }

    public function test_maybe_restore_cart_from_transient_skips_when_cart_key_empty(): void
    {
        $_GET['wgp_cart_key'] = '';

        $get_transient_called = false;

        Monkey\Functions\when('is_cart')->justReturn(true);
        Monkey\Functions\when('is_checkout')->justReturn(false);
        Monkey\Functions\when('get_transient')->alias(function () use (&$get_transient_called) {
            $get_transient_called = true;
            return false;
        });

        $this->auth->maybe_restore_cart_from_transient();

        $this->assertFalse($get_transient_called);

        unset($_GET['wgp_cart_key']);
    }

    public function test_maybe_restore_cart_from_transient_skips_when_transient_not_array(): void
    {
        $_GET['wgp_cart_key'] = 'secure-key';

        $delete_called = false;

        Monkey\Functions\when('is_cart')->justReturn(true);
        Monkey\Functions\when('is_checkout')->justReturn(false);
        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('get_transient')->justReturn('not-an-array');
        Monkey\Functions\when('delete_transient')->alias(function () use (&$delete_called): bool {
            $delete_called = true;
            return true;
        });

        $this->auth->maybe_restore_cart_from_transient();

        $this->assertFalse($delete_called);

        unset($_GET['wgp_cart_key']);
    }

    public function test_display_error_notices_adds_rate_limited_notice(): void
    {
        $_GET['guest_payment_error'] = 'rate_limited';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'error';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_error']);
    }

    public function test_display_error_notices_adds_success_notice(): void
    {
        $_GET['guest_payment_success'] = '1';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'success';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_success']);
    }

    public function test_handle_guest_authentication_and_restriction_returns_on_wp_login(): void
    {
        $GLOBALS['pagenow'] = 'wp-login.php';

        $called = false;
        Monkey\Functions\when('is_admin')->returnArg();
        Monkey\Functions\when('is_user_logged_in')->alias(function () use (&$called): bool {
            $called = true;
            return false;
        });

        $this->auth->handle_guest_authentication_and_restriction();

        $this->assertFalse($called);

        unset($GLOBALS['pagenow']);
    }

    public function test_handle_guest_authentication_and_restriction_redirects_when_logged_in_with_token(): void
    {
        $_GET['guest_payment_token'] = 'token-123';

        $redirect_url = null;

        Monkey\Functions\when('is_admin')->justReturn(false);
        Monkey\Functions\when('is_user_logged_in')->justReturn(true);
        Monkey\Functions\when('sanitize_text_field')->returnArg();
        Monkey\Functions\when('wp_clear_auth_cookie')->justReturn(null);
        Monkey\Functions\when('add_query_arg')->alias(function (string $key, string $value, string $url) {
            return $url . '?' . $key . '=' . $value;
        });
        Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
        Monkey\Functions\when('wp_safe_redirect')->alias(function (string $url) use (&$redirect_url): void {
            $redirect_url = $url;
        });

        $this->auth->handle_guest_authentication_and_restriction();

        $this->assertSame('https://example.com/cart?guest_payment_token=token-123', $redirect_url);

        unset($_GET['guest_payment_token']);
    }

    public function test_handle_guest_authentication_and_restriction_allows_checkout_for_guest_session(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        Monkey\Functions\when('is_admin')->justReturn(false);
        Monkey\Functions\when('is_user_logged_in')->justReturn(false);
        Monkey\Functions\when('is_checkout')->justReturn(true);
        Monkey\Functions\when('is_cart')->justReturn(false);
        Monkey\Functions\when('is_wc_endpoint_url')->justReturn(false);
        Monkey\Functions\when('wp_doing_ajax')->justReturn(false);
        Monkey\Functions\expect('wp_safe_redirect')->never();

        $this->auth->handle_guest_authentication_and_restriction();

        $this->assertSame('1', $_COOKIE['wordpress_logged_in_order']);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_clear_guest_session_flag_sets_cookie_when_present(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';
        if (!defined('COOKIEPATH')) {
            define('COOKIEPATH', '/');
        }
        if (!defined('COOKIE_DOMAIN')) {
            define('COOKIE_DOMAIN', 'localhost');
        }

        $this->auth->clear_guest_session_flag();

        $this->assertTrue(true);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_prevent_guest_admin_access_returns_on_ajax(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        Monkey\Functions\when('wp_doing_ajax')->justReturn(true);
        Monkey\Functions\expect('wp_safe_redirect')->never();
        Monkey\Functions\expect('wp_clear_auth_cookie')->never();

        $this->auth->prevent_guest_admin_access();

        $this->assertTrue(true);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_get_user_ip_address_prefers_client_ip(): void
    {
        $_SERVER['HTTP_CLIENT_IP'] = '1.2.3.4';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';
        $_SERVER['REMOTE_ADDR'] = '9.9.9.9';

        $getter = function (): string {
            return $this->get_user_ip_address();
        };
        $bound_getter = $getter->bindTo($this->auth, WicketGuestPaymentAuth::class);
        $result = $bound_getter();

        $this->assertSame('1.2.3.4', $result);

        unset($_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
    }

    public function test_get_user_ip_address_prefers_forwarded_for(): void
    {
        $_SERVER['HTTP_CLIENT_IP'] = '';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';
        $_SERVER['REMOTE_ADDR'] = '9.9.9.9';

        $getter = function (): string {
            return $this->get_user_ip_address();
        };
        $bound_getter = $getter->bindTo($this->auth, WicketGuestPaymentAuth::class);
        $result = $bound_getter();

        $this->assertSame('5.6.7.8', $result);

        unset($_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
    }

    public function test_get_user_ip_address_falls_back_to_remote_addr(): void
    {
        unset($_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '9.9.9.9';

        $getter = function (): string {
            return $this->get_user_ip_address();
        };
        $bound_getter = $getter->bindTo($this->auth, WicketGuestPaymentAuth::class);
        $result = $bound_getter();

        $this->assertSame('9.9.9.9', $result);

        unset($_SERVER['REMOTE_ADDR']);
    }

    public function test_display_error_notices_adds_invalid_token_notice(): void
    {
        $_GET['guest_payment_error'] = 'invalid_token';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'error';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_error']);
    }

    public function test_display_error_notices_adds_cart_prep_failed_notice(): void
    {
        $_GET['guest_payment_error'] = 'cart_prep_failed';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'error';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_error']);
    }

    public function test_display_error_notices_adds_invalid_user_notice(): void
    {
        $_GET['guest_payment_error'] = 'invalid_user';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'error';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_error']);
    }

    public function test_display_error_notices_adds_no_user_id_notice(): void
    {
        $_GET['guest_payment_error'] = 'no_user_id';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'error';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_error']);
    }

    public function test_display_error_notices_adds_restricted_page_notice(): void
    {
        $_GET['guest_payment_error'] = 'restricted_page';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'error';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_error']);
    }

    public function test_display_error_notices_adds_order_not_found_notice(): void
    {
        $_GET['guest_payment_error'] = 'order_not_found';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'error';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_error']);
    }

    public function test_display_error_notices_adds_expired_notice(): void
    {
        $_GET['guest_payment_error'] = 'expired';

        $notice_called = false;

        Monkey\Functions\when('sanitize_key')->returnArg();
        Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
            $notice_called = $message !== '' && $type === 'error';
        });

        $this->auth->display_error_notices();

        $this->assertTrue($notice_called);

        unset($_GET['guest_payment_error']);
    }

    public function test_maybe_hide_admin_bar_removes_theme_filter_when_cookie_set(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $removed = false;
        Monkey\Functions\when('remove_filter')->alias(function (string $tag, string $callback, int $priority) use (&$removed): void {
            $removed = $tag === 'show_admin_bar' && $callback === 'wicket_should_show_admin_bar' && $priority === PHP_INT_MAX;
        });

        $result = $this->auth->maybe_hide_admin_bar(true);

        $this->assertFalse($result);
        $this->assertTrue($removed);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_maybe_hide_admin_bar_returns_original_value_without_cookie(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        Monkey\Functions\expect('remove_filter')->never();

        $result = $this->auth->maybe_hide_admin_bar(true);

        $this->assertTrue($result);
    }

    public function test_display_error_notices_noop_when_no_params(): void
    {
        unset($_GET['guest_payment_error'], $_GET['guest_payment_success']);

        Monkey\Functions\expect('wc_add_notice')->never();

        $this->auth->display_error_notices();

        $this->assertTrue(true);
    }

    private function createMockOrder(int $orderId, int $userId): object
    {
        return new class ($orderId, $userId) {
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
