<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use Wicket\GuestPayment\Tests\AbstractTestCase;
use WicketGuestPaymentCore;
use Mockery;

#[CoversClass(WicketGuestPaymentCore::class)]
class WicketGuestPaymentCoreValidateTokenTest extends AbstractTestCase
{
    public function test_validate_token_returns_false_on_empty_token(): void
    {
        $core = new WicketGuestPaymentCore();
        $this->assertFalse($core->validate_token(''));
    }

    public function test_validate_token_successful_with_order(): void
    {
        $token = 'valid-token';
        $key = 'test-key-32-chars-long-exactly-32';
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
            define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
        }
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
            define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
        }
        
        $iv_length = 16;
        $iv = str_repeat('a', $iv_length);
        $encrypted = openssl_encrypt($token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $stored_meta_data = base64_encode($iv . $encrypted);
        $created_time = (string)time();

        // Mock order
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('pending');
        $order->shouldReceive('has_status')->andReturn(true);
        $order->shouldReceive('get_meta')->andReturnUsing(function($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });
        
        // Mock global functions
        Functions\expect('wc_get_orders')->once()->andReturn([123]);
        Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);
        
        $this->assertSame($order, $result);
    }
}