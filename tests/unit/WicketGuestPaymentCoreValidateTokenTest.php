<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_validate_token_returns_false_when_encryption_key_missing(): void
    {
        $token = 'valid-token';

        Functions\when('wc_get_orders')->justReturn([]);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertFalse($result);
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

    public function test_validate_token_returns_false_for_expired_token(): void
    {
        $token = 'valid-token';
        $key = 'test-key-32-chars-long-exactly-32';
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }

        $iv_length = 16;
        $iv = str_repeat('a', $iv_length);
        $encrypted = openssl_encrypt($token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $stored_meta_data = base64_encode($iv . $encrypted);
        $created_time = (string)(time() - (8 * DAY_IN_SECONDS)); // Expired 8 days ago

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

        Functions\expect('wc_get_orders')->once()->andReturn([123]);
        Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertFalse($result);
    }

    public function test_validate_token_returns_false_for_tampered_token(): void
    {
        $original_token = 'valid-token';
        $tampered_token = 'invalid-token';
        $key = 'test-key-32-chars-long-exactly-32';

        $iv_length = 16;
        $iv = str_repeat('a', $iv_length);
        $encrypted = openssl_encrypt($original_token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $stored_meta_data = base64_encode($iv . $encrypted);
        $created_time = (string)time();

        // Mock order with original token
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('pending');
        $order->shouldReceive('has_status')->andReturn(true);
        $order->shouldReceive('get_meta')->andReturnUsing(function($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\expect('wc_get_orders')->once()->andReturn([123]);
        Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $core = new WicketGuestPaymentCore();
        // Try to validate with tampered token
        $result = $core->validate_token($tampered_token);

        // Should fail - decrypted token won't match
        $this->assertFalse($result);
    }

    public function test_validate_token_accepts_failed_status_orders(): void
    {
        $token = 'valid-token';
        $key = 'test-key-32-chars-long-exactly-32';

        $iv_length = 16;
        $iv = str_repeat('a', $iv_length);
        $encrypted = openssl_encrypt($token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $stored_meta_data = base64_encode($iv . $encrypted);
        $created_time = (string)time();

        // Mock order with 'failed' status
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('failed');
        $order->shouldReceive('has_status')->with(Mockery::type('array'))->andReturnUsing(function($statuses) {
            return in_array('failed', $statuses, true);
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\expect('wc_get_orders')->once()->andReturn([123]);
        Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertSame($order, $result);
    }

    public function test_validate_token_accepts_on_hold_status_orders(): void
    {
        $token = 'valid-token';
        $key = 'test-key-32-chars-long-exactly-32';

        $iv_length = 16;
        $iv = str_repeat('a', $iv_length);
        $encrypted = openssl_encrypt($token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $stored_meta_data = base64_encode($iv . $encrypted);
        $created_time = (string)time();

        // Mock order with 'on-hold' status
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('on-hold');
        $order->shouldReceive('has_status')->with(Mockery::type('array'))->andReturnUsing(function($statuses) {
            return in_array('on-hold', $statuses, true);
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\expect('wc_get_orders')->once()->andReturn([123]);
        Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertSame($order, $result);
    }

    public function test_validate_token_rejects_completed_status_orders(): void
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

        // Mock order with 'completed' status
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('completed');
        $order->shouldReceive('has_status')->with(Mockery::type('array'))->andReturnUsing(function($statuses) {
            // has_status returns true if 'completed' is in the allowed statuses array
            // Since 'completed' shouldn't be in allowed statuses, this should return false
            return in_array('completed', $statuses, true);
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\when('wcs_get_subscriptions')->justReturn([]);
        Functions\when('wc_get_orders')->justReturn([123]);
        Functions\when('wc_get_order')->justReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertEquals('invalid_token', $result);
    }

    public function test_validate_token_returns_false_when_no_order_found(): void
    {
        $token = str_repeat('a', 64);
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
            define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'test-key-32-chars-long-exactly-32');
        }
        if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
            define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
        }

        Functions\when('wcs_get_subscriptions')->justReturn([]);
        Functions\when('wc_get_orders')->justReturn([]);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertEquals('invalid_token', $result);
    }

    public function test_validate_token_returns_false_when_timestamp_missing(): void
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

        // Mock order without timestamp
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('pending');
        $order->shouldReceive('has_status')->andReturn(true);
        $order->shouldReceive('get_meta')->andReturnUsing(function($key) use ($stored_meta_data) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return ''; // Empty timestamp
            return null;
        });

        Functions\expect('wc_get_orders')->once()->andReturn([123]);
        Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertFalse($result);
    }

    public function test_validate_token_accepts_subscription_with_active_status(): void
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
        $created_time = (string) time();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('active');
        $order->shouldReceive('has_status')->with(Mockery::type('array'))->andReturnUsing(function ($statuses) {
            return in_array('active', $statuses, true);
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\when('wcs_get_subscriptions')->alias(function () {
            return [123 => true];
        });
        Functions\when('wc_get_order')->justReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertSame($order, $result);
    }

    public function test_validate_token_rejects_tampered_subscription_token(): void
    {
        $token = 'valid-token';
        $tampered_token = 'invalid-token';
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
        $created_time = (string) time();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('active');
        $order->shouldReceive('has_status')->with(Mockery::type('array'))->andReturnUsing(function ($statuses) {
            return in_array('active', $statuses, true);
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\when('wcs_get_subscriptions')->alias(function () {
            return [123 => true];
        });
        Functions\when('wc_get_order')->justReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($tampered_token);

        $this->assertFalse($result);
    }

    public function test_validate_token_rejects_subscription_with_non_allowed_status(): void
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
        $created_time = (string) time();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('cancelled');
        $order->shouldReceive('has_status')->with(Mockery::type('array'))->andReturnUsing(function ($statuses) {
            return in_array('cancelled', $statuses, true);
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\when('wcs_get_subscriptions')->alias(function () {
            return [123 => true];
        });
        Functions\when('wc_get_order')->justReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertEquals('invalid_token', $result);
    }

    public function test_validate_token_allows_custom_status_via_filter(): void
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
        $created_time = (string) time();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('custom-status');
        $order->shouldReceive('has_status')->with(Mockery::type('array'))->andReturnUsing(function ($statuses) {
            return in_array('custom-status', $statuses, true);
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\when('apply_filters')->alias(function (string $filter, $value) {
            if ($filter === 'wicket_guest_payment_allowed_order_statuses') {
                $value[] = 'custom-status';
            }
            return $value;
        });
        Functions\when('wcs_get_subscriptions')->justReturn([]);
        Functions\when('wc_get_orders')->justReturn([123]);
        Functions\when('wc_get_order')->justReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertSame($order, $result);
    }

    public function test_validate_token_allows_subscription_status_via_filter(): void
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
        $created_time = (string) time();

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('custom-sub-status');
        $order->shouldReceive('has_status')->with(Mockery::type('array'))->andReturnUsing(function ($statuses) {
            return in_array('custom-sub-status', $statuses, true);
        });
        $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
            if ($key === '_wgp_guest_payment_token_encrypted') return $stored_meta_data;
            if ($key === '_wgp_guest_payment_token_created') return $created_time;
            return null;
        });

        Functions\when('apply_filters')->alias(function (string $filter, $value) {
            if ($filter === 'wicket_guest_payment_allowed_subscription_statuses') {
                $value[] = 'custom-sub-status';
            }
            return $value;
        });
        Functions\when('wcs_get_subscriptions')->alias(function () {
            return [123 => true];
        });
        Functions\when('wc_get_order')->justReturn($order);

        $core = new WicketGuestPaymentCore();
        $result = $core->validate_token($token);

        $this->assertSame($order, $result);
    }
}
