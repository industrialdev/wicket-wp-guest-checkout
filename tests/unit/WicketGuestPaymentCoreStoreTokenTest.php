<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use Wicket\GuestPayment\Tests\AbstractTestCase;
use WicketGuestPaymentCore;
use Mockery;

#[CoversClass(WicketGuestPaymentCore::class)]
class WicketGuestPaymentCoreStoreTokenTest extends AbstractTestCase
{
    public function test_store_token_data_fails_if_order_not_found(): void
    {
        Functions\expect('wc_get_order')->with(123)->andReturn(false);
        
        $core = new WicketGuestPaymentCore();
        $result = $core->store_token_data(123, 'token', 1, 'test@example.com', 'email');
        
        $this->assertFalse($result);
    }

    public function test_store_token_data_successful(): void
    {
        $order_id = 123;
        $token = 'test-token-value';
        $user_id = 456;
        $guest_email = 'guest@example.com';
        
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_type')->andReturn('shop_order');
        $order->shouldReceive('update_meta_data')->atLeast()->once();
        $order->shouldReceive('add_order_note')->once();
        $order->shouldReceive('save')->once()->andReturn(true);
        $order->shouldReceive('get_meta')->andReturn('dummy');
        
        Functions\expect('wc_get_order')->with($order_id)->andReturn($order);
        
        // Mock encryption constants if not defined (they might be in bootstrap, but let's be safe)
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
            define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'test-key-32-chars-long-exactly-32');
        }
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
            define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
        }

        $core = new WicketGuestPaymentCore();
        $result = $core->store_token_data($order_id, $token, $user_id, $guest_email, 'email');
        
        $this->assertTrue($result);
    }
}
