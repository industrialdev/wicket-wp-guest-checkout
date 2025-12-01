<?php

/**
 * PHPUnit Bootstrap File.
 *
 * Loads WordPress test environment and plugin for unit testing.
 */

// Get WordPress tests directory
$_tests_dir = getenv('WP_TESTS_DIR');

// Fallback to default location if not set
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Check if tests library exists
if (!file_exists($_tests_dir . '/includes/functions.php')) {
    trigger_error('Could not find WordPress test library. Set WP_TESTS_DIR environment variable.', E_USER_ERROR);
}

// Load WordPress test functions
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin for testing.
 */
function _manually_load_plugin()
{
    // Load WooCommerce first (required dependency)
    if (file_exists(dirname(__FILE__) . '/../../woocommerce/woocommerce.php')) {
        require dirname(__FILE__) . '/../../woocommerce/woocommerce.php';
    }

    // Load our plugin
    require dirname(__FILE__) . '/../wicket-wp-guest-checkout.php';
}

// Hook plugin loading
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Load WordPress test bootstrap
require $_tests_dir . '/includes/bootstrap.php';
