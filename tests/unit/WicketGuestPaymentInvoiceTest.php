<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPayment;
use WicketGuestPaymentInvoice;

if (!class_exists(__NAMESPACE__ . '\\TestWCOrder')) {
    class TestWCOrder extends \WC_Order
    {
        public function __construct(
            private int $id = 1,
            private string $status = 'pending',
            private string $billing_email = 'guest@example.com',
            private array $meta = []
        ) {}

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function get_billing_email(): string
        {
            return $this->billing_email;
        }

        public function get_meta(string $key, bool $single = false)
        {
            return $this->meta[$key] ?? '';
        }

        public function add_order_note(string $note): void {}
    }
}

class TestableWicketGuestPaymentInvoice extends WicketGuestPaymentInvoice
{
    private ?string $link = null;

    public function __construct(?string $link = null)
    {
        $this->link = $link;
        parent::__construct();
    }

    public function get_or_generate_guest_payment_link(int $order_id)
    {
        return $this->link ?? parent::get_or_generate_guest_payment_link($order_id);
    }
}

class TestableWicketGuestPaymentInvoicePlugin extends WicketGuestPayment
{
    public function initiate_guest_payment(int $order_id, string $guest_email, bool $send_email = true)
    {
        return [
            'token' => 'token123',
            'order_id' => $order_id,
            'user_id' => 11,
        ];
    }
}

#[CoversClass(WicketGuestPaymentInvoice::class)]
class WicketGuestPaymentInvoiceTest extends AbstractTestCase
{
    protected function tearDown(): void
    {
        $this->reset_plugin_singleton();
        parent::tearDown();
    }

    public function test_get_or_generate_guest_payment_link_returns_false_when_order_missing(): void
    {
        Monkey\Functions\when('wc_get_order')->justReturn(false);

        $invoice = new WicketGuestPaymentInvoice();
        $this->assertFalse($invoice->get_or_generate_guest_payment_link(123));
    }

    public function test_get_or_generate_guest_payment_link_returns_false_for_invalid_email(): void
    {
        $order = new TestWCOrder(1, 'pending', 'invalid-email', []);
        Monkey\Functions\when('wc_get_order')->justReturn($order);
        Monkey\Functions\when('is_email')->justReturn(false);

        $invoice = new WicketGuestPaymentInvoice();
        $this->assertFalse($invoice->get_or_generate_guest_payment_link(1));
    }

    public function test_get_or_generate_guest_payment_link_generates_token_when_missing(): void
    {
        $this->set_plugin_singleton(new TestableWicketGuestPaymentInvoicePlugin());

        $order = new TestWCOrder(1, 'pending', 'guest@example.com', []);
        Monkey\Functions\when('wc_get_order')->justReturn($order);
        Monkey\Functions\when('is_email')->justReturn(true);
        Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
        Monkey\Functions\when('add_query_arg')->alias(function (string $key, string $value, string $url) {
            return $url . '?' . $key . '=' . $value;
        });

        $invoice = new WicketGuestPaymentInvoice();
        $link = $invoice->get_or_generate_guest_payment_link(1);

        $this->assertSame('https://example.com/cart?guest_payment_token=token123', $link);
    }

    public function test_insert_guest_payment_link_email_outputs_message(): void
    {
        Monkey\Functions\when('apply_filters')->justReturn(true);
        Monkey\Functions\when('esc_url')->alias(fn(string $url) => $url);
        Monkey\Functions\when('esc_html')->alias(fn(string $text) => $text);
        Monkey\Functions\when('__')->alias(fn(string $text) => $text);

        $order = new TestWCOrder(2, 'pending', 'guest@example.com', []);
        $invoice = new TestableWicketGuestPaymentInvoice('https://example.com/pay');

        ob_start();
        $invoice->insert_guest_payment_link_email($order, false, false, null);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('guest payment', $output);
        $this->assertStringContainsString('https://example.com/pay', $output);
    }

    public function test_append_guest_payment_link_to_pdf_outputs_message(): void
    {
        Monkey\Functions\when('apply_filters')->justReturn(true);
        Monkey\Functions\when('esc_url')->alias(fn(string $url) => $url);
        Monkey\Functions\when('esc_html')->alias(fn(string $text) => $text);
        Monkey\Functions\when('__')->alias(fn(string $text) => $text);

        $order = new TestWCOrder(3, 'pending', 'guest@example.com', []);
        $invoice = new TestableWicketGuestPaymentInvoice('https://example.com/pay');

        ob_start();
        $invoice->append_guest_payment_link_to_pdf(new \stdClass(), $order);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('guest payment', $output);
        $this->assertStringContainsString('https://example.com/pay', $output);
    }

    private function set_plugin_singleton(WicketGuestPayment $instance): void
    {
        $reflection = new \ReflectionClass(WicketGuestPayment::class);
        $property = $reflection->getProperty('instance');
        $property->setValue(null, $instance);
    }

    private function reset_plugin_singleton(): void
    {
        $reflection = new \ReflectionClass(WicketGuestPayment::class);
        $property = $reflection->getProperty('instance');
        $property->setValue(null, null);
    }
}
