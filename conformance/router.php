<?php

declare(strict_types=1);

use Connectrpc\Conformance\V1\ConformanceServiceInterface;
use Ftshp\Connect\Conformance\ConformanceServiceImpl;
use Ftshp\Connect\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/vendor/autoload.php';

$factory = new Psr17Factory();
$request = new ServerRequestCreator($factory, $factory, $factory, $factory)->fromGlobals();

$server = new Server($factory, $factory, maxRequestBytes: (int) getenv('CONFORMANCE_MESSAGE_RECEIVE_LIMIT'));
$server->register(
    ConformanceServiceInterface::class,
    new ConformanceServiceImpl($request->getMethod() === 'GET' ? $request->getUri()->getQuery() : null),
);
$response = $server->handle($request);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
echo $response->getBody();
