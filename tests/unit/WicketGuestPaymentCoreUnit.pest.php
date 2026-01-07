<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

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

    class_alias('TestWCProduct', 'WC_Product');
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

    class_alias('TestWCCart', 'WC_Cart');
}

function wgp_core_unit_encrypt_token_for_test(string $token): string
{
    $method = WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD;
    $key = WICKET_GUEST_PAYMENT_ENCRYPTION_KEY;
    $iv_length = openssl_cipher_iv_length($method);
    $iv = str_repeat('a', $iv_length);
    $encrypted = openssl_encrypt($token, $method, $key, OPENSSL_RAW_DATA, $iv);

    return base64_encode($iv . $encrypted);
}

beforeEach(function (): void {
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'test-key-32-chars-long-exactly-32');
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    $this->core = new WicketGuestPaymentCore();
});

afterEach(function (): void {
    $this->core = null;
});

it('generates 64 char hex tokens', function (): void {
    $token = $this->core->generate_token();

    expect($token)->toBeString();
    expect(strlen($token))->toBe(64);
    expect($token)->toMatch('/^[a-f0-9]{64}$/');
});

it('generates unique tokens', function (): void {
    $token1 = $this->core->generate_token();
    $token2 = $this->core->generate_token();

    expect($token1)->not->toBe($token2);
});

it('calculates token expiry timestamp from now', function (): void {
    $now = time();
    $expiry = $this->core->get_token_expiry_timestamp();
    $expectedExpiry = $now + (7 * DAY_IN_SECONDS);

    expect(abs($expiry - $expectedExpiry))->toBeLessThanOrEqual(2);
});

it('calculates token expiry timestamp from created time', function (): void {
    $createdTimestamp = 1704067200;
    $expiry = $this->core->get_token_expiry_timestamp($createdTimestamp);
    $expectedExpiry = $createdTimestamp + (7 * DAY_IN_SECONDS);

    expect($expiry)->toBe($expectedExpiry);
});

it('returns false when guest session cookie missing', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    expect($this->core->has_guest_session_cookie())->toBeFalse();
});

it('returns true when guest session cookie set', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    expect($this->core->has_guest_session_cookie())->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns false when guest session cookie empty', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '';

    expect($this->core->has_guest_session_cookie())->toBeFalse();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns default token expiry days', function (): void {
    expect($this->core->get_token_expiry_days())->toBe(7);
});

it('updates token expiry days', function (): void {
    $this->core->set_token_expiry_days(14);

    expect($this->core->get_token_expiry_days())->toBe(14);
});

it('rejects zero token expiry days', function (): void {
    $this->core->set_token_expiry_days(0);

    expect($this->core->get_token_expiry_days())->toBe(7);
});

it('rejects negative token expiry days', function (): void {
    $this->core->set_token_expiry_days(-5);

    expect($this->core->get_token_expiry_days())->toBe(7);
});

it('accepts minimum token expiry days', function (): void {
    $this->core->set_token_expiry_days(1);

    expect($this->core->get_token_expiry_days())->toBe(1);
});

it('returns false for guest payment session without cookie or meta', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    expect($this->core->is_guest_payment_session())->toBeFalse();
});

it('returns true for guest payment session with cookie', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    expect($this->core->is_guest_payment_session())->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns true for guest payment session with user meta', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    Functions\when('get_current_user_id')->justReturn(99);
    Functions\when('get_user_meta')->justReturn('token-hash');

    expect($this->core->is_guest_payment_session())->toBeTrue();
});

it('returns null for expired token data', function (): void {
    $token = 'valid-token';
    $encrypted = wgp_core_unit_encrypt_token_for_test($token);
    $expired_timestamp = time() - (8 * DAY_IN_SECONDS);

    $order = \Mockery::mock('WC_Order');
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

    expect($data)->toBeNull();
});

it('removes cart items with missing product id', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $notice_called = false;

    $product = \Mockery::mock('WC_Product');
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

    expect($cart->removed)->toBe(['item_1']);
    expect($notice_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('rehydrates invalid cart item data object', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $product = \Mockery::mock('WC_Product');
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

    expect($cart->set_contents['item_1']['data'])->toBeInstanceOf(WC_Product::class);
    expect($cart->removed)->toBe([]);

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('removes variable product without variation', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $notice_called = false;
    $variable_product = \Mockery::mock('WC_Product');
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

    expect($cart->removed)->toBe(['item_1']);
    expect($notice_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('removes missing variation product', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $notice_called = false;
    $variable_product = \Mockery::mock('WC_Product');
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

    expect($cart->removed)->toBe(['item_1']);
    expect($notice_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('removes missing parent product', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $notice_called = false;
    $product = \Mockery::mock('WC_Product');
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

    expect($cart->removed)->toBe(['item_1']);
    expect($notice_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('rehydrates variation data object', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $parent_product = \Mockery::mock('WC_Product');
    $parent_product->shouldReceive('exists')->andReturn(true);
    $parent_product->shouldReceive('is_type')->with('variable')->andReturn(true);
    $parent_product->shouldReceive('is_type')->with('variable-subscription')->andReturn(false);
    $parent_product->shouldReceive('get_id')->andReturn(10);

    $variation_product = \Mockery::mock('WC_Product');
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

    expect($cart->set_contents['item_1']['data'])->toBe($variation_product);
    expect($cart->removed)->toBe([]);

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('sets custom price when guest session active', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $price_set = false;
    $product = \Mockery::mock('WC_Product');
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

    expect($price_set)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('skips custom price when no guest session', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    $product = \Mockery::mock('WC_Product');
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

    expect(true)->toBeTrue();
});

it('sets cart item custom price from session for guest', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $product = \Mockery::mock('WC_Product');
    $product->shouldReceive('set_price')->with(9.99)->once();

    $cart_item = [
        'product_id' => 10,
        'custom_price' => 9.99,
        'data' => $product,
    ];

    $result = $this->core->set_cart_item_custom_price_from_session($cart_item, [], 'item_1');

    expect($result)->toBe($cart_item);

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('skips cart item custom price without guest session', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    $product = \Mockery::mock('WC_Product');
    $product->shouldNotReceive('set_price');

    $cart_item = [
        'product_id' => 10,
        'custom_price' => 9.99,
        'data' => $product,
    ];

    $result = $this->core->set_cart_item_custom_price_from_session($cart_item, [], 'item_1');

    expect($result)->toBe($cart_item);
});

it('skips cart item custom price for invalid data object', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $cart_item = [
        'product_id' => 10,
        'custom_price' => 9.99,
        'data' => 'not-a-product',
    ];

    $result = $this->core->set_cart_item_custom_price_from_session($cart_item, [], 'item_1');

    expect($result)->toBe($cart_item);

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns token data on successful initiate payment', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('has_status')->with('pending')->andReturn(true);
    $order->shouldReceive('get_customer_id')->andReturn(55);

    Functions\when('wc_get_order')->justReturn($order);
    Functions\when('is_email')->justReturn(true);
    Functions\when('get_userdata')->justReturn((object) ['ID' => 55]);

    $core = \Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
    $core->shouldReceive('generate_token')->andReturn('token-123');
    $core->shouldReceive('store_token_data')
        ->with(100, 'token-123', 55, 'guest@example.com', 'email')
        ->andReturn(true);

    $result = $core->initiate_payment(100, 'guest@example.com');

    expect($result)->toBe([
        'token' => 'token-123',
        'order_id' => 100,
        'user_id' => 55,
    ]);
});

it('returns false when initiating payment with invalid email', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('has_status')->with('pending')->andReturn(true);

    Functions\when('wc_get_order')->justReturn($order);
    Functions\when('is_email')->justReturn(false);

    expect($this->core->initiate_payment(100, 'invalid-email'))->toBeFalse();
});

it('returns false when initiating payment for non-pending order', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('has_status')->with('pending')->andReturn(false);

    Functions\when('wc_get_order')->justReturn($order);
    Functions\when('is_email')->justReturn(true);

    expect($this->core->initiate_payment(100, 'guest@example.com'))->toBeFalse();
});

it('returns false when initiating payment for missing user', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('has_status')->with('pending')->andReturn(true);
    $order->shouldReceive('get_customer_id')->andReturn(0);

    Functions\when('wc_get_order')->justReturn($order);
    Functions\when('is_email')->justReturn(true);
    Functions\when('get_userdata')->justReturn(false);

    expect($this->core->initiate_payment(100, 'guest@example.com'))->toBeFalse();
});

it('returns false when generating token with invalid email', function (): void {
    $order = \Mockery::mock('WC_Order');

    Functions\when('wc_get_order')->justReturn($order);
    Functions\when('is_email')->justReturn(false);

    $core = \Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
    $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);

    $result = $core->generate_token_for_order(100, 'invalid-email', 'manual');

    expect($result)->toBeFalse();
});

it('uses user meta user id when generating token', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_meta')->with('_wgp_guest_payment_user_id', true)->andReturn('77');
    $order->shouldReceive('get_customer_id')->andReturn(0);

    Functions\when('wc_get_order')->justReturn($order);
    Functions\when('is_email')->justReturn(true);

    $core = \Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
    $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);
    $core->shouldReceive('generate_token')->andReturn('token-456');
    $core->shouldReceive('store_token_data')
        ->with(100, 'token-456', 77, 'guest@example.com', 'manual')
        ->andReturn(true);

    $result = $core->generate_token_for_order(100, 'guest@example.com', 'manual');

    expect($result)->toBe('token-456');
});

it('allows empty email for manual token generation', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_meta')->with('_wgp_guest_payment_user_id', true)->andReturn('');
    $order->shouldReceive('get_customer_id')->andReturn(44);

    Functions\when('wc_get_order')->justReturn($order);

    $core = \Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
    $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);
    $core->shouldReceive('generate_token')->andReturn('token-789');
    $core->shouldReceive('store_token_data')
        ->with(100, 'token-789', 44, '', 'manual')
        ->andReturn(true);

    $result = $core->generate_token_for_order(100, '', 'manual');

    expect($result)->toBe('token-789');
});

it('returns false when token storage fails', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_meta')->with('_wgp_guest_payment_user_id', true)->andReturn('77');
    $order->shouldReceive('get_customer_id')->andReturn(0);

    Functions\when('wc_get_order')->justReturn($order);
    Functions\when('is_email')->justReturn(true);

    $core = \Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
    $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);
    $core->shouldReceive('generate_token')->andReturn('token-999');
    $core->shouldReceive('store_token_data')
        ->with(100, 'token-999', 77, 'guest@example.com', 'manual')
        ->andReturn(false);

    $result = $core->generate_token_for_order(100, 'guest@example.com', 'manual');

    expect($result)->toBeFalse();
});

it('returns false when generating token with missing order', function (): void {
    Functions\when('wc_get_order')->justReturn(null);

    $core = \Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
    $core->shouldReceive('invalidate_token_for_order')->with(100)->andReturn(true);

    $result = $core->generate_token_for_order(100, 'guest@example.com', 'manual');

    expect($result)->toBeFalse();
});

it('returns false when initiating payment with missing order', function (): void {
    Functions\when('wc_get_order')->justReturn(null);

    expect($this->core->initiate_payment(100, 'guest@example.com'))->toBeFalse();
});

it('returns early when handling payment completion with missing order', function (): void {
    Functions\when('wc_get_order')->justReturn(null);

    $core = \Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
    $core->shouldNotReceive('invalidate_token_for_order');

    $core->handle_payment_completion(100);

    expect(true)->toBeTrue();
});

it('invalidates token when handling payment completion', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_user_id')->andReturn(55);

    Functions\when('wc_get_order')->justReturn($order);

    $core = \Mockery::mock(WicketGuestPaymentCore::class)->makePartial();
    $core->shouldReceive('invalidate_token_for_order')->with(100)->once();

    $core->handle_payment_completion(100);

    expect(true)->toBeTrue();
});

it('removes token meta when present', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('meta_exists')->with('_wgp_guest_payment_token_hash')->andReturn(true);
    $order->shouldReceive('meta_exists')->with('_wgp_guest_payment_token_encrypted')->andReturn(true);
    $order->shouldReceive('meta_exists')->with('_wgp_guest_payment_token_created')->andReturn(true);
    $order->shouldReceive('delete_meta_data')->with('_wgp_guest_payment_token_encrypted')->once();
    $order->shouldReceive('delete_meta_data')->with('_wgp_guest_payment_token_hash')->once();
    $order->shouldReceive('delete_meta_data')->with('_wgp_guest_payment_token_created')->once();
    $order->shouldReceive('delete_meta_data')->with('_wgp_guest_payment_generation_method')->once();
    $order->shouldReceive('save')->andReturn(true);
    $order->shouldReceive('get_user_id')->andReturn(0);

    $order_after = \Mockery::mock('WC_Order');
    $order_after->shouldReceive('meta_exists')->andReturn(false);

    $call_count = 0;
    Functions\when('wc_get_order')->alias(function () use ($order, $order_after, &$call_count) {
        $call_count++;
        return $call_count === 1 ? $order : $order_after;
    });

    $result = $this->core->invalidate_token_for_order(100);

    expect($result)->toBeTrue();
});

it('cleans up secure cart data for user when invalidating token', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('meta_exists')->andReturn(true);
    $order->shouldReceive('delete_meta_data')->andReturnNull();
    $order->shouldReceive('save')->andReturn(true);
    $order->shouldReceive('get_user_id')->andReturn(55);

    $order_after = \Mockery::mock('WC_Order');
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

    $plugin = \Mockery::mock('WicketGuestPayment');
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

    expect($result)->toBeTrue();
});

it('returns early when loading cart items without guest cookie', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    $remove_called = false;
    Functions\when('remove_action')->alias(function () use (&$remove_called): void {
        $remove_called = true;
    });

    $this->core->ensure_cart_items_loaded();

    expect($remove_called)->toBeFalse();
});

it('removes cart validation actions for guest', function (): void {
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

    expect($remove_calls)->toBe([
        ['woocommerce_check_cart_items', 1],
        ['woocommerce_check_cart_items', 1],
    ]);

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns null when token missing', function (): void {
    $order = \Mockery::mock('WC_Order');
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

    expect($data)->toBeNull();
});

it('returns null when token decrypt fails', function (): void {
    $order = \Mockery::mock('WC_Order');
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

    expect($data)->toBeNull();
});

it('defaults generation method to email', function (): void {
    $token = 'valid-token';
    $encrypted = wgp_core_unit_encrypt_token_for_test($token);
    $created_timestamp = time();

    $order = \Mockery::mock('WC_Order');
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

    expect($data)->not->toBeNull();
    expect($data['generation_method'])->toBe('email');
});

it('skips custom cart item price for invalid data object', function (): void {
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

    expect($cart->get_cart())->toBe([
        'item_1' => [
            'product_id' => 10,
            'variation_id' => 0,
            'custom_price' => 12.34,
            'data' => 'not-a-product',
        ],
    ]);

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns data when token valid', function (): void {
    $token = 'valid-token';
    $encrypted = wgp_core_unit_encrypt_token_for_test($token);
    $created_timestamp = time();

    $order = \Mockery::mock('WC_Order');
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

    expect($data)->not->toBeNull();
    expect($data['token'])->toBe($token);
    expect($data['guest_email'])->toBe('guest@example.com');
    expect($data['user_id'])->toBe(42);
    expect($data['created_timestamp'])->toBe($created_timestamp);
    expect($data['generation_method'])->toBe('manual');
});

it('returns null when user id missing', function (): void {
    $token = 'valid-token';
    $encrypted = wgp_core_unit_encrypt_token_for_test($token);

    $order = \Mockery::mock('WC_Order');
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

    expect($data)->toBeNull();
});

it('returns true when no token data exists', function (): void {
    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('meta_exists')->andReturn(false);
    $order->shouldNotReceive('save');

    Functions\expect('wc_get_order')->once()->andReturn($order);

    $result = $this->core->invalidate_token_for_order(123);

    expect($result)->toBeTrue();
});

class TestCart extends WC_Cart
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
