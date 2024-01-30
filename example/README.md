# Elephant.io Example

This examples bellow shows typical Elephant.io usage to connect to socket.io server.

## Available examples

| Description                                 | Server                                                      | Client                                            |
|---------------------------------------------|-------------------------------------------------------------|---------------------------------------------------|
| Sending and receiving binary data           | [serve-binary-event.js](./server/serve-binary-event.js)     | [binary-event.php](./client/binary-event.php)     |
| Authentication using handshake              | [serve-handshake-auth.js](./server/serve-handshake-auth.js) | [handshake-auth.php](./client/handshake-auth.php) |
| Authentication using `Authorization` header | [serve-header-auth.js](./server/serve-header-auth.js)       | [header-auth.php](./client/header-auth.php)       |
| Keep alive                                  | [serve-keep-alive.js](./server/serve-keep-alive.js)         | [keep-alive.php](./client/keep-alive.php)         |
| Polling                                     | [serve-polling.js](./server/serve-polling.js)               | [polling.php](./client/polling.php)               |

## Run server part first

Ensure Nodejs already installed on your system, then issue:

```sh
cd server
npm install
npm start
```

## Run actual example

On another terminal, issue:

```sh
cd client
php binary-event.php
```

A log file named `socket.log` will be created upon running the example which
contains the log when connecting to socket server.
