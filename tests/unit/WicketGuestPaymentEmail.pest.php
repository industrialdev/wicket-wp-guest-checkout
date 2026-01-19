<?php

declare(strict_types=1);

use Brain\Monkey;

function wgp_email_boot(): WicketGuestPaymentEmail
{
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'test-key-32-chars-long-exactly-32');
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    $core = new WicketGuestPaymentCore();

    return new WicketGuestPaymentEmail($core);
}

function wgp_email_mock_order(): object
{
    return new class {
        public function get_id(): int
        {
            return 1;
        }

        public function get_meta(string $key, bool $single = false)
        {
            return '';
        }
    };
}

function wgp_email_mock_order_with_guest_email(): object
{
    return new class {
        public function get_id(): int
        {
            return 1;
        }

        public function get_meta(string $key, bool $single = false)
        {
            if ($key === '_wgp_guest_payment_email') {
                return 'guest@example.com';
            }

            return '';
        }
    };
}

function wgp_email_mock_order_with_methods(): object
{
    return new class {
        public function get_id(): int
        {
            return 1;
        }

        public function get_order_number(): string
        {
            return '12345';
        }

        public function get_formatted_order_total(): string
        {
            return '$100.00';
        }

        public function get_items(): array
        {
            return [];
        }

        public function add_order_note(string $note, bool $is_error = false): void {}
    };
}

function wgp_email_mock_user(): object
{
    return new class {
        public $first_name = 'John';
        public $last_name = 'Doe';
    };
}

it('constructs with core dependency', function (): void {
    $email = wgp_email_boot();

    expect($email)->toBeInstanceOf(WicketGuestPaymentEmail::class);
});

it('returns original header for non-target emails', function (): void {
    $email = wgp_email_boot();
    $mockOrder = wgp_email_mock_order();

    $result = $email->add_cc_to_emails('Header: value', 'admin_new_order', $mockOrder);

    expect($result)->toBe('Header: value');
});

it('returns original header when order is missing', function (): void {
    $email = wgp_email_boot();

    $result = $email->add_cc_to_emails('Header: value', 'customer_completed_order', null);

    expect($result)->toBe('Header: value');
});

it('adds cc for customer completed order', function (): void {
    $email = wgp_email_boot();
    $mockOrder = wgp_email_mock_order_with_guest_email();

    Monkey\Functions\when('is_email')->justReturn(true);

    $result = $email->add_cc_to_emails('Header: value', 'customer_completed_order', $mockOrder);

    expect($result)->toContain('Cc: guest@example.com');
});

it('adds cc for customer processing order', function (): void {
    $email = wgp_email_boot();
    $mockOrder = wgp_email_mock_order_with_guest_email();

    Monkey\Functions\when('is_email')->justReturn(true);

    $result = $email->add_cc_to_emails('Header: value', 'customer_processing_order', $mockOrder);

    expect($result)->toContain('Cc: guest@example.com');
});

it('skips cc when guest email is invalid', function (): void {
    $email = wgp_email_boot();
    $mockOrder = wgp_email_mock_order_with_guest_email();

    Monkey\Functions\when('is_email')->justReturn(false);

    $result = $email->add_cc_to_emails('Header: value', 'customer_completed_order', $mockOrder);

    expect($result)->not->toContain('Cc:');
});

it('returns false when sending email with invalid email address', function (): void {
    $email = wgp_email_boot();

    Monkey\Functions\when('is_email')->justReturn(false);

    $result = $email->send_payment_email('invalid-email', 'token123', 1, 1);

    expect($result)->toBeFalse();
});

it('returns false when sending email with empty token', function (): void {
    $email = wgp_email_boot();

    Monkey\Functions\when('is_email')->justReturn(true);

    $result = $email->send_payment_email('test@example.com', '', 1, 1);

    expect($result)->toBeFalse();
});

it('returns false when sending email with invalid order id', function (): void {
    $email = wgp_email_boot();

    Monkey\Functions\when('is_email')->justReturn(true);

    $result = $email->send_payment_email('test@example.com', 'token123', 0, 1);

    expect($result)->toBeFalse();
});

it('returns false when sending email with invalid user id', function (): void {
    $email = wgp_email_boot();

    Monkey\Functions\when('is_email')->justReturn(true);

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 0);

    expect($result)->toBeFalse();
});

it('returns false when user is not found', function (): void {
    $email = wgp_email_boot();

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn(false);
    Monkey\Functions\when('wc_get_order')->justReturn(false);

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 999);

    expect($result)->toBeFalse();
});

it('returns false when order is not found', function (): void {
    $email = wgp_email_boot();
    $mockUser = wgp_email_mock_user();

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn($mockUser);
    Monkey\Functions\when('wc_get_order')->justReturn(false);

    $result = $email->send_payment_email('test@example.com', 'token123', 999, 1);

    expect($result)->toBeFalse();
});

it('sends payment email with valid data', function (): void {
    $email = wgp_email_boot();
    $mockUser = wgp_email_mock_user();
    $mockOrder = wgp_email_mock_order_with_methods();

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn($mockUser);
    Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
    Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
    Monkey\Functions\when('wp_date')->justReturn('2025-01-15');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('wp_mail')->justReturn(true);

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 1);

    expect($result)->toBeTrue();
});

it('returns false when wp_mail fails', function (): void {
    $email = wgp_email_boot();
    $mockUser = wgp_email_mock_user();
    $mockOrder = wgp_email_mock_order_with_methods();

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn($mockUser);
    Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
    Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
    Monkey\Functions\when('wp_date')->justReturn('2025-01-15');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('wp_mail')->justReturn(false);

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 1);

    expect($result)->toBeFalse();
});
