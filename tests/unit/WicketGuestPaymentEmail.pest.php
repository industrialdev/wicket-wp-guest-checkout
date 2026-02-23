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
    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/cart?guest_payment_token=token123');
    Monkey\Functions\when('wp_date')->justReturn('2025-01-15');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('get_bloginfo')->justReturn('Example Site');
    Monkey\Functions\when('get_option')->alias(function (string $name, $default = '') {
        if ($name === 'date_format') {
            return 'Y-m-d';
        }
        if ($name === 'admin_email') {
            return 'admin@example.com';
        }

        return $default;
    });
    Monkey\Functions\when('apply_filters')->alias(fn (string $hook, $value, ...$args) => $value);
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
    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/cart?guest_payment_token=token123');
    Monkey\Functions\when('wp_date')->justReturn('2025-01-15');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('get_bloginfo')->justReturn('Example Site');
    Monkey\Functions\when('get_option')->alias(function (string $name, $default = '') {
        if ($name === 'date_format') {
            return 'Y-m-d';
        }
        if ($name === 'admin_email') {
            return 'admin@example.com';
        }

        return $default;
    });
    Monkey\Functions\when('apply_filters')->alias(fn (string $hook, $value, ...$args) => $value);
    Monkey\Functions\when('wp_mail')->justReturn(false);

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 1);

    expect($result)->toBeFalse();
});

it('uses wicket settings templates and keeps full html body markup', function (): void {
    $email = wgp_email_boot();
    $mockUser = wgp_email_mock_user();
    $mockOrder = wgp_email_mock_order_with_methods();

    $captured_subject = '';
    $captured_message = '';

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn($mockUser);
    Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
    Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/cart?guest_payment_token=token123');
    Monkey\Functions\when('wp_date')->justReturn('2026-02-23');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('get_bloginfo')->justReturn('Example Site');
    Monkey\Functions\when('get_option')->alias(function (string $name, $default = '') {
        if ($name === 'wicket_settings') {
            return [
                'wicket_admin_settings_guest_payment_email_subject_template' => 'Subject for {member_name} / {site_name}',
                'wicket_admin_settings_guest_payment_email_body_template' => '<table><tr><td>{member_name}</td><td>{payment_link}</td></tr></table>',
            ];
        }
        if ($name === 'date_format') {
            return 'Y-m-d';
        }
        if ($name === 'admin_email') {
            return 'admin@example.com';
        }

        return $default;
    });
    Monkey\Functions\when('apply_filters')->alias(fn (string $hook, $value, ...$args) => $value);
    Monkey\Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers) use (&$captured_subject, &$captured_message): bool {
        $captured_subject = (string) $subject;
        $captured_message = (string) $message;

        return true;
    });

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 1);

    expect($result)->toBeTrue();
    expect($captured_subject)->toBe('Subject for John Doe / Example Site');
    expect($captured_message)->toContain('<table><tr><td>John Doe</td><td><a href="https://example.com/cart?guest_payment_token=token123">Complete Payment</a></td></tr></table>');
});

it('falls back to legacy template options when wicket settings templates are absent', function (): void {
    $email = wgp_email_boot();
    $mockUser = wgp_email_mock_user();
    $mockOrder = wgp_email_mock_order_with_methods();

    $captured_subject = '';

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn($mockUser);
    Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
    Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/cart?guest_payment_token=token123');
    Monkey\Functions\when('wp_date')->justReturn('2026-02-23');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('get_bloginfo')->justReturn('Example Site');
    Monkey\Functions\when('get_option')->alias(function (string $name, $default = '') {
        if ($name === 'wicket_settings') {
            return [];
        }
        if ($name === 'wicket_guest_payment_email_subject_template') {
            return 'Legacy {site_name}';
        }
        if ($name === 'wicket_guest_payment_email_body_template') {
            return '<p>Legacy body for {member_name}</p>';
        }
        if ($name === 'date_format') {
            return 'Y-m-d';
        }
        if ($name === 'admin_email') {
            return 'admin@example.com';
        }

        return $default;
    });
    Monkey\Functions\when('apply_filters')->alias(fn (string $hook, $value, ...$args) => $value);
    Monkey\Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers) use (&$captured_subject): bool {
        $captured_subject = (string) $subject;

        return true;
    });

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 1);

    expect($result)->toBeTrue();
    expect($captured_subject)->toBe('Legacy Example Site');
});

it('sanitizes html when sanitize filter is enabled', function (): void {
    $email = wgp_email_boot();
    $mockUser = wgp_email_mock_user();
    $mockOrder = wgp_email_mock_order_with_methods();

    $captured_message = '';

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn($mockUser);
    Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
    Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/cart?guest_payment_token=token123');
    Monkey\Functions\when('wp_date')->justReturn('2026-02-23');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('get_bloginfo')->justReturn('Example Site');
    Monkey\Functions\when('get_option')->alias(function (string $name, $default = '') {
        if ($name === 'wicket_settings') {
            return [
                'wicket_admin_settings_guest_payment_email_body_template' => '<p>Safe</p><script>alert(1)</script>',
            ];
        }
        if ($name === 'date_format') {
            return 'Y-m-d';
        }
        if ($name === 'admin_email') {
            return 'admin@example.com';
        }

        return $default;
    });
    Monkey\Functions\when('apply_filters')->alias(function (string $hook, $value, ...$args) {
        if ($hook === 'wicket_guest_payment_email_sanitize_html') {
            return true;
        }

        if ($hook === 'wicket_guest_payment_email_allowed_html') {
            return ['p' => []];
        }

        return $value;
    });
    Monkey\Functions\when('wp_kses_allowed_html')->justReturn(['p' => []]);
    Monkey\Functions\when('wp_kses')->alias(fn (string $html, array $allowed) => str_replace('<script>alert(1)</script>', '', $html));
    Monkey\Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers) use (&$captured_message): bool {
        $captured_message = (string) $message;

        return true;
    });

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 1);

    expect($result)->toBeTrue();
    expect($captured_message)->not->toContain('<script>alert(1)</script>');
    expect($captured_message)->toContain('<p>Safe</p>');
});

it('allows email header customization via filter', function (): void {
    $email = wgp_email_boot();
    $mockUser = wgp_email_mock_user();
    $mockOrder = wgp_email_mock_order_with_methods();

    $captured_headers = [];

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn($mockUser);
    Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
    Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/cart?guest_payment_token=token123');
    Monkey\Functions\when('wp_date')->justReturn('2026-02-23');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('get_bloginfo')->justReturn('Example Site');
    Monkey\Functions\when('get_option')->alias(function (string $name, $default = '') {
        if ($name === 'wicket_settings') {
            return [];
        }
        if ($name === 'date_format') {
            return 'Y-m-d';
        }
        if ($name === 'admin_email') {
            return 'admin@example.com';
        }

        return $default;
    });
    Monkey\Functions\when('apply_filters')->alias(function (string $hook, $value, ...$args) {
        if ($hook === 'wicket_guest_payment_email_headers') {
            $value[] = 'Reply-To: billing@example.com';

            return $value;
        }

        return $value;
    });
    Monkey\Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers) use (&$captured_headers): bool {
        $captured_headers = $headers;

        return true;
    });

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 1);

    expect($result)->toBeTrue();
    expect($captured_headers)->toContain('Reply-To: billing@example.com');
});

it('allows subject and content customization via filters and replaces payment_url placeholder', function (): void {
    $email = wgp_email_boot();
    $mockUser = wgp_email_mock_user();
    $mockOrder = wgp_email_mock_order_with_methods();

    $captured_subject = '';
    $captured_message = '';

    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('get_userdata')->justReturn($mockUser);
    Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
    Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
    Monkey\Functions\when('add_query_arg')->justReturn('https://example.com/cart?guest_payment_token=token123');
    Monkey\Functions\when('wp_date')->justReturn('2026-02-23');
    Monkey\Functions\when('get_theme_mod')->justReturn(false);
    Monkey\Functions\when('get_bloginfo')->justReturn('Example Site');
    Monkey\Functions\when('get_option')->alias(function (string $name, $default = '') {
        if ($name === 'wicket_settings') {
            return [
                'wicket_admin_settings_guest_payment_email_subject_template' => 'Raw {site_name}',
                'wicket_admin_settings_guest_payment_email_body_template' => '<p>URL: {payment_url}</p>',
            ];
        }
        if ($name === 'date_format') {
            return 'Y-m-d';
        }
        if ($name === 'admin_email') {
            return 'admin@example.com';
        }

        return $default;
    });
    Monkey\Functions\when('apply_filters')->alias(function (string $hook, $value, ...$args) {
        if ($hook === 'wicket_guest_payment_email_subject') {
            return 'Filtered Subject';
        }

        if ($hook === 'wicket_guest_payment_email_content') {
            return '<div class="custom-wrapper">' . (string) $value . '</div>';
        }

        return $value;
    });
    Monkey\Functions\when('wp_mail')->alias(function ($to, $subject, $message, $headers) use (&$captured_subject, &$captured_message): bool {
        $captured_subject = (string) $subject;
        $captured_message = (string) $message;

        return true;
    });

    $result = $email->send_payment_email('test@example.com', 'token123', 1, 1);

    expect($result)->toBeTrue();
    expect($captured_subject)->toBe('Filtered Subject');
    expect($captured_message)->toContain('<div class="custom-wrapper"><p>URL: https://example.com/cart?guest_payment_token=token123</p></div>');
});
