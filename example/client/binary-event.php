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

$namespace = 'binary-event';
$event = 'test-binary';

$client = setup_client($namespace);

// create binary payload
$payload100k = __DIR__ . '/../../test/Payload/data/payload-100k.txt';
$payload = fopen($payload100k, 'rb');
$bindata = fopen('php://memory', 'w+');
fwrite($bindata, '1234567890');

$client->emit($event, ['data1' => ['test' => $payload], 'data2' => $bindata]);
if ($retval = $client->wait($event)) {
    truncate_data($retval->data);
    echo sprintf("Got a reply: %s\n", json_encode($retval->data));
}
$client->close();
