<?php
/**
 * Plugin Name: Wicket Guest Checkout
 * Plugin URI: https://github.com/wicket/wicket-guest-checkout
 * Description: Guest payment system for WooCommerce orders. Allows admins to generate secure payment links that can be shared with guests to complete payment on behalf of a registered user.
 * Version: 1.5.0
 * Author: Wicket Inc.
 * Author URI: https://wicket.io
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 8.2
 * Requires Plugins: wicket-wp-base-plugin, woocommerce
 * WC requires at least: 10.0
 * WC tested up to: 10.0
 * Text Domain: wicket-wgc
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html.
 */

declare(strict_types=1);

// No direct access
defined('ABSPATH') || exit;

// Define plugin constants
define('WICKET_GUEST_CHECKOUT_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
define('WICKET_GUEST_CHECKOUT_FILE', __FILE__);
define('WICKET_GUEST_CHECKOUT_PATH', plugin_dir_path(__FILE__));
define('WICKET_GUEST_CHECKOUT_URL', plugin_dir_url(__FILE__));
define('WICKET_GUEST_CHECKOUT_BASENAME', plugin_basename(__FILE__));

// Define encryption keys if not already defined
// If you want to define them yourself to be different for security reasons, add them to wp-config.php
if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
    // Use wp-config.php SECURE_AUTH_KEY + AUTH_KEY for encryption
    $wgp_secure_auth_key = defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : '';
    $wgp_auth_key = defined('AUTH_KEY') ? (string) AUTH_KEY : '';
    // WWID-2665: a salt left at the wp-config-sample default
    // ('put your unique phrase here') or the Wicket baseline default
    // ('generateme') is a public literal, not a key. Treat it (or an
    // empty string) as missing.
    $wgp_usable_salt = static fn (string $value): bool => !in_array($value, ['', 'put your unique phrase here', 'generateme'], true);

    if ($wgp_usable_salt($wgp_secure_auth_key) && $wgp_usable_salt($wgp_auth_key)) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', $wgp_secure_auth_key . $wgp_auth_key);
    } else {
        // Fail closed (WWID-2665): outside local development, with no usable
        // wp-config key and no site salts, there is no safe key to derive.
        // The constant stays undefined, which disables guest payment token
        // encryption/issuance (Core::get_encryption_keys() returns no keys)
        // and an admin notice tells the operator to define it. This gate
        // MUST live in this file: it runs before the autoloader, so a gate
        // inside the src classes can never execute. 'staging' is
        // intentionally NOT in the derive list: staging hosts prod-data
        // clones, so it fails closed and the operator defines the key there.
        $is_local_env = function_exists('wp_get_environment_type')
            ? in_array(wp_get_environment_type(), ['local', 'development'], true)
            : false;
        if ($is_local_env) {
            // Local convenience only: a site-derived key is fine for dev.
            define(
                'WICKET_GUEST_PAYMENT_ENCRYPTION_KEY',
                hash('sha256', get_site_url() . get_option('admin_email') . 'wicket-wgc-enc')
            );
        } else {
            add_action('admin_notices', 'wicket_guest_checkout_encryption_key_notice');
        }
    }
}
if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
    define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
}

// Load Composer autoloader
require_once WICKET_GUEST_CHECKOUT_PATH . 'vendor/autoload.php';

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function wicket_guest_checkout_is_woocommerce_active(): bool
{
    return in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true);
}

/**
 * Display admin notice if WooCommerce is not active.
 *
 * @return void
 */
function wicket_guest_checkout_woocommerce_missing_notice(): void
{
    ?>
	<div class="notice notice-error">
		<p>
			<?php
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: WooCommerce plugin link */
                    __('<strong>Wicket Guest Checkout</strong> requires WooCommerce to be installed and activated. Please install %s to use this plugin.', 'wicket-wgc'),
                    '<a href="' . esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')) . '">WooCommerce</a>'
                )
            );
    ?>
		</p>
	</div>
	<?php
}

/**
 * Collect keys supplied through the wicket_guest_payment_encryption_keys filter.
 *
 * Mirrors the normalization in Core::get_encryption_keys(). That class is
 * not loaded at bootstrap time and its method is private, so this small
 * copy lives in the main plugin file.
 *
 * @return array<int, string>
 */
function wicket_guest_checkout_filtered_encryption_keys(): array
{
    $filtered = apply_filters('wicket_guest_payment_encryption_keys', []);
    if (is_string($filtered)) {
        $filtered = [$filtered];
    }

    if (!is_array($filtered)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $filtered), static fn (string $value): bool => $value !== ''));
}

/**
 * Display admin notice when the encryption key is missing outside local development.
 *
 * Filter-supplied keys enable token validation but not issuance, so the
 * notice distinguishes the two states instead of claiming "disabled".
 *
 * @return void
 */
function wicket_guest_checkout_encryption_key_notice(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (defined('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY')) {
        // A later plugin or mu-plugin defined the key after bootstrap; the
        // registered notice is stale and must stay silent.
        return;
    }

    if (wicket_guest_checkout_filtered_encryption_keys() !== []) {
        echo '<div class="notice notice-warning"><p>'
            . esc_html__('Wicket Guest Checkout: guest payment token validation is using keys supplied by the wicket_guest_payment_encryption_keys filter. New payment links cannot be issued until WICKET_GUEST_PAYMENT_ENCRYPTION_KEY (or SECURE_AUTH_KEY/AUTH_KEY) is defined in wp-config.php.', 'wicket-wgc')
            . '</p></div>';

        return;
    }

    echo '<div class="notice notice-error"><p>'
        . esc_html__('Wicket Guest Checkout: guest payment token encryption is disabled. Define WICKET_GUEST_PAYMENT_ENCRYPTION_KEY (or SECURE_AUTH_KEY/AUTH_KEY) in wp-config.php, or supply keys via the wicket_guest_payment_encryption_keys filter.', 'wicket-wgc')
        . '</p></div>';
}

/*
 * Declare HPOS compatibility
 *
 * @return void
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/*
 * Initialize the plugin using testability-focused pattern
 *
 * The main class has an empty constructor, and hooks are registered
 * in the plugin_setup() method, allowing for better testability.
 *
 * @return void
 */
add_action(
    'plugins_loaded',
    [Wicket\GuestPayment\WicketGuestPayment::get_instance(), 'plugin_setup']
);

/**
 * Plugin activation hook.
 *
 * @return void
 */
function wicket_guest_checkout_activate(): void
{
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.2', '<')) {
        deactivate_plugins(WICKET_GUEST_CHECKOUT_BASENAME);
        wp_die(
            esc_html__('Wicket Guest Checkout requires PHP 8.2 or higher. Please upgrade your PHP version.', 'wicket-wgc'),
            esc_html__('Plugin Activation Error', 'wicket-wgc'),
            ['back_link' => true]
        );
    }

    // Check for WooCommerce
    if (!wicket_guest_checkout_is_woocommerce_active()) {
        deactivate_plugins(WICKET_GUEST_CHECKOUT_BASENAME);
        wp_die(
            esc_html__('Wicket Guest Checkout requires WooCommerce to be installed and activated.', 'wicket-wgc'),
            esc_html__('Plugin Activation Error', 'wicket-wgc'),
            ['back_link' => true]
        );
    }

    // Set flag to flush rewrite rules on next init
    update_option('wicket_guest_payment_receipt_rules_flushed', 'no');

    // Set activation timestamp
    if (!get_option('wicket_guest_checkout_activated_time')) {
        update_option('wicket_guest_checkout_activated_time', time());
    }
}

register_activation_hook(__FILE__, 'wicket_guest_checkout_activate');

/**
 * Plugin deactivation hook.
 *
 * @return void
 */
function wicket_guest_checkout_deactivate(): void
{
    // Flush rewrite rules
    flush_rewrite_rules();

    // Delete the rewrite rules flag
    delete_option('wicket_guest_payment_receipt_rules_flushed');
}

register_deactivation_hook(__FILE__, 'wicket_guest_checkout_deactivate');
