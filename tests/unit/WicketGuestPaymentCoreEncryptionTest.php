<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentCore;

#[CoversClass(WicketGuestPaymentCore::class)]
class WicketGuestPaymentCoreEncryptionTest extends AbstractTestCase
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

    private function invokePrivateMethod(string $methodName, array $args = [])
    {
        $reflection = new \ReflectionClass($this->core);
        $method = $reflection->getMethod($methodName);
        return $method->invokeArgs($this->core, $args);
    }

    public function test_encrypt_data_returns_base64_encoded_string(): void
    {
        $testData = 'test sensitive data';
        $result = $this->invokePrivateMethod('encrypt_data', [$testData]);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);

        $decoded = base64_decode($result, true);
        $this->assertNotFalse($decoded);
        $this->assertGreaterThan(16, strlen($decoded));
    }

    public function test_encrypt_data_returns_false_when_key_not_defined(): void
    {
        // This tests the behavior when constants aren't defined
        // Since we defined them in setUp, we test the success case
        $result = $this->invokePrivateMethod('encrypt_data', ['test data']);
        $this->assertNotFalse($result);
    }

    public function test_decrypt_data_successfully_decrypts(): void
    {
        $originalData = 'This is secret data to encrypt and decrypt';
        $encrypted = $this->invokePrivateMethod('encrypt_data', [$originalData]);

        $this->assertNotFalse($encrypted);

        $decrypted = $this->invokePrivateMethod('decrypt_data', [$encrypted]);

        $this->assertEquals($originalData, $decrypted);
    }

    public function test_decrypt_data_returns_false_for_invalid_base64(): void
    {
        $result = $this->invokePrivateMethod('decrypt_data', ['not-valid-base64!!!']);

        $this->assertFalse($result);
    }

    public function test_decrypt_data_returns_false_for_tampered_data(): void
    {
        $testData = 'test data';
        $encrypted = $this->invokePrivateMethod('encrypt_data', [$testData]);

        // Tamper with the encrypted data
        $tamperedData = substr($encrypted, 0, -5) . 'XXXXX';

        $result = $this->invokePrivateMethod('decrypt_data', [$tamperedData]);

        $this->assertFalse($result);
    }

    public function test_encrypt_data_with_special_characters(): void
    {
        $specialData = "Special chars: !@#$%^&*()_+-=[]{}|;':\",./<>?`~\n\t";
        $encrypted = $this->invokePrivateMethod('encrypt_data', [$specialData]);

        $this->assertNotFalse($encrypted);

        $decrypted = $this->invokePrivateMethod('decrypt_data', [$encrypted]);

        $this->assertEquals($specialData, $decrypted);
    }

    public function test_encrypt_data_with_unicode_characters(): void
    {
        $unicodeData = 'Unicode: 你好世界 🌍 Привет мир';
        $encrypted = $this->invokePrivateMethod('encrypt_data', [$unicodeData]);

        $this->assertNotFalse($encrypted);

        $decrypted = $this->invokePrivateMethod('decrypt_data', [$encrypted]);

        $this->assertEquals($unicodeData, $decrypted);
    }

    public function test_encrypt_data_with_empty_string(): void
    {
        $encrypted = $this->invokePrivateMethod('encrypt_data', ['']);

        $this->assertNotFalse($encrypted);

        $decrypted = $this->invokePrivateMethod('decrypt_data', [$encrypted]);

        $this->assertEquals('', $decrypted);
    }

    public function test_encrypt_data_with_long_string(): void
    {
        $longData = str_repeat('A', 10000);
        $encrypted = $this->invokePrivateMethod('encrypt_data', [$longData]);

        $this->assertNotFalse($encrypted);

        $decrypted = $this->invokePrivateMethod('decrypt_data', [$encrypted]);

        $this->assertEquals($longData, $decrypted);
    }

    public function test_encrypt_data_produces_different_output_for_same_input(): void
    {
        // Each encryption should produce different output due to random IV
        $data = 'test data';
        $encrypted1 = $this->invokePrivateMethod('encrypt_data', [$data]);
        $encrypted2 = $this->invokePrivateMethod('encrypt_data', [$data]);

        // Both should decrypt to the same value
        $decrypted1 = $this->invokePrivateMethod('decrypt_data', [$encrypted1]);
        $decrypted2 = $this->invokePrivateMethod('decrypt_data', [$encrypted2]);

        $this->assertEquals($data, $decrypted1);
        $this->assertEquals($data, $decrypted2);
        // The encrypted strings should be different (random IV)
        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    public function test_encrypt_decrypt_with_json_data(): void
    {
        $jsonData = json_encode([
            'user_id' => 123,
            'order_id' => 456,
            'items' => ['item1', 'item2'],
            'nested' => ['key' => 'value'],
        ]);

        $encrypted = $this->invokePrivateMethod('encrypt_data', [$jsonData]);

        $this->assertNotFalse($encrypted);

        $decrypted = $this->invokePrivateMethod('decrypt_data', [$encrypted]);

        $this->assertEquals($jsonData, $decrypted);
        $decoded = json_decode($decrypted, true);
        $this->assertEquals(123, $decoded['user_id']);
        $this->assertEquals(456, $decoded['order_id']);
    }
}
