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

namespace ElephantIO\Engine\SocketIO;

use InvalidArgumentException;
use RuntimeException;
use stdClass;
use ElephantIO\Engine\AbstractSocketIO;
use ElephantIO\Exception\ServerConnectionFailureException;
use ElephantIO\Payload\Encoder;
use ElephantIO\SequenceReader;
use ElephantIO\Util;

/**
 * Implements the dialog with Socket.IO version 0.x
 *
 * Based on the work of Baptiste Clavié (@Taluu)
 *
 * @auto ByeoungWook Kim <quddnr145@gmail.com>
 * @link https://tools.ietf.org/html/rfc6455#section-5.2 Websocket's RFC
 */
class Version0X extends AbstractSocketIO
{
    public const PROTO_DISCONNECT = 0;
    public const PROTO_CONNECT = 1;
    public const PROTO_HEARTBEAT = 2;
    public const PROTO_MESSAGE = 3;
    public const PROTO_JSON = 4;
    public const PROTO_EVENT = 5;
    public const PROTO_ACK = 6;
    public const PROTO_ERROR = 7;
    public const PROTO_NOOP = 8;

    /** {@inheritDoc} */
    public function connect()
    {
        if ($this->connected()) {
            return;
        }

        $this->setTransport($this->options['transport']);
        $this->handshake();
        $this->upgradeTransport();
    }

    /** {@inheritDoc} */
    public function close()
    {
        if (!$this->connected()) {
            return;
        }

        if ($this->session) {
            $this->send(static::PROTO_DISCONNECT);
        }
        $this->reset();
    }

    /** {@inheritDoc} */
    public function emit($event, array $args)
    {
        $this->send(static::PROTO_EVENT, json_encode(['name' => $event, 'args' => $args]));
    }

    /** {@inheritDoc} */
    public function wait($event)
    {
        while (true) {
            if ($packet = $this->drain(0)) {
                if ($packet->proto === static::PROTO_EVENT && $this->matchNamespace($packet->nsp)) {
                    return $packet;
                }
            }
        }
    }

    /** {@inheritDoc} */
    public function drain($timeout = 0)
    {
        $result = null;
        $data = $this->read($timeout);
        if (null !== $data) {
            $this->logger->debug(sprintf('Got data: %s', Util::truncate((string) $data)));
            $packet = $this->decodePacket($data);
            switch ($packet->proto) {
                case static::PROTO_DISCONNECT:
                    $this->logger->debug('Connection closed by server');
                    $this->reset();
                    throw new RuntimeException('Connection closed by server!');
                case static::PROTO_HEARTBEAT:
                    $this->logger->debug('Got HEARTBEAT');
                    $this->send(static::PROTO_HEARTBEAT);
                    break;
                case static::PROTO_NOOP:
                    break;
                default:
                    $result = $packet;
                    break;
            }
        }

        return $result;
    }

    /** {@inheritDoc} */
    public function send($code, $message = null)
    {
        if (!$this->connected()) {
            return;
        }
        if (!is_int($code) || $code < static::PROTO_DISCONNECT || $code > static::PROTO_NOOP) {
            throw new InvalidArgumentException('Wrong message type to sent to socket');
        }
        $payload = $this->getPayload($code . '::' . $this->normalizeNamespace($this->namespace) . ($message ? ':' . $message : ''));

        return $this->write((string) $payload);
    }

    /**
     * Write to the stream.
     *
     * @param string $data
     * @return int
     */
    protected function write($data)
    {
        $bytes = $this->stream->write($data);
        if ($this->session) {
            $this->session->resetHeartbeat();
        }

        // wait a little bit of time after this message was sent
        \usleep((int) $this->options['wait']);

        return $bytes;
    }

    /** {@inheritDoc} */
    public function of($namespace)
    {
        parent::of($namespace);

        $this->send(static::PROTO_CONNECT);
        if (($packet = $this->drain()) && $packet->proto === static::PROTO_CONNECT) {
            $this->logger->debug('Successfully connected');
        }
    }

    /** {@inheritDoc} */
    public function getName()
    {
        return 'SocketIO Version 0.X';
    }

    /** {@inheritDoc} */
    protected function getDefaultOptions()
    {
        return [
            'version' => 1,
            'transport' => static::TRANSPORT_POLLING,
        ];
    }

    /**
     * Create payload.
     *
     * @param string $data
     * @param int $encoding
     * @throws \InvalidArgumentException
     * @return \ElephantIO\Payload\Encoder
     */
    protected function getPayload($data, $encoding = Encoder::OPCODE_TEXT)
    {
        return new Encoder($data, $encoding, true);
    }

    /**
     * Decode a packet.
     *
     * @param string $data
     * @return \stdClass
     */
    protected function decodePacket($data)
    {
        $seq = new SequenceReader($data);
        $proto = (int) $seq->readUntil(':');
        if ($proto >= static::PROTO_DISCONNECT && $proto <= static::PROTO_NOOP) {
            $ack = $seq->readUntil(':');
            $packet = new stdClass();
            $packet->proto = $proto;
            $packet->nsp = $seq->readUntil(':');
            $packet->data = !$seq->isEof() ? $seq->getData() : null;
            switch ($packet->proto) {
                case static::PROTO_MESSAGE:
                case static::PROTO_JSON:
                    if ($packet->data) {
                        $packet->data = json_decode($packet->data, true);
                    }
                    break;
                case static::PROTO_EVENT:
                    if ($packet->data) {
                        $data = json_decode($packet->data, true);
                        $packet->event = $data['name'];
                        $packet->args = $data['args'];
                        $packet->data = count($packet->args) ? $packet->args[0] : null;
                    }
                    break;
            }

            return $packet;
        }
    }

    /**
     * Do the handshake with the socket.io server and populates the `session` value object.
     */
    protected function handshake()
    {
        if (null !== $this->session) {
            return;
        }

        $this->logger->debug('Starting handshake');

        // set timeout to default
        $this->setTimeout($this->defaults['timeout']);

        if ($this->doPoll() != 200) {
            throw new ServerConnectionFailureException('unable to perform handshake');
        }

        $sess = explode(':', $this->stream->getBody());
        $handshake = [
            'sid' => $sess[0],
            'pingInterval' => $sess[1],
            'pingTimeout' => $sess[2],
            'upgrades' => explode(',', $sess[3]),
        ];
        $this->storeSession($handshake, $this->stream->getHeaders());

        $this->logger->debug(sprintf('Handshake finished with %s', (string) $this->session));
    }

    /** Upgrades the transport to WebSocket */
    protected function upgradeTransport()
    {
        // check if websocket upgrade is needed
        if (!in_array(static::TRANSPORT_WEBSOCKET, $this->session->upgrades) ||
            !$this->isTransportEnabled(static::TRANSPORT_WEBSOCKET)) {
            return;
        }

        $this->logger->debug('Starting websocket upgrade');

        // set timeout based on handshake response
        $this->setTimeout($this->session->getTimeout());

        if ($this->doPoll(static::TRANSPORT_WEBSOCKET, null, $this->getUpgradeHeaders(), ['skip_body' => true]) == 101) {
            $this->setTransport(static::TRANSPORT_WEBSOCKET);

            $this->logger->debug('Websocket upgrade completed');
        } else {
            $this->logger->debug('Upgrade failed, skipping websocket');
        }
    }

    /** {@inheritDoc} */
    protected function getTransports()
    {
        return [static::TRANSPORT_POLLING, static::TRANSPORT_WEBSOCKET];
    }

    protected function buildQueryParameters($transport)
    {
        $transports = [static::TRANSPORT_POLLING => 'xhr-polling'];
        $transport = $transport ?? $this->options['transport'];
        if (isset($transports[$transport])) {
            $transport = $transports[$transport];
        }
        $path = [$this->options['version'], $transport];
        if ($this->session) {
            $path[] = $this->session->id;
        }

        return ['path' => $path];
    }

    protected function buildQuery($query)
    {
        $url = $this->stream->getUrl()->getParsed();
        $uri = sprintf('/%s/%s', trim($url['path'], '/'), implode('/', $query['path']));
        if (isset($url['query']) && $params = http_build_query($url['query'])) {
            $uri .= '/?' . $params;
        }

        return $uri;
    }
}
