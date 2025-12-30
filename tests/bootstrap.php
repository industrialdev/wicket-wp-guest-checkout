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

require_once dirname(__DIR__) . '/vendor/autoload.php';