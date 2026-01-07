<?php

declare(strict_types=1);

use Brain\Monkey;

function wgp_core_token_boot(): WicketGuestPaymentCore
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

    global $wpdb;
    $wpdb = new class {
        public $prefix = 'wp_';
        public $options = 'wp_options';
        public function get_col($query)
        {
            return [];
        }
        public function prepare($query, ...$args)
        {
            return $query;
        }
        public function esc_like($text)
        {
            return addcslashes($text, '_%\\');
        }
    };
    $GLOBALS['wpdb'] = $wpdb;

    return new WicketGuestPaymentCore();
}

function wgp_core_token_mock_order(): object
{
    return new class {
        public function get_id(): int
        {
            return 1;
        }
        public function get_type(): string
        {
            return 'shop_order';
        }
        public function get_customer_id(): int
        {
            return 1;
        }
        public function get_meta(string $key)
        {
            return null;
        }
        public function meta_exists(string $key): bool
        {
            return false;
        }
        public function update_meta_data(string $key, $value): void {}
        public function add_order_note(string $note): void {}
        public function save(): int
        {
            return 1;
        }
    };
}

function wgp_core_token_mock_order_with_meta(): object
{
    return new class {
        public int $saveCount = 0;
        private array $deletedMeta = [];

        public function get_id(): int
        {
            return 1;
        }
        public function get_type(): string
        {
            return 'shop_order';
        }
        public function get_customer_id(): int
        {
            return 1;
        }
        public function get_user_id(): int
        {
            return 1;
        }
        public function get_meta(string $key)
        {
            if ($key === '_wgp_guest_payment_user_id') {
                return 1;
            }
            return 'some_value';
        }
        public function update_meta_data(string $key, $value): void {}
        public function delete_meta_data(string $key): void
        {
            $this->deletedMeta[$key] = true;
        }
        public function meta_exists(string $key): bool
        {
            return !isset($this->deletedMeta[$key]);
        }
        public function add_order_note(string $note): void {}
        public function save(): int
        {
            $this->saveCount++;
            return 1;
        }
    };
}

function wgp_core_token_mock_order_no_meta(): object
{
    return new class {
        public function get_id(): int
        {
            return 1;
        }
        public function get_type(): string
        {
            return 'shop_order';
        }
        public function get_user_id(): int
        {
            return 1;
        }
        public function meta_exists(string $key): bool
        {
            return false;
        }
        public function get_meta(string $key, bool $single = false)
        {
            return '';
        }
    };
}

it('returns false for invalid order', function (): void {
    $core = wgp_core_token_boot();

    Monkey\Functions\stubs([
        'wc_get_order' => false,
    ]);

    $result = $core->generate_token_for_order(999, 'test@example.com');

    expect($result)->toBeFalse();
});

it('returns false for invalid email', function (): void {
    $core = wgp_core_token_boot();
    $mockOrder = wgp_core_token_mock_order();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
        'is_email' => false,
    ]);

    $result = $core->generate_token_for_order(1, 'invalid-email');

    expect($result)->toBeFalse();
});

it('generates token for valid inputs', function (): void {
    $core = wgp_core_token_boot();
    $mockOrder = wgp_core_token_mock_order_with_meta();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
        'is_email' => true,
    ]);

    $result = $core->generate_token_for_order(1, 'test@example.com', 'manual');

    expect($result)->toBeString();
    expect(strlen($result))->toBe(64);
});

it('allows empty email for manual generation', function (): void {
    $core = wgp_core_token_boot();
    $mockOrder = wgp_core_token_mock_order();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
    ]);

    $result = $core->generate_token_for_order(1, '', 'manual');

    expect($result)->toBeString();
    expect(strlen($result))->toBe(64);
});

it('returns false when invalidating token for missing order', function (): void {
    $core = wgp_core_token_boot();

    Monkey\Functions\stubs([
        'wc_get_order' => false,
    ]);

    $result = $core->invalidate_token_for_order(999);

    expect($result)->toBeFalse();
});

it('returns true when no token data exists', function (): void {
    $core = wgp_core_token_boot();
    $mockOrder = wgp_core_token_mock_order_no_meta();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
    ]);

    $result = $core->invalidate_token_for_order(1);

    expect($result)->toBeTrue();
});

it('removes token data when present', function (): void {
    $core = wgp_core_token_boot();
    $mockOrder = wgp_core_token_mock_order_with_meta();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
    ]);

    $result = $core->invalidate_token_for_order(1);

    expect($result)->toBeTrue();
});

it('returns null when getting valid token data with missing order', function (): void {
    $core = wgp_core_token_boot();

    Monkey\Functions\stubs([
        'wc_get_order' => false,
    ]);

    $result = $core->get_valid_token_data(999);

    expect($result)->toBeNull();
});

it('returns null when no token exists', function (): void {
    $core = wgp_core_token_boot();
    $mockOrder = wgp_core_token_mock_order_no_meta();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
    ]);

    $result = $core->get_valid_token_data(1);

    expect($result)->toBeNull();
});

it('returns null for expired token', function (): void {
    $core = wgp_core_token_boot();

    $mockOrder = new class ($core) {
        private string $encrypted;

        public function __construct(WicketGuestPaymentCore $core)
        {
            $token = $core->generate_token();
            $reflection = new ReflectionClass($core);
            $method = $reflection->getMethod('encrypt_data');
            $this->encrypted = $method->invoke($core, $token);
        }

        public function get_id(): int
        {
            return 1;
        }
        public function get_type(): string
        {
            return 'shop_order';
        }
        public function get_user_id(): int
        {
            return 1;
        }
        public function meta_exists(string $key): bool
        {
            return true;
        }
        public function get_meta(string $key, bool $single = false)
        {
            if ($key === '_wgp_guest_payment_token_encrypted') {
                return $this->encrypted;
            }
            if ($key === '_wgp_guest_payment_token_created') {
                return time() - (8 * 86400);
            }
            if ($key === '_wgp_guest_payment_email') {
                return 'test@example.com';
            }
            if ($key === '_wgp_guest_payment_user_id') {
                return 1;
            }
            if ($key === '_wgp_guest_payment_generation_method') {
                return 'email';
            }
            return '';
        }
    };

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
    ]);

    $result = $core->get_valid_token_data(1);

    expect($result)->toBeNull();
});

it('returns data for valid token', function (): void {
    $core = wgp_core_token_boot();

    $mockOrder = new class ($core) {
        private string $encrypted;

        public function __construct(WicketGuestPaymentCore $core)
        {
            $token = $core->generate_token();
            $reflection = new ReflectionClass($core);
            $method = $reflection->getMethod('encrypt_data');
            $this->encrypted = $method->invoke($core, $token);
        }

        public function get_id(): int
        {
            return 1;
        }
        public function get_type(): string
        {
            return 'shop_order';
        }
        public function get_user_id(): int
        {
            return 1;
        }
        public function meta_exists(string $key): bool
        {
            return true;
        }
        public function get_meta(string $key, bool $single = false)
        {
            if ($key === '_wgp_guest_payment_token_encrypted') {
                return $this->encrypted;
            }
            if ($key === '_wgp_guest_payment_token_created') {
                return time();
            }
            if ($key === '_wgp_guest_payment_email') {
                return 'test@example.com';
            }
            if ($key === '_wgp_guest_payment_user_id') {
                return 1;
            }
            if ($key === '_wgp_guest_payment_generation_method') {
                return 'email';
            }
            return '';
        }
    };

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
    ]);

    $result = $core->get_valid_token_data(1);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['token', 'guest_email', 'user_id', 'created_timestamp', 'generation_method']);
    expect($result['guest_email'])->toBe('test@example.com');
    expect($result['user_id'])->toBe(1);
    expect($result['generation_method'])->toBe('email');
});

it('returns null when decryption fails', function (): void {
    $core = wgp_core_token_boot();

    $mockOrder = new class {
        public function get_id(): int
        {
            return 1;
        }
        public function get_type(): string
        {
            return 'shop_order';
        }
        public function get_user_id(): int
        {
            return 1;
        }
        public function meta_exists(string $key): bool
        {
            return true;
        }
        public function get_meta(string $key, bool $single = false)
        {
            if ($key === '_wgp_guest_payment_token_encrypted') {
                return 'invalid-encrypted-data!!!';
            }
            if ($key === '_wgp_guest_payment_token_created') {
                return time();
            }
            if ($key === '_wgp_guest_payment_email') {
                return 'test@example.com';
            }
            if ($key === '_wgp_guest_payment_user_id') {
                return 1;
            }
            return '';
        }
    };

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
    ]);

    $result = $core->get_valid_token_data(1);

    expect($result)->toBeNull();
});

it('returns null when user id missing', function (): void {
    $core = wgp_core_token_boot();

    $mockOrder = new class ($core) {
        private string $encrypted;

        public function __construct(WicketGuestPaymentCore $core)
        {
            $token = $core->generate_token();
            $reflection = new ReflectionClass($core);
            $method = $reflection->getMethod('encrypt_data');
            $this->encrypted = $method->invoke($core, $token);
        }

        public function get_id(): int
        {
            return 1;
        }
        public function get_type(): string
        {
            return 'shop_order';
        }
        public function get_user_id(): int
        {
            return 0;
        }
        public function meta_exists(string $key): bool
        {
            return true;
        }
        public function get_meta(string $key, bool $single = false)
        {
            if ($key === '_wgp_guest_payment_token_encrypted') {
                return $this->encrypted;
            }
            if ($key === '_wgp_guest_payment_token_created') {
                return time();
            }
            if ($key === '_wgp_guest_payment_email') {
                return 'test@example.com';
            }
            if ($key === '_wgp_guest_payment_user_id') {
                return 0;
            }
            return '';
        }
    };

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
    ]);

    $result = $core->get_valid_token_data(1);

    expect($result)->toBeNull();
});
