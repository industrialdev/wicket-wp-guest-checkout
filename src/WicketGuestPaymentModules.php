<?php

declare(strict_types=1);

// No direct access
defined('ABSPATH') || exit;

/**
 * Module loader for Wicket Woo tweaks.
 */
class WicketGuestPaymentModules extends WicketGuestPaymentComponent
{
    /**
     * Settings instance.
     *
     * @var WicketGuestPaymentModulesSettings
     */
    private WicketGuestPaymentModulesSettings $settings;

    /**
     * Loaded module instances.
     *
     * @var array<string, object>
     */
    private array $modules = [];

    /**
     * Constructor.
     *
     * @param WicketGuestPaymentModulesSettings $settings Settings instance.
     */
    public function __construct(WicketGuestPaymentModulesSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Initialize enabled modules.
     *
     * @return void
     */
    public function init(): void
    {
        $this->maybe_init_module(
            WicketGuestPaymentModulesSettings::MODULE_EMAIL_BLOCKER,
            WicketGuestPaymentModuleEmailBlocker::class
        );
    }

    /**
     * Get a module instance by key.
     *
     * @param string $key Module key.
     * @return object|null
     */
    public function get_module(string $key): ?object
    {
        return $this->modules[$key] ?? null;
    }

    /**
     * Initialize a module when enabled.
     *
     * @param string $key Module key.
     * @param string $class Module class name.
     * @return void
     */
    private function maybe_init_module(string $key, string $class): void
    {
        if (!$this->settings->is_module_enabled($key)) {
            return;
        }

        if (!class_exists($class)) {
            return;
        }

        $module = new $class($this->settings);
        if (method_exists($module, 'init')) {
            $module->init();
        }

        $this->modules[$key] = $module;
    }
}
