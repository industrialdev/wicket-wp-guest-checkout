<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentCore;
use Brain\Monkey;

#[CoversClass(WicketGuestPaymentCore::class)]
class WicketGuestPaymentCoreStoreTokenTest extends AbstractTestCase
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

        $this->core = new WicketGuestPaymentCore();
    }

    protected function tearDown(): void
    {
        $this->core = null;
        parent::tearDown();
    }

    public function test_store_token_data_returns_false_when_order_not_found(): void
    {
        Monkey\Functions\stubs([
            'wc_get_order' => false,
        ]);

        $result = $this->core->store_token_data(999, 'test_token', 1, 'test@example.com', 'email');

        $this->assertFalse($result);
    }

    public function test_store_token_data_returns_false_for_empty_token(): void
    {
        $mockOrder = $this->createMockOrder();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
            'is_email' => false,
        ]);

        $result = $this->core->store_token_data(1, '', 1, 'test@example.com', 'email');

        $this->assertFalse($result);
    }

    public function test_store_token_data_returns_false_for_invalid_email(): void
    {
        $mockOrder = $this->createMockOrder();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
            'is_email' => false,
        ]);

        $result = $this->core->store_token_data(1, 'valid_token', 1, 'invalid-email', 'email');

        $this->assertFalse($result);
    }

    public function test_store_token_data_returns_false_for_invalid_user_id(): void
    {
        $mockOrder = $this->createMockOrder();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
            'is_email' => true,
        ]);

        $result = $this->core->store_token_data(1, 'valid_token', 0, 'test@example.com', 'email');

        $this->assertFalse($result);
    }

    public function test_store_token_data_succeeds_with_valid_inputs(): void
    {
        $mockOrder = $this->createMockOrderWithSave();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
            'is_email' => true,
        ]);

        $result = $this->core->store_token_data(1, 'valid_token_123456789', 1, 'test@example.com', 'email');

        $this->assertTrue($result);
    }

    public function test_store_token_data_handles_subscription_orders(): void
    {
        $mockOrder = $this->createMockSubscriptionOrderWithSave();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
            'is_email' => true,
            'get_post_meta' => 'some_hash',
            'update_post_meta' => true,
        ]);

        $result = $this->core->store_token_data(1, 'valid_token_123456789', 1, 'test@example.com', 'manual');

        $this->assertTrue($result);
    }

    private function createMockOrder(): object
    {
        return new class {
            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function update_meta_data(string $key, $value): void {}
            public function add_order_note(string $note): void {}
            public function save(): int { return 1; }
            public function get_meta(string $key) { return 'some_hash'; }
        };
    }

    private function createMockOrderWithSave(): object
    {
        return new class {
            public int $saveCount = 0;
            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function update_meta_data(string $key, $value): void {}
            public function add_order_note(string $note): void {}
            public function save(): int {
                $this->saveCount++;
                return 1;
            }
            public function get_meta(string $key) { return 'some_hash'; }
            public function meta_exists(string $key): bool { return true; }
        };
    }

    private function createMockSubscriptionOrderWithSave(): object
    {
        return new class {
            public int $saveCount = 0;
            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_subscription'; }
            public function update_meta_data(string $key, $value): void {}
            public function add_order_note(string $note): void {}
            public function save(): int {
                $this->saveCount++;
                return 1;
            }
            public function get_meta(string $key) { return 'some_hash'; }
            public function meta_exists(string $key): bool { return true; }
        };
    }
}
