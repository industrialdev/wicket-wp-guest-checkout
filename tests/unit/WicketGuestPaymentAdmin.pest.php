<?php

declare(strict_types=1);

use Brain\Monkey;

function wgp_with_post(array $post, callable $callback): void
{
    $original = $_POST;
    $_POST = $post;

    try {
        $callback();
    } finally {
        $_POST = $original;
    }
}

function wgp_expect_json_success(array $data): void
{
    Monkey\Functions\expect('wp_send_json_success')
        ->once()
        ->with($data)
        ->andReturnUsing(function (): void {
            throw new RuntimeException('json_exit');
        });
}

function wgp_expect_json_error(array $data): void
{
    Monkey\Functions\expect('wp_send_json_error')
        ->once()
        ->with($data)
        ->andReturnUsing(function (): void {
            throw new RuntimeException('json_exit');
        });
}

it('adds the guest payment meta box for order screens', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $GLOBALS['current_screen'] = new class {
        public string $id = 'shop_order';
    };

    Monkey\Functions\expect('add_meta_box')
        ->once()
        ->with(
            'wicket_guest_payment_metabox',
            Mockery::type('string'),
            Mockery::type('array'),
            Mockery::on(static function ($screens): bool {
                return is_array($screens) && in_array('shop_order', $screens, true);
            }),
            'side',
            'default'
        );

    $admin->add_guest_payment_meta_box('shop_order', new stdClass());

    unset($GLOBALS['current_screen']);
});

it('enqueues admin scripts on HPOS order screens', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $screen = new class {
        public string $id = 'woocommerce_page_wc-orders';
        public string $post_type = 'shop_order';
        public string $base = 'woocommerce_page_wc-orders';
    };

    $root = rtrim(dirname(__DIR__, 2), '/') . '/';

    Monkey\Functions\when('get_current_screen')->justReturn($screen);
    Monkey\Functions\when('plugin_dir_path')->justReturn($root);
    Monkey\Functions\when('plugin_dir_url')->justReturn('https://example.com/plugin/');

    Monkey\Functions\expect('wp_enqueue_script')
        ->once()
        ->with(
            'wicket-guest-payment-admin',
            Mockery::type('string'),
            Mockery::type('array'),
            Mockery::on(static function ($value): bool {
                return is_int($value) || is_string($value);
            }),
            true
        );

    Monkey\Functions\expect('wp_localize_script')
        ->once()
        ->with(
            'wicket-guest-payment-admin',
            'wicketGuestPayment',
            Mockery::type('array')
        );

    $admin->enqueue_admin_scripts('edit.php');
});

it('rejects manual link generation with invalid nonce', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    Monkey\Functions\when('wp_verify_nonce')->justReturn(false);
    wgp_expect_json_error(['message' => 'Invalid request or missing data.']);

    expect(function () use ($admin): void {
        wgp_with_post([
            'order_id' => 123,
            'nonce' => 'bad-nonce',
        ], function () use ($admin): void {
            $admin->handle_generate_guest_link_only_ajax();
        });
    })->toThrow(RuntimeException::class);
});

it('generates a manual link for valid requests', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $core->shouldReceive('generate_token_for_order')
        ->once()
        ->with(123, '', 'manual')
        ->andReturn('token123');

    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $order = new class {
        public function get_meta(string $key, bool $single = false)
        {
            return '';
        }
        public function get_customer_id(): int
        {
            return 55;
        }
        public function add_order_note(string $note): void {}
    };

    Monkey\Functions\when('wp_verify_nonce')->justReturn(true);
    Monkey\Functions\when('current_user_can')->justReturn(true);
    Monkey\Functions\when('wc_get_order')->justReturn($order);
    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/cart?guest_payment_token=token123');

    wgp_expect_json_success([
        'message' => 'New payment link has been generated successfully.',
        'link' => 'https://example.com/cart?guest_payment_token=token123',
    ]);

    expect(function () use ($admin): void {
        wgp_with_post([
            'order_id' => 123,
            'nonce' => 'good-nonce',
        ], function () use ($admin): void {
            $admin->handle_generate_guest_link_only_ajax();
        });
    })->toThrow(RuntimeException::class);
});

it('resends email for existing token via ajax', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $core->shouldReceive('get_valid_token_data')
        ->once()
        ->with(123, Mockery::type('object'))
        ->andReturn([
            'guest_email' => 'guest@example.com',
            'token' => 'token123',
            'user_id' => 88,
        ]);

    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $email->shouldReceive('send_payment_email')
        ->once()
        ->with('guest@example.com', 'token123', 123, 88)
        ->andReturn(true);

    $admin = new WicketGuestPaymentAdmin($core, $email);

    $order = Mockery::mock('WC_Order');

    Monkey\Functions\when('check_ajax_referer')->justReturn(true);
    Monkey\Functions\when('wc_get_order')->justReturn($order);

    wgp_expect_json_success(['message' => 'Email sent successfully.']);

    expect(function () use ($admin): void {
        wgp_with_post([
            'order_id' => 123,
            'nonce' => 'good-nonce',
        ], function () use ($admin): void {
            $admin->handle_resend_email_ajax();
        });
    })->toThrow(RuntimeException::class);
});

it('invalidates a guest link via ajax', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $core->shouldReceive('invalidate_token_for_order')
        ->once()
        ->with(123)
        ->andReturn(true);

    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $order = new class {
        public function add_order_note(string $note): void {}
    };

    Monkey\Functions\when('check_ajax_referer')->justReturn(true);
    Monkey\Functions\when('wc_get_order')->justReturn($order);

    wgp_expect_json_success(['message' => 'Payment link has been invalidated successfully.']);

    expect(function () use ($admin): void {
        wgp_with_post([
            'order_id' => 123,
            'nonce' => 'good-nonce',
        ], function () use ($admin): void {
            $admin->handle_invalidate_link_ajax();
        });
    })->toThrow(RuntimeException::class);
});

it('generates and sends a link via ajax', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $core->shouldReceive('generate_token_for_order')
        ->once()
        ->with(123, 'guest@example.com', 'email')
        ->andReturn('token123');

    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $email->shouldReceive('send_payment_email')
        ->once()
        ->with('guest@example.com', 'token123', 123, 77)
        ->andReturn(true);

    $admin = new WicketGuestPaymentAdmin($core, $email);

    $order = new class {
        public function get_user_id(): int
        {
            return 77;
        }
        public function get_meta(string $key, bool $single = false)
        {
            return '';
        }
    };

    Monkey\Functions\when('wp_verify_nonce')->justReturn(true);
    Monkey\Functions\when('current_user_can')->justReturn(true);
    Monkey\Functions\when('sanitize_email')->justReturn('guest@example.com');
    Monkey\Functions\when('wc_get_order')->justReturn($order);
    Monkey\Functions\when('is_email')->justReturn(true);

    wgp_expect_json_success(['message' => 'Payment link generated and sent to guest@example.com.']);

    expect(function () use ($admin): void {
        wgp_with_post([
            'order_id' => 123,
            'nonce' => 'good-nonce',
            'guest_email' => 'guest@example.com',
        ], function () use ($admin): void {
            $admin->handle_generate_and_send_ajax();
        });
    })->toThrow(RuntimeException::class);
});

it('handles resend request from admin action', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $core->shouldReceive('get_valid_token_data')
        ->once()
        ->with(123, Mockery::type('WC_Order'))
        ->andReturn(['token' => 'token123']);

    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $email->shouldReceive('send_payment_email')
        ->once()
        ->with('guest@example.com', 'token123', 123, 88)
        ->andReturn(true);

    $admin = new WicketGuestPaymentAdmin($core, $email);

    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_meta')->with('_wgp_guest_payment_email', true)->andReturn('guest@example.com');
    $order->shouldReceive('get_meta')->with('_wgp_guest_payment_user_id', true)->andReturn(88);

    Monkey\Functions\when('wp_verify_nonce')->justReturn(true);
    Monkey\Functions\when('current_user_can')->justReturn(true);
    Monkey\Functions\when('wc_get_order')->justReturn($order);
    Monkey\Functions\when('wp_get_referer')->justReturn('https://example.com/admin/edit');

    wgp_with_post([
        'order_id' => 123,
        '_wpnonce_resend_guest' => 'good-nonce',
    ], function () use ($admin): void {
        $admin->handle_guest_payment_request();
    });

    expect(true)->toBeTrue();
});

it('handles invalidate request from admin action', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $core->shouldReceive('invalidate_token_for_order')
        ->once()
        ->with(123)
        ->andReturn(true);

    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    Monkey\Functions\when('wp_verify_nonce')->justReturn(true);
    Monkey\Functions\when('current_user_can')->justReturn(true);
    Monkey\Functions\when('wp_get_referer')->justReturn(false);
    Monkey\Functions\when('admin_url')->justReturn('https://example.com/wp-admin/post.php?post=123&action=edit');

    wgp_with_post([
        'order_id' => 123,
        '_wpnonce_invalidate_guest' => 'good-nonce',
    ], function () use ($admin): void {
        $admin->handle_guest_payment_request();
    });

    expect(true)->toBeTrue();
});

it('constructs with dependencies', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);

    $admin = new WicketGuestPaymentAdmin($core, $email);

    expect($admin)->toBeInstanceOf(WicketGuestPaymentAdmin::class);
});

it('adds guest payment order action when token missing', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $GLOBALS['theorder'] = new class {
        public function get_meta(string $key, bool $single = false)
        {
            return '';
        }
    };

    $actions = ['some_action' => 'Some Action'];
    $result = $admin->add_guest_payment_order_action($actions);

    expect(array_key_exists('wicket_create_guest_payment_link', $result))->toBeTrue();
    expect(array_key_exists('some_action', $result))->toBeTrue();

    unset($GLOBALS['theorder']);
});

it('skips guest payment order action when token exists', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $GLOBALS['theorder'] = new class {
        public function get_meta(string $key, bool $single = false)
        {
            return 'existing_token_123';
        }
    };

    $actions = ['some_action' => 'Some Action'];
    $result = $admin->add_guest_payment_order_action($actions);

    expect(array_key_exists('wicket_create_guest_payment_link', $result))->toBeFalse();
    expect(array_key_exists('some_action', $result))->toBeTrue();

    unset($GLOBALS['theorder']);
});

it('skips adding meta box for non order screens', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $GLOBALS['current_screen'] = new class {
        public $id = 'post';
    };

    Monkey\Functions\when('wc_get_page_screen_id')->justReturn('wc-orders');
    Monkey\Functions\expect('add_meta_box')->never();

    $admin->add_guest_payment_meta_box('post', new stdClass());

    expect(true)->toBeTrue();

    unset($GLOBALS['current_screen']);
});

it('skips enqueue on non order pages', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $mockScreen = new class {
        public $id = 'dashboard';
        public $post_type = 'page';
        public $base = 'dashboard';
    };

    Monkey\Functions\when('get_current_screen')->justReturn($mockScreen);
    Monkey\Functions\when('wc_get_page_screen_id')->justReturn('shop-order');

    $admin->enqueue_admin_scripts('edit.php');

    expect(true)->toBeTrue();
});

it('adds admin notice action when processing guest payment order action', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $email = Mockery::mock(WicketGuestPaymentEmail::class);
    $admin = new WicketGuestPaymentAdmin($core, $email);

    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);

    $called = false;
    Monkey\Functions\when('add_action')->alias(function (string $hook, $callback) use (&$called): void {
        $called = $hook === 'admin_notices' && is_callable($callback);
    });

    $admin->process_guest_payment_order_action($order);

    expect($called)->toBeTrue();
});
