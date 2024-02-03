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

    public const SEPARATOR = "\u{fffd}";

    /** {@inheritDoc} */
    public function emit($event, array $args)
    {
        $this->send(static::PROTO_EVENT, json_encode(['name' => $event, 'args' => $this->replaceResources($args)]));
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

        $payload = $code . '::' . $this->namespace . ($message ? ':' . $message : '');
        $this->logger->debug(sprintf('Send data: %s', Util::truncate($payload)));

        switch ($this->transport) {
            case static::TRANSPORT_POLLING:
                return $this->doPoll(null, $payload) ? strlen($payload) : null;
            case static::TRANSPORT_WEBSOCKET:
                return $this->write((string) $this->getPayload($payload));
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

    /** {@inheritDoc} */
    protected function processData($data)
    {
        $result = null;
        if ($this->transport === static::TRANSPORT_POLLING && false !== strpos($data, static::SEPARATOR)) {
            $packets = explode(static::SEPARATOR, trim($data, static::SEPARATOR));
        } else {
            $packets = [$data];
        }
        $more = count($packets) > 1;
        while (count($packets)) {
            // skip length line if multiple packets found
            if ($more) {
                array_shift($packets);
            }
            $data = array_shift($packets);
            $this->logger->debug(sprintf('Processing data: %s', $data));
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
                    if (null === $result) {
                        $result = $packet;
                    } else {
                        if (!isset($result->next)) {
                            $result->next = [];
                        }
                        $result->next[] = $packet;
                    }
                    break;
            }
        }

        return $result;
    }

    /** {@inheritDoc} */
    protected function matchEvent($packet, $event)
    {
        if (($found = $this->peekPacket($packet, static::PROTO_EVENT)) && $this->matchNamespace($found->nsp) && $found->event === $event) {
            return $found;
        }
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
        $proto = $seq->readUntil(':');
        if (null === $proto && is_numeric($seq->getData())) {
            $proto = $seq->getData();
        }
        $proto = (int) $proto;
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
                        $this->replaceBuffers($packet->args);
                        $packet->data = count($packet->args) ? $packet->args[0] : null;
                    }
                    break;
            }

            return $packet;
        }
    }

    /**
     * Replace arguments with resource content.
     *
     * @param array $array
     * @return array
     */
    protected function replaceResources($array)
    {
        if (is_array($array)) {
            foreach ($array as &$value) {
                if (is_resource($value)) {
                    fseek($value, 0);
                    if ($content = stream_get_contents($value)) {
                        $value = $content;
                    } else {
                        $value = null;
                    }
                }
                if (is_array($value)) {
                    $value = $this->replaceResources($value);
                }
            }
        }

        return $array;
    }

    /**
     * Replace returned buffer content.
     *
     * @param array $array
     */
    protected function replaceBuffers(&$array)
    {
        if (is_array($array)) {
            foreach ($array as &$value) {
                if (is_array($value) && isset($value['type']) && isset($value['data'])) {
                    if ($value['type'] === 'Buffer') {
                        $value = implode(array_map('chr', $value['data']));
                    }
                }
                if (is_array($value)) {
                    $this->replaceBuffers($value);
                }
            }
        }
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

    protected function doHandshake()
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

    protected function doUpgrade()
    {
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

    protected function doSkipUpgrade()
    {
        // send get request to setup connection
        $this->doPoll();
    }

    protected function doChangeNamespace()
    {
        $this->send(static::PROTO_CONNECT);

        $packet = null;
        switch ($this->transport) {
            case static::TRANSPORT_POLLING:
                $packet = $this->decodePacket($this->stream->getBody());
                break;
            case static::TRANSPORT_WEBSOCKET:
                $packet = $this->drain();
                break;
        }

        if ($packet && $packet->proto === static::PROTO_CONNECT) {
            $this->logger->debug('Successfully connected');
        }

        return $packet;
    }

    protected function doClose()
    {
        $this->send(static::PROTO_DISCONNECT);
    }
}
