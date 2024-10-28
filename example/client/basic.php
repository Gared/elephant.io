<?php

/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

require __DIR__ . '/common.php';

$logger = setup_logger();

echo "Listening event...\n";

$client = setup_client(null, $logger);
while (true) {
    if ($packet = $client->drain(1)) {
        echo sprintf("Got event %s\n", $packet->event);
        break;
    }
}
$client->disconnect();

echo "Listening event without reuse connection...\n";

$client = setup_client(null, $logger, ['reuse_connection' => false]);
while (true) {
    if ($packet = $client->drain(1)) {
        echo sprintf("Got event %s\n", $packet->event);
        break;
    }
}
$client->disconnect();
