<?php

declare(strict_types=1);

// No direct access
defined('ABSPATH') || exit;

/**
 * Concrete base component all guest-payment classes can extend.
 *
 * Currently a thin wrapper over AbstractWicketGuestPaymentComponent so the
 * legacy class name expected by the plugin continues to exist.
 */
class WicketGuestPaymentComponent extends AbstractWicketGuestPaymentComponent
{
}
