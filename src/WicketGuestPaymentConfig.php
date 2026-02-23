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
     * Track whether we attached the integrations callback via the new tab system.
     *
     * @var bool
     */
    private bool $integrations_tab_extended = false;

    /**
     * Configuration option keys.
     */
    private const OPTION_EMAIL_ENABLED = 'wicket_guest_payment_enable_email_integration';
    private const OPTION_PDF_ENABLED = 'wicket_guest_payment_enable_pdf_integration';
    private const OPTION_EMAIL_SUBJECT_TEMPLATE = 'wicket_guest_payment_email_subject_template'; // legacy fallback
    private const OPTION_EMAIL_BODY_TEMPLATE = 'wicket_guest_payment_email_body_template'; // legacy fallback
    private const OPTION_WICKET_TOKEN_EXPIRY_DAYS = 'wicket_admin_settings_guest_payment_token_expiry_days';
    private const OPTION_WICKET_EMAIL_SUBJECT_TEMPLATE = 'wicket_admin_settings_guest_payment_email_subject_template';
    private const OPTION_WICKET_EMAIL_BODY_TEMPLATE = 'wicket_admin_settings_guest_payment_email_body_template';

    /**
     * Initialize configuration hooks and filters.
     *
     * @return void
     */
    public function init(): void
    {
        $this->register_configuration_filters();
        $this->register_plugin_action_links();
        $this->register_wicket_settings();
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
        add_filter('wicket/wooguestpay/pdf_integration_enabled', [$this, 'filter_pdf_integration_enabled'], 10, 2);

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
     * Register Wicket Settings integrations fields.
     *
     * @return void
     */
    private function register_wicket_settings(): void
    {
        add_filter('wicket_settings_tabs', [$this, 'extend_wicket_settings_tabs'], 20, 1);
        add_filter('wicket_settings_tab_int', [$this, 'extend_wicket_integrations_tab'], 20, 1);
    }

    /**
     * Extend Integrations tab callback via the new priority-based tabs config.
     *
     * @param array $tabs Tabs configuration.
     * @return array
     */
    public function extend_wicket_settings_tabs(array $tabs): array
    {
        foreach ($tabs as $priority => $config) {
            if (!is_array($config) || ('integrations' !== ($config['key'] ?? ''))) {
                continue;
            }

            $original_callback = $config['callback'] ?? null;
            $this->integrations_tab_extended = true;

            $tabs[$priority]['callback'] = function ($tab) use ($original_callback): void {
                if (is_callable($original_callback)) {
                    call_user_func($original_callback, $tab);
                }

                $this->add_guest_checkout_settings_section($tab);
            };

            return $tabs;
        }

        return $tabs;
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
    public function filter_pdf_integration_enabled(bool $default_enabled, ?string $document_type = null): bool
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
        // Prefer Wicket Settings option
        $option_days = (int) $this->get_wicket_option(self::OPTION_WICKET_TOKEN_EXPIRY_DAYS, 0);
        if ($option_days > 0) {
            return $option_days;
        }

        // Backward compatibility with legacy standalone option
        $legacy_days = (int) get_option('wicket_guest_payment_token_expiry_days', 0);
        if ($legacy_days > 0) {
            return $legacy_days;
        }

        return $default_days;
    }

    /**
     * Read a value from the Wicket Settings datastore.
     *
     * @param string $key Option key inside wicket_settings.
     * @param mixed  $fallback Fallback value.
     * @return mixed
     */
    private function get_wicket_option(string $key, $fallback = null)
    {
        if (function_exists('wicket_get_option')) {
            return wicket_get_option($key, $fallback);
        }

        $options = get_option('wicket_settings', []);
        if (!is_array($options)) {
            return $fallback;
        }

        return $options[$key] ?? $fallback;
    }

    /**
     * Save a value to the Wicket Settings datastore.
     *
     * @param string $key Option key inside wicket_settings.
     * @param mixed  $value Value to store.
     * @return bool
     */
    private function set_wicket_option(string $key, $value): bool
    {
        $options = get_option('wicket_settings', []);
        if (!is_array($options)) {
            $options = [];
        }

        $options[$key] = $value;

        return update_option('wicket_settings', $options);
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
            esc_url(admin_url('admin.php?page=wicket-settings&tab=integrations&section=guest-checkout')),
            esc_html__('Settings', 'wicket-wgc')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Add a Guest Checkout section to Wicket Settings > Integrations.
     *
     * @param mixed $integrations_tab WPSettings integrations tab instance.
     * @return mixed
     */
    public function extend_wicket_integrations_tab($integrations_tab)
    {
        // Fallback path for older base plugin versions that don't expose wicket_settings_tabs.
        if ($this->integrations_tab_extended) {
            return $integrations_tab;
        }

        $this->add_guest_checkout_settings_section($integrations_tab);

        return $integrations_tab;
    }

    /**
     * Add guest checkout section and options to the Integrations tab object.
     *
     * @param mixed $integrations_tab WPSettings tab instance.
     * @return void
     */
    private function add_guest_checkout_settings_section($integrations_tab): void
    {
        if (!is_object($integrations_tab) || !method_exists($integrations_tab, 'add_section')) {
            return;
        }

        $section = $integrations_tab->add_section(__('Guest Checkout', 'wicket-wgc'), [
            'as_link' => true,
            'description' => __(
                'Configure guest payment link behaviour and the email template sent to payers.',
                'wicket-wgc'
            ),
        ]);

        $section->add_option('text', [
            'name' => self::OPTION_WICKET_TOKEN_EXPIRY_DAYS,
            'label' => __('Token Expiry (days)', 'wicket-wgc'),
            'description' => __('Number of days before guest payment links expire.', 'wicket-wgc'),
            'type' => 'number',
            'default' => (string) $this->filter_token_expiry_days(7),
            'attributes' => [
                'min' => '1',
                'step' => '1',
            ],
        ]);

        $section->add_option('text', [
            'name' => self::OPTION_WICKET_EMAIL_SUBJECT_TEMPLATE,
            'label' => __('Email Subject Template', 'wicket-wgc'),
            'description' => __(
                'Available placeholders: <code>{site_name}</code>, <code>{member_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{expiry_date}</code>.',
                'wicket-wgc'
            ),
            'default' => $this->get_default_email_subject_template(),
        ]);

        $section->add_option('textarea', [
            'name' => self::OPTION_WICKET_EMAIL_BODY_TEMPLATE,
            'label' => __('Email Body Template', 'wicket-wgc'),
            'description' => sprintf(
                __(
                    'Full HTML is supported (no auto-paragraph formatting). Available placeholders: <code>{site_name}</code>, <code>{member_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{payment_link}</code>, <code>{payment_url}</code>, <code>{expiry_date}</code>, <code>{subscription_details}</code>.<br><br>Upload/manage images in the <a href="%1$s" target="_blank" rel="noopener noreferrer">Media Library</a> and use the image URL in your template. Example:<br><code>%2$s</code><br>Replace <code>{Image-URL}</code> with the image URL from the Media Library.',
                    'wicket-wgc'
                ),
                esc_url(admin_url('upload.php')),
                esc_html('<img src="{Image-URL}" alt="Logo" style="max-width:200px;height:auto;">')
            ),
            'default' => $this->get_default_email_body_template(),
            'rows' => 16,
            'cols' => 90,
        ]);
    }

    /**
     * Add "Guest Payment" section under WooCommerce checkout settings.
     *
     * @param array $sections Existing checkout settings sections.
     * @return array
     */
    public function add_checkout_settings_section(array $sections): array
    {
        $sections['wicket_guest_payment'] = __('Guest Payment', 'wicket-wgc');

        return $sections;
    }

    /**
     * Add settings fields for guest payment customization.
     *
     * @param array  $settings Existing settings.
     * @param string $current_section Current section key.
     * @return array
     */
    public function add_checkout_settings_fields(array $settings, ?string $current_section = null): array
    {
        if ('wicket_guest_payment' !== (string) $current_section) {
            return $settings;
        }

        return $this->get_checkout_settings_fields();
    }

    /**
     * Persist custom checkout settings fields.
     *
     * @return void
     */
    public function save_checkout_settings_fields(): void
    {
        if (function_exists('woocommerce_update_options')) {
            woocommerce_update_options($this->get_checkout_settings_fields());
        }
    }

    /**
     * Return checkout settings fields for this plugin section.
     *
     * @return array
     */
    private function get_checkout_settings_fields(): array
    {
        return [
            [
                'name' => __('Wicket Guest Payment Settings', 'wicket-wgc'),
                'type' => 'title',
                'desc' => __('Configure guest payment behavior and email content.', 'wicket-wgc'),
                'id' => 'wicket_guest_payment_settings_section_title',
            ],
            [
                'title' => __('Token Expiry (days)', 'wicket-wgc'),
                'desc' => __('Number of days before guest payment links expire.', 'wicket-wgc'),
                'id' => 'wicket_guest_payment_token_expiry_days',
                'type' => 'number',
                'default' => 7,
                'custom_attributes' => [
                    'min' => 1,
                    'step' => 1,
                ],
            ],
            [
                'title' => __('Email Subject Template', 'wicket-wgc'),
                'desc' => __('Available placeholders: <code>{site_name}</code>, <code>{member_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{expiry_date}</code>.', 'wicket-wgc'),
                'id' => self::OPTION_EMAIL_SUBJECT_TEMPLATE,
                'type' => 'text',
                'default' => $this->get_default_email_subject_template(),
                'css' => 'min-width: 500px;',
            ],
            [
                'title' => __('Email Body Template', 'wicket-wgc'),
                'desc' => __('Use one or more blank lines to separate paragraphs. Available placeholders: <code>{site_name}</code>, <code>{member_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{payment_link}</code>, <code>{payment_url}</code>, <code>{expiry_date}</code>, <code>{subscription_details}</code>.', 'wicket-wgc'),
                'id' => self::OPTION_EMAIL_BODY_TEMPLATE,
                'type' => 'textarea',
                'default' => $this->get_default_email_body_template(),
                'css' => 'min-width: 500px; min-height: 240px;',
            ],
            [
                'type' => 'sectionend',
                'id' => 'wicket_guest_payment_settings_section_title',
            ],
        ];
    }

    /**
     * Get default subject template.
     *
     * @return string
     */
    private function get_default_email_subject_template(): string
    {
        return __('Payment Request for {site_name} Subscription', 'wicket-wgc');
    }

    /**
     * Get default body template.
     *
     * @return string
     */
    private function get_default_email_body_template(): string
    {
        return __(
            "<p>Hello,</p>\n\n<p>\nYou have received a request to complete payment for a subscription on behalf of {member_name}.<br>\nOrder Number: {order_number}<br>\nOrder Total: {order_total}\n</p>\n\n{subscription_details}\n\n<p>\nPlease use the secure link below to complete the payment:<br>\n{payment_link}\n</p>\n\n<p>This payment link is valid until {expiry_date} and can only be used once.</p>\n\n<p>If you have any questions about this payment request, please contact us.</p>\n\n<p>Thank you,<br>\n{site_name}</p>",
            'wicket-wgc'
        );
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
            'email_subject_template' => (string) get_option(
                self::OPTION_EMAIL_SUBJECT_TEMPLATE,
                (string) $this->get_wicket_option(
                    self::OPTION_WICKET_EMAIL_SUBJECT_TEMPLATE,
                    $this->get_default_email_subject_template()
                )
            ),
            'email_body_template' => (string) get_option(
                self::OPTION_EMAIL_BODY_TEMPLATE,
                (string) $this->get_wicket_option(
                    self::OPTION_WICKET_EMAIL_BODY_TEMPLATE,
                    $this->get_default_email_body_template()
                )
            ),
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

        $saved_wicket = $this->set_wicket_option(self::OPTION_WICKET_TOKEN_EXPIRY_DAYS, (string) $days);
        $saved_legacy = update_option('wicket_guest_payment_token_expiry_days', $days);

        return $saved_wicket || $saved_legacy;
    }

    /**
     * Set custom subject template for guest payment emails.
     *
     * @param string $template Subject template.
     * @return bool
     */
    public function set_email_subject_template(string $template): bool
    {
        $clean_template = trim($template);
        $saved_wicket = $this->set_wicket_option(self::OPTION_WICKET_EMAIL_SUBJECT_TEMPLATE, $clean_template);
        $saved_legacy = update_option(self::OPTION_EMAIL_SUBJECT_TEMPLATE, $clean_template);

        return $saved_wicket || $saved_legacy;
    }

    /**
     * Set custom body template for guest payment emails.
     *
     * @param string $template Body template.
     * @return bool
     */
    public function set_email_body_template(string $template): bool
    {
        $clean_template = trim($template);
        $saved_wicket = $this->set_wicket_option(self::OPTION_WICKET_EMAIL_BODY_TEMPLATE, $clean_template);
        $saved_legacy = update_option(self::OPTION_EMAIL_BODY_TEMPLATE, $clean_template);

        return $saved_wicket || $saved_legacy;
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
                'option' => self::OPTION_WICKET_TOKEN_EXPIRY_DAYS,
            ],
            'email_subject_template' => [
                'label' => __('Email Subject Template', 'wicket-wgc'),
                'description' => __('Template for guest payment email subject line.', 'wicket-wgc'),
                'value' => (string) $this->get_wicket_option(
                    self::OPTION_WICKET_EMAIL_SUBJECT_TEMPLATE,
                    $this->get_default_email_subject_template()
                ),
                'option' => self::OPTION_WICKET_EMAIL_SUBJECT_TEMPLATE,
            ],
            'email_body_template' => [
                'label' => __('Email Body Template', 'wicket-wgc'),
                'description' => __('Template for guest payment email body content.', 'wicket-wgc'),
                'value' => (string) $this->get_wicket_option(
                    self::OPTION_WICKET_EMAIL_BODY_TEMPLATE,
                    $this->get_default_email_body_template()
                ),
                'option' => self::OPTION_WICKET_EMAIL_BODY_TEMPLATE,
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

        // Validate email subject template
        if (isset($config['email_subject_template'])) {
            $subject_template = trim((string) $config['email_subject_template']);
            if ('' === $subject_template) {
                $errors['email_subject_template'] = __('Email subject template cannot be empty', 'wicket-wgc');
            } else {
                $validated['email_subject_template'] = sanitize_text_field($subject_template);
            }
        }

        // Validate email body template
        if (isset($config['email_body_template'])) {
            $body_template = trim((string) $config['email_body_template']);
            if ('' === $body_template) {
                $errors['email_body_template'] = __('Email body template cannot be empty', 'wicket-wgc');
            } else {
                $validated['email_body_template'] = wp_kses_post($body_template);
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

        // Save email subject template
        if (isset($validated['email_subject_template'])) {
            $this->set_email_subject_template((string) $validated['email_subject_template']);
        }

        // Save email body template
        if (isset($validated['email_body_template'])) {
            $this->set_email_body_template((string) $validated['email_body_template']);
        }

        return true;
    }
}
