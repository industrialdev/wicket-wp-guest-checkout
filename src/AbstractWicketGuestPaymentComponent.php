<?php

declare(strict_types=1);

namespace Wicket\GuestPayment;

/**
 * Abstract Base Class for Guest Payment Components.
 *
 * Provides common functionality for all guest payment component classes.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Abstract base class for guest payment components.
 *
 * Provides shared functionality including logging and plugin instance access.
 */
abstract class AbstractWicketGuestPaymentComponent
{
    use TraitWicketGuestPaymentLogger;

    /**
     * Get main plugin instance.
     *
     * Provides access to the main plugin singleton instance.
     *
     * @return WicketGuestPayment The main plugin instance
     */
    protected function get_plugin(): WicketGuestPayment
    {
        return WicketGuestPayment::get_instance();
    }

    /**
     * Get plugin URL.
     *
     * Convenience method to access the plugin URL.
     *
     * @return string The plugin URL
     */
    protected function get_plugin_url(): string
    {
        return $this->get_plugin()->plugin_url;
    }

    /**
     * Get plugin path.
     *
     * Convenience method to access the plugin filesystem path.
     *
     * @return string The plugin path
     */
    protected function get_plugin_path(): string
    {
        return $this->get_plugin()->plugin_path;
    }

    /**
     * Get plugin version.
     *
     * Convenience method to access the plugin version.
     *
     * @return string The plugin version
     */
    protected function get_plugin_version(): string
    {
        return $this->get_plugin()->version;
    }

    /**
     * Exit helper for runtime flows that should stop processing.
     *
     * @return void
     */
    protected function maybe_exit(): void
    {
        if (defined('WGP_DOING_TESTING') && WGP_DOING_TESTING) {
            return;
        }

        exit;
    }

    /**
     * Extract a guest payment token from either direct query params or a login redirect referrer.
     *
     * @return string|null
     */
    protected function extract_guest_payment_token_from_request(): ?string
    {
        if (!empty($_GET['guest_payment_token'])) {
            $token = sanitize_text_field(wp_unslash((string) $_GET['guest_payment_token']));

            return '' !== $token ? $token : null;
        }

        if (empty($_GET['referrer'])) {
            return null;
        }

        $referrer = sanitize_text_field(wp_unslash((string) $_GET['referrer']));
        if ('' === $referrer) {
            return null;
        }

        $parsed_referrer = parse_url($referrer);
        if (!is_array($parsed_referrer) || empty($parsed_referrer['query'])) {
            return null;
        }

        if (!empty($parsed_referrer['host'])) {
            $current_host = parse_url(home_url('/'), PHP_URL_HOST);
            if (!is_string($current_host) || '' === $current_host || strtolower($parsed_referrer['host']) !== strtolower($current_host)) {
                return null;
            }
        }

        parse_str($parsed_referrer['query'], $referrer_query);
        if (empty($referrer_query['guest_payment_token'])) {
            return null;
        }

        $token = sanitize_text_field((string) $referrer_query['guest_payment_token']);

        return '' !== $token ? $token : null;
    }
}
