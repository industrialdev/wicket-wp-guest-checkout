<?php

declare(strict_types=1);

use Brain\Monkey;

function wgp_core_store_boot(): WicketGuestPaymentCore
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

    return new WicketGuestPaymentCore();
}

function wgp_core_store_mock_order(): object
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

        public function update_meta_data(string $key, $value): void {}

        public function add_order_note(string $note): void {}

        public function save(): int
        {
            return 1;
        }

        public function get_meta(string $key)
        {
            return 'some_hash';
        }
    };
}

function wgp_core_store_mock_order_with_save(): object
{
    return new class {
        public int $saveCount = 0;

        public function get_id(): int
        {
            return 1;
        }

        public function get_type(): string
        {
            return 'shop_order';
        }

        public function update_meta_data(string $key, $value): void {}

        public function add_order_note(string $note): void {}

        public function save(): int
        {
            $this->saveCount++;

            return 1;
        }

        public function get_meta(string $key)
        {
            return 'some_hash';
        }

        public function meta_exists(string $key): bool
        {
            return true;
        }
    };
}

function wgp_core_store_mock_subscription_order_with_save(): object
{
    return new class {
        public int $saveCount = 0;

        public function get_id(): int
        {
            return 1;
        }

        public function get_type(): string
        {
            return 'shop_subscription';
        }

        public function update_meta_data(string $key, $value): void {}

        public function add_order_note(string $note): void {}

        public function save(): int
        {
            $this->saveCount++;

            return 1;
        }

        public function get_meta(string $key)
        {
            return 'some_hash';
        }

        public function meta_exists(string $key): bool
        {
            return true;
        }
    };
}

it('returns false when order not found', function (): void {
    $core = wgp_core_store_boot();

    Monkey\Functions\stubs([
        'wc_get_order' => false,
    ]);

    $result = $core->store_token_data(999, 'test_token', 1, 'test@example.com', 'email');

    expect($result)->toBeFalse();
});

it('returns false for empty token', function (): void {
    $core = wgp_core_store_boot();
    $mockOrder = wgp_core_store_mock_order();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
        'is_email' => false,
    ]);

    $result = $core->store_token_data(1, '', 1, 'test@example.com', 'email');

    expect($result)->toBeFalse();
});

it('returns false for invalid email', function (): void {
    $core = wgp_core_store_boot();
    $mockOrder = wgp_core_store_mock_order();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
        'is_email' => false,
    ]);

    $result = $core->store_token_data(1, 'valid_token', 1, 'invalid-email', 'email');

    expect($result)->toBeFalse();
});

it('returns false for invalid user id', function (): void {
    $core = wgp_core_store_boot();
    $mockOrder = wgp_core_store_mock_order();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
        'is_email' => true,
    ]);

    $result = $core->store_token_data(1, 'valid_token', 0, 'test@example.com', 'email');

    expect($result)->toBeFalse();
});

it('stores token data for valid inputs', function (): void {
    $core = wgp_core_store_boot();
    $mockOrder = wgp_core_store_mock_order_with_save();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
        'is_email' => true,
    ]);

    $result = $core->store_token_data(1, 'valid_token_123456789', 1, 'test@example.com', 'email');

    expect($result)->toBeTrue();
});

it('handles subscription orders', function (): void {
    $core = wgp_core_store_boot();
    $mockOrder = wgp_core_store_mock_subscription_order_with_save();

    Monkey\Functions\stubs([
        'wc_get_order' => $mockOrder,
        'is_email' => true,
        'get_post_meta' => 'some_hash',
        'update_post_meta' => true,
    ]);

    $result = $core->store_token_data(1, 'valid_token_123456789', 1, 'test@example.com', 'manual');

    expect($result)->toBeTrue();
});
