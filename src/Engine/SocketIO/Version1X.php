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
use ElephantIO\Exception\UnsuccessfulOperationException;
use ElephantIO\Payload\Encoder;
use ElephantIO\SequenceReader;
use ElephantIO\Util;
use ElephantIO\Yeast;

/**
 * Implements the dialog with Socket.IO version 1.x
 *
 * Based on the work of Mathieu Lallemand (@lalmat)
 *
 * @author Baptiste Clavié <baptiste@wisembly.com>
 * @link https://tools.ietf.org/html/rfc6455#section-5.2 Websocket's RFC
 */
class Version1X extends AbstractSocketIO
{
    public const PROTO_OPEN = 0;
    public const PROTO_CLOSE = 1;
    public const PROTO_PING = 2;
    public const PROTO_PONG = 3;
    public const PROTO_MESSAGE = 4;
    public const PROTO_UPGRADE = 5;
    public const PROTO_NOOP = 6;

    public const PACKET_CONNECT = 0;
    public const PACKET_DISCONNECT = 1;
    public const PACKET_EVENT = 2;
    public const PACKET_ACK = 3;
    public const PACKET_ERROR = 4;
    public const PACKET_BINARY_EVENT = 5;
    public const PACKET_BINARY_ACK = 6;

    public const SEPARATOR = "\x1e";

    /**
     * {@inheritDoc}
     */
    public function keepAlive()
    {
        if ($this->options['version'] <= 3 && $this->session->needsHeartbeat()) {
            $this->logger->debug('Sending PING');
            $this->send(static::PROTO_PING);
        }
    }

    /** {@inheritDoc} */
    public function emit($event, array $args)
    {
        $attachments = [];
        $this->getAttachments($args, $attachments);
        $type = count($attachments) ? static::PACKET_BINARY_EVENT : static::PACKET_EVENT;
        $data = $this->concatNamespace($this->namespace, json_encode([$event, $args]));
        if ($type === static::PACKET_BINARY_EVENT) {
            $data = sprintf('%d-%s', count($attachments), $data);
            $this->logger->debug(sprintf('Binary event arguments %s', json_encode($args)));
        }

        if ($this->transport === static::TRANSPORT_POLLING && count($attachments)) {
            foreach ($attachments as $attachment) {
                $data .= static::SEPARATOR;
                $data .= 'b' . base64_encode($attachment);
            }
        }
        $count = $this->send(static::PROTO_MESSAGE, $type . $data);
        if ($this->transport === static::TRANSPORT_WEBSOCKET) {
            foreach ($attachments as $attachment) {
                $count += $this->write($this->getPayload($attachment, Encoder::OPCODE_BINARY));
            }
        }

        return $count;
    }

    /** {@inheritDoc} */
    public function send($type, $data = null)
    {
        if (!$this->connected()) {
            return;
        }
        if (!is_int($type) || $type < static::PROTO_OPEN || $type > static::PROTO_NOOP) {
            throw new InvalidArgumentException('Wrong protocol type to sent to server');
        }

        $payload = $type . $data;
        $this->logger->debug(sprintf('Send data: %s', Util::truncate($payload)));

        switch ($this->transport) {
            case static::TRANSPORT_POLLING:
                return $this->doPoll(null, $payload) ? strlen($payload) : null;
            case static::TRANSPORT_WEBSOCKET:
                $payload = $this->getPayload($payload);
                if (count($fragments = $payload->encode()->getFragments()) > 1) {
                    throw new RuntimeException(sprintf(
                        'Payload is exceed the maximum allowed length of %d!',
                        $this->options['max_payload']
                    ));
                }
                return $this->write($fragments[0]);
        }
    }

    /** {@inheritDoc} */
    public function getName()
    {
        return 'SocketIO Version 1.X';
    }

    /** {@inheritDoc} */
    protected function getDefaultOptions()
    {
        return [
            'version' => 2,
            'use_b64' => false,
            'transport' => static::TRANSPORT_POLLING,
            'max_payload' => 10e7,
        ];
    }

    /** {@inheritDoc} */
    protected function processData($data)
    {
        // @see https://socket.io/docs/v4/engine-io-protocol/
        $result = null;
        if ($this->transport === static::TRANSPORT_POLLING && false !== strpos($data, static::SEPARATOR)) {
            $packets = explode(static::SEPARATOR, $data);
        } else {
            $packets = [$data];
        }
        while (count($packets)) {
            $data = array_shift($packets);
            $packet = $this->decodePacket($data);
            if ($packet->proto === static::PROTO_MESSAGE &&
                $packet->type === static::PACKET_BINARY_EVENT) {
                $packet->type = static::PACKET_EVENT;
                for ($i = 0; $i < $packet->binCount; $i++) {
                    $bindata = null;
                    switch ($this->transport) {
                        case static::TRANSPORT_POLLING:
                            $bindata = array_shift($packets);
                            $prefix = substr($bindata, 0, 1);
                            if ($prefix !== 'b') {
                                throw new RuntimeException(sprintf('Unable to decode binary data with prefix "%s"!', $prefix));
                            }
                            $bindata = base64_decode(substr($bindata, 1));
                            break;
                        case static::TRANSPORT_WEBSOCKET:
                            $bindata = $this->read();
                            break;
                    }
                    if (null === $bindata) {
                        throw new RuntimeException(sprintf('Binary data unavailable for index %d!', $i));
                    }
                    $this->replaceAttachment($packet->data, $i, $bindata);
                }
            }
            switch ($packet->proto) {
                case static::PROTO_CLOSE:
                    $this->logger->debug('Connection closed by server');
                    $this->reset();
                    throw new RuntimeException('Connection closed by server!');
                case static::PROTO_PING:
                    $this->logger->debug('Got PING, sending PONG');
                    $this->send(static::PROTO_PONG);
                    break;
                case static::PROTO_PONG:
                    $this->logger->debug('Got PONG');
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
        if (($found = $this->peekPacket($packet, static::PROTO_MESSAGE)) && $this->matchNamespace($found->nsp) && $found->event === $event) {
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
        $encoder = new Encoder($data, $encoding, true);
        $encoder->setMaxPayload($this->session->maxPayload ? $this->session->maxPayload : $this->options['max_payload']);

        return $encoder;
    }

    /**
     * Decode payload data.
     *
     * @param string $data
     * @return \stdClass[]
     */
    protected function decodeData($data)
    {
        $result = [];
        $seq = new SequenceReader($data);
        while (!$seq->isEof()) {
            $len = null;
            switch (true) {
                case $this->options['version'] >= 4:
                    $len = strlen($seq->getData());
                    break;
                case $this->options['version'] >= 3:
                    $len = (int) $seq->readUntil(':');
                    break;
                case $this->options['version'] >= 2:
                    $prefix = $seq->read();
                    if (ord($prefix) === 0) {
                        $len = 0;
                        $sizes = $seq->readUntil("\xff");
                        $n = strlen($sizes) - 1;
                        for ($i = 0; $i <= $n; $i++) {
                            $len += ord($sizes[$i]) * pow(10, $n - $i);
                        }
                    } else {
                        throw new RuntimeException('Unsupported encoding detected!');
                    }
                    break;
            }
            if (null === $len) {
                throw new RuntimeException('Data delimiter not found!');
            }

            $result[] = $this->decodePacket($seq->read($len));
        }

        return $result;
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
        $proto = (int) $seq->read();
        if ($proto >= static::PROTO_OPEN && $proto <= static::PROTO_NOOP) {
            $packet = new stdClass();
            $packet->proto = $proto;
            $packet->data = null;
            switch ($packet->proto) {
                case static::PROTO_OPEN:
                    if (!$seq->isEof()) {
                        $packet->data = json_decode($seq->getData(), true);
                    }
                    break;
                case static::PROTO_MESSAGE:
                    $packet->type = (int) $seq->read();
                    if ($packet->type === static::PACKET_BINARY_EVENT) {
                        $packet->binCount = (int) $seq->readUntil('-');
                        $seq->read();
                    }
                    $packet->nsp = $seq->readUntil(',[{', ['[', '{']);
                    if (null !== ($data = json_decode($seq->getData(), true))) {
                        switch ($packet->type) {
                            case static::PACKET_EVENT:
                            case static::PACKET_BINARY_EVENT:
                                $packet->event = array_shift($data);
                                $packet->args = $data;
                                $packet->data = count($data) ? $data[0] : null;
                                break;
                            default:
                                $packet->data = $data;
                                break;
                        }
                    }
                    break;
                default:
                    if (!$seq->isEof()) {
                        $packet->data = $seq->getData();
                    }
                    break;
            }

            return $packet;
        }
    }

    /**
     * Get attachment from packet data. A packet data considered as attachment
     * if it's a resource and it has content.
     *
     * @param array $array
     * @param array $result
     */
    protected function getAttachments(&$array, &$result)
    {
        if (is_array($array)) {
            foreach ($array as &$value) {
                if (is_resource($value)) {
                    fseek($value, 0);
                    if ($content = stream_get_contents($value)) {
                        $idx = count($result);
                        $result[] = $content;
                        $value = ['_placeholder' => true, 'num' => $idx];
                    } else {
                        $value = null;
                    }
                }
                if (is_array($value)) {
                    $this->getAttachments($value, $result);
                }
            }
        }
    }

    /**
     * Replace binary attachment.
     *
     * @param array $array
     * @param int $index
     * @param string $data
     */
    protected function replaceAttachment(&$array, $index, $data)
    {
        if (is_array($array)) {
            foreach ($array as $key => &$value) {
                if (is_array($value)) {
                    if (isset($value['_placeholder']) && $value['_placeholder'] && $value['num'] === $index) {
                        $value = $data;
                        $this->logger->debug(sprintf('Replacing binary attachment for %d (%s)', $index, $key));
                    } else {
                        $this->replaceAttachment($value, $index, $data);
                    }
                }
            }
        }
    }

    /**
     * Get authentication payload handshake.
     *
     * @return string
     */
    protected function getAuthPayload()
    {
        if (!isset($this->options['auth']) || !$this->options['auth'] || $this->options['version'] < 4) {
            return '';
        }
        if (($authData = json_encode($this->options['auth'])) === false) {
            throw new InvalidArgumentException(sprintf('Can\'t parse auth option JSON: %s!', json_last_error_msg()));
        }

        return $authData;
    }

    /**
     * Get confirmed namespace result. Namespace is confirmed if the returned
     * value is true, otherwise failed. If the return value is a string, it's
     * indicated an error message.
     *
     * @param \stdClass $packet
     * @return bool|string
     */
    protected function getConfirmedNamespace($packet)
    {
        if ($packet && $packet->proto === static::PROTO_MESSAGE) {
            if ($packet->type === static::PACKET_CONNECT) {
                return true;
            }
            if ($packet->type === static::PACKET_ERROR) {
                return isset($packet->data['message']) ? $packet->data['message'] : false;
            }
        }
    }

    protected function buildQueryParameters($transport)
    {
        $parameters = [
            'EIO' => $this->options['version'],
            'transport' => $transport ?? $this->transport,
            't' => Yeast::yeast(),
        ];
        if ($this->session) {
            $parameters['sid'] = $this->session->id;
        }
        if ($this->options['use_b64']) {
            $parameters['b64'] = 1;
        }

        return $parameters;
    }

    protected function buildQuery($query)
    {
        $url = $this->stream->getUrl()->getParsed();
        if (isset($url['query']) && $url['query']) {
            $query = array_replace($query, $url['query']);
        }

        return sprintf('/%s/?%s', trim($url['path'], '/'), http_build_query($query));
    }

    protected function doHandshake()
    {
        if (null !== $this->session) {
            return;
        }

        $this->logger->debug('Starting handshake');

        // set timeout to default
        $this->setTimeout($this->defaults['timeout']);

        $success = null;
        switch ($this->transport) {
            case static::TRANSPORT_POLLING:
                $success = $this->doPoll() == 200;
                break;
            case static::TRANSPORT_WEBSOCKET:
                $success = $this->doPoll(null, null, $this->getUpgradeHeaders(), ['skip_body' => true]) == 101;
                break;
        }
        if (!$success) {
            throw new ServerConnectionFailureException('unable to perform handshake');
        }

        $handshake = null;
        switch ($this->transport) {
            case static::TRANSPORT_POLLING:
                if (count($packets = $this->decodeData($this->stream->getBody()))) {
                    if ($packet = $this->peekPacket($packets, static::PROTO_OPEN)) {
                        $handshake = $packet->data;
                    }
                }
                break;
            case static::TRANSPORT_WEBSOCKET:
                if ($packet = $this->drain()) {
                    $handshake = $packet->data;
                }
                break;
        }
        if (null === $handshake) {
            throw new RuntimeException('Handshake is successful but without data!');
        }
        array_walk($handshake, function(&$value, $key) {
            if (in_array($key, ['pingInterval', 'pingTimeout'])) {
                $value /= 1000;
            }
        });
        $this->storeSession($handshake, $this->stream->getHeaders());

        $this->logger->debug(sprintf('Handshake finished with %s', (string) $this->session));
    }

    protected function doAfterHandshake()
    {
        // connect to namespace for protocol version 4 and later
        if ($this->options['version'] < 4) {
            return;
        }

        $this->logger->debug('Starting namespace connect');

        // set timeout based on handshake response
        $this->setTimeout($this->session->getTimeout());

        $this->doChangeNamespace();

        $this->logger->debug('Namespace connect completed');
    }

    protected function doUpgrade()
    {
        $this->logger->debug('Starting websocket upgrade');

        // set timeout based on handshake response
        $this->setTimeout($this->session->getTimeout());

        if ($this->doPoll(static::TRANSPORT_WEBSOCKET, null, $this->getUpgradeHeaders(), ['skip_body' => true]) == 101) {
            $this->setTransport(static::TRANSPORT_WEBSOCKET);

            $this->send(static::PROTO_UPGRADE);

            // ensure got packet connect on socket.io 1.x
            if ($this->options['version'] === 2 && $packet = $this->drain()) {
                if ($packet->proto === static::PROTO_MESSAGE && $packet->type === static::PACKET_CONNECT) {
                    $this->logger->debug('Upgrade successfully confirmed');
                } else {
                    $this->logger->debug('Upgrade not confirmed');
                }
            }
    
            $this->logger->debug('Websocket upgrade completed');
        } else {
            $this->logger->debug('Upgrade failed, skipping websocket');
        }
    }

    protected function doChangeNamespace()
    {
        if (!$this->session) {
            throw new RuntimeException('To switch namespace, a session must has been established!');
        }

        $this->send(static::PROTO_MESSAGE, static::PACKET_CONNECT . $this->concatNamespace($this->namespace, $this->getAuthPayload()));

        $packet = null;
        switch ($this->transport) {
            case static::TRANSPORT_POLLING:
                $this->doPoll();
                $packet = $this->decodePacket($this->stream->getBody());
                break;
            case static::TRANSPORT_WEBSOCKET:
                $packet = $this->drain();
                break;
        }

        if (true === ($result = $this->getConfirmedNamespace($packet))) {
            return $packet;
        }
        if (is_string($result)) {
            throw new UnsuccessfulOperationException(sprintf('Unable to switch namespace: %s!', $result));
        } else {
            throw new UnsuccessfulOperationException('Unable to switch namespace!');
        }
    }

    protected function doClose()
    {
        $this->send(static::PROTO_CLOSE);
    }
}
