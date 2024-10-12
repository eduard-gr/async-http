# Async HTTP Library

## Overview

This library is designed to implement asynchronous HTTP requests without relying on external libraries. It is built on non-blocking sockets and a finite state machine, allowing you to handle HTTP communication efficiently without blocking the execution flow.

## Key Features

- **Non-blocking Sockets:** The library utilizes non-blocking sockets to ensure that no operation halts the flow of your application.
- **Finite State Machine:** A state machine is used to manage the various stages of HTTP request and response processing.
- **TCP Connection Reuse:** The library reuses existing TCP connections for subsequent requests, improving performance and reducing the overhead associated with opening new connections.

## Usage

The library's primary method is `tick()`, which should be invoked periodically to progress through the various states of the HTTP communication. The state of the socket (whether it's ready to read or write) determines the actions taken during each tick.

## Example

```php
use GuzzleHttp\Psr7\Request;

$request = new Request(
  method:'GET',
  uri: 'http://anglesharp.azurewebsites.net/Chunked',
  headers:[
    'Connection' => 'keep-alive',
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
  ]);

$client->send($request);

while($client->isDone() !== true){
  $client->tick();
}

echo $client->getResponse()->getStatusCode();
echo $client->getResponse()->getBody()->getContents();
```
