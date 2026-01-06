<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentEmail;
use WicketGuestPaymentCore;
use Brain\Monkey;

#[CoversClass(WicketGuestPaymentEmail::class)]
class WicketGuestPaymentEmailTest extends AbstractTestCase
{
    private ?WicketGuestPaymentCore $core = null;
    private ?WicketGuestPaymentEmail $email = null;

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
        $this->email = new WicketGuestPaymentEmail($this->core);
    }

    protected function tearDown(): void
    {
        $this->email = null;
        $this->core = null;
        parent::tearDown();
    }

    public function test_constructor_sets_core_dependency(): void
    {
        $this->assertInstanceOf(WicketGuestPaymentEmail::class, $this->email);
    }

    public function test_add_cc_to_emails_returns_original_header_for_non_target_emails(): void
    {
        $mockOrder = $this->createMockOrder();

        $result = $this->email->add_cc_to_emails('Header: value', 'admin_new_order', $mockOrder);

        $this->assertEquals('Header: value', $result);
    }

    public function test_add_cc_to_emails_returns_original_header_when_no_order(): void
    {
        $result = $this->email->add_cc_to_emails('Header: value', 'customer_completed_order', null);

        $this->assertEquals('Header: value', $result);
    }

    public function test_add_cc_to_emails_adds_cc_for_customer_completed_order(): void
    {
        $mockOrder = $this->createMockOrderWithGuestEmail();

        Monkey\Functions\when('is_email')->justReturn(true);

        $result = $this->email->add_cc_to_emails('Header: value', 'customer_completed_order', $mockOrder);

        $this->assertStringContainsString('Cc: guest@example.com', $result);
    }

    public function test_add_cc_to_emails_adds_cc_for_customer_processing_order(): void
    {
        $mockOrder = $this->createMockOrderWithGuestEmail();

        Monkey\Functions\when('is_email')->justReturn(true);

        $result = $this->email->add_cc_to_emails('Header: value', 'customer_processing_order', $mockOrder);

        $this->assertStringContainsString('Cc: guest@example.com', $result);
    }

    public function test_add_cc_to_emails_skips_invalid_email(): void
    {
        $mockOrder = $this->createMockOrderWithGuestEmail();

        Monkey\Functions\when('is_email')->justReturn(false);

        $result = $this->email->add_cc_to_emails('Header: value', 'customer_completed_order', $mockOrder);

        $this->assertStringNotContainsString('Cc:', $result);
    }

    public function test_send_payment_email_returns_false_for_invalid_email(): void
    {
        Monkey\Functions\when('is_email')->justReturn(false);

        $result = $this->email->send_payment_email('invalid-email', 'token123', 1, 1);

        $this->assertFalse($result);
    }

    public function test_send_payment_email_returns_false_for_empty_token(): void
    {
        Monkey\Functions\when('is_email')->justReturn(true);

        $result = $this->email->send_payment_email('test@example.com', '', 1, 1);

        $this->assertFalse($result);
    }

    public function test_send_payment_email_returns_false_for_invalid_order_id(): void
    {
        Monkey\Functions\when('is_email')->justReturn(true);

        $result = $this->email->send_payment_email('test@example.com', 'token123', 0, 1);

        $this->assertFalse($result);
    }

    public function test_send_payment_email_returns_false_for_invalid_user_id(): void
    {
        Monkey\Functions\when('is_email')->justReturn(true);

        $result = $this->email->send_payment_email('test@example.com', 'token123', 1, 0);

        $this->assertFalse($result);
    }

    public function test_send_payment_email_returns_false_when_user_not_found(): void
    {
        Monkey\Functions\when('is_email')->justReturn(true);
        Monkey\Functions\when('get_userdata')->justReturn(false);
        Monkey\Functions\when('wc_get_order')->justReturn(false);

        $result = $this->email->send_payment_email('test@example.com', 'token123', 1, 999);

        $this->assertFalse($result);
    }

    public function test_send_payment_email_returns_false_when_order_not_found(): void
    {
        $mockUser = $this->createMockUser();

        Monkey\Functions\when('is_email')->justReturn(true);
        Monkey\Functions\when('get_userdata')->justReturn($mockUser);
        Monkey\Functions\when('wc_get_order')->justReturn(false);

        $result = $this->email->send_payment_email('test@example.com', 'token123', 999, 1);

        $this->assertFalse($result);
    }

    public function test_send_payment_email_sends_successfully_with_valid_data(): void
    {
        $mockUser = $this->createMockUser();
        $mockOrder = $this->createMockOrderWithMethods();

        Monkey\Functions\when('is_email')->justReturn(true);
        Monkey\Functions\when('get_userdata')->justReturn($mockUser);
        Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
        Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
        Monkey\Functions\when('wp_date')->justReturn('2025-01-15');
        Monkey\Functions\when('get_theme_mod')->justReturn(false);
        Monkey\Functions\when('wp_mail')->justReturn(true);

        $result = $this->email->send_payment_email('test@example.com', 'token123', 1, 1);

        $this->assertTrue($result);
    }

    public function test_send_payment_email_returns_false_when_wp_mail_fails(): void
    {
        $mockUser = $this->createMockUser();
        $mockOrder = $this->createMockOrderWithMethods();

        Monkey\Functions\when('is_email')->justReturn(true);
        Monkey\Functions\when('get_userdata')->justReturn($mockUser);
        Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
        Monkey\Functions\when('wc_get_cart_url')->justReturn('https://example.com/cart');
        Monkey\Functions\when('wp_date')->justReturn('2025-01-15');
        Monkey\Functions\when('get_theme_mod')->justReturn(false);
        Monkey\Functions\when('wp_mail')->justReturn(false);

        $result = $this->email->send_payment_email('test@example.com', 'token123', 1, 1);

        $this->assertFalse($result);
    }

    private function createMockOrder(): object
    {
        return new class {
            public function get_id(): int
            {
                return 1;
            }
            public function get_meta(string $key, bool $single = false)
            {
                return '';
            }
        };
    }

    private function createMockOrderWithGuestEmail(): object
    {
        return new class {
            public function get_id(): int
            {
                return 1;
            }
            public function get_meta(string $key, bool $single = false)
            {
                if ($key === '_wgp_guest_payment_email') {
                    return 'guest@example.com';
                }
                return '';
            }
        };
    }

    private function createMockOrderWithMethods(): object
    {
        return new class {
            public function get_id(): int
            {
                return 1;
            }
            public function get_order_number(): string
            {
                return '12345';
            }
            public function get_formatted_order_total(): string
            {
                return '$100.00';
            }
            public function get_items(): array
            {
                return [];
            }
            public function add_order_note(string $note, bool $is_error = false): void {}
        };
    }

    private function createMockUser(): object
    {
        return new class {
            public $first_name = 'John';
            public $last_name = 'Doe';
        };
    }
}
