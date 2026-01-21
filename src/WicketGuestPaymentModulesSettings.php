<?php

declare(strict_types=1);

// No direct access
defined('ABSPATH') || exit;

use HyperFields\Field;
use HyperFields\OptionsPage;
use HyperFields\OptionsSection;

/**
 * Settings and module toggles for Wicket Woo tweaks.
 */
class WicketGuestPaymentModulesSettings extends WicketGuestPaymentComponent
{
    public const OPTION_NAME = 'wicket_woo_tweaks';

    public const MODULE_EMAIL_BLOCKER = 'email_blocker';

    public const OPTION_EMAIL_BLOCKER_ENABLED = 'wicket_woo_email_blocker_enabled';
    public const OPTION_EMAIL_BLOCKER_ALLOW_REFUNDS = 'wicket_woo_email_blocker_allow_refund_emails';

    private const MODULE_OPTION_MAP = [
        self::MODULE_EMAIL_BLOCKER => self::OPTION_EMAIL_BLOCKER_ENABLED,
    ];

    private const DEFAULTS = [
        self::OPTION_EMAIL_BLOCKER_ENABLED => true,
        self::OPTION_EMAIL_BLOCKER_ALLOW_REFUNDS => false,
    ];

    /**
     * Initialize settings page in admin.
     *
     * @return void
     */
    public function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [$this, 'register_settings_page'], 99);
    }

    /**
     * Check if a module is enabled.
     *
     * @param string $module_key Module identifier.
     * @return bool
     */
    public function is_module_enabled(string $module_key): bool
    {
        $option = self::MODULE_OPTION_MAP[$module_key] ?? null;
        if (!$option) {
            return false;
        }

        return $this->get_option_bool($option, self::DEFAULTS[$option] ?? false);
    }

    /**
     * Allow refund emails when admin triggers a refund.
     *
     * @return bool
     */
    public function allow_refund_emails(): bool
    {
        return $this->get_option_bool(
            self::OPTION_EMAIL_BLOCKER_ALLOW_REFUNDS,
            self::DEFAULTS[self::OPTION_EMAIL_BLOCKER_ALLOW_REFUNDS] ?? false
        );
    }

    /**
     * Get raw options array.
     *
     * @return array
     */
    public function get_options(): array
    {
        $options = get_option(self::OPTION_NAME, []);

        return is_array($options) ? $options : [];
    }

    /**
     * Ensure Hyperfields is loaded for options page rendering.
     *
     * @return bool
     */
    private function ensure_hyperfields_loaded(): bool
    {
        if (class_exists(\HyperFields\LibraryBootstrap::class)) {
            \HyperFields\LibraryBootstrap::init([
                'plugin_file' => WICKET_GUEST_CHECKOUT_FILE,
            ]);
        }

        return function_exists('hf_option_page');
    }

    /**
     * Register the Wicket Woo tweaks settings page under Wicket menu.
     *
     * @return void
     */
    public function register_settings_page(): void
    {
        if (!$this->ensure_hyperfields_loaded()) {
            return;
        }

        $page = OptionsPage::make(__('Wicket Woo Tweaks', 'wicket-wgc'), 'wicket-woo-tweaks')
            ->setMenuTitle(__('Woo Tweaks', 'wicket-wgc'))
            ->setParentSlug('wicket-settings')
            ->setOptionName(self::OPTION_NAME)
            ->setPosition(99);

        $email_section = new OptionsSection(
            'email_blocker',
            __('WooCommerce Email Blocking', 'wicket-wgc'),
            __('Block admin-triggered customer emails unless explicitly sent.', 'wicket-wgc')
        );

        $email_section->addField(
            Field::make('checkbox', self::OPTION_EMAIL_BLOCKER_ENABLED, __('Enable email blocker', 'wicket-wgc'))
                ->setDefault(self::DEFAULTS[self::OPTION_EMAIL_BLOCKER_ENABLED])
                ->setHelp(__('Stops customer emails from admin order updates unless explicitly sent (for example, the "Send order details to customer" action).', 'wicket-wgc'))
        );

        $email_section->addField(
            Field::make('checkbox', self::OPTION_EMAIL_BLOCKER_ALLOW_REFUNDS, __('Allow refund emails from admin', 'wicket-wgc'))
                ->setDefault(self::DEFAULTS[self::OPTION_EMAIL_BLOCKER_ALLOW_REFUNDS])
                ->setHelp(__('When enabled, customer refund emails will still send when a refund is triggered from the admin.', 'wicket-wgc'))
        );

        $page->addSectionObject($email_section)->register();
    }

    /**
     * Normalize boolean option value.
     *
     * @param mixed $value Raw option value.
     * @param bool $default Default when value is missing.
     * @return bool
     */
    private function get_option_bool(string $key, bool $default = false): bool
    {
        $options = $this->get_options();
        if (!array_key_exists($key, $options)) {
            return $this->normalize_bool($default);
        }

        return $this->normalize_bool($options[$key]);
    }

    /**
     * Normalize a value into a boolean.
     *
     * @param mixed $value Raw value.
     * @return bool
     */
    private function normalize_bool($value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
