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

namespace ElephantIO;

use Psr\Log\LoggerAwareInterface;

/**
 * Represents an engine used within ElephantIO to send / receive messages from
 * a websocket real time server
 *
 * Loosely based on the work of the following :
 *   - Ludovic Barreca (@ludovicbarreca)
 *   - Mathieu Lallemand (@lalmat)
 *
 * @author Baptiste Clavié <baptiste@wisembly.com>
 */
interface EngineInterface extends LoggerAwareInterface
{
    /**
     * Connect to the targeted server.
     *
     * @return \ElephantIO\EngineInterface
     */
    public function connect();

    /**
     * Close connection to server.
     *
     * @return \ElephantIO\EngineInterface
     */
    public function close();

    /**
     * Is connected to server?
     *
     * @return bool
     */
    public function connected();

    /**
     * Emit an event to server.
     *
     * @param string $event Event to emit
     * @param array  $args  Arguments to send
     * @return int Number of bytes written
     */
    public function emit($event, array $args);

    /**
     * Wait for event to arrive.
     *
     * @param string $event
     * @return \stdClass
     */
    public function wait($event);

    /**
     * Read data from socket.
     *
     * @param float $timeout Timeout in seconds
     * @return string Data read from socket
     */
    public function read($timeout = 0);

    /**
     * Drain data from socket.
     *
     * @param float $timeout Timeout in seconds
     * @return mixed
     */
    public function drain($timeout = 0);

    /**
     * Keep the connection alive.
     */
    public function keepAlive();

    /**
     * Get the name of the engine.
     *
     * @return string
     */
    public function getName();

    /**
     * Set socket namespace.
     *
     * @param string $namespace The namespace
     * @return \stdClass
     */
    public function of($namespace);

    /**
     * Get options.
     *
     * @param array
     */
    public function getOptions();
}
