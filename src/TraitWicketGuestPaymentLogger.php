<?php

declare(strict_types=1);

/**
 * Logger Trait for Guest Payment System.
 *
 * Provides shared logging functionality for all guest payment classes.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Shared logger trait.
 *
 * Provides consistent logging across all guest payment components using WooCommerce logger.
 */
trait TraitWicketGuestPaymentLogger
{
    /**
     * Log a message using WooCommerce logger.
     *
     * @param string $message The log message.
     * @param string $level The log level ('emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug').
     * @param array $context Additional context data.
     * @return void
     */
    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        // Ensure context has the source
        $context = array_merge(['source' => 'wicket-guest-payment'], $context);

        // Get the logger instance
        $logger = wc_get_logger();

        // Log with the appropriate level
        switch ($level) {
            case 'emergency':
            case 'alert':
            case 'critical':
                $logger->critical($message, $context);
                break;
            case 'error':
                $logger->error($message, $context);
                break;
            case 'warning':
                $logger->warning($message, $context);
                break;
            case 'notice':
                $logger->notice($message, $context);
                break;
            case 'info':
                $logger->info($message, $context);
                break;
            case 'debug':
                $logger->debug($message, $context);
                break;
            default:
                $logger->info($message, $context);
        }
    }
}
