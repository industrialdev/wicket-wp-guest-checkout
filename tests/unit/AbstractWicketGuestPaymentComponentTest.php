<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use WicketGuestPayment;

class TestableWicketGuestPaymentComponent extends \AbstractWicketGuestPaymentComponent
{
    public function get_plugin_url_public(): string
    {
        return $this->get_plugin_url();
    }

    public function get_plugin_path_public(): string
    {
        return $this->get_plugin_path();
    }

    public function get_plugin_version_public(): string
    {
        return $this->get_plugin_version();
    }

    public function maybe_exit_public(): void
    {
        $this->maybe_exit();
    }
}

#[CoversClass(\AbstractWicketGuestPaymentComponent::class)]
class AbstractWicketGuestPaymentComponentTest extends AbstractTestCase
{
    public function test_plugin_accessors_return_values_from_singleton(): void
    {
        $plugin = WicketGuestPayment::get_instance();
        $plugin->plugin_url = 'https://example.com/plugin/';
        $plugin->plugin_path = '/var/www/plugin/';
        $plugin->version = '2.0.0-test';

        $component = new TestableWicketGuestPaymentComponent();

        $this->assertSame('https://example.com/plugin/', $component->get_plugin_url_public());
        $this->assertSame('/var/www/plugin/', $component->get_plugin_path_public());
        $this->assertSame('2.0.0-test', $component->get_plugin_version_public());
    }

    public function test_maybe_exit_returns_early_when_testing_flag_enabled(): void
    {
        if (!defined('WGP_DOING_TESTING')) {
            define('WGP_DOING_TESTING', true);
        }

        $component = new TestableWicketGuestPaymentComponent();
        $component->maybe_exit_public();

        $this->assertTrue(true);
    }
}
