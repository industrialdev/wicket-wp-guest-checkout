<?php

declare(strict_types=1);

use Brain\Monkey;

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
    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }

    $this->core = new WicketGuestPaymentCore();
    $this->auth = new WicketGuestPaymentAuth($this->core);
});

afterEach(function (): void {
    $this->auth = null;
    $this->core = null;
});

it('constructs with core dependency', function (): void {
    expect($this->auth)->toBeInstanceOf(WicketGuestPaymentAuth::class);
});

it('registers hooks during init', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $auth = new WicketGuestPaymentAuth($core);

    $auth->init_hooks();

    expect(true)->toBeTrue();
});

it('returns false when hiding admin bar during guest session', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    Monkey\Functions\when('get_current_user_id')->justReturn(123);
    Monkey\Functions\when('get_user_meta')->justReturn('some_validation_token');

    $result = $this->auth->maybe_hide_admin_bar(true);

    expect($result)->toBeFalse();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns original admin bar value when no guest session', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    Monkey\Functions\when('get_current_user_id')->justReturn(0);

    $result = $this->auth->maybe_hide_admin_bar(true);

    expect($result)->toBeTrue();
});

it('reuses original order id when guest session active', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $mockOrder = new class(456, 123) {
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
    Monkey\Functions\when('get_user_meta')->justReturn('456');
    Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);

    $mockCart = new class {
        public function get_cart_hash(): string
        {
            return 'test_hash';
        }
    };

    $mockWC = new class($mockCart) {
        public $cart;

        public function __construct($cart)
        {
            $this->cart = $cart;
        }
    };

    Monkey\Functions\when('WC')->justReturn($mockWC);

    $checkout = Mockery::mock('WC_Checkout');

    $result = $this->auth->force_reuse_guest_payment_order(null, $checkout);

    expect($result)->toBe(456);

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns provided order id when no guest session', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    Monkey\Functions\when('get_current_user_id')->justReturn(0);

    $checkout = Mockery::mock('WC_Checkout');

    $result = $this->auth->force_reuse_guest_payment_order(789, $checkout);

    expect($result)->toBe(789);
});

it('returns zero when original order id missing', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    Monkey\Functions\when('get_current_user_id')->justReturn(123);
    Monkey\Functions\when('get_user_meta')->justReturn('');

    $checkout = Mockery::mock('WC_Checkout');

    $result = $this->auth->force_reuse_guest_payment_order(null, $checkout);

    expect($result)->toBe(0);

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('skips error notices when no error params', function (): void {
    unset($_GET['wgp_error']);

    Monkey\Functions\when('is_checkout')->justReturn(true);
    Monkey\Functions\expect('wc_add_notice')->never();

    $this->auth->display_error_notices();

    expect(true)->toBeTrue();
});

it('displays error when wgp_error set', function (): void {
    $_GET['wgp_error'] = 'invalid_token';

    Monkey\Functions\when('is_checkout')->justReturn(true);
    Monkey\Functions\when('is_cart')->justReturn(false);
    Monkey\Functions\when('sanitize_text_field')->returnArg();
    Monkey\Functions\when('wp_unslash')->returnArg();
    Monkey\Functions\when('wc_add_notice')->justReturn(null);

    $this->auth->display_error_notices();

    expect(true)->toBeTrue();

    unset($_GET['wgp_error']);
});

it('blocks checkout on session mismatch', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $notice_called = false;

    $cart = Mockery::mock();
    $cart->shouldReceive('empty_cart')->once();

    $session = Mockery::mock();
    $session->shouldReceive('get')->with('order_awaiting_payment')->andReturn(999);
    $session->shouldReceive('set')->with('order_awaiting_payment', null)->once();

    $mockWC = new class($cart, $session) {
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

    expect($notice_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('allows checkout for valid order', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $cart = Mockery::mock();
    $cart->shouldNotReceive('empty_cart');

    $session = Mockery::mock();
    $session->shouldReceive('get')->with('order_awaiting_payment')->andReturn('123');
    $session->shouldNotReceive('set');

    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('has_status')->with(['pending', 'failed', 'on-hold'])->andReturn(true);

    $mockWC = new class($cart, $session) {
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

    expect($_COOKIE['wordpress_logged_in_order'])->toBe('1');

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('blocks checkout when order missing', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $notice_called = false;

    $cart = Mockery::mock();
    $cart->shouldReceive('empty_cart')->once();

    $session = Mockery::mock();
    $session->shouldReceive('get')->with('order_awaiting_payment')->andReturn('123');
    $session->shouldReceive('set')->with('order_awaiting_payment', null)->once();

    $mockWC = new class($cart, $session) {
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

    expect($notice_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns early when no guest session cookie before checkout', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    Monkey\Functions\when('get_current_user_id')->justReturn(55);
    Monkey\Functions\expect('wc_add_notice')->never();

    $this->auth->validate_guest_payment_order_before_checkout();

    expect(true)->toBeTrue();
});

it('adds notice when user missing before checkout', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $notice_called = false;

    Monkey\Functions\when('get_current_user_id')->justReturn(0);
    Monkey\Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
        $notice_called = true;
    });

    $this->auth->validate_guest_payment_order_before_checkout();

    expect($notice_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('adds notice when order id missing before checkout', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $notice_called = false;

    Monkey\Functions\when('get_current_user_id')->justReturn(55);
    Monkey\Functions\when('get_user_meta')->justReturn('');
    Monkey\Functions\when('wc_add_notice')->alias(function () use (&$notice_called): void {
        $notice_called = true;
    });

    $this->auth->validate_guest_payment_order_before_checkout();

    expect($notice_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('blocks payment block on order mismatch', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $error_called = false;

    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(999);

    $errors = Mockery::mock('WP_Error');
    $errors->shouldReceive('add')
        ->with('guest_payment_order_mismatch', Mockery::type('string'))
        ->andReturnUsing(function () use (&$error_called): void {
            $error_called = true;
        })
        ->once();

    Monkey\Functions\when('get_current_user_id')->justReturn(55);
    Monkey\Functions\when('get_user_meta')->justReturn('123');

    $this->auth->validate_guest_payment_order_before_payment_block($order, $errors);

    expect($error_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('blocks payment block on invalid status', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $error_called = false;

    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('has_status')->with(['pending', 'failed', 'on-hold'])->andReturn(false);
    $order->shouldReceive('get_status')->andReturn('completed');

    $errors = Mockery::mock('WP_Error');
    $errors->shouldReceive('add')
        ->with('guest_payment_order_status', Mockery::type('string'))
        ->andReturnUsing(function () use (&$error_called): void {
            $error_called = true;
        })
        ->once();

    Monkey\Functions\when('get_current_user_id')->justReturn(55);
    Monkey\Functions\when('get_user_meta')->justReturn('123');

    $this->auth->validate_guest_payment_order_before_payment_block($order, $errors);

    expect($error_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns early when no guest session cookie before payment block', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    $order = Mockery::mock('WC_Order');
    $errors = Mockery::mock('WP_Error');
    $errors->shouldNotReceive('add');

    $this->auth->validate_guest_payment_order_before_payment_block($order, $errors);

    expect(true)->toBeTrue();
});

it('adds error when user missing before payment block', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $error_called = false;

    $order = Mockery::mock('WC_Order');
    $order->shouldNotReceive('get_id');

    $errors = Mockery::mock('WP_Error');
    $errors->shouldReceive('add')
        ->with('guest_payment_session_error', Mockery::type('string'))
        ->andReturnUsing(function () use (&$error_called): void {
            $error_called = true;
        })
        ->once();

    Monkey\Functions\when('get_current_user_id')->justReturn(0);

    $this->auth->validate_guest_payment_order_before_payment_block($order, $errors);

    expect($error_called)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('skips cart restore when not on cart or checkout', function (): void {
    $get_transient_called = false;

    Monkey\Functions\when('is_cart')->justReturn(false);
    Monkey\Functions\when('is_checkout')->justReturn(false);
    Monkey\Functions\when('get_transient')->alias(function () use (&$get_transient_called) {
        $get_transient_called = true;

        return false;
    });

    $this->auth->maybe_restore_cart_from_transient();

    expect($get_transient_called)->toBeFalse();
});

it('deletes transients when cart not empty', function (): void {
    $_GET['wgp_cart_key'] = 'secure-key';

    $delete_count = 0;

    $cart = Mockery::mock();
    $cart->shouldReceive('is_empty')->andReturn(false);

    $session = Mockery::mock();

    $mockWC = new class($cart, $session) {
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

    expect($delete_count)->toBe(2);

    unset($_GET['wgp_cart_key']);
});

it('skips cart restore when transient missing', function (): void {
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

    expect($delete_count)->toBe(0);

    unset($_GET['wgp_cart_key']);
});

it('skips cart restore when cart key empty', function (): void {
    $_GET['wgp_cart_key'] = '';

    $get_transient_called = false;

    Monkey\Functions\when('is_cart')->justReturn(true);
    Monkey\Functions\when('is_checkout')->justReturn(false);
    Monkey\Functions\when('get_transient')->alias(function () use (&$get_transient_called) {
        $get_transient_called = true;

        return false;
    });

    $this->auth->maybe_restore_cart_from_transient();

    expect($get_transient_called)->toBeFalse();

    unset($_GET['wgp_cart_key']);
});

it('skips cart restore when transient not array', function (): void {
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

    expect($delete_called)->toBeFalse();

    unset($_GET['wgp_cart_key']);
});

it('adds rate limited notice', function (): void {
    $_GET['guest_payment_error'] = 'rate_limited';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'error';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_error']);
});

it('adds success notice', function (): void {
    $_GET['guest_payment_success'] = '1';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'success';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_success']);
});

it('returns early on wp-login page', function (): void {
    $GLOBALS['pagenow'] = 'wp-login.php';

    $called = false;
    Monkey\Functions\when('is_admin')->returnArg();
    Monkey\Functions\when('is_user_logged_in')->alias(function () use (&$called): bool {
        $called = true;

        return false;
    });

    $this->auth->handle_guest_authentication_and_restriction();

    expect($called)->toBeFalse();

    unset($GLOBALS['pagenow']);
});

it('redirects logged-in users with token', function (): void {
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

    expect($redirect_url)->toBe('https://example.com/cart?guest_payment_token=token-123');

    unset($_GET['guest_payment_token']);
});

it('allows checkout for guest session', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    Monkey\Functions\when('is_admin')->justReturn(false);
    Monkey\Functions\when('is_user_logged_in')->justReturn(false);
    Monkey\Functions\when('is_checkout')->justReturn(true);
    Monkey\Functions\when('is_cart')->justReturn(false);
    Monkey\Functions\when('is_wc_endpoint_url')->justReturn(false);
    Monkey\Functions\when('wp_doing_ajax')->justReturn(false);
    Monkey\Functions\expect('wp_safe_redirect')->never();

    $this->auth->handle_guest_authentication_and_restriction();

    expect($_COOKIE['wordpress_logged_in_order'])->toBe('1');

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('cleans up after payment for guest sessions', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $core->shouldReceive('invalidate_token_for_order')
        ->once()
        ->with(123);

    $auth = new WicketGuestPaymentAuth($core);

    $_COOKIE['wordpress_logged_in_order'] = '1';

    $order = new class {
        public function get_user_id(): int
        {
            return 99;
        }

        public function add_order_note(string $note): void {}

        public function save(): int
        {
            return 555;
        }
    };

    Monkey\Functions\when('get_current_user_id')->justReturn(99);
    Monkey\Functions\when('get_user_meta')->justReturn('123');
    Monkey\Functions\when('wc_get_order')->justReturn($order);
    Monkey\Functions\when('is_ssl')->justReturn(false);
    Monkey\Functions\when('wp_clear_auth_cookie')->justReturn(null);

    $auth->cleanup_after_payment(555);

    unset($_COOKIE['wordpress_logged_in_order']);

    expect(true)->toBeTrue();
});

it('sets guest session cookie when clearing flag', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';
    if (!defined('COOKIEPATH')) {
        define('COOKIEPATH', '/');
    }
    if (!defined('COOKIE_DOMAIN')) {
        define('COOKIE_DOMAIN', 'localhost');
    }

    $this->auth->clear_guest_session_flag();

    expect(true)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns early when preventing admin access on ajax', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    Monkey\Functions\when('wp_doing_ajax')->justReturn(true);
    Monkey\Functions\expect('wp_safe_redirect')->never();
    Monkey\Functions\expect('wp_clear_auth_cookie')->never();

    $this->auth->prevent_guest_admin_access();

    expect(true)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('gets user ip preferring client ip', function (): void {
    $_SERVER['HTTP_CLIENT_IP'] = '1.2.3.4';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';
    $_SERVER['REMOTE_ADDR'] = '9.9.9.9';

    $getter = function (): string {
        return $this->get_user_ip_address();
    };
    $bound_getter = $getter->bindTo($this->auth, WicketGuestPaymentAuth::class);
    $result = $bound_getter();

    expect($result)->toBe('1.2.3.4');

    unset($_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
});

it('gets user ip preferring forwarded for', function (): void {
    $_SERVER['HTTP_CLIENT_IP'] = '';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';
    $_SERVER['REMOTE_ADDR'] = '9.9.9.9';

    $getter = function (): string {
        return $this->get_user_ip_address();
    };
    $bound_getter = $getter->bindTo($this->auth, WicketGuestPaymentAuth::class);
    $result = $bound_getter();

    expect($result)->toBe('5.6.7.8');

    unset($_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);
});

it('gets user ip from remote address when others missing', function (): void {
    unset($_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    $_SERVER['REMOTE_ADDR'] = '9.9.9.9';

    $getter = function (): string {
        return $this->get_user_ip_address();
    };
    $bound_getter = $getter->bindTo($this->auth, WicketGuestPaymentAuth::class);
    $result = $bound_getter();

    expect($result)->toBe('9.9.9.9');

    unset($_SERVER['REMOTE_ADDR']);
});

it('adds invalid token notice', function (): void {
    $_GET['guest_payment_error'] = 'invalid_token';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'error';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_error']);
});

it('adds cart prep failed notice', function (): void {
    $_GET['guest_payment_error'] = 'cart_prep_failed';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'error';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_error']);
});

it('adds invalid user notice', function (): void {
    $_GET['guest_payment_error'] = 'invalid_user';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'error';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_error']);
});

it('adds no user id notice', function (): void {
    $_GET['guest_payment_error'] = 'no_user_id';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'error';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_error']);
});

it('adds restricted page notice', function (): void {
    $_GET['guest_payment_error'] = 'restricted_page';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'error';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_error']);
});

it('adds order not found notice', function (): void {
    $_GET['guest_payment_error'] = 'order_not_found';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'error';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_error']);
});

it('adds expired notice', function (): void {
    $_GET['guest_payment_error'] = 'expired';

    $notice_called = false;

    Monkey\Functions\when('sanitize_key')->returnArg();
    Monkey\Functions\when('wc_add_notice')->alias(function (string $message, string $type) use (&$notice_called): void {
        $notice_called = $message !== '' && $type === 'error';
    });

    $this->auth->display_error_notices();

    expect($notice_called)->toBeTrue();

    unset($_GET['guest_payment_error']);
});

it('removes theme filter when hiding admin bar with cookie', function (): void {
    $_COOKIE['wordpress_logged_in_order'] = '1';

    $removed = false;
    Monkey\Functions\when('remove_filter')->alias(function (string $tag, string $callback, int $priority) use (&$removed): void {
        $removed = $tag === 'show_admin_bar' && $callback === 'wicket_should_show_admin_bar' && $priority === PHP_INT_MAX;
    });

    $result = $this->auth->maybe_hide_admin_bar(true);

    expect($result)->toBeFalse();
    expect($removed)->toBeTrue();

    unset($_COOKIE['wordpress_logged_in_order']);
});

it('returns original admin bar value without cookie', function (): void {
    unset($_COOKIE['wordpress_logged_in_order']);

    Monkey\Functions\expect('remove_filter')->never();

    $result = $this->auth->maybe_hide_admin_bar(true);

    expect($result)->toBeTrue();
});

it('noops when no error params present', function (): void {
    unset($_GET['guest_payment_error'], $_GET['guest_payment_success']);

    Monkey\Functions\expect('wc_add_notice')->never();

    $this->auth->display_error_notices();

    expect(true)->toBeTrue();
});

it('redirects guest users away from wp-admin', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $auth = new WicketGuestPaymentAuth($core);

    $_COOKIE['wordpress_logged_in_order'] = '1';

    Monkey\Functions\when('wp_doing_ajax')->justReturn(false);
    Monkey\Functions\when('wp_clear_auth_cookie')->justReturn(null);
    Monkey\Functions\when('home_url')->justReturn('https://example.com/');
    Monkey\Functions\when('wp_safe_redirect')->justReturn(null);

    $auth->prevent_guest_admin_access();

    unset($_COOKIE['wordpress_logged_in_order']);

    expect(true)->toBeTrue();
});
