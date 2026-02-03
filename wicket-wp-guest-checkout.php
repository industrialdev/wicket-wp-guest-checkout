<?php
/**
 * Plugin Name: Wicket Guest Checkout
 * Plugin URI: https://github.com/wicket/wicket-guest-checkout
 * Description: Guest payment system for WooCommerce orders. Allows admins to generate secure payment links that can be shared with guests to complete payment on behalf of a registered user.
 * Version: 1.2.5
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
    if (defined('SECURE_AUTH_KEY') && defined('AUTH_KEY')) {
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', SECURE_AUTH_KEY . AUTH_KEY);
    } else {
        // Fallback - but this should be defined in wp-config.php
        define('WICKET_GUEST_PAYMENT_ENCRYPTION_KEY', 'fallback-key-please-define-in-wp-config');
    }
}
if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
    define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
}

// Load Composer autoloader
require_once WICKET_GUEST_CHECKOUT_PATH . 'vendor/autoload.php';
require_once WICKET_GUEST_CHECKOUT_PATH . 'src/WicketGuestPaymentAdminPay.php';

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
    [WicketGuestPayment::get_instance(), 'plugin_setup']
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
