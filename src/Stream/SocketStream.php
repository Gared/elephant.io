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

namespace ElephantIO\Stream;

use RuntimeException;

use ElephantIO\Util;

/**
 * Basic stream to connect to the socket server which behave as an HTTP client.
 *
 * @author Toha <tohenk@yahoo.com>
 */
class SocketStream extends AbstractStream
{
    public const EOL = "\r\n";

    /**
     * @var resource
     */
    protected $handle = null;

    /**
     * @var array
     */
    protected $errors = null;

    /**
     * @var array
     */
    protected $result = null;

    /**
     * @var array
     */
    protected $metadata = null;

    /**
     * {@inheritDoc}
     */
    protected function initialize()
    {
        $autoConnect = isset($this->options['autoconnect']) ? $this->options['autoconnect'] : true;
        if ($autoConnect) {
            $this->connect();
        }
    }

    /**
     * Get connection timeout (in second).
     *
     * @return int
     */
    protected function getTimeout()
    {
        return isset($this->options['timeout']) ? $this->options['timeout'] : 5;
    }

    /**
     * Read metadata from socket.
     *
     * @return array
     */
    protected function readMetadata()
    {
        if (is_resource($this->handle)) {
            $this->metadata = stream_get_meta_data($this->handle);

            return $this->metadata;
        }
    }

    /**
     * Copied from https://stackoverflow.com/questions/10793017/how-to-easily-decode-http-chunked-encoded-string-when-making-raw-http-request
     */
    protected function decodeChunked($str)
    {
        for ($res = ''; !empty($str); $str = trim($str)) {
            $pos = strpos($str, "\r\n");
            $len = hexdec(substr($str, 0, $pos));
            $res .= substr($str, $pos + 2, $len);
            $str = substr($str, $pos + 2 + $len);
        }

        return $res;
    }

    /**
     * Normalize request headers from key-value pair array.
     *
     * @param array $headers
     * @return array
     */
    protected function normalizeHeaders($headers)
    {
        return array_map(
            function($key, $value) {
                return "$key: $value";
            },
            array_keys($headers),
            $headers
        );
    }

    /**
     * {@inheritDoc}
     */
    public function connect()
    {
        $errors = [null, null];
        $timeout = $this->getTimeout();
        $address = $this->url->getAddress();

        $this->logger->debug(sprintf('Socket connect %s', $address));
        $flags = STREAM_CLIENT_CONNECT;
        if (!isset($this->options['persistent']) || $this->options['persistent']) {
            $flags |= STREAM_CLIENT_PERSISTENT;
        }

        $context = !isset($this->context['headers']) ? $this->context :
            array_merge($this->context, ['headers' => $this->normalizeHeaders($this->context['headers'])]);

        $this->handle = @stream_socket_client(
            sprintf('%s/%s', $address, uniqid()),
            $errors[0],
            $errors[1],
            $timeout,
            $flags,
            stream_context_create($context)
        );

        if (is_resource($this->handle)) {
            stream_set_timeout($this->handle, $timeout);
            stream_set_blocking($this->handle, false);
        } else {
            $this->errors = $errors;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function connected()
    {
        if ($metadata = $this->readMetadata()) {
            return $metadata['eof'] ? false : true;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function read($size)
    {
        if (is_resource($this->handle)) {
            return fread($this->handle, $size);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function write($data)
    {
        $bytes = null;
        if (is_resource($this->handle)) {
            $data = (string) $data;
            $len = strlen($data);
            while (true) {
                if (false === ($written = fwrite($this->handle, $data))) {
                    throw new RuntimeException(sprintf('Unable to write %d data to stream!', strlen($data)));
                }
                if ($written > 0) {
                    $lines = explode(static::EOL, substr($data, 0, $written));
                    foreach ($lines as $line) {
                        $this->logger->debug(sprintf('Write data: %s', Util::truncate($line)));
                    }
                    if (null === $bytes) {
                        $bytes = $written;
                    } else {
                        $bytes += $written;
                    }
                    // all data has been written
                    if ($len === $bytes) {
                        break;
                    }
                    // this is the remaining data
                    $data = substr($data, $written);
                }
            }
        }

        return $bytes;
    }

    /**
     * {@inheritDoc}
     */
    public function request($uri, $headers = [], $options = [])
    {
        if (!is_resource($this->handle)) {
            return;
        }

        $method = isset($options['method']) ? $options['method'] : 'GET';
        $skip_body = isset($options['skip_body']) ? $options['skip_body'] : false;
        $payload = isset($options['payload']) ? $options['payload'] : null;

        if ($payload) {
            $contentType = $headers['Content-Type'] ?? null;

            if (null === $contentType) {
                $payload = mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1');
                $headers['Content-Type'] = 'text/plain; charset=UTF-8';
                $headers['Content-Length'] = strlen($payload);
            }
        }

        $headers['Host'] = $this->url->getHost();
        if (isset($this->options['headers'])) {
            $headers = array_merge($headers, $this->options['headers']);
        }
        $request = array_merge([
            sprintf('%s %s HTTP/1.1', strtoupper($method), $uri),
        ], $this->normalizeHeaders($headers));

        $request = implode(static::EOL, $request) . static::EOL . static::EOL . $payload;

        $this->write($request);

        $this->result = ['headers' => [], 'body' => null];

        // wait for response
        $header = true;
        $len = null;
        $this->logger->debug('Waiting for response!!!');
        while (true) {
            if (!$this->connected()) {
                break;
            }
            if ($content = ($header || $len === null) ? fgets($this->handle) : fread($this->handle, (int) $len)) {
                $this->logger->debug(sprintf('Receive: %s', trim($content)));
                if ($content === static::EOL && $header) {
                    if ($skip_body) {
                        break;
                    }
                    $header = false;
                } else {
                    if ($header) {
                        $this->result['headers'][] = trim($content);
                        if (null === $len && 0 === stripos($content, 'Content-Length:')) {
                            $len = (int) trim(substr($content, 16));
                        }
                    } else {
                        $this->result['body'] .= $content;
                        $isChunkEnd = $len === null && substr($content, -3) === '0' . static::EOL;
                        if ($isChunkEnd) {
                            $this->result['body'] = $this->decodeChunked($this->result['body']);
                            break;
                        }
                        if ($len === strlen($this->result['body'])) {
                            break;
                        }
                    }
                }
            }
            usleep($this->options['wait']);
        }

        return count($this->result['headers']) ? true : false;
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        if (!is_resource($this->handle)) {
            return;
        }
        @stream_socket_shutdown($this->handle, STREAM_SHUT_RDWR);
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * {@inheritDoc}
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * {@inheritDoc}
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /**
     * {@inheritDoc}
     */
    public function getHeaders()
    {
        return is_array($this->result) ? $this->result['headers'] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getBody()
    {
        return is_array($this->result) ? $this->result['body'] : null;
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus()
    {
        if (count($headers = $this->getHeaders())) {
            return $headers[0];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getStatusCode()
    {
        if (($status = $this->getStatus()) && preg_match('#^HTTP\/\d+\.\d+#', $status)) {
            list(, $code, ) = explode(' ', $status, 3);

            return $code;
        }
    }
}
