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

## Debugging

It's sometime necessary to get the verbose output for debugging. Elephant.io utilizes `Psr\Log\LoggerInterface`
for this purpose.

```php
<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Log\LogLevel;

$logfile = __DIR__ . '/socket.log';

$logger = new Logger('elephant.io');
$logger->pushHandler(new StreamHandler($logfile, LogLevel::DEBUG)); // set LogLevel::INFO for brief logging

$options = ['logger' => $logger];

$client = Client::create($url, $options);
...
```

Here is an example of debug logging:

```log
[2024-02-04T16:11:56.015041+07:00] elephant.io.INFO: Connecting to server [] []
[2024-02-04T16:11:56.016737+07:00] elephant.io.INFO: Starting handshake [] []
[2024-02-04T16:11:56.017782+07:00] elephant.io.INFO: Socket connect tcp://localhost:14000 [] []
[2024-02-04T16:11:56.022344+07:00] elephant.io.DEBUG: Write data: GET /socket.io/?EIO=4&transport=polling&t=OrpOtjW HTTP/1.1 [] []
[2024-02-04T16:11:56.022399+07:00] elephant.io.DEBUG: Write data: Host: localhost:14000 [] []
[2024-02-04T16:11:56.022425+07:00] elephant.io.DEBUG: Write data: Connection: keep-alive [] []
[2024-02-04T16:11:56.022443+07:00] elephant.io.DEBUG: Write data:  [] []
[2024-02-04T16:11:56.022461+07:00] elephant.io.DEBUG: Write data:  [] []
[2024-02-04T16:11:56.022480+07:00] elephant.io.DEBUG: Waiting for response... [] []
[2024-02-04T16:11:56.028340+07:00] elephant.io.DEBUG: Receive: HTTP/1.1 200 OK [] []
[2024-02-04T16:11:56.044420+07:00] elephant.io.DEBUG: Receive: Content-Type: text/plain; charset=UTF-8 [] []
[2024-02-04T16:11:56.046469+07:00] elephant.io.DEBUG: Receive: Content-Length: 118 [] []
[2024-02-04T16:11:56.062623+07:00] elephant.io.DEBUG: Receive: cache-control: no-store [] []
[2024-02-04T16:11:56.078634+07:00] elephant.io.DEBUG: Receive: Date: Sun, 04 Feb 2024 09:11:56 GMT [] []
[2024-02-04T16:11:56.094206+07:00] elephant.io.DEBUG: Receive: Connection: keep-alive [] []
[2024-02-04T16:11:56.109975+07:00] elephant.io.DEBUG: Receive: Keep-Alive: timeout=5 [] []
[2024-02-04T16:11:56.125569+07:00] elephant.io.DEBUG: Receive:  [] []
[2024-02-04T16:11:56.141312+07:00] elephant.io.DEBUG: Receive: 0{"sid":"bFjtgbDtgyXss-eMAABm","upgrades":["websocket"],"pingInterval":25000,"pingTimeout":20000,"ma... 18 more [] []
[2024-02-04T16:11:56.143079+07:00] elephant.io.INFO: Got packet: OPEN{data:{"sid":"bFjtgbDtgyXss-eMAABm","upgrades":["websocket"],"pingInterval":25000,"pingTimeout":... 28 more [] []
[2024-02-04T16:11:56.144217+07:00] elephant.io.INFO: Handshake finished with {"id":"bFjtgbDtgyXss-eMAABm","heartbeat":1707037916.144179,"timeouts":{"timeout":20,"interval":25},"upgrades":["websocket"],"maxPayload":1000000} [] []
[2024-02-04T16:11:56.144329+07:00] elephant.io.INFO: Starting namespace connect [] []
[2024-02-04T16:11:56.144484+07:00] elephant.io.DEBUG: Send data: 40 [] []
[2024-02-04T16:11:56.145057+07:00] elephant.io.DEBUG: Write data: POST /socket.io/?EIO=4&transport=polling&t=OrpOtjW.0&sid=bFjtgbDtgyXss-eMAABm HTTP/1.1 [] []
[2024-02-04T16:11:56.145156+07:00] elephant.io.DEBUG: Write data: Host: localhost:14000 [] []
[2024-02-04T16:11:56.145213+07:00] elephant.io.DEBUG: Write data: Content-Type: text/plain; charset=UTF-8 [] []
[2024-02-04T16:11:56.145264+07:00] elephant.io.DEBUG: Write data: Content-Length: 2 [] []
[2024-02-04T16:11:56.145311+07:00] elephant.io.DEBUG: Write data: Connection: keep-alive [] []
[2024-02-04T16:11:56.145360+07:00] elephant.io.DEBUG: Write data:  [] []
[2024-02-04T16:11:56.145409+07:00] elephant.io.DEBUG: Write data: 40 [] []
[2024-02-04T16:11:56.145515+07:00] elephant.io.DEBUG: Waiting for response... [] []
[2024-02-04T16:11:56.156923+07:00] elephant.io.DEBUG: Receive: HTTP/1.1 200 OK [] []
[2024-02-04T16:11:56.172603+07:00] elephant.io.DEBUG: Receive: Content-Type: text/html [] []
[2024-02-04T16:11:56.188045+07:00] elephant.io.DEBUG: Receive: Content-Length: 2 [] []
[2024-02-04T16:11:56.203408+07:00] elephant.io.DEBUG: Receive: cache-control: no-store [] []
[2024-02-04T16:11:56.219137+07:00] elephant.io.DEBUG: Receive: Date: Sun, 04 Feb 2024 09:11:56 GMT [] []
[2024-02-04T16:11:56.234896+07:00] elephant.io.DEBUG: Receive: Connection: keep-alive [] []
[2024-02-04T16:11:56.250487+07:00] elephant.io.DEBUG: Receive: Keep-Alive: timeout=5 [] []
[2024-02-04T16:11:56.266399+07:00] elephant.io.DEBUG: Receive:  [] []
[2024-02-04T16:11:56.282791+07:00] elephant.io.DEBUG: Receive: ok [] []
[2024-02-04T16:11:56.284456+07:00] elephant.io.DEBUG: Write data: GET /socket.io/?EIO=4&transport=polling&t=OrpOtjW.1&sid=bFjtgbDtgyXss-eMAABm HTTP/1.1 [] []
[2024-02-04T16:11:56.284627+07:00] elephant.io.DEBUG: Write data: Host: localhost:14000 [] []
[2024-02-04T16:11:56.284818+07:00] elephant.io.DEBUG: Write data: Connection: keep-alive [] []
[2024-02-04T16:11:56.285028+07:00] elephant.io.DEBUG: Write data:  [] []
[2024-02-04T16:11:56.285236+07:00] elephant.io.DEBUG: Write data:  [] []
[2024-02-04T16:11:56.285324+07:00] elephant.io.DEBUG: Waiting for response... [] []
[2024-02-04T16:11:56.285420+07:00] elephant.io.DEBUG: Receive: HTTP/1.1 200 OK [] []
[2024-02-04T16:11:56.301289+07:00] elephant.io.DEBUG: Receive: Content-Type: text/plain; charset=UTF-8 [] []
[2024-02-04T16:11:56.316162+07:00] elephant.io.DEBUG: Receive: Content-Length: 32 [] []
[2024-02-04T16:11:56.317317+07:00] elephant.io.DEBUG: Receive: cache-control: no-store [] []
[2024-02-04T16:11:56.334009+07:00] elephant.io.DEBUG: Receive: Date: Sun, 04 Feb 2024 09:11:56 GMT [] []
[2024-02-04T16:11:56.336420+07:00] elephant.io.DEBUG: Receive: Connection: keep-alive [] []
[2024-02-04T16:11:56.352912+07:00] elephant.io.DEBUG: Receive: Keep-Alive: timeout=5 [] []
[2024-02-04T16:11:56.368733+07:00] elephant.io.DEBUG: Receive:  [] []
[2024-02-04T16:11:56.384437+07:00] elephant.io.DEBUG: Receive: 40{"sid":"-nfM6ff5hmd0EVREAABn"} [] []
[2024-02-04T16:11:56.384800+07:00] elephant.io.INFO: Got packet: MESSAGE{type:'connect', nsp:'', data:{"sid":"-nfM6ff5hmd0EVREAABn"}} [] []
[2024-02-04T16:11:56.384883+07:00] elephant.io.INFO: Namespace connect completed [] []
[2024-02-04T16:11:56.384955+07:00] elephant.io.INFO: Starting websocket upgrade [] []
[2024-02-04T16:11:56.385362+07:00] elephant.io.DEBUG: Write data: GET /socket.io/?EIO=4&transport=websocket&t=OrpOtjW.2&sid=bFjtgbDtgyXss-eMAABm HTTP/1.1 [] []
[2024-02-04T16:11:56.385455+07:00] elephant.io.DEBUG: Write data: Host: localhost:14000 [] []
[2024-02-04T16:11:56.385514+07:00] elephant.io.DEBUG: Write data: Connection: Upgrade [] []
[2024-02-04T16:11:56.385568+07:00] elephant.io.DEBUG: Write data: Upgrade: websocket [] []
[2024-02-04T16:11:56.385619+07:00] elephant.io.DEBUG: Write data: Sec-WebSocket-Key: mz0qbktAwmPoX0RLSWqDsw== [] []
[2024-02-04T16:11:56.385671+07:00] elephant.io.DEBUG: Write data: Sec-WebSocket-Version: 13 [] []
[2024-02-04T16:11:56.385724+07:00] elephant.io.DEBUG: Write data: Origin: * [] []
[2024-02-04T16:11:56.385776+07:00] elephant.io.DEBUG: Write data:  [] []
[2024-02-04T16:11:56.385829+07:00] elephant.io.DEBUG: Write data:  [] []
[2024-02-04T16:11:56.385881+07:00] elephant.io.DEBUG: Waiting for response... [] []
[2024-02-04T16:11:56.399853+07:00] elephant.io.DEBUG: Receive: HTTP/1.1 101 Switching Protocols [] []
[2024-02-04T16:11:56.415586+07:00] elephant.io.DEBUG: Receive: Upgrade: websocket [] []
[2024-02-04T16:11:56.430881+07:00] elephant.io.DEBUG: Receive: Connection: Upgrade [] []
[2024-02-04T16:11:56.446892+07:00] elephant.io.DEBUG: Receive: Sec-WebSocket-Accept: aYEbOSb/U7+2ejmUpTcKA00nde0= [] []
[2024-02-04T16:11:56.461933+07:00] elephant.io.DEBUG: Receive:  [] []
[2024-02-04T16:11:56.462234+07:00] elephant.io.DEBUG: Send data: 5 [] []
[2024-02-04T16:11:56.464335+07:00] elephant.io.DEBUG: Write data: �����`� [] []
[2024-02-04T16:11:56.478166+07:00] elephant.io.INFO: Websocket upgrade completed [] []
[2024-02-04T16:11:56.478427+07:00] elephant.io.INFO: Connected to server [] []
[2024-02-04T16:11:56.478620+07:00] elephant.io.INFO: Setting namespace {"namespace":"/keep-alive"} []
[2024-02-04T16:11:56.478760+07:00] elephant.io.DEBUG: Send data: 40/keep-alive [] []
[2024-02-04T16:11:56.479055+07:00] elephant.io.DEBUG: Write data: ���k��&D��s�z�� [] []
[2024-02-04T16:11:56.493826+07:00] elephant.io.DEBUG: Receiving data: �,40/keep-alive,{"sid":"l5Lf1a5zRsWCvnRlAABo"} [] []
[2024-02-04T16:11:56.495533+07:00] elephant.io.DEBUG: Got data: 40/keep-alive,{"sid":"l5Lf1a5zRsWCvnRlAABo"} [] []
[2024-02-04T16:11:56.495842+07:00] elephant.io.INFO: Got packet: MESSAGE{type:'connect', nsp:'/keep-alive', data:{"sid":"l5Lf1a5zRsWCvnRlAABo"}} [] []
[2024-02-04T16:11:56.496005+07:00] elephant.io.INFO: Sending a new message {"event":"message","args":{"message":"A message"}} []
[2024-02-04T16:11:56.496151+07:00] elephant.io.DEBUG: Send data: 42/keep-alive,["message",{"message":"A message"}] [] []
[2024-02-04T16:11:56.496527+07:00] elephant.io.DEBUG: Write data: ��>�� 0͗[g��_n��[.��Sg��_e��y��[q��Yg��C[q��Yg��c [] []
[2024-02-04T16:11:56.508711+07:00] elephant.io.INFO: Waiting for event {"event":"message"} []
[2024-02-04T16:11:56.509108+07:00] elephant.io.DEBUG: Receiving data: �*42/keep-alive,["message",{"success":true}] [] []
[2024-02-04T16:11:56.509351+07:00] elephant.io.DEBUG: Got data: 42/keep-alive,["message",{"success":true}] [] []
[2024-02-04T16:11:56.509911+07:00] elephant.io.INFO: Got packet: MESSAGE{type:'event', nsp:'/keep-alive', event:'message', args:[{"success":true}]} [] []
[2024-02-04T16:12:21.032799+07:00] elephant.io.DEBUG: Receiving data: �2 [] []
[2024-02-04T16:12:21.032924+07:00] elephant.io.DEBUG: Got data: 2 [] []
[2024-02-04T16:12:21.032967+07:00] elephant.io.INFO: Got packet: PING{} [] []
[2024-02-04T16:12:21.032991+07:00] elephant.io.DEBUG: Got PING, sending PONG [] []
[2024-02-04T16:12:21.033019+07:00] elephant.io.DEBUG: Send data: 3 [] []
[2024-02-04T16:12:21.033144+07:00] elephant.io.DEBUG: Write data: ��ko�- [] []
[2024-02-04T16:12:27.042586+07:00] elephant.io.INFO: Sending a new message {"event":"message","args":{"message":"Last message"}} []
[2024-02-04T16:12:27.042744+07:00] elephant.io.DEBUG: Send data: 42/keep-alive,["message",{"message":"Last message"}] [] []
[2024-02-04T16:12:27.042930+07:00] elephant.io.DEBUG: Write data: ���}|7�OS\��A�Q'�D��^Z�V�^ �1D�]R�P�_j [] []
[2024-02-04T16:12:27.056610+07:00] elephant.io.INFO: Waiting for event {"event":"message"} []
[2024-02-04T16:12:27.056777+07:00] elephant.io.DEBUG: Receiving data: �*42/keep-alive,["message",{"success":true}] [] []
[2024-02-04T16:12:27.056958+07:00] elephant.io.DEBUG: Got data: 42/keep-alive,["message",{"success":true}] [] []
[2024-02-04T16:12:27.057069+07:00] elephant.io.INFO: Got packet: MESSAGE{type:'event', nsp:'/keep-alive', event:'message', args:[{"success":true}]} [] []
[2024-02-04T16:12:27.057449+07:00] elephant.io.INFO: Closing connection to server [] []
[2024-02-04T16:12:27.057525+07:00] elephant.io.DEBUG: Send data: 1 [] []
[2024-02-04T16:12:27.057686+07:00] elephant.io.DEBUG: Write data: ��%E�s [] []
```

## Examples

The [the example directory](/example) shows how to get a basic knowledge of library usage.

## Special Thanks

Special thanks goes to Mark Karpeles who helped the project founder to understand the way websockets works.
