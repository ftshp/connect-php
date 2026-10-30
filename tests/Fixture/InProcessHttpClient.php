<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Fixture;

use Ftshp\Connect\Server;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InProcessHttpClient implements ClientInterface
{
    public ?ServerRequestInterface $lastRequest = null;
    public ?ResponseInterface $lastResponse = null;

    public function __construct(
        private readonly Server $server,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        $this->lastRequest = new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders(), $request->getBody())
            ->withQueryParams($query);

        return $this->lastResponse = $this->server->handle($this->lastRequest);
    }
}
