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

namespace ElephantIO\Engine;

use Psr\Log\LoggerAwareTrait;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use ElephantIO\EngineInterface;
use ElephantIO\Exception\SocketException;
use ElephantIO\Exception\UnsupportedActionException;
use ElephantIO\Payload\Decoder;
use ElephantIO\Stream\AbstractStream;
use ElephantIO\Util;

abstract class AbstractSocketIO implements EngineInterface
{
    use LoggerAwareTrait;

    public const PACKET_CONNECT = 0;
    public const PACKET_DISCONNECT = 1;
    public const PACKET_EVENT = 2;
    public const PACKET_ACK = 3;
    public const PACKET_ERROR = 4;
    public const PACKET_BINARY_EVENT = 5;
    public const PACKET_BINARY_ACK = 6;

    /** @var string[] Parse url result */
    protected $url;

    /** @var array Cookies received during handshake */
    protected $cookies = [];

    /** @var \ElephantIO\Engine\Session Session information */
    protected $session;

    /** @var mixed[] Array of default options for the engine */
    protected $defaults;

    /** @var mixed[] Array of options for the engine */
    protected $options;

    /** @var \ElephantIO\StreamInterface Resource to the connected stream */
    protected $stream;

    /** @var string The namespace of the next message */
    protected $namespace = '';

    /** @var string Current socket transport */
    protected $transport = null;

    /** @var mixed[] Array of php stream context options */
    protected $context = [];

    public function __construct($url, array $options = [])
    {
        $this->url = $url;

        if (isset($options['headers'])) {
            $this->handleDeprecatedHeaderOptions($options['headers']);
        }

        if (isset($options['context']['headers'])) {
            $this->handleDeprecatedHeaderOptions($options['context']['headers']);
        }

        if (isset($options['context'])) {
            $this->context = $options['context'];
            unset($options['context']);
        }

        $this->defaults = array_merge([
            'debug' => false,
            'wait' => 50, // 50 ms
            'timeout' => \ini_get('default_socket_timeout'),
            'reuse_connection' => true,
            'transports' => null,
        ], $this->getDefaultOptions());
        $this->options = \array_replace($this->defaults, $options);
    }

    /**
     * Get options.
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Get current socket transport.
     *
     * @return string
     */
    public function getTransport()
    {
        return $this->transport;
    }

    /**
     * Set current socket transport.
     *
     * @param string $transport Socket transport name
     * @return \ElephantIO\Engine\AbstractSocketIO
     */
    public function setTransport($transport)
    {
        if (!in_array($transport, $this->getTransports())) {
            throw new InvalidArgumentException(sprintf('Unsupported transport "%s"!', $transport));
        }
        $this->transport = $transport;

        return $this;
    }

    /** {@inheritDoc} */
    public function connected()
    {
        return $this->stream ? $this->stream->connected() : false;
    }

    /** {@inheritDoc} */
    public function connect()
    {
        throw new UnsupportedActionException($this, 'connect');
    }

    /** {@inheritDoc} */
    public function keepAlive()
    {
    }

    /** {@inheritDoc} */
    public function close()
    {
        throw new UnsupportedActionException($this, 'close');
    }

    /** {@inheritDoc} */
    public function of($namespace)
    {
        $this->namespace = $namespace;
    }

    /**
     * Send message to the socket.
     *
     * @param integer $code    type of message (one of EngineInterface constants)
     * @param string  $message Message to send, correctly formatted
     */
    abstract public function send($code, $message = null);

    /** {@inheritDoc} */
    public function emit($event, array $args)
    {
        throw new UnsupportedActionException($this, 'emit');
    }

    /** {@inheritDoc} */
    public function wait($event)
    {
        throw new UnsupportedActionException($this, 'wait');
    }

    /** {@inheritDoc} */
    public function drain($timeout = 0)
    {
        throw new UnsupportedActionException($this, 'drain');
    }

    /**
     * Network safe fread wrapper.
     *
     * @param integer $bytes
     * @param int $timeout
     * @return bool|string
     */
    protected function readBytes($bytes, $timeout = 0)
    {
        $data = '';
        $chunk = null;
        $start = microtime(true);
        while ($bytes > 0) {
            if ($timeout > 0 && microtime(true) - $start >= $timeout) {
                break;
            }
            if (!$this->stream->connected()) {
                throw new RuntimeException('Stream disconnected');
            }
            $this->keepAlive();
            if (false === ($chunk = $this->stream->read($bytes))) {
                break;
            }
            $bytes -= \strlen($chunk);
            $data .= $chunk;
        }
        if (false === $chunk) {
            throw new RuntimeException('Could not read from stream');
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     *
     * Be careful, this method may hang your script, as we're not in a non
     * blocking mode.
     */
    public function read($timeout = 0)
    {
        if (!$this->stream || !$this->stream->connected()) {
            return;
        }

        /*
         * The first byte contains the FIN bit, the reserved bits, and the
         * opcode... We're not interested in them. Yet.
         * the second byte contains the mask bit and the payload's length
         */
        $data = $this->readBytes(2, $timeout);
        $bytes = \unpack('C*', $data);

        if (empty($bytes[2])) {
            return;
        }

        $mask = ($bytes[2] & 0b10000000) >> 7;
        $length = $bytes[2] & 0b01111111;

        /*
         * Here is where it is getting tricky :
         *
         * - If the length <= 125, then we do not need to do anything ;
         * - if the length is 126, it means that it is coded over the next 2 bytes ;
         * - if the length is 127, it means that it is coded over the next 8 bytes.
         *
         * But, here's the trick : we cannot interpret a length over 127 if the
         * system does not support 64bits integers (such as Windows, or 32bits
         * processors architectures).
         */
        switch ($length) {
            case 0x7D: // 125
                break;
            case 0x7E: // 126
                $data .= $bytes = $this->readBytes(2);
                $bytes = \unpack('n', $bytes);

                if (empty($bytes[1])) {
                    throw new RuntimeException('Invalid extended packet len');
                }

                $length = $bytes[1];
                break;
            case 0x7F: // 127
                // are (at least) 64 bits not supported by the architecture ?
                if (8 > PHP_INT_SIZE) {
                    throw new DomainException('64 bits unsigned integer are not supported on this architecture');
                }

                /*
                 * As (un)pack does not support unpacking 64bits unsigned
                 * integer, we need to split the data
                 *
                 * {@link http://stackoverflow.com/questions/14405751/pack-and-unpack-64-bit-integer}
                 */
                $data .= $bytes = $this->readBytes(8);
                list($left, $right) = \array_values(\unpack('N2', $bytes));
                $length = $left << 32 | $right;
                break;
        }

        // incorporate the mask key if the mask bit is 1
        if (true === $mask) {
            $data .= $this->readBytes(4);
        }

        $data .= $this->readBytes($length);
        $this->logger->debug(sprintf('Receiving data: %s', Util::truncate($data)));

        // decode the payload
        return new Decoder($data);
    }

    /** {@inheritDoc} */
    public function getName()
    {
        return 'SocketIO';
    }

    /**
     * Handles deprecated header options in an array.
     *
     * This function checks the format of the provided array of headers. If the headers are in the old
     * non-associative format (numeric indexed), it triggers a deprecated warning and converts them
     * to the new key-value array format.
     *
     * @param array $headers A reference to the array of HTTP headers to be processed. This array may
     *                      be modified if the headers are in the deprecated format.
     *
     * @return void This function modifies the input array in place and does not return any value.
     */
    protected function handleDeprecatedHeaderOptions(&$headers)
    {
        if (is_array($headers) && count($headers) > 0) {
            // Check if the array is not associative (indicating old format)
            if (array_values($headers) == $headers) {
                trigger_error('You are using a deprecated header format. Please update to the new key-value array format.', E_USER_DEPRECATED);
                $newHeaders = [];
                foreach ($headers as $header) {
                    list($key, $value) = explode(': ', $header, 2);
                    $newHeaders[$key] = $value;
                }
                $headers = $newHeaders; // Convert to new format
            }
        }
    }

    /**
     * Get the defaults options.
     *
     * @return array Defaults options for this engine
     */
    protected function getDefaultOptions()
    {
        return [];
    }

    /**
     * Get connection default headers.
     *
     * @return array
     */
    protected function getDefaultHeaders()
    {
        return [
            'Connection' => $this->options['reuse_connection'] ? 'keep-alive' : 'close',
        ];
    }

    /**
     * Store successful connection handshake as session.
     *
     * @param array $handshake
     * @param array $headers
     */
    protected function storeSession($handshake, $headers = [])
    {
        $cookies = [];
        if (is_array($headers) && count($headers)) {
            foreach ($headers as $header) {
                $matches = null;
                if (preg_match('/^Set-Cookie:\s*([^;]*)/i', $header, $matches)) {
                    $cookies[] = $matches[1];
                }
            }
        }
        $this->cookies = $cookies;
        $this->session = new Session(
            $handshake['sid'],
            $handshake['pingInterval'],
            $handshake['pingTimeout'],
            $handshake['upgrades'],
            isset($handshake['maxPayload']) ? $handshake['maxPayload'] : null
        );
    }

    /**
     * Get underlying socket stream.
     *
     * @return \ElephantIO\StreamInterface
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Create socket stream.
     *
     * @throws \ElephantIO\Exception\SocketException
     */
    protected function createStream()
    {
        if ($this->stream && !$this->options['reuse_connection']) {
            $this->logger->debug('Closing socket connection');
            $this->stream->close();
            $this->stream = null;
        }
        if (!$this->stream) {
            $this->stream = AbstractStream::create($this->url, $this->context, array_merge($this->options, ['logger' => $this->logger]));
            if ($errors = $this->stream->getErrors()) {
                throw new SocketException($errors[0], $errors[1]);
            }
        }
    }

    /**
     * Update or set connection timeout.
     *
     * @param int $timeout
     * @return \ElephantIO\Engine\AbstractSocketIO
     */
    protected function setTimeout($timeout)
    {
        // stream already established?
        if ($this->options['reuse_connection'] && $this->stream) {
            $this->stream->setTimeout($timeout);
        } else {
            $this->options['timeout'] = $timeout;
        }

        return $this;
    }

    /**
     * Check if socket transport is enabled.
     *
     * @param string $transport
     * @return bool
     */
    protected function isTransportEnabled($transport)
    {
        $transports = $this->options['transports'];

        return
            null === $transports ||
            $transport === $transports ||
            (is_array($transports) && in_array($transport, $transports)) ? true : false;
    }

    /**
     * Get supported socket transports.
     *
     * @return string[]
     */
    protected function getTransports()
    {
        return [];
    }

    /**
     * Build query parameters.
     *
     * @param string $transport
     * @return array
     */
    protected function buildQueryParameters($transport)
    {
        return [];
    }

    /**
     * Build query from parameters.
     *
     * @param array $query
     * @return string
     */
    protected function buildQuery($query)
    {
    }

    /**
     * Perform HTTP polling request.
     *
     * @param string $data
     * @param string $transport
     * @param array $headers
     * @param array $options
     * @return int Response status code
     */
    protected function doPoll($transport = null, $data = null, $headers = [], $options = [])
    {
        $this->createStream();

        $uri = $this->buildQuery($this->buildQueryParameters($transport));
        if ($data) {
            $options['method'] = 'POST';
            $options['payload'] = $data;
        }
        $this->stream->request($uri, array_merge($this->getDefaultHeaders(), $headers), $options);

        return $this->stream->getStatusCode();
    }

    /**
     * Do reset.
     */
    protected function reset()
    {
        if ($this->stream) {
            $this->stream->close();
            $this->stream = null;
            $this->session = null;
            $this->cookies = [];
        }
    }
}
