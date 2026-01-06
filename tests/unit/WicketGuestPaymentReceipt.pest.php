<?php

declare(strict_types=1);

use Brain\Monkey;

function wgp_receipt_with_post(array $post, callable $callback): void
{
    $original = $_POST;
    $_POST = $post;

    try {
        $callback();
    } finally {
        $_POST = $original;
    }
}

function wgp_receipt_expect_json_success(array $data): void
{
    Monkey\Functions\expect('wp_send_json_success')
        ->once()
        ->with($data)
        ->andReturnUsing(function (): void {
            throw new RuntimeException('json_exit');
        });
}

it('stores generated receipt access token', function (): void {
    $order = new class {
        public array $meta = [
            '_wgp_guest_payment_email' => 'guest@example.com',
        ];

        public function get_id(): int
        {
            return 123;
        }
        public function get_meta(string $key, bool $single = false)
        {
            return $this->meta[$key] ?? '';
        }
        public function update_meta_data(string $key, $value): void
        {
            $this->meta[$key] = $value;
        }
        public function save(): int
        {
            return 123;
        }
    };

    Monkey\Functions\when('wc_get_order')->justReturn($order);

    $receipt = new WicketGuestPaymentReceipt();
    $receipt->generate_receipt_access_token(123);

    expect($order->meta['_wgp_receipt_access_token'] ?? '')->not()->toBeEmpty();
    expect($order->meta['_wgp_receipt_token_created'] ?? 0)->toBeGreaterThan(0);
});

it('sends receipt email via ajax', function (): void {
    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(123);
    $order->shouldReceive('get_order_number')->andReturn('1001');
    $order->shouldReceive('get_date_created')->andReturn(new DateTime('2024-01-01'));
    $order->shouldReceive('get_formatted_order_total')->andReturn('$10.00');
    $order->shouldReceive('get_meta')->andReturnUsing(function (string $key) {
        if ($key === '_wgp_receipt_token_created') {
            return time();
        }
        if ($key === '_wgp_receipt_access_token') {
            return 'token123';
        }
        return '';
    });

    Monkey\Functions\when('wp_verify_nonce')->justReturn(true);
    Monkey\Functions\when('sanitize_text_field')->alias(function ($value) {
        return $value;
    });
    Monkey\Functions\when('sanitize_email')->alias(function ($value) {
        return $value;
    });
    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('wc_get_orders')->justReturn([123]);
    Monkey\Functions\when('wc_get_order')->justReturn($order);
    Monkey\Functions\when('wp_mail')->justReturn(true);
    Monkey\Functions\when('home_url')->justReturn('https://example.com/guest-receipt/token123');

    wgp_receipt_expect_json_success(['message' => 'Receipt has been sent to your email address.']);

    $receipt = new WicketGuestPaymentReceipt();
    expect(function () use ($receipt): void {
        wgp_receipt_with_post([
            'nonce' => 'good-nonce',
            'token' => 'token123',
            'email' => 'guest@example.com',
        ], function () use ($receipt): void {
            $receipt->ajax_send_receipt_email();
        });
    })->toThrow(RuntimeException::class);
});

it('captures guest email and sends receipt', function (): void {
    $order = new class extends WC_Order {
        public array $meta = [];

        public function get_id(): int
        {
            return 123;
        }
        public function get_order_number(): string
        {
            return '1001';
        }
        public function get_date_created(): DateTime
        {
            return new DateTime('2024-01-01');
        }
        public function get_formatted_order_total(): string
        {
            return '$10.00';
        }
        public function get_meta(string $key, bool $single = false)
        {
            return $this->meta[$key] ?? '';
        }
        public function update_meta_data(string $key, $value): void
        {
            $this->meta[$key] = $value;
        }
        public function save(): int
        {
            return 123;
        }
    };

    Monkey\Functions\when('wp_hash')->justReturn('good-hash');
    Monkey\Functions\when('sanitize_email')->alias(function ($value) {
        return $value;
    });
    Monkey\Functions\when('is_email')->justReturn(true);
    Monkey\Functions\when('wc_get_order')->justReturn($order);
    Monkey\Functions\when('wp_mail')->justReturn(true);
    Monkey\Functions\when('home_url')->justReturn('https://example.com/guest-receipt/token123');

    wgp_receipt_expect_json_success(['message' => 'Receipt sent successfully to guest@example.com']);

    $receipt = new WicketGuestPaymentReceipt();
    expect(function () use ($receipt): void {
        wgp_receipt_with_post([
            'order_id' => 123,
            'nonce' => 'good-hash',
            'email' => 'guest@example.com',
        ], function () use ($receipt): void {
            $receipt->ajax_set_guest_email_and_send_receipt();
        });
    })->toThrow(RuntimeException::class);
});
