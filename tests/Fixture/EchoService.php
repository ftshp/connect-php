<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Fixture;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;
use Ftshp\Connect\Test\EchoInterface;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use Ftshp\Connect\Test\FailRequest;
use Ftshp\Connect\Test\PBEmpty;
use Google\Protobuf\Struct;
use Google\Protobuf\Value;

final class EchoService implements EchoInterface
{
    #[\Override]
    public function echo(EchoRequest $request, Context $context = new Context()): EchoResponse
    {
        $headers = [];
        foreach ($context->requestHeaders->toArray() as $name => $values) {
            if (str_starts_with((string) $name, 'x-')) {
                $headers[(string) $name] = implode(', ', $values);
            }
        }
        $context->responseHeaders->set('x-echoed', '1');
        $context->responseTrailers->set('x-trailer', 't');
        if ($context->timeoutMs !== null) {
            $context->responseHeaders->set('x-timeout', (string) $context->timeoutMs);
            $context->responseHeaders->set('x-remaining', (string) $context->remainingMs());
        }

        return new EchoResponse(['text' => strtoupper($request->getText()), 'headers' => $headers]);
    }

    #[\Override]
    public function peek(EchoRequest $request, Context $context = new Context()): EchoResponse
    {
        return new EchoResponse(['text' => $request->getText()]);
    }

    #[\Override]
    public function fail(FailRequest $request, Context $context = new Context()): PBEmpty
    {
        if ($request->getCode() === 'boom') {
            throw new \RuntimeException('boom');
        }
        $details = $request->getWithDetail()
            ? [new Struct(['fields' => ['reason' => new Value(['string_value' => 'detail'])]])]
            : [];

        throw new ConnectException(Code::from($request->getCode()), $request->getMessage(), $details);
    }
}
