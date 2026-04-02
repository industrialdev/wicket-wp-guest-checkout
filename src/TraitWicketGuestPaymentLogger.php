<?php

declare(strict_types=1);

namespace Wicket\GuestPayment;

/*
 * Logger Trait for Guest Payment System.
 *
 * Provides shared logging functionality for all guest payment classes.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Shared logger trait.
 *
 * Delegates all logging to the centralized WicketWP\Log via Wicket()->log().
 * The 'wicket-guest-payment' source is injected automatically so log files
 * are grouped consistently under that identifier.
 */
trait TraitWicketGuestPaymentLogger
{
    /**
     * Log a message via the base plugin logger.
     *
     * @param string $message The log message.
     * @param string $level   Log level: 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'.
     * @param array  $context Additional context data.
     * @return void
     */
    protected function log(string $message, string $level = 'info', array $context = []): void
    {
        $context['source'] = 'wicket-guest-payment';

        switch ($level) {
            case 'emergency':
            case 'alert':
            case 'critical':
                Wicket()->log()->critical($message, $context);
                break;
            case 'error':
                Wicket()->log()->error($message, $context);
                break;
            case 'warning':
                Wicket()->log()->warning($message, $context);
                break;
            case 'notice':
            case 'info':
                Wicket()->log()->info($message, $context);
                break;
            case 'debug':
                Wicket()->log()->debug($message, $context);
                break;
            default:
                Wicket()->log()->info($message, $context);
        }
    }
}
