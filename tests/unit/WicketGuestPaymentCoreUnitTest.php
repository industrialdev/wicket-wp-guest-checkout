<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

if (!class_exists('WC_Product')) {
    class TestWCProduct
    {
        private int $id;
        private string $type;
        private bool $exists;

        public function __construct(int $id, string $type = 'simple', bool $exists = true)
        {
            $this->id = $id;
            $this->type = $type;
            $this->exists = $exists;
        }

        public function exists(): bool
        {
            return $this->exists;
        }

        public function is_type(string $type): bool
        {
            return $this->type === $type;
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function set_price(float $price): void {}
    }

    class_alias(__NAMESPACE__ . '\\TestWCProduct', 'WC_Product');
}

if (!class_exists('WC_Cart')) {
    class TestWCCart
    {
        public function get_cart(): array
        {
            return [];
        }

        public function remove_cart_item(string $key): void {}

        public function set_cart_contents(array $contents): void {}
    }

    class_alias(__NAMESPACE__ . '\\TestWCCart', 'WC_Cart');
}

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentCore;
use Mockery;

#[CoversClass(WicketGuestPaymentCore::class)]
class WicketGuestPaymentCoreUnitTest extends AbstractTestCase
{
    private ?WicketGuestPaymentCore $core = null;

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

        // Create Core instance
        $this->core = new WicketGuestPaymentCore();
    }

    protected function tearDown(): void
    {
        $this->core = null;
        parent::tearDown();
    }

    public function test_generate_token_returns_64_char_hex_string(): void
    {
        $token = $this->core->generate_token();

        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token), 'Token should be 64 characters');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function test_generate_token_returns_unique_tokens(): void
    {
        $token1 = $this->core->generate_token();
        $token2 = $this->core->generate_token();

        $this->assertNotEquals($token1, $token2, 'Each generated token should be unique');
    }

    public function test_get_token_expiry_timestamp_with_current_time(): void
    {
        $now = time();
        $expiry = $this->core->get_token_expiry_timestamp();

        $expectedExpiry = $now + (7 * DAY_IN_SECONDS);
        $this->assertLessThanOrEqual(2, abs($expiry - $expectedExpiry), 'Expiry should be 7 days from now');
    }

    public function test_get_token_expiry_timestamp_with_specific_created_time(): void
    {
        $createdTimestamp = 1704067200;
        $expiry = $this->core->get_token_expiry_timestamp($createdTimestamp);

        $expectedExpiry = $createdTimestamp + (7 * DAY_IN_SECONDS);
        $this->assertEquals($expectedExpiry, $expiry);
    }

    public function test_has_guest_session_cookie_returns_false_when_not_set(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        $result = $this->core->has_guest_session_cookie();

        $this->assertFalse($result);
    }

    public function test_has_guest_session_cookie_returns_true_when_set(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $result = $this->core->has_guest_session_cookie();

        $this->assertTrue($result);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_has_guest_session_cookie_returns_false_when_empty(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '';

        $result = $this->core->has_guest_session_cookie();

        $this->assertFalse($result);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_get_token_expiry_days_returns_default(): void
    {
        $days = $this->core->get_token_expiry_days();

        $this->assertEquals(7, $days);
    }

    public function test_set_token_expiry_days_updates_value(): void
    {
        $this->core->set_token_expiry_days(14);

        $days = $this->core->get_token_expiry_days();

        $this->assertEquals(14, $days);
    }

    public function test_set_token_expiry_days_rejects_zero(): void
    {
        $this->core->set_token_expiry_days(0);

        $days = $this->core->get_token_expiry_days();

        $this->assertEquals(7, $days);
    }

    public function test_set_token_expiry_days_rejects_negative(): void
    {
        $this->core->set_token_expiry_days(-5);

        $days = $this->core->get_token_expiry_days();

        $this->assertEquals(7, $days);
    }

    public function test_set_token_expiry_days_accepts_minimum_value(): void
    {
        $this->core->set_token_expiry_days(1);

        $days = $this->core->get_token_expiry_days();

        $this->assertEquals(1, $days);
    }

    public function test_is_guest_payment_session_returns_false_without_cookie_or_meta(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        $result = $this->core->is_guest_payment_session();

        $this->assertFalse($result);
    }

    public function test_is_guest_payment_session_returns_true_with_cookie(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $result = $this->core->is_guest_payment_session();

        $this->assertTrue($result);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_is_guest_payment_session_returns_true_with_user_meta(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        Functions\when('get_current_user_id')->justReturn(99);
        Functions\when('get_user_meta')->justReturn('token-hash');

        $result = $this->core->is_guest_payment_session();

        $this->assertTrue($result);
    }

    public function test_get_valid_token_data_returns_null_when_expired(): void
    {
        $token = 'valid-token';
        $encrypted = $this->encrypt_token_for_test($token);
        $expired_timestamp = time() - (8 * DAY_IN_SECONDS);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andReturnUsing(function (string $key) use ($encrypted, $expired_timestamp) {
            return match ($key) {
                '_wgp_guest_payment_token_encrypted' => $encrypted,
                '_wgp_guest_payment_token_created' => (string) $expired_timestamp,
                '_wgp_guest_payment_email' => 'guest@example.com',
                '_wgp_guest_payment_user_id' => '42',
                '_wgp_guest_payment_generation_method' => 'email',
                default => null,
            };
        });

        $data = $this->core->get_valid_token_data(123, $order);

        $this->assertNull($data);
    }

    public function test_guard_guest_cart_products_removes_items_with_missing_product_id(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $notice_called = false;

        $product = Mockery::mock('WC_Product');
        $cart = new TestCart([
            'item_1' => [
                'product_id' => 0,
                'variation_id' => 0,
                'data' => $product,
            ],
        ]);

        Functions\when('wc_get_product')->justReturn(null);
        Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
            $notice_called = true;
        });

        $this->core->guard_guest_cart_products($cart);

        $this->assertSame(['item_1'], $cart->removed);
        $this->assertTrue($notice_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_guard_guest_cart_products_rehydrates_invalid_data_object(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('exists')->andReturn(true);
        $product->shouldReceive('is_type')->andReturn(false);
        $product->shouldReceive('get_id')->andReturn(10);
        $cart = new TestCart([
            'item_1' => [
                'product_id' => 10,
                'variation_id' => 0,
                'data' => 'not-a-product',
            ],
        ]);

        Functions\when('wc_get_product')->alias(function (int $product_id) use ($product) {
            return $product_id === 10 ? $product : null;
        });

        $this->core->guard_guest_cart_products($cart);

        $this->assertInstanceOf(\WC_Product::class, $cart->set_contents['item_1']['data']);
        $this->assertSame([], $cart->removed);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_guard_guest_cart_products_removes_variable_without_variation(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $notice_called = false;
        $variable_product = Mockery::mock('WC_Product');
        $variable_product->shouldReceive('exists')->andReturn(true);
        $variable_product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $variable_product->shouldReceive('is_type')->with('variable-subscription')->andReturn(false);
        $variable_product->shouldReceive('get_id')->andReturn(10);

        $cart = new TestCart([
            'item_1' => [
                'product_id' => 10,
                'variation_id' => 0,
                'data' => $variable_product,
            ],
        ]);

        Functions\when('wc_get_product')->alias(function (int $product_id) use ($variable_product) {
            return $product_id === 10 ? $variable_product : null;
        });
        Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
            $notice_called = true;
        });

        $this->core->guard_guest_cart_products($cart);

        $this->assertSame(['item_1'], $cart->removed);
        $this->assertTrue($notice_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_guard_guest_cart_products_removes_missing_variation_product(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $notice_called = false;
        $variable_product = Mockery::mock('WC_Product');
        $variable_product->shouldReceive('exists')->andReturn(true);
        $variable_product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $variable_product->shouldReceive('is_type')->with('variable-subscription')->andReturn(false);
        $variable_product->shouldReceive('get_id')->andReturn(10);

        $cart = new TestCart([
            'item_1' => [
                'product_id' => 10,
                'variation_id' => 99,
                'data' => $variable_product,
            ],
        ]);

        Functions\when('wc_get_product')->alias(function (int $product_id) use ($variable_product) {
            if ($product_id === 10) {
                return $variable_product;
            }
            return null;
        });
        Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
            $notice_called = true;
        });

        $this->core->guard_guest_cart_products($cart);

        $this->assertSame(['item_1'], $cart->removed);
        $this->assertTrue($notice_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_guard_guest_cart_products_removes_missing_parent_product(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $notice_called = false;
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('exists')->andReturn(true);
        $product->shouldReceive('is_type')->andReturn(false);

        $cart = new TestCart([
            'item_1' => [
                'product_id' => 10,
                'variation_id' => 0,
                'data' => $product,
            ],
        ]);

        Functions\when('wc_get_product')->alias(function (int $product_id) {
            return null;
        });
        Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
            $notice_called = true;
        });

        $this->core->guard_guest_cart_products($cart);

        $this->assertSame(['item_1'], $cart->removed);
        $this->assertTrue($notice_called);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_guard_guest_cart_products_rehydrates_variation_data_object(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $parent_product = Mockery::mock('WC_Product');
        $parent_product->shouldReceive('exists')->andReturn(true);
        $parent_product->shouldReceive('is_type')->with('variable')->andReturn(true);
        $parent_product->shouldReceive('is_type')->with('variable-subscription')->andReturn(false);
        $parent_product->shouldReceive('get_id')->andReturn(10);

        $variation_product = Mockery::mock('WC_Product');
        $variation_product->shouldReceive('exists')->andReturn(true);
        $variation_product->shouldReceive('get_id')->andReturn(99);

        $cart = new TestCart([
            'item_1' => [
                'product_id' => 10,
                'variation_id' => 99,
                'data' => $parent_product,
            ],
        ]);

        Functions\when('wc_get_product')->alias(function (int $product_id) use ($parent_product, $variation_product) {
            if ($product_id === 10) {
                return $parent_product;
            }
            if ($product_id === 99) {
                return $variation_product;
            }
            return null;
        });

        $this->core->guard_guest_cart_products($cart);

        $this->assertSame($variation_product, $cart->set_contents['item_1']['data']);
        $this->assertSame([], $cart->removed);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_set_custom_cart_item_price_sets_custom_price_when_guest_session(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $price_set = false;
        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('set_price')
            ->with(12.34)
            ->andReturnUsing(function () use (&$price_set): void {
                $price_set = true;
            })
            ->once();

        $cart = new TestCart([
            'item_1' => [
                'product_id' => 10,
                'variation_id' => 0,
                'custom_price' => 12.34,
                'data' => $product,
            ],
        ]);

        $this->core->set_custom_cart_item_price($cart);

        $this->assertTrue($price_set);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_set_custom_cart_item_price_skips_when_no_guest_session(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        $product = Mockery::mock('WC_Product');
        $product->shouldNotReceive('set_price');

        $cart = new TestCart([
            'item_1' => [
                'product_id' => 10,
                'variation_id' => 0,
                'custom_price' => 12.34,
                'data' => $product,
            ],
        ]);

        $this->core->set_custom_cart_item_price($cart);
        $this->assertTrue(true);
    }

    public function test_set_cart_item_custom_price_from_session_sets_price_with_guest_session(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $product = Mockery::mock('WC_Product');
        $product->shouldReceive('set_price')->with(9.99)->once();

        $cart_item = [
            'product_id' => 10,
            'custom_price' => 9.99,
            'data' => $product,
        ];

        $result = $this->core->set_cart_item_custom_price_from_session($cart_item, [], 'item_1');

        $this->assertSame($cart_item, $result);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_set_cart_item_custom_price_from_session_skips_without_guest_session(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        $product = Mockery::mock('WC_Product');
        $product->shouldNotReceive('set_price');

        $cart_item = [
            'product_id' => 10,
            'custom_price' => 9.99,
            'data' => $product,
        ];

        $result = $this->core->set_cart_item_custom_price_from_session($cart_item, [], 'item_1');

        $this->assertSame($cart_item, $result);
    }

    public function test_set_cart_item_custom_price_from_session_skips_invalid_data_object(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $cart_item = [
            'product_id' => 10,
            'custom_price' => 9.99,
            'data' => 'not-a-product',
        ];

        $result = $this->core->set_cart_item_custom_price_from_session($cart_item, [], 'item_1');

        $this->assertSame($cart_item, $result);

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_initiate_payment_returns_token_data_on_success(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('has_status')->with('pending')->andReturn(true);
        $order->shouldReceive('get_customer_id')->andReturn(55);

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('is_email')->justReturn(true);
        Functions\when('get_userdata')->justReturn((object) ['ID' => 55]);

        $core = Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
        $core->shouldReceive('generate_token')->andReturn('token-123');
        $core->shouldReceive('store_token_data')
            ->with(100, 'token-123', 55, 'guest@example.com', 'email')
            ->andReturn(true);

        $result = $core->initiate_payment(100, 'guest@example.com');

        $this->assertSame(
            [
                'token' => 'token-123',
                'order_id' => 100,
                'user_id' => 55,
            ],
            $result
        );
    }

    public function test_initiate_payment_returns_false_for_invalid_email(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('has_status')->with('pending')->andReturn(true);

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('is_email')->justReturn(false);

        $result = $this->core->initiate_payment(100, 'invalid-email');

        $this->assertFalse($result);
    }

    public function test_initiate_payment_returns_false_when_order_not_pending(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('has_status')->with('pending')->andReturn(false);

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('is_email')->justReturn(true);

        $result = $this->core->initiate_payment(100, 'guest@example.com');

        $this->assertFalse($result);
    }

    public function test_initiate_payment_returns_false_when_user_missing(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('has_status')->with('pending')->andReturn(true);
        $order->shouldReceive('get_customer_id')->andReturn(0);

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('is_email')->justReturn(true);
        Functions\when('get_userdata')->justReturn(false);

        $result = $this->core->initiate_payment(100, 'guest@example.com');

        $this->assertFalse($result);
    }

    public function test_generate_token_for_order_returns_false_on_invalid_email(): void
    {
        $order = Mockery::mock('WC_Order');

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('is_email')->justReturn(false);

        $core = Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
        $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);

        $result = $core->generate_token_for_order(100, 'invalid-email', 'manual');

        $this->assertFalse($result);
    }

    public function test_generate_token_for_order_uses_user_meta_user_id(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_wgp_guest_payment_user_id', true)->andReturn('77');
        $order->shouldReceive('get_customer_id')->andReturn(0);

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('is_email')->justReturn(true);

        $core = Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
        $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);
        $core->shouldReceive('generate_token')->andReturn('token-456');
        $core->shouldReceive('store_token_data')
            ->with(100, 'token-456', 77, 'guest@example.com', 'manual')
            ->andReturn(true);

        $result = $core->generate_token_for_order(100, 'guest@example.com', 'manual');

        $this->assertSame('token-456', $result);
    }

    public function test_generate_token_for_order_allows_empty_email_for_manual(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_wgp_guest_payment_user_id', true)->andReturn('');
        $order->shouldReceive('get_customer_id')->andReturn(44);

        Functions\when('wc_get_order')->justReturn($order);

        $core = Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
        $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);
        $core->shouldReceive('generate_token')->andReturn('token-789');
        $core->shouldReceive('store_token_data')
            ->with(100, 'token-789', 44, '', 'manual')
            ->andReturn(true);

        $result = $core->generate_token_for_order(100, '', 'manual');

        $this->assertSame('token-789', $result);
    }

    public function test_generate_token_for_order_returns_false_when_store_fails(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->with('_wgp_guest_payment_user_id', true)->andReturn('77');
        $order->shouldReceive('get_customer_id')->andReturn(0);

        Functions\when('wc_get_order')->justReturn($order);
        Functions\when('is_email')->justReturn(true);

        $core = Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
        $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);
        $core->shouldReceive('generate_token')->andReturn('token-999');
        $core->shouldReceive('store_token_data')
            ->with(100, 'token-999', 77, 'guest@example.com', 'manual')
            ->andReturn(false);

        $result = $core->generate_token_for_order(100, 'guest@example.com', 'manual');

        $this->assertFalse($result);
    }

    public function test_generate_token_for_order_returns_false_when_order_missing(): void
    {
        Functions\when('wc_get_order')->justReturn(null);

        $core = Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
        $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);

        $result = $core->generate_token_for_order(100, 'guest@example.com', 'manual');

        $this->assertFalse($result);
    }

    public function test_initiate_payment_returns_false_when_order_missing(): void
    {
        Functions\when('wc_get_order')->justReturn(null);

        $result = $this->core->initiate_payment(100, 'guest@example.com');

        $this->assertFalse($result);
    }

    public function test_handle_payment_completion_returns_when_order_missing(): void
    {
        Functions\when('wc_get_order')->justReturn(null);

        $core = Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
        $core->shouldNotReceive('invalidate_token_for_order');

        $core->handle_payment_completion(100);

        $this->assertTrue(true);
    }

    public function test_handle_payment_completion_invalidates_token_when_order_found(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_user_id')->andReturn(55);

        Functions\when('wc_get_order')->justReturn($order);

        $core = Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
        $core->shouldReceive('invalidate_token_for_order')->with(100)->once();

        $core->handle_payment_completion(100);

        $this->assertTrue(true);
    }

    public function test_invalidate_token_for_order_removes_token_meta_when_present(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('meta_exists')->with('_wgp_guest_payment_token_hash')->andReturn(true);
        $order->shouldReceive('meta_exists')->with('_wgp_guest_payment_token_encrypted')->andReturn(true);
        $order->shouldReceive('meta_exists')->with('_wgp_guest_payment_token_created')->andReturn(true);
        $order->shouldReceive('delete_meta_data')->with('_wgp_guest_payment_token_encrypted')->once();
        $order->shouldReceive('delete_meta_data')->with('_wgp_guest_payment_token_hash')->once();
        $order->shouldReceive('delete_meta_data')->with('_wgp_guest_payment_token_created')->once();
        $order->shouldReceive('delete_meta_data')->with('_wgp_guest_payment_generation_method')->once();
        $order->shouldReceive('save')->andReturn(true);
        $order->shouldReceive('get_user_id')->andReturn(0);

        $order_after = Mockery::mock('WC_Order');
        $order_after->shouldReceive('meta_exists')->andReturn(false);

        $call_count = 0;
        Functions\when('wc_get_order')->alias(function () use ($order, $order_after, &$call_count) {
            $call_count++;
            return $call_count === 1 ? $order : $order_after;
        });

        $result = $this->core->invalidate_token_for_order(100);

        $this->assertTrue($result);
    }

    public function test_invalidate_token_for_order_cleans_up_secure_cart_data_for_user(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('meta_exists')->andReturn(true);
        $order->shouldReceive('delete_meta_data')->andReturnNull();
        $order->shouldReceive('save')->andReturn(true);
        $order->shouldReceive('get_user_id')->andReturn(55);

        $order_after = Mockery::mock('WC_Order');
        $order_after->shouldReceive('meta_exists')->andReturn(false);

        $call_count = 0;
        Functions\when('wc_get_order')->alias(function () use ($order, $order_after, &$call_count) {
            $call_count++;
            return $call_count === 1 ? $order : $order_after;
        });

        $original_wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new class {
            public $options = 'wp_options';
            public function get_col($query): array
            {
                return ['_transient_wgp_map_key_one', '_transient_wgp_map_key_two'];
            }
            public function prepare($query, ...$args): string
            {
                return $query;
            }
            public function esc_like($text): string
            {
                return addcslashes($text, '_%\\');
            }
        };

        $plugin = Mockery::mock('WicketGuestPayment');
        $plugin->shouldReceive('delete_secure_cart_data')->with('key_one')->once()->andReturn(true);
        $plugin->shouldReceive('delete_secure_cart_data')->with('key_two')->once()->andReturn(true);

        $getter = static function () {
            return self::$instance;
        };
        $setter = static function ($value): void {
            self::$instance = $value;
        };

        $bound_getter = $getter->bindTo(null, 'WicketGuestPayment');
        $bound_setter = $setter->bindTo(null, 'WicketGuestPayment');

        $original_instance = $bound_getter();
        $bound_setter($plugin);

        $result = $this->core->invalidate_token_for_order(100);

        $bound_setter($original_instance);
        $GLOBALS['wpdb'] = $original_wpdb;

        $this->assertTrue($result);
    }

    public function test_ensure_cart_items_loaded_returns_when_no_guest_cookie(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);

        $remove_called = false;
        Functions\when('remove_action')->alias(function () use (&$remove_called): void {
            $remove_called = true;
        });

        $this->core->ensure_cart_items_loaded();

        $this->assertFalse($remove_called);
    }

    public function test_ensure_cart_items_loaded_removes_cart_validation_for_guest(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $remove_calls = [];
        Functions\when('remove_action')->alias(function (string $hook, array $callback, int $priority) use (&$remove_calls): void {
            $remove_calls[] = [$hook, $priority];
        });

        $cart = new class {
            public function check_cart_items(): void {}

            public function check_cart_coupons(): void {}

            public function is_empty(): bool
            {
                return true;
            }
        };

        $session = new class {};

        $mockWC = new class ($cart, $session) {
            public $cart;
            public $session;
            public function __construct($cart, $session)
            {
                $this->cart = $cart;
                $this->session = $session;
            }
        };

        Functions\when('WC')->justReturn($mockWC);

        $this->core->ensure_cart_items_loaded();

        $this->assertSame(
            [
                ['woocommerce_check_cart_items', 1],
                ['woocommerce_check_cart_items', 1],
            ],
            $remove_calls
        );

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_get_valid_token_data_returns_null_when_token_missing(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andReturnUsing(function (string $key) {
            return match ($key) {
                '_wgp_guest_payment_token_encrypted' => '',
                '_wgp_guest_payment_token_created' => '0',
                '_wgp_guest_payment_email' => 'guest@example.com',
                '_wgp_guest_payment_user_id' => '42',
                default => null,
            };
        });

        $data = $this->core->get_valid_token_data(123, $order);

        $this->assertNull($data);
    }

    public function test_get_valid_token_data_returns_null_when_decrypt_fails(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andReturnUsing(function (string $key) {
            return match ($key) {
                '_wgp_guest_payment_token_encrypted' => 'not-base64',
                '_wgp_guest_payment_token_created' => (string) time(),
                '_wgp_guest_payment_email' => 'guest@example.com',
                '_wgp_guest_payment_user_id' => '42',
                default => null,
            };
        });

        $data = $this->core->get_valid_token_data(123, $order);

        $this->assertNull($data);
    }

    public function test_get_valid_token_data_defaults_generation_method_to_email(): void
    {
        $token = 'valid-token';
        $encrypted = $this->encrypt_token_for_test($token);
        $created_timestamp = time();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andReturnUsing(function (string $key) use ($encrypted, $created_timestamp) {
            return match ($key) {
                '_wgp_guest_payment_token_encrypted' => $encrypted,
                '_wgp_guest_payment_token_created' => (string) $created_timestamp,
                '_wgp_guest_payment_email' => 'guest@example.com',
                '_wgp_guest_payment_user_id' => '42',
                '_wgp_guest_payment_generation_method' => '',
                default => null,
            };
        });

        $data = $this->core->get_valid_token_data(123, $order);

        $this->assertNotNull($data);
        $this->assertSame('email', $data['generation_method']);
    }

    public function test_set_custom_cart_item_price_skips_invalid_data_object(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';

        $cart = new TestCart([
            'item_1' => [
                'product_id' => 10,
                'variation_id' => 0,
                'custom_price' => 12.34,
                'data' => 'not-a-product',
            ],
        ]);

        $this->core->set_custom_cart_item_price($cart);

        $this->assertSame(
            [
                'item_1' => [
                    'product_id' => 10,
                    'variation_id' => 0,
                    'custom_price' => 12.34,
                    'data' => 'not-a-product',
                ],
            ],
            $cart->get_cart()
        );

        unset($_COOKIE['wordpress_logged_in_order']);
    }

    public function test_get_valid_token_data_returns_data_when_valid(): void
    {
        $token = 'valid-token';
        $encrypted = $this->encrypt_token_for_test($token);
        $created_timestamp = time();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andReturnUsing(function (string $key) use ($encrypted, $created_timestamp) {
            return match ($key) {
                '_wgp_guest_payment_token_encrypted' => $encrypted,
                '_wgp_guest_payment_token_created' => (string) $created_timestamp,
                '_wgp_guest_payment_email' => 'guest@example.com',
                '_wgp_guest_payment_user_id' => '42',
                '_wgp_guest_payment_generation_method' => 'manual',
                default => null,
            };
        });

        $data = $this->core->get_valid_token_data(123, $order);

        $this->assertNotNull($data);
        $this->assertSame($token, $data['token']);
        $this->assertSame('guest@example.com', $data['guest_email']);
        $this->assertSame(42, $data['user_id']);
        $this->assertSame($created_timestamp, $data['created_timestamp']);
        $this->assertSame('manual', $data['generation_method']);
    }

    public function test_get_valid_token_data_returns_null_when_user_id_missing(): void
    {
        $token = 'valid-token';
        $encrypted = $this->encrypt_token_for_test($token);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_meta')->andReturnUsing(function (string $key) use ($encrypted) {
            return match ($key) {
                '_wgp_guest_payment_token_encrypted' => $encrypted,
                '_wgp_guest_payment_token_created' => (string) time(),
                '_wgp_guest_payment_email' => 'guest@example.com',
                '_wgp_guest_payment_user_id' => '0',
                '_wgp_guest_payment_generation_method' => 'email',
                default => null,
            };
        });

        $data = $this->core->get_valid_token_data(123, $order);

        $this->assertNull($data);
    }

    public function test_invalidate_token_for_order_returns_true_when_no_token_data(): void
    {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('meta_exists')->andReturn(false);
        $order->shouldNotReceive('save');

        Functions\expect('wc_get_order')->once()->andReturn($order);

        $result = $this->core->invalidate_token_for_order(123);

        $this->assertTrue($result);
    }

    private function encrypt_token_for_test(string $token): string
    {
        $method = WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD;
        $key = WICKET_GUEST_PAYMENT_ENCRYPTION_KEY;
        $iv_length = openssl_cipher_iv_length($method);
        $iv = str_repeat('a', $iv_length);
        $encrypted = openssl_encrypt($token, $method, $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $encrypted);
    }
}

class TestCart extends \WC_Cart
{
    public array $cart;
    public array $removed = [];
    public array $set_contents = [];

    public function __construct(array $cart)
    {
        $this->cart = $cart;
    }

    public function get_cart(): array
    {
        return $this->cart;
    }

    public function remove_cart_item(string $key): void
    {
        $this->removed[] = $key;
        unset($this->cart[$key]);
    }

    public function set_cart_contents(array $contents): void
    {
        $this->set_contents = $contents;
        $this->cart = $contents;
    }
}
