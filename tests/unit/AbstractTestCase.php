<?php

declare(strict_types=1);

namespace Wicket\GuestPayment\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Brain\Monkey;

abstract class AbstractTestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Default WooCommerce/WP mocks
        $logger = \Mockery::mock('WC_Logger');
        $logger->shouldReceive('error', 'warning', 'notice', 'info', 'debug', 'critical', 'alert', 'emergency')
            ->byDefault()
            ->andReturnUsing(function($message) {
                // echo "\nLOG: $message\n";
            });

        Monkey\Functions\stubs([
            'wc_get_logger' => $logger,
            'add_action' => null,
            'add_filter' => null,
            '__',
            'esc_html__',
            'esc_attr__',
            'is_email' => true,
            'wcs_get_subscriptions' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
