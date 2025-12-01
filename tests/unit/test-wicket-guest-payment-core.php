<?php

/**
 * Tests for WicketGuestPaymentCore class.
 */

declare(strict_types=1);

/**
 * Test core functionality.
 */
class Test_WicketGuestPaymentCore extends WP_UnitTestCase
{
    /**
     * Test token generation.
     */
    public function test_token_generation()
    {
        $core = new WicketGuestPaymentCore();
        $token = $core->generate_token();

        // Verify token is string
        $this->assertIsString($token);

        // Verify token length (32 bytes = 64 hex characters)
        $this->assertEquals(64, strlen($token));

        // Verify token contains only hex characters
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    /**
     * Test token expiry calculation.
     */
    public function test_token_expiry_calculation()
    {
        $core = new WicketGuestPaymentCore();
        $expiry = $core->get_token_expiry_timestamp();

        // Default expiry is 7 days
        $expected_expiry = time() + (7 * DAY_IN_SECONDS);

        // Allow 5 second tolerance for test execution time
        $this->assertEqualsWithDelta($expected_expiry, $expiry, 5);
    }

    /**
     * Test token expiry days getter.
     */
    public function test_get_token_expiry_days()
    {
        $core = new WicketGuestPaymentCore();

        // Default should be 7 days
        $this->assertEquals(7, $core->get_token_expiry_days());
    }

    /**
     * Test token expiry days setter.
     */
    public function test_set_token_expiry_days()
    {
        $core = new WicketGuestPaymentCore();

        // Set to 14 days
        $core->set_token_expiry_days(14);

        // Verify it was set
        $this->assertEquals(14, $core->get_token_expiry_days());

        // Test expiry timestamp reflects new setting
        $expiry = $core->get_token_expiry_timestamp();
        $expected_expiry = time() + (14 * DAY_IN_SECONDS);
        $this->assertEqualsWithDelta($expected_expiry, $expiry, 5);
    }

    /**
     * Test that invalid expiry days are rejected.
     */
    public function test_invalid_expiry_days_rejected()
    {
        $core = new WicketGuestPaymentCore();

        // Try to set 0 days (invalid)
        $core->set_token_expiry_days(0);

        // Should remain at default (7)
        $this->assertEquals(7, $core->get_token_expiry_days());

        // Try to set negative days (invalid)
        $core->set_token_expiry_days(-1);

        // Should remain at default (7)
        $this->assertEquals(7, $core->get_token_expiry_days());
    }
}
