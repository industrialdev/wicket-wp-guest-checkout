<?php

/**
 * PHPUnit Bootstrap File.
 *
 * Loads Composer autoloader and defines essential WordPress constants for isolated unit testing.
 */

declare(strict_types=1);

// Define essential WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 2592000);
}

if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}

if (!defined('WGP_DOING_TESTING')) {
    define('WGP_DOING_TESTING', true);
}

if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}

if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', 'localhost');
}

if (!defined('SECURE_AUTH_KEY')) {
    define('SECURE_AUTH_KEY', 'test-key-32-chars-');
}

if (!defined('AUTH_KEY')) {
    define('AUTH_KEY', 'long-exactly-32');
}

if (!defined('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD')) {
    define('WICKET_GUEST_PAYMENT_ENCRYPTION_METHOD', 'aes-256-cbc');
}

// Ensure WooCommerce objects are mockable
if (!class_exists('WC_Product')) {
    class WC_Product {}
}
if (!class_exists('WC_Product_Variation')) {
    class WC_Product_Variation {}
}
if (!class_exists('WC_Order')) {
    class WC_Order {}
}

if (!defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__) . '/vendor/autoload.php');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/WicketGuestPaymentAdminPay.php';
