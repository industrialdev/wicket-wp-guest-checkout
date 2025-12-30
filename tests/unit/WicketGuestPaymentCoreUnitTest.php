<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPaymentCore;

#[CoversClass(WicketGuestPaymentCore::class)]
class WicketGuestPaymentCoreUnitTest extends AbstractTestCase
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
        
        // Create Core instance
        $this->core = new WicketGuestPaymentCore();
    }
    
    protected function tearDown(): void
    {
        $this->core = null;
        parent::tearDown();
    }
    
    public function test_generate_token_returns_64_char_hex_string(): void
    {
        $token = $this->core->generate_token();
        
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token), 'Token should be 64 characters');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }
    
    public function test_generate_token_returns_unique_tokens(): void
    {
        $token1 = $this->core->generate_token();
        $token2 = $this->core->generate_token();
        
        $this->assertNotEquals($token1, $token2, 'Each generated token should be unique');
    }
    
    public function test_get_token_expiry_timestamp_with_current_time(): void
    {
        $now = time();
        $expiry = $this->core->get_token_expiry_timestamp();
        
        $expectedExpiry = $now + (7 * DAY_IN_SECONDS);
        $this->assertLessThanOrEqual(2, abs($expiry - $expectedExpiry), 'Expiry should be 7 days from now');
    }
    
    public function test_get_token_expiry_timestamp_with_specific_created_time(): void
    {
        $createdTimestamp = 1704067200;
        $expiry = $this->core->get_token_expiry_timestamp($createdTimestamp);
        
        $expectedExpiry = $createdTimestamp + (7 * DAY_IN_SECONDS);
        $this->assertEquals($expectedExpiry, $expiry);
    }
    
    public function test_has_guest_session_cookie_returns_false_when_not_set(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);
        
        $result = $this->core->has_guest_session_cookie();
        
        $this->assertFalse($result);
    }
    
    public function test_has_guest_session_cookie_returns_true_when_set(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';
        
        $result = $this->core->has_guest_session_cookie();
        
        $this->assertTrue($result);
        
        unset($_COOKIE['wordpress_logged_in_order']);
    }
    
    public function test_has_guest_session_cookie_returns_false_when_empty(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '';
        
        $result = $this->core->has_guest_session_cookie();
        
        $this->assertFalse($result);
        
        unset($_COOKIE['wordpress_logged_in_order']);
    }
    
    public function test_get_token_expiry_days_returns_default(): void
    {
        $days = $this->core->get_token_expiry_days();
        
        $this->assertEquals(7, $days);
    }
    
    public function test_set_token_expiry_days_updates_value(): void
    {
        $this->core->set_token_expiry_days(14);
        
        $days = $this->core->get_token_expiry_days();
        
        $this->assertEquals(14, $days);
    }
    
    public function test_set_token_expiry_days_rejects_zero(): void
    {
        $this->core->set_token_expiry_days(0);
        
        $days = $this->core->get_token_expiry_days();
        
        $this->assertEquals(7, $days);
    }
    
    public function test_set_token_expiry_days_rejects_negative(): void
    {
        $this->core->set_token_expiry_days(-5);
        
        $days = $this->core->get_token_expiry_days();
        
        $this->assertEquals(7, $days);
    }
    
    public function test_set_token_expiry_days_accepts_minimum_value(): void
    {
        $this->core->set_token_expiry_days(1);
        
        $days = $this->core->get_token_expiry_days();
        
        $this->assertEquals(1, $days);
    }
    
    public function test_is_guest_payment_session_returns_false_without_cookie_or_meta(): void
    {
        unset($_COOKIE['wordpress_logged_in_order']);
        
        $result = $this->core->is_guest_payment_session();
        
        $this->assertFalse($result);
    }
    
    public function test_is_guest_payment_session_returns_true_with_cookie(): void
    {
        $_COOKIE['wordpress_logged_in_order'] = '1';
        
        $result = $this->core->is_guest_payment_session();
        
        $this->assertTrue($result);
        
        unset($_COOKIE['wordpress_logged_in_order']);
    }
}
