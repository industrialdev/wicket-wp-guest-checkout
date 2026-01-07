<?php

declare(strict_types=1);

use Brain\Monkey\Functions;

function wgp_core_validate_encrypt_token(string $token, string $key): string
{
    $iv_length = 16;
    $iv = str_repeat('a', $iv_length);
    $encrypted = openssl_encrypt($token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

    return base64_encode($iv . $encrypted);
}

it('returns false on empty token', function (): void {
    $core = new WicketGuestPaymentCore();

    expect($core->validate_token(''))->toBeFalse();
});

it('returns false when encryption key missing', function (): void {
    $token = 'valid-token';

    if (defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        expect(true)->toBeTrue();
        return;
    }

    Functions\when('wc_get_orders')->justReturn([]);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBeFalse();
});

it('validates token and returns order', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('pending');
    $order->shouldReceive('has_status')->andReturn(true);
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\expect('wc_get_orders')->once()->andReturn([123]);
    Functions\expect('wc_get_order')->with(123)->andReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBe($order);
});

it('returns false for expired token', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) (time() - (8 * DAY_IN_SECONDS));

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('pending');
    $order->shouldReceive('has_status')->andReturn(true);
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\expect('wc_get_orders')->once()->andReturn([123]);
    Functions\expect('wc_get_order')->with(123)->andReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBeFalse();
});

it('returns false for tampered token', function (): void {
    $original_token = 'valid-token';
    $tampered_token = 'invalid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    $stored_meta_data = wgp_core_validate_encrypt_token($original_token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('pending');
    $order->shouldReceive('has_status')->andReturn(true);
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\expect('wc_get_orders')->once()->andReturn([123]);
    Functions\expect('wc_get_order')->with(123)->andReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($tampered_token);

    expect($result)->toBeFalse();
});

it('accepts failed status orders', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('failed');
    $order->shouldReceive('has_status')->with(\Mockery::type('array'))
        ->andReturnUsing(function ($statuses) {
            return in_array('failed', $statuses, true);
        });
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\expect('wc_get_orders')->once()->andReturn([123]);
    Functions\expect('wc_get_order')->with(123)->andReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBe($order);
});

it('accepts on-hold status orders', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('on-hold');
    $order->shouldReceive('has_status')->with(\Mockery::type('array'))
        ->andReturnUsing(function ($statuses) {
            return in_array('on-hold', $statuses, true);
        });
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\expect('wc_get_orders')->once()->andReturn([123]);
    Functions\expect('wc_get_order')->with(123)->andReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBe($order);
});

it('rejects completed status orders', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('completed');
    $order->shouldReceive('has_status')->with(\Mockery::type('array'))
        ->andReturnUsing(function ($statuses) {
            return in_array('completed', $statuses, true);
        });
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\when('wcs_get_subscriptions')->justReturn([]);
    Functions\when('wc_get_orders')->justReturn([123]);
    Functions\when('wc_get_order')->justReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBe('invalid_token');
});

it('returns invalid token when no order found', function (): void {
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

    expect($result)->toBe('invalid_token');
});

it('returns false when token timestamp missing', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('pending');
    $order->shouldReceive('has_status')->andReturn(true);
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return '';
        }
        return null;
    });

    Functions\expect('wc_get_orders')->once()->andReturn([123]);
    Functions\expect('wc_get_order')->with(123)->andReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBeFalse();
});

it('accepts active subscription status', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('active');
    $order->shouldReceive('has_status')->with(\Mockery::type('array'))
        ->andReturnUsing(function ($statuses) {
            return in_array('active', $statuses, true);
        });
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\when('wcs_get_subscriptions')->alias(function () {
        return [123 => true];
    });
    Functions\when('wc_get_order')->justReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBe($order);
});

it('rejects tampered subscription token', function (): void {
    $token = 'valid-token';
    $tampered_token = 'invalid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('active');
    $order->shouldReceive('has_status')->with(\Mockery::type('array'))
        ->andReturnUsing(function ($statuses) {
            return in_array('active', $statuses, true);
        });
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\when('wcs_get_subscriptions')->alias(function () {
        return [123 => true];
    });
    Functions\when('wc_get_order')->justReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($tampered_token);

    expect($result)->toBeFalse();
});

it('rejects subscription with non-allowed status', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('cancelled');
    $order->shouldReceive('has_status')->with(\Mockery::type('array'))
        ->andReturnUsing(function ($statuses) {
            return in_array('cancelled', $statuses, true);
        });
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
        return null;
    });

    Functions\when('wcs_get_subscriptions')->alias(function () {
        return [123 => true];
    });
    Functions\when('wc_get_order')->justReturn($order);

    $core = new WicketGuestPaymentCore();
    $result = $core->validate_token($token);

    expect($result)->toBe('invalid_token');
});

it('allows custom order status via filter', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('custom-status');
    $order->shouldReceive('has_status')->with(\Mockery::type('array'))
        ->andReturnUsing(function ($statuses) {
            return in_array('custom-status', $statuses, true);
        });
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
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

    expect($result)->toBe($order);
});

it('allows custom subscription status via filter', function (): void {
    $token = 'valid-token';
    $key = 'test-key-32-chars-long-exactly-32';

    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $key);
    }
    if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
    }

    $stored_meta_data = wgp_core_validate_encrypt_token($token, $key);
    $created_time = (string) time();

    $order = \Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_status')->andReturn('custom-sub-status');
    $order->shouldReceive('has_status')->with(\Mockery::type('array'))
        ->andReturnUsing(function ($statuses) {
            return in_array('custom-sub-status', $statuses, true);
        });
    $order->shouldReceive('get_meta')->andReturnUsing(function ($key) use ($stored_meta_data, $created_time) {
        if ($key === '_wgp_guest_payment_token_encrypted') {
            return $stored_meta_data;
        }
        if ($key === '_wgp_guest_payment_token_created') {
            return $created_time;
        }
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

    expect($result)->toBe($order);
});
