<?php

declare(strict_types=1);

function wgp_core_encryption_boot(): WicketGuestPaymentCore
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

function wgp_core_encryption_invoke(WicketGuestPaymentCore $core, string $methodName, array $args = [])
{
    $reflection = new ReflectionClass($core);
    $method = $reflection->getMethod($methodName);

    return $method->invokeArgs($core, $args);
}

it('encrypts data into base64 string', function (): void {
    $core = wgp_core_encryption_boot();
    $testData = 'test sensitive data';

    $result = wgp_core_encryption_invoke($core, 'encrypt_data', [$testData]);

    expect($result)->toBeString()->not->toBeEmpty();
    $decoded = base64_decode($result, true);
    expect($decoded)->not->toBeFalse();
    expect(strlen($decoded))->toBeGreaterThan(16);
});

it('encrypts data with defined key', function (): void {
    $core = wgp_core_encryption_boot();

    $result = wgp_core_encryption_invoke($core, 'encrypt_data', ['test data']);

    expect($result)->not->toBeFalse();
});

it('decrypts encrypted data', function (): void {
    $core = wgp_core_encryption_boot();
    $originalData = 'This is secret data to encrypt and decrypt';

    $encrypted = wgp_core_encryption_invoke($core, 'encrypt_data', [$originalData]);

    expect($encrypted)->not->toBeFalse();

    $decrypted = wgp_core_encryption_invoke($core, 'decrypt_data', [$encrypted]);

    expect($decrypted)->toBe($originalData);
});

it('returns false for invalid base64', function (): void {
    $core = wgp_core_encryption_boot();

    $result = wgp_core_encryption_invoke($core, 'decrypt_data', ['not-valid-base64!!!']);

    expect($result)->toBeFalse();
});

it('returns false for tampered data', function (): void {
    $core = wgp_core_encryption_boot();
    $testData = 'test data';

    $encrypted = wgp_core_encryption_invoke($core, 'encrypt_data', [$testData]);
    $tamperedData = substr($encrypted, 0, -5) . 'XXXXX';

    $result = wgp_core_encryption_invoke($core, 'decrypt_data', [$tamperedData]);

    expect($result)->toBeFalse();
});

it('encrypts and decrypts special characters', function (): void {
    $core = wgp_core_encryption_boot();
    $specialData = "Special chars: !@#$%^&*()_+-=[]{}|;':\",./<>?`~\n\t";

    $encrypted = wgp_core_encryption_invoke($core, 'encrypt_data', [$specialData]);

    expect($encrypted)->not->toBeFalse();

    $decrypted = wgp_core_encryption_invoke($core, 'decrypt_data', [$encrypted]);

    expect($decrypted)->toBe($specialData);
});

it('encrypts and decrypts unicode characters', function (): void {
    $core = wgp_core_encryption_boot();
    $unicodeData = 'Unicode: 你好世界 🌍 Привет мир';

    $encrypted = wgp_core_encryption_invoke($core, 'encrypt_data', [$unicodeData]);

    expect($encrypted)->not->toBeFalse();

    $decrypted = wgp_core_encryption_invoke($core, 'decrypt_data', [$encrypted]);

    expect($decrypted)->toBe($unicodeData);
});

it('encrypts and decrypts empty string', function (): void {
    $core = wgp_core_encryption_boot();

    $encrypted = wgp_core_encryption_invoke($core, 'encrypt_data', ['']);

    expect($encrypted)->not->toBeFalse();

    $decrypted = wgp_core_encryption_invoke($core, 'decrypt_data', [$encrypted]);

    expect($decrypted)->toBe('');
});

it('encrypts and decrypts long string', function (): void {
    $core = wgp_core_encryption_boot();
    $longData = str_repeat('A', 10000);

    $encrypted = wgp_core_encryption_invoke($core, 'encrypt_data', [$longData]);

    expect($encrypted)->not->toBeFalse();

    $decrypted = wgp_core_encryption_invoke($core, 'decrypt_data', [$encrypted]);

    expect($decrypted)->toBe($longData);
});

it('produces different output for same input', function (): void {
    $core = wgp_core_encryption_boot();
    $data = 'test data';

    $encrypted1 = wgp_core_encryption_invoke($core, 'encrypt_data', [$data]);
    $encrypted2 = wgp_core_encryption_invoke($core, 'encrypt_data', [$data]);

    $decrypted1 = wgp_core_encryption_invoke($core, 'decrypt_data', [$encrypted1]);
    $decrypted2 = wgp_core_encryption_invoke($core, 'decrypt_data', [$encrypted2]);

    expect($decrypted1)->toBe($data);
    expect($decrypted2)->toBe($data);
    expect($encrypted1)->not->toBe($encrypted2);
});

it('encrypts and decrypts json data', function (): void {
    $core = wgp_core_encryption_boot();
    $jsonData = json_encode([
        'user_id' => 123,
        'order_id' => 456,
        'items' => ['item1', 'item2'],
        'nested' => ['key' => 'value'],
    ]);

    $encrypted = wgp_core_encryption_invoke($core, 'encrypt_data', [$jsonData]);

    expect($encrypted)->not->toBeFalse();

    $decrypted = wgp_core_encryption_invoke($core, 'decrypt_data', [$encrypted]);

    expect($decrypted)->toBe($jsonData);
    $decoded = json_decode($decrypted, true);
    expect($decoded['user_id'])->toBe(123);
    expect($decoded['order_id'])->toBe(456);
});
