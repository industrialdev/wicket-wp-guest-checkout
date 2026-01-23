<?php

declare(strict_types=1);

use Brain\Monkey;

if (!function_exists('is_order_received_page')) {
    function is_order_received_page(): bool
    {
        return true;
    }
}

function wgp_with_get(array $get, callable $callback): void
{
    $original = $_GET;
    $_GET = $get;

    try {
        $callback();
    } finally {
        $_GET = $original;
    }
}

function wgp_with_cookie(array $cookie, callable $callback): void
{
    $original = $_COOKIE;
    $_COOKIE = $cookie;

    try {
        $callback();
    } finally {
        $_COOKIE = $original;
    }
}

it('blocks admin pay when user is not admin', function (): void {
    $handler = new WicketGuestPaymentAdminPay();

    Monkey\Functions\when('absint')->alias(static fn ($value) => (int) $value);
    Monkey\Functions\when('sanitize_key')->alias(static fn ($value) => $value);
    Monkey\Functions\when('wp_unslash')->alias(static fn ($value) => $value);
    Monkey\Functions\when('get_current_user_id')->justReturn(11);
    Monkey\Functions\when('get_userdata')->justReturn((object) ['roles' => ['shop_manager']]);
    Monkey\Functions\when('user_can')->alias(static fn (): bool => false);

    Monkey\Functions\expect('wp_die')
        ->once()
        ->andReturnUsing(function (): void {
            throw new RuntimeException('wp_die');
        });

    expect(function () use ($handler): void {
        wgp_with_get(['order_id' => 123], function () use ($handler): void {
            $handler->handle_admin_pay_request();
        });
    })->toThrow(RuntimeException::class);
});

it('starts admin pay session for valid admin', function (): void {
    $handler = new WicketGuestPaymentAdminPay();
    $redirect_url = null;

    $order = new class {
        public function get_status(): string
        {
            return 'pending';
        }

        public function has_status(array $statuses): bool
        {
            return in_array('pending', $statuses, true);
        }

        public function get_customer_id(): int
        {
            return 55;
        }

        public function get_checkout_payment_url(): string
        {
            return 'https://example.com/checkout/order-pay/123/?key=abc';
        }

        public function add_order_note(string $note): void {}
    };

    Monkey\Functions\when('absint')->alias(static fn ($value) => (int) $value);
    Monkey\Functions\when('sanitize_key')->alias(static fn ($value) => $value);
    Monkey\Functions\when('wp_unslash')->alias(static fn ($value) => $value);
    Monkey\Functions\when('get_current_user_id')->justReturn(99);
    Monkey\Functions\when('get_userdata')->justReturn((object) [
        'roles' => ['administrator'],
        'display_name' => 'Admin User',
    ]);
    Monkey\Functions\when('user_can')->alias(static fn (int $user_id, string $capability): bool => $user_id === 99);

    Monkey\Functions\expect('check_admin_referer')->once();
    Monkey\Functions\when('wc_get_order')->justReturn($order);
    Monkey\Functions\expect('set_transient')
        ->once()
        ->with(
            Mockery::on(static fn ($key): bool => str_starts_with($key, 'wgp_admin_pay_')),
            Mockery::on(static function ($data): bool {
                return is_array($data)
                    && $data['admin_id'] === 99
                    && $data['customer_id'] === 55
                    && $data['order_id'] === 123
                    && !empty($data['return_secret']);
            }),
            Mockery::type('int')
        );

    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/pay');
    Monkey\Functions\when('wp_safe_redirect')->alias(static function (string $url) use (&$redirect_url): void {
        $redirect_url = $url;
    });

    wgp_with_get(['order_id' => 123], function () use ($handler): void {
        $handler->handle_admin_pay_request();
    });

    expect($redirect_url)->toBe('https://example.com/pay');
});

it('auto returns admin on thank you page', function (): void {
    $handler = new WicketGuestPaymentAdminPay();
    $redirect_url = null;
    $switched_user = null;
    $auth_user = null;

    $order = new class {
        public function add_order_note(string $note): void {}
    };

    Monkey\Functions\when('sanitize_key')->alias(static fn ($value) => $value);
    Monkey\Functions\when('wp_unslash')->alias(static fn ($value) => $value);
    Monkey\Functions\when('absint')->alias(static fn ($value) => (int) $value);
    Monkey\Functions\when('get_current_user_id')->justReturn(55);
    Monkey\Functions\when('get_userdata')->justReturn((object) [
        'roles' => ['administrator'],
        'display_name' => 'Admin User',
    ]);
    Monkey\Functions\when('user_can')->alias(static fn (int $user_id, string $capability): bool => $user_id === 99);
    Monkey\Functions\when('wc_get_order')->justReturn($order);

    Monkey\Functions\expect('get_transient')
        ->once()
        ->with('wgp_admin_pay_token123')
        ->andReturn([
            'admin_id' => 99,
            'customer_id' => 55,
            'order_id' => 123,
            'return_secret' => 'secret456',
            'return_url' => 'https://example.com/wp-admin/post.php?post=123&action=edit',
        ]);

    Monkey\Functions\when('wp_clear_auth_cookie')->justReturn(null);
    Monkey\Functions\when('wp_set_current_user')->alias(static function (int $user_id) use (&$switched_user): void {
        $switched_user = $user_id;
    });
    Monkey\Functions\when('wp_set_auth_cookie')->alias(static function (int $user_id) use (&$auth_user): void {
        $auth_user = $user_id;
    });
    Monkey\Functions\expect('delete_transient')->once()->with('wgp_admin_pay_token123');
    Monkey\Functions\when('wp_safe_redirect')->alias(static function (string $url) use (&$redirect_url): void {
        $redirect_url = $url;
    });

    wgp_with_get(['order_id' => 123], function () use ($handler): void {
        wgp_with_cookie([
            'wgp_admin_pay' => 'token123',
            'wgp_admin_pay_secret' => 'secret456',
        ], function () use ($handler): void {
            $handler->maybe_auto_return_admin();
        });
    });

    expect($redirect_url)->toBe('https://example.com/wp-admin/post.php?post=123&action=edit');
    expect($switched_user)->toBe(99);
    expect($auth_user)->toBe(99);
});
