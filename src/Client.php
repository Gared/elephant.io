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

use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use ElephantIO\Engine\SocketIO\Version0X;
use ElephantIO\Engine\SocketIO\Version1X;
use ElephantIO\Engine\SocketIO\Version2X;
use ElephantIO\Engine\SocketIO\Version3X;
use ElephantIO\Engine\SocketIO\Version4X;
use ElephantIO\Exception\SocketException;

/**
 * Represents the IO Client which will send and receive the requests to the
 * websocket server. It basically suggercoat the Engine used with loggers.
 *
 * @author Baptiste Clavié <baptiste@wisembly.com>
 */
class Client
{
    public const CLIENT_0X = 0;
    public const CLIENT_1X = 1;
    public const CLIENT_2X = 2;
    public const CLIENT_3X = 3;
    public const CLIENT_4X = 4;

    /** @var \ElephantIO\EngineInterface */
    private $engine;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    private $isConnected = false;

    public function __construct(EngineInterface $engine, LoggerInterface $logger = null)
    {
        $this->engine = $engine;
        $this->logger = $logger ?: new NullLogger();
        $this->engine->setLogger($this->logger);
    }

    public function __destruct()
    {
        if (!$this->isConnected) {
            return;
        }

        $this->close();
    }

    /**
     * Connects to the websocket
     *
     * @return \ElephantIO\Client
     */
    public function initialize()
    {
        try {
            $this->logger->debug('Connecting to server');
            $this->engine->connect();
            $this->logger->debug('Connected to server');

            $this->isConnected = true;
        } catch (SocketException $e) {
            $this->logger->error('Could not connect to server', ['exception' => $e]);

            throw $e;
        }

        return $this;
    }

    /**
     * Reads a message from the socket
     *
     * @param float $timeout Timeout in seconds
     * @return string Message read from the socket
     */
    public function read($timeout = 0)
    {
        $this->logger->debug('Reading a new message from socket');

        return $this->engine->read($timeout);
    }

    /**
     * Emits a message through the engine
     *
     * @param string $event
     * @param array  $args
     * @return \ElephantIO\Client
     */
    public function emit($event, array $args)
    {
        $this->logger->debug('Sending a new message', ['event' => $event, 'args' => $args]);
        $this->engine->emit($event, $args);

        return $this;
    }

    /**
     * Wait an event arrived from the engine
     *
     * @param string $event
     * @return \stdClass
     */
    public function wait($event)
    {
        $this->logger->debug('Waiting for event', ['event' => $event]);

        return $this->engine->wait($event);
    }

    /**
     * Drain socket.
     *
     * @param float $timeout Timeout in seconds
     * @return mixed
     */
    public function drain($timeout = 0)
    {
        return $this->engine->drain($timeout);
    }

    /**
     * Sets the namespace for the next messages
     *
     * @param string namespace the name of the namespace
     * @return \ElephantIO\Client
     */
    public function of($namespace)
    {
        $this->logger->debug('Setting namespace', ['namespace' => $namespace]);
        $this->engine->of($namespace);

        return $this;
    }

    /**
     * Closes the connection
     *
     * @return \ElephantIO\Client
     */
    public function close()
    {
        $this->logger->debug('Closing connection to server');
        $this->engine->close();

        $this->isConnected = false;

        return $this;
    }

    /**
     * Gets the engine used, for more advanced functions
     *
     * @return \ElephantIO\EngineInterface
     */
    public function getEngine()
    {
        return $this->engine;
    }

    /**
     * Create socket.io engine.
     *
     * @param int $version
     * @param string $url
     * @param array $options
     * @throws \InvalidArgumentException
     * @return \ElephantIO\Engine\AbstractSocketIO
     */
    public static function engine($version, $url, $options = [])
    {
        switch ($version) {
            case static::CLIENT_0X:
                return new Version0X($url, $options);
            case static::CLIENT_1X:
                return new Version1X($url, $options);
            case static::CLIENT_2X:
                return new Version2X($url, $options);
            case static::CLIENT_3X:
                return new Version3X($url, $options);
            case static::CLIENT_4X:
                return new Version4X($url, $options);
            default:
                throw new InvalidArgumentException(sprintf('Unknown engine version %d!', $version));
        }
    }

    /**
     * Create socket client.
     *
     * Available options:
     * - client: client version
     * - logger: a Psr\Log\LoggerInterface instance
     *
     * Options not listed above will be passed to engine.
     *
     * @param string $url
     * @param array $options
     * @throws \InvalidArgumentException
     * @return \ElephantIO\Client
     */
    public static function create($url, $options = [])
    {
        $version = isset($options['client']) ? $options['client'] : static::CLIENT_4X;
        $logger = isset($options['logger']) ? $options['logger'] : null;
        unset($options['client'], $options['logger']);

        return new self(static::engine($version, $url, $options), $logger);
    }
}
