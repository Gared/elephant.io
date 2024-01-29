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

use ElephantIO\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LogLevel;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Create a logger channel.
 *
 * @return \Monolog\Logger
 */
function setup_logger()
{
    $logfile = __DIR__ . '/socket.log';
    if (is_readable($logfile)) {
        @unlink($logfile);
    }
    $logger = new Logger('client');
    $logger->pushHandler(new StreamHandler($logfile, LogLevel::DEBUG));

    return $logger;
}

/**
 * Create a socket client.
 *
 * @param string $namespace
 * @param \Monolog\Logger $logger
 * @param array $options
 * @return \ElephantIO\Client
 */
function setup_client($namespace, $logger = null, $options = [])
{
    $url = 'http://localhost:14000';

    $logger = $logger ?? setup_logger();
    $client = Client::create($url, array_merge(['client' => Client::CLIENT_4X, 'logger' => $logger], $options));
    $client->initialize();
    if ($namespace) {
        $client->of(sprintf('/%s', $namespace));
    }

    return $client;
}

/**
 * Truncate a long string from array value.
 *
 * @param array $data
 * @param integer $len
 * @return void
 */
function truncate_data(&$data, $len = 100)
{
    if (is_array($data)) {
        foreach ($data as $k => &$v) {
            if (is_array($v)) {
                truncate_data($v, $len);
            } else if (is_string($v)) {
                if (($n = strlen($v)) > $len) {
                    $n -= $len;
                    if ($len > 3) {
                        $v = sprintf('%s... (%d more)', substr($v, 0, $len), $n);
                    }
                }
            }
        }
    }
}
