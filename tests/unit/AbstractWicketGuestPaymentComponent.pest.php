<?php

declare(strict_types=1);

class TestableWicketGuestPaymentComponent extends AbstractWicketGuestPaymentComponent
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

it('returns plugin accessors from singleton', function (): void {
    $plugin = WicketGuestPayment::get_instance();
    $plugin->plugin_url = 'https://example.com/plugin/';
    $plugin->plugin_path = '/var/www/plugin/';
    $plugin->version = '2.0.0-test';

    $component = new TestableWicketGuestPaymentComponent();

    expect($component->get_plugin_url_public())->toBe('https://example.com/plugin/');
    expect($component->get_plugin_path_public())->toBe('/var/www/plugin/');
    expect($component->get_plugin_version_public())->toBe('2.0.0-test');
});

it('returns early when testing flag enabled', function (): void {
    if (!defined('WGP_DOING_TESTING')) {
        define('WGP_DOING_TESTING', true);
    }

    $component = new TestableWicketGuestPaymentComponent();
    $component->maybe_exit_public();

    expect(true)->toBeTrue();
});
