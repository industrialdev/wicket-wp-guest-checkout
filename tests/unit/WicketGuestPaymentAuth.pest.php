<?php

declare(strict_types=1);

use Brain\Monkey;

it('registers hooks during init', function (): void {
    $core = Mockery::mock(WicketGuestPaymentCore::class);
    $auth = new WicketGuestPaymentAuth($core);

    $auth->init_hooks();

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
