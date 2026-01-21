<?php

declare(strict_types=1);

use Pest\Platform;

$testSuite = Platform::getTestSuite();

$testSuite->configure(
    testDirectory: 'tests',
    namespace: 'Wicket\\GuestPayment\\Tests',
    source: 'src'
);
