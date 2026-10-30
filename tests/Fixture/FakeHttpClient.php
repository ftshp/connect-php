<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Fixture;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class FakeHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    /**
     * @param \Closure(RequestInterface): ResponseInterface $respond may throw instead of returning
     */
    public function __construct(
        private readonly \Closure $respond,
    ) {}

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;

        return ($this->respond)($request);
    }
}
