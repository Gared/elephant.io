# Elephant.io

![Build Status](https://github.com/ElephantIO/elephant.io/actions/workflows/continuous-integration.yml/badge.svg)
[![Latest Stable Version](https://poser.pugx.org/elephantio/elephant.io/v/stable.svg)](https://packagist.org/packages/elephantio/elephant.io)
[![Total Downloads](https://poser.pugx.org/elephantio/elephant.io/downloads.svg)](https://packagist.org/packages/elephantio/elephant.io) 
[![License](https://poser.pugx.org/elephantio/elephant.io/license.svg)](https://packagist.org/packages/elephantio/elephant.io)

```
        ___     _,.--.,_         Elephant.io is a rough websocket client
      .-~   ~--"~-.   ._ "-.     written in PHP. Its goal is to ease the
     /      ./_    Y    "-. \    communications between your PHP Application and
    Y       :~     !         Y   a real-time server.
    lq p    |     /         .|
 _   \. .-, l    /          |j   Requires PHP 7.2 and openssl, licensed under
()\___) |/   \_/";          !    the MIT License.
 \._____.-~\  .  ~\.      ./
            Y_ Y_. "vr"~  T      Built-in Engines:
            (  (    |L    j      - Socket.io 4.x, 3.x, 2.x, 1.x
            [nn[nn..][nn..]      - Socket.io 0.x (courtesy of @kbu1564)
          ~~~~~~~~~~~~~~~~~~~
```

## Installation

We are suggesting you to use composer, using `composer require elephantio/elephant.io`. For other ways, you can check the release page, or the git clone urls.

## Usage

To use Elephant.io to communicate with socket server is described as follows.

```php
<?php

use ElephantIO\Client;

$url = 'http://localhost:8080';

// if client option is omitted then it will use latest client available,
// aka. version 4.x
$options = ['client' => Client::CLIENT_4X];

$client = Client::create($url, $options);
$client->initialize();
$client->of('/');

// emit an event to the server
$data = ['username' => 'my-user'];
$client->emit('get-user-info', $data);

// wait an event to arrive
// beware when waiting for response from server, the script may be killed if
// PHP max_execution_time is reached
if ($packet = $client->wait('user-info')) {
    // an event has been received, the result will be a stdClass
    // data property contains the first argument
    // args property contains array of arguments, [$data, ...]
    $data = $packet->data;
    $args = $packet->args;
    // access data
    $email = $data['email'];
}
```

## Options

Elephant.io accepts options to configure the internal engine such as passing headers, providing additional
authentication token, or providing stream context.

* `headers`

  An array of key-value pair to be sent as request headers. For example, pass a bearer token to the server.

  ```php
  <?php

  $options = [
      'headers' => [
          'Authorization' => 'Bearer MYTOKEN',
      ]
  ];
  $client = Client::create($url, $options);
  ```

* `auth`

  Specify an array to be passed as handshake. The data to be passed depends on the server implementation.

  ```php
  <?php

  $options = [
      'auth' => [
          'user' => 'user@example.com',
          'token' => 'my-secret-token',
      ]
  ];
  $client = Client::create($url, $options);
  ```

  On the server side, those data can be accessed using:

  ```js
  io.use((socket, next) => {
      const user = socket.handshake.auth.user;
      const token = socket.handshake.auth.token;
      // do something with data
  });
  ```

* `context`
  
  A [stream context](https://www.php.net/manual/en/function.stream-context-create.php) options for the socket stream.

  ```php
  <?php

  $options = [
      'context' => [
          'http' => [],
          'ssl' => [],
      ]
  ];
  $client = Client::create($url, $options);
  ```

* `persistent`

  The socket connection by default will be using a persistent connection. If you prefer for some
  reasons to disable it, set `persistent` to `false`.

* `reuse_connection`

  Enable or disable existing connection reuse, by default the engine will reuse existing
  connection. To disable to reuse existing connection set `reuse_connection` to `false`.

* `transports`

  An array of enabled transport. Set to `null` or combination of `polling` or `websocket` to enable
  specific transport.

* `transport`

  Initial socket transport used to connect to server, either `polling` or `websocket` is supported.
  The default transport used is `polling` and it will be upgraded to `websocket` if the server offer
  to upgrade and `transports` option does not exclude `websocket`.

  To connect to server with `polling` only transport:

  ```php
  <?php

  $options = [
      'transport' => 'polling',     // can be omitted as polling is default transport
      'transports' => ['polling'],
  ];
  $client = Client::create($url, $options);
  ```

  To connect to server with `websocket` only transport:

  ```php
  <?php

  $options = [
      'transport' => 'websocket',
  ];
  $client = Client::create($url, $options);
  ```

## Examples

The [the example directory](/example) shows how to get a basic knowledge of library usage.

## Special Thanks

Special thanks goes to Mark Karpeles who helped the project founder to understand the way websockets works.
