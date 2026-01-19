<?php

declare(strict_types=1);

/**
 * Guest Subscription Payment Flow for WooCommerce - Configuration Class.
 *
 * Handles all configuration options, filters, and constants for the plugin.
 * Provides a centralized way to manage plugin settings and integration toggles.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Configuration management class for Guest Subscription Payment Flow.
 */
class WicketGuestPaymentConfig extends WicketGuestPaymentComponent
{
    /**
     * Configuration option keys.
     */
    private const OPTION_EMAIL_ENABLED = 'wicket_guest_payment_enable_email_integration';
    private const OPTION_PDF_ENABLED = 'wicket_guest_payment_enable_pdf_integration';

    /**
     * Initialize configuration hooks and filters.
     *
     * @return void
     */
    public function init(): void
    {
        $this->register_configuration_filters();
        $this->register_plugin_action_links();
    }

    /**
     * Register all configuration filters.
     *
     * @return void
     */
    private function register_configuration_filters(): void
    {
        // Email integration configuration
        add_filter('wicket/wooguestpay/email_integration_enabled', [$this, 'filter_email_integration_enabled'], 10, 1);

        // PDF integration configuration
        add_filter('wicket/wooguestpay/pdf_integration_enabled', [$this, 'filter_pdf_integration_enabled'], 10, 1);

        // Token expiry configuration
        add_filter('wicket/wooguestpay/token_expiry_days', [$this, 'filter_token_expiry_days'], 10, 1);
    }

    /**
     * Register plugin action links.
     *
     * @return void
     */
    private function register_plugin_action_links(): void
    {
        add_filter('plugin_action_links_' . WICKET_GUEST_CHECKOUT_BASENAME, [$this, 'add_plugin_action_links'], 10, 1);
    }

    /**
     * Filter for email integration enabled status.
     *
     * @param bool $default_enabled Default enabled status.
     * @return bool Filtered enabled status.
     */
    public function filter_email_integration_enabled(bool $default_enabled): bool
    {
        // Check if enabled via WordPress option
        if ($this->get_option_bool(self::OPTION_EMAIL_ENABLED)) {
            return true;
        }

        // Return default (disabled unless explicitly enabled)
        return $default_enabled;
    }

    /**
     * Filter for PDF integration enabled status.
     *
     * @param bool $default_enabled Default enabled status.
     * @return bool Filtered enabled status.
     */
    public function filter_pdf_integration_enabled(bool $default_enabled): bool
    {
        // Check if enabled via WordPress option
        if ($this->get_option_bool(self::OPTION_PDF_ENABLED)) {
            return true;
        }

        // Return default (enabled by default)
        return true;
    }

    /**
     * Filter for token expiry days.
     *
     * @param int $default_days Default number of days.
     * @return int Filtered number of days.
     */
    public function filter_token_expiry_days(int $default_days): int
    {
        // Check for custom expiry via option
        $option_days = (int) get_option('wicket_guest_payment_token_expiry_days', 0);
        if ($option_days > 0) {
            return $option_days;
        }

        return $default_days;
    }

    /**
     * Get boolean value from WordPress option.
     *
     * @param string $option_name Option name.
     * @param bool $default Default value if option doesn't exist.
     * @return bool Option value as boolean.
     */
    private function get_option_bool(string $option_name, bool $default = false): bool
    {
        $value = get_option($option_name, $default);

        // Convert various values to boolean
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Add settings link to plugin actions.
     *
     * @param array $links Plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_plugin_action_links(array $links): array
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=wicket_guest_payment')),
            esc_html__('Settings', 'wicket-wgc')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Get current configuration state as array.
     *
     * @return array Current configuration values.
     */
    public function get_configuration_state(): array
    {
        return [
            'email_integration_enabled' => $this->filter_email_integration_enabled(false),
            'pdf_integration_enabled' => $this->filter_pdf_integration_enabled(false),
            'token_expiry_days' => $this->filter_token_expiry_days(7),
        ];
    }

    /**
     * Enable email integration via WordPress option.
     *
     * @return bool True if option was updated successfully.
     */
    public function enable_email_integration(): bool
    {
        return update_option(self::OPTION_EMAIL_ENABLED, true);
    }

    /**
     * Disable email integration via WordPress option.
     *
     * @return bool True if option was updated successfully.
     */
    public function disable_email_integration(): bool
    {
        return update_option(self::OPTION_EMAIL_ENABLED, false);
    }

    /**
     * Enable PDF integration via WordPress option.
     *
     * @return bool True if option was updated successfully.
     */
    public function enable_pdf_integration(): bool
    {
        return update_option(self::OPTION_PDF_ENABLED, true);
    }

    /**
     * Disable PDF integration via WordPress option.
     *
     * @return bool True if option was updated successfully.
     */
    public function disable_pdf_integration(): bool
    {
        return update_option(self::OPTION_PDF_ENABLED, false);
    }

    /**
     * Set token expiry days.
     *
     * @param int $days Number of days for token expiry.
     * @return bool True if option was updated successfully.
     */
    public function set_token_expiry_days(int $days): bool
    {
        if ($days <= 0) {
            return false;
        }

        return update_option('wicket_guest_payment_token_expiry_days', $days);
    }

    /**
     * Get all configuration options in a format suitable for admin display.
     *
     * @return array Formatted configuration options.
     */
    public function get_admin_configuration_options(): array
    {
        return [
            'email_integration' => [
                'label' => __('Email Integration', 'wicket-wgc'),
                'description' => __('Automatically add guest payment links to WooCommerce emails', 'wicket-wgc'),
                'enabled' => $this->filter_email_integration_enabled(false),
                'option' => self::OPTION_EMAIL_ENABLED,
            ],
            'pdf_integration' => [
                'label' => __('PDF Integration', 'wicket-wgc'),
                'description' => __('Automatically add guest payment links to PDF invoices', 'wicket-wgc'),
                'enabled' => $this->filter_pdf_integration_enabled(false),
                'option' => self::OPTION_PDF_ENABLED,
            ],
            'token_expiry' => [
                'label' => __('Token Expiry', 'wicket-wgc'),
                'description' => __('Number of days before guest payment tokens expire', 'wicket-wgc'),
                'value' => $this->filter_token_expiry_days(7),
                'option' => 'wicket_guest_payment_token_expiry_days',
            ],
        ];
    }

    /**
     * Validate configuration values.
     *
     * @param array $config Configuration data to validate.
     * @return array|WP_Error Validated configuration or error.
     */
    public function validate_configuration(array $config)
    {
        $validated = [];
        $errors = [];

        // Validate email integration
        if (isset($config['email_integration'])) {
            $validated['email_integration'] = rest_sanitize_boolean($config['email_integration']);
        }

        // Validate PDF integration
        if (isset($config['pdf_integration'])) {
            $validated['pdf_integration'] = rest_sanitize_boolean($config['pdf_integration']);
        }

        // Validate token expiry
        if (isset($config['token_expiry_days'])) {
            $days = (int) $config['token_expiry_days'];
            if ($days > 0 && $days <= 365) {
                $validated['token_expiry_days'] = $days;
            } else {
                $errors['token_expiry_days'] = __('Token expiry must be between 1 and 365 days', 'wicket-wgc');
            }
        }

        if (!empty($errors)) {
            return new WP_Error('invalid_config', __('Configuration validation failed', 'wicket-wgc'), $errors);
        }

        return $validated;
    }

    /**
     * Save configuration from admin form.
     *
     * @param array $config Configuration data to save.
     * @return bool|WP_Error True on success, error on failure.
     */
    public function save_configuration(array $config)
    {
        $validation = $this->validate_configuration($config);

        if (is_wp_error($validation)) {
            return $validation;
        }

        $validated = $validation;

        // Save email integration setting
        if (isset($validated['email_integration'])) {
            if ($validated['email_integration']) {
                $this->enable_email_integration();
            } else {
                $this->disable_email_integration();
            }
        }

        // Save PDF integration setting
        if (isset($validated['pdf_integration'])) {
            if ($validated['pdf_integration']) {
                $this->enable_pdf_integration();
            } else {
                $this->disable_pdf_integration();
            }
        }

        // Save token expiry setting
        if (isset($validated['token_expiry_days'])) {
            $this->set_token_expiry_days($validated['token_expiry_days']);
        }

        return true;
    }
}
