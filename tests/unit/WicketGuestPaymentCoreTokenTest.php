<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentCore;
use Brain\Monkey;

#[CoversClass(WicketGuestPaymentCore::class)]
class WicketGuestPaymentCoreTokenTest extends AbstractTestCase
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

        // Stub global $wpdb
        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';
            public $options = 'wp_options';
            public function get_col($query) { return []; }
            public function prepare($query, ...$args) { return $query; }
            public function esc_like($text) { return addcslashes($text, '_%\\'); }
        };
        $GLOBALS['wpdb'] = $wpdb;

        $this->core = new WicketGuestPaymentCore();
    }

    protected function tearDown(): void
    {
        $this->core = null;
        parent::tearDown();
    }

    public function test_generate_token_for_order_returns_false_for_invalid_order(): void
    {
        Monkey\Functions\stubs([
            'wc_get_order' => false,
        ]);

        $result = $this->core->generate_token_for_order(999, 'test@example.com');

        $this->assertFalse($result);
    }

    public function test_generate_token_for_order_returns_false_for_invalid_email(): void
    {
        $mockOrder = $this->createMockOrder();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
            'is_email' => false,
        ]);

        $result = $this->core->generate_token_for_order(1, 'invalid-email');

        $this->assertFalse($result);
    }

    public function test_generate_token_for_order_succeeds_with_valid_inputs(): void
    {
        $mockOrder = $this->createMockOrderWithMeta();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
            'is_email' => true,
        ]);

        $result = $this->core->generate_token_for_order(1, 'test@example.com', 'manual');

        $this->assertIsString($result);
        $this->assertEquals(64, strlen($result));
    }

    public function test_generate_token_for_order_allows_empty_email_for_manual(): void
    {
        $mockOrder = $this->createMockOrder();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
        ]);

        // Empty email should be allowed for 'manual' generation
        $result = $this->core->generate_token_for_order(1, '', 'manual');

        $this->assertIsString($result);
        $this->assertEquals(64, strlen($result));
    }

    public function test_invalidate_token_for_order_returns_false_when_order_not_found(): void
    {
        Monkey\Functions\stubs([
            'wc_get_order' => false,
        ]);

        $result = $this->core->invalidate_token_for_order(999);

        $this->assertFalse($result);
    }

    public function test_invalidate_token_for_order_returns_true_when_no_token_data_exists(): void
    {
        $mockOrder = $this->createMockOrderNoMeta();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
        ]);

        $result = $this->core->invalidate_token_for_order(1);

        $this->assertTrue($result);
    }

    public function test_invalidate_token_for_order_successfully_removes_token_data(): void
    {
        $mockOrder = $this->createMockOrderWithMeta();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
        ]);

        $result = $this->core->invalidate_token_for_order(1);

        $this->assertTrue($result);
    }

    private function createMockOrder(): object
    {
        return new class {
            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function get_customer_id(): int { return 1; }
            public function get_meta(string $key) { return null; }
            public function meta_exists(string $key): bool { return false; }
            public function update_meta_data(string $key, $value): void {}
            public function add_order_note(string $note): void {}
            public function save(): int { return 1; }
        };
    }

    private function createMockOrderWithMeta(): object
    {
        return new class {
            public int $saveCount = 0;
            private array $deletedMeta = [];

            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function get_customer_id(): int { return 1; }
            public function get_user_id(): int { return 1; }
            public function get_meta(string $key) {
                if ($key === '_wgp_guest_payment_user_id') {
                    return 1;
                }
                return 'some_value';
            }
            public function update_meta_data(string $key, $value): void {}
            public function delete_meta_data(string $key): void {
                $this->deletedMeta[$key] = true;
            }
            public function meta_exists(string $key): bool {
                return !isset($this->deletedMeta[$key]);
            }
            public function add_order_note(string $note): void {}
            public function save(): int {
                $this->saveCount++;
                return 1;
            }
        };
    }

    private function createMockOrderNoMeta(): object
    {
        return new class {
            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function get_user_id(): int { return 1; }
            public function meta_exists(string $key): bool { return false; }
            public function get_meta(string $key, bool $single = false) { return ''; }
        };
    }

    public function test_get_valid_token_data_returns_null_when_order_not_found(): void
    {
        Monkey\Functions\stubs([
            'wc_get_order' => false,
        ]);

        $result = $this->core->get_valid_token_data(999);

        $this->assertNull($result);
    }

    public function test_get_valid_token_data_returns_null_when_no_token_exists(): void
    {
        $mockOrder = $this->createMockOrderNoMeta();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
        ]);

        $result = $this->core->get_valid_token_data(1);

        $this->assertNull($result);
    }

    public function test_get_valid_token_data_returns_null_when_token_expired(): void
    {
        $mockOrder = $this->createMockOrderWithExpiredToken();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
        ]);

        // Don't pass the order - let it be fetched internally to avoid type hint issues
        $result = $this->core->get_valid_token_data(1);

        $this->assertNull($result);
    }

    public function test_get_valid_token_data_returns_data_for_valid_token(): void
    {
        $mockOrder = $this->createMockOrderWithValidToken();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
        ]);

        // Don't pass the order - let it be fetched internally
        $result = $this->core->get_valid_token_data(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('guest_email', $result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('created_timestamp', $result);
        $this->assertArrayHasKey('generation_method', $result);
        $this->assertEquals('test@example.com', $result['guest_email']);
        $this->assertEquals(1, $result['user_id']);
        $this->assertEquals('email', $result['generation_method']);
    }

    public function test_get_valid_token_data_returns_null_when_decryption_fails(): void
    {
        $mockOrder = $this->createMockOrderWithInvalidEncryption();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
        ]);

        // Don't pass the order - let it be fetched internally
        $result = $this->core->get_valid_token_data(1);

        $this->assertNull($result);
    }

    public function test_get_valid_token_data_returns_null_when_user_id_missing(): void
    {
        $mockOrder = $this->createMockOrderWithoutUserId();

        Monkey\Functions\stubs([
            'wc_get_order' => $mockOrder,
        ]);

        // Don't pass the order - let it be fetched internally
        $result = $this->core->get_valid_token_data(1);

        $this->assertNull($result);
    }

    private function createMockOrderWithExpiredToken(): object
    {
        // Create encrypted token that's valid
        $token = $this->core->generate_token();
        $reflection = new \ReflectionClass($this->core);
        $method = $reflection->getMethod('encrypt_data');
        $encryptedToken = $method->invoke($this->core, $token);

        return new class($encryptedToken) {
            private $encrypted;

            public function __construct($encrypted)
            {
                $this->encrypted = $encrypted;
            }

            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function get_user_id(): int { return 1; }
            public function meta_exists(string $key): bool { return true; }
            public function get_meta(string $key, bool $single = false) {
                if ($key === '_wgp_guest_payment_token_encrypted') {
                    return $this->encrypted;
                }
                if ($key === '_wgp_guest_payment_token_created') {
                    return time() - (8 * 86400); // 8 days ago - expired
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
    }

    private function createMockOrderWithValidToken(): object
    {
        $token = $this->core->generate_token();
        $reflection = new \ReflectionClass($this->core);
        $method = $reflection->getMethod('encrypt_data');
        $encryptedToken = $method->invoke($this->core, $token);

        return new class($encryptedToken) {
            private $encrypted;

            public function __construct($encrypted)
            {
                $this->encrypted = $encrypted;
            }

            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function get_user_id(): int { return 1; }
            public function meta_exists(string $key): bool { return true; }
            public function get_meta(string $key, bool $single = false) {
                if ($key === '_wgp_guest_payment_token_encrypted') {
                    return $this->encrypted;
                }
                if ($key === '_wgp_guest_payment_token_created') {
                    return time(); // Current time - valid
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
    }

    private function createMockOrderWithInvalidEncryption(): object
    {
        return new class {
            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function get_user_id(): int { return 1; }
            public function meta_exists(string $key): bool { return true; }
            public function get_meta(string $key, bool $single = false) {
                if ($key === '_wgp_guest_payment_token_encrypted') {
                    return 'invalid-encrypted-data!!!'; // Invalid base64
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
    }

    private function createMockOrderWithoutUserId(): object
    {
        $token = $this->core->generate_token();
        $reflection = new \ReflectionClass($this->core);
        $method = $reflection->getMethod('encrypt_data');
        $encryptedToken = $method->invoke($this->core, $token);

        return new class($encryptedToken) {
            private $encrypted;

            public function __construct($encrypted)
            {
                $this->encrypted = $encrypted;
            }

            public function get_id(): int { return 1; }
            public function get_type(): string { return 'shop_order'; }
            public function get_user_id(): int { return 0; } // No user ID
            public function meta_exists(string $key): bool { return true; }
            public function get_meta(string $key, bool $single = false) {
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
                    return 0; // No user ID
                }
                return '';
            }
        };
    }
}
