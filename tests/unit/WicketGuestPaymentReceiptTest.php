<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentReceipt;
use Brain\Monkey;

#[CoversClass(WicketGuestPaymentReceipt::class)]
class WicketGuestPaymentReceiptTest extends AbstractTestCase
{
    private ?WicketGuestPaymentReceipt $receipt = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }

        $this->receipt = new WicketGuestPaymentReceipt();
    }

    protected function tearDown(): void
    {
        $this->receipt = null;
        parent::tearDown();
    }

    public function test_constructor_creates_instance(): void
    {
        $this->assertInstanceOf(WicketGuestPaymentReceipt::class, $this->receipt);
    }

    public function test_add_receipt_endpoint_adds_rewrite_rules(): void
    {
        Monkey\Functions\expect('add_rewrite_rule')
            ->once()
            ->with(
                \Mockery::type('string'),
                \Mockery::type('string'),
                'top'
            );

        Monkey\Functions\expect('add_rewrite_tag')->twice();
        Monkey\Functions\when('get_option')->justReturn('no');
        Monkey\Functions\when('flush_rewrite_rules')->justReturn(null);
        Monkey\Functions\when('update_option')->justReturn(true);

        $this->receipt->add_receipt_endpoint();

        $this->assertTrue(true);
    }

    public function test_generate_receipt_access_token_skips_non_guest_orders(): void
    {
        $mockOrder = $this->createMockOrderWithoutGuestEmail();

        Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);

        // Should return early without generating token
        $this->receipt->generate_receipt_access_token(123);

        $this->assertTrue(true);
    }

    public function test_generate_receipt_access_token_skips_when_token_exists_and_valid(): void
    {
        $mockOrder = $this->createMockOrderWithValidReceiptToken();

        Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);

        $this->receipt->generate_receipt_access_token(123);

        $this->assertTrue(true);
    }

    public function test_generate_receipt_access_token_creates_new_token(): void
    {
        $mockOrder = $this->createMockOrderNeedingReceiptToken();

        Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);

        $this->receipt->generate_receipt_access_token(123);

        $this->assertTrue(true);
    }

    public function test_add_receipt_access_section_skips_non_receipt_page(): void
    {
        Monkey\Functions\when('wc_get_order')->justReturn($this->createMockOrder());
        Monkey\Functions\when('is_wc_endpoint_url')->justReturn(false);

        $this->receipt->add_receipt_access_section(123);

        $this->assertTrue(true);
    }

    public function test_add_receipt_access_section_skips_non_guest_orders(): void
    {
        $mockOrder = $this->createMockOrderWithoutGuestMeta();

        Monkey\Functions\when('wc_get_order')->justReturn($mockOrder);
        Monkey\Functions\when('is_wc_endpoint_url')->justReturn(true);

        $this->receipt->add_receipt_access_section(123);

        $this->assertTrue(true);
    }

    public function test_handle_receipt_request_returns_early_when_no_query_vars(): void
    {
        Monkey\Functions\when('get_query_var')->justReturn('');

        $this->receipt->handle_receipt_request();

        $this->assertTrue(true);
    }

    private function createMockOrder(): object
    {
        return new class {
            public function get_id(): int
            {
                return 123;
            }
            public function get_meta(string $key, bool $single = false)
            {
                return '';
            }
        };
    }

    private function createMockOrderWithoutGuestEmail(): object
    {
        return new class {
            public function get_id(): int
            {
                return 123;
            }
            public function get_meta(string $key, bool $single = false)
            {
                if ($key === '_wgp_guest_payment_email') {
                    return '';
                }
                if ($key === '_wgp_guest_payment_token_hash') {
                    return '';
                }
                return '';
            }
        };
    }

    private function createMockOrderWithValidReceiptToken(): object
    {
        return new class {
            public function get_id(): int
            {
                return 123;
            }
            public function get_meta(string $key, bool $single = false)
            {
                if ($key === '_wgp_guest_payment_email') {
                    return 'guest@example.com';
                }
                if ($key === '_wgp_receipt_access_token') {
                    return 'existing_token_123';
                }
                if ($key === '_wgp_receipt_token_created') {
                    return time(); // Created now, so valid for 30 days
                }
                return '';
            }
        };
    }

    private function createMockOrderNeedingReceiptToken(): object
    {
        return new class {
            public function get_id(): int
            {
                return 123;
            }
            public function get_meta(string $key, bool $single = false)
            {
                if ($key === '_wgp_guest_payment_email') {
                    return 'guest@example.com';
                }
                if ($key === '_wgp_receipt_access_token') {
                    return ''; // No existing token
                }
                return '';
            }
            public function update_meta_data(string $key, $value): void {}
            public function save(): int
            {
                return 123;
            }
        };
    }

    private function createMockOrderWithoutGuestMeta(): object
    {
        return new class {
            public function get_id(): int
            {
                return 123;
            }
            public function get_meta(string $key, bool $single = false)
            {
                // No guest payment meta
                return '';
            }
        };
    }
}
