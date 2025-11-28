<?php

declare(strict_types=1);

/**
 * Abstract Base Class for Guest Payment Components
 *
 * Provides common functionality for all guest payment component classes.
 *
 * @package Wicket
 * @subpackage GuestPayment
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Abstract base class for guest payment components
 *
 * Provides shared functionality including logging and plugin instance access.
 */
abstract class WicketGuestPaymentComponent
{
	use WicketGuestPaymentLogger;

	/**
	 * Get main plugin instance
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
	 * Get plugin URL
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
	 * Get plugin path
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
	 * Get plugin version
	 *
	 * Convenience method to access the plugin version.
	 *
	 * @return string The plugin version
	 */
	protected function get_plugin_version(): string
	{
		return $this->get_plugin()->version;
	}
}
