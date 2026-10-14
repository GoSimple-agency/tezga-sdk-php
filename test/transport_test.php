<?php

declare(strict_types=1);

// Exercises the real curl transport, with no stub: a refused connection must surface as
// a TezgaException with status 0 and must not emit notices or deprecations.
// Run: php -d error_reporting=-1 test/transport_test.php
require __DIR__ . '/../src/TezgaException.php';
require __DIR__ . '/../src/TezgaClient.php';

use GoSimple\Tezga\TezgaClient;
use GoSimple\Tezga\TezgaException;

$client = new TezgaClient('tzg_test', 'http://127.0.0.1:9', 'custom', 3);

try {
    $client->getTaxLabels();
    echo "FAIL a refused connection did not throw\n";
    exit(1);
} catch (TezgaException $e) {
    if ($e->getStatus() !== 0 || $e->isRetryable()) {
        echo "FAIL unexpected status {$e->getStatus()}\n";
        exit(1);
    }
    echo "OK  refused connection -> TezgaException status 0, not retryable\n";
}

echo "\nAll passed\n";
