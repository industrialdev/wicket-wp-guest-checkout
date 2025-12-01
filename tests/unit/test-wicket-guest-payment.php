<?php

/**
 * Tests for Wicket_Guest_Payment main class.
 */

declare(strict_types=1);

/**
 * Test main plugin class functionality.
 */
class Test_Wicket_Guest_Payment extends WP_UnitTestCase
{
    /**
     * Test that instances can be created without triggering hooks.
     *
     * This demonstrates the testability pattern - we can create
     * instances for testing without side effects.
     */
    public function test_instance_creation_without_hooks()
    {
        // Create instance without firing hooks
        $plugin = new WicketGuestPayment();

        // Verify instance is correct type
        $this->assertInstanceOf(WicketGuestPayment::class, $plugin);

        // Verify properties are empty (plugin_setup not called)
        $this->assertEmpty($plugin->plugin_url);
        $this->assertEmpty($plugin->plugin_path);
        $this->assertEmpty($plugin->version);
    }

    /**
     * Test singleton pattern works correctly.
     */
    public function test_singleton_pattern()
    {
        $instance1 = WicketGuestPayment::get_instance();
        $instance2 = WicketGuestPayment::get_instance();

        // Same instance should be returned
        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test plugin setup initializes properties.
     */
    public function test_plugin_setup_initializes_paths()
    {
        $plugin = new WicketGuestPayment();

        // Mock WooCommerce being active
        if (!class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce not available for testing');
        }

        // Call plugin_setup
        $plugin->plugin_setup();

        // Verify paths are set
        $this->assertNotEmpty($plugin->plugin_url);
        $this->assertNotEmpty($plugin->plugin_path);
        $this->assertNotEmpty($plugin->version);
    }

    /**
     * Test get instance returns same object.
     */
    public function test_get_instance_returns_same_object()
    {
        $instance1 = WicketGuestPayment::get_instance();
        $instance2 = WicketGuestPayment::get_instance();

        $this->assertSame($instance1, $instance2, 'get_instance() should return the same instance');
    }
}
