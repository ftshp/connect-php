<?php

declare(strict_types=1);

namespace Ftshp\Connect\Conformance;

use Connectrpc\Conformance\V1\Code as ConformanceCode;
use Connectrpc\Conformance\V1\ConformancePayload;
use Connectrpc\Conformance\V1\ConformancePayload\ConnectGetInfo;
use Connectrpc\Conformance\V1\ConformancePayload\RequestInfo;
use Connectrpc\Conformance\V1\ConformanceServiceInterface;
use Connectrpc\Conformance\V1\Error;
use Connectrpc\Conformance\V1\Header;
use Connectrpc\Conformance\V1\IdempotentUnaryRequest;
use Connectrpc\Conformance\V1\IdempotentUnaryResponse;
use Connectrpc\Conformance\V1\UnaryRequest;
use Connectrpc\Conformance\V1\UnaryResponse;
use Connectrpc\Conformance\V1\UnaryResponseDefinition;
use Connectrpc\Conformance\V1\UnimplementedRequest;
use Connectrpc\Conformance\V1\UnimplementedResponse;
use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;
use Ftshp\Connect\ErrorDetail;
use Ftshp\Connect\Headers;
use Google\Protobuf\Any;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\RepeatedField;

final readonly class ConformanceServiceImpl implements ConformanceServiceInterface
{
    /**
     * @param ?string $getQuery the raw query string when the request was a GET, null otherwise
     */
    public function __construct(private ?string $getQuery = null) {}

    #[\Override]
    public function unary(UnaryRequest $request, Context $context = new Context()): UnaryResponse
    {
        return new UnaryResponse(['payload' => $this->respond($request, $request->getResponseDefinition(), $context)]);
    }

    #[\Override]
    public function unimplemented(UnimplementedRequest $request, Context $context = new Context()): UnimplementedResponse
    {
        throw new ConnectException(Code::Unimplemented, 'unimplemented');
    }

    #[\Override]
    public function idempotentUnary(IdempotentUnaryRequest $request, Context $context = new Context()): IdempotentUnaryResponse
    {
        return new IdempotentUnaryResponse(['payload' => $this->respond($request, $request->getResponseDefinition(), $context)]);
    }

    private function respond(Message $request, ?UnaryResponseDefinition $definition, Context $context): ConformancePayload
    {
        $info = $this->requestInfo($request, $context);
        if ($definition === null) {
            return new ConformancePayload(['request_info' => $info]);
        }

        self::copyHeaders($definition->getResponseHeaders(), $context->responseHeaders);
        self::copyHeaders($definition->getResponseTrailers(), $context->responseTrailers);
        usleep($definition->getResponseDelayMs() * 1000);

        $error = $definition->getError();
        if ($error !== null) {
            throw self::exception($error, $info);
        }

        return new ConformancePayload(['data' => $definition->getResponseData(), 'request_info' => $info]);
    }

    private function requestInfo(Message $request, Context $context): RequestInfo
    {
        $any = new Any();
        $any->pack($request);
        $info = new RequestInfo([
            'request_headers' => self::toHeaderList($context->requestHeaders->toArray()),
            'requests' => [$any],
        ]);
        if ($context->timeoutMs !== null) {
            $info->setTimeoutMs($context->timeoutMs);
        }
        if ($this->getQuery !== null) {
            $info->setConnectGetInfo(new ConnectGetInfo(['query_params' => self::toHeaderList(self::parseQuery($this->getQuery))]));
        }

        return $info;
    }

    private static function exception(Error $error, RequestInfo $info): ConnectException
    {
        $details = [];
        foreach ($error->getDetails() as $detail) {
            $parts = explode('/', $detail->getTypeUrl());
            $details[] = new ErrorDetail(end($parts), $detail->getValue());
        }
        $details[] = ErrorDetail::fromMessage($info);
        $name = ConformanceCode::name($error->getCode());
        \assert(\is_string($name));

        return new ConnectException(Code::tryFrom(strtolower(substr($name, \strlen('CODE_')))) ?? Code::Unknown, $error->getMessage(), $details);
    }

    /**
     * @param RepeatedField<Header> $from
     */
    private static function copyHeaders(RepeatedField $from, Headers $to): void
    {
        foreach ($from as $header) {
            foreach ($header->getValue() as $value) {
                $to->add($header->getName(), $value);
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseQuery(string $query): array
    {
        $params = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            $params[urldecode($name)][] = urldecode($value);
        }

        return $params;
    }

    /**
     * @param array<array-key, list<string>> $headers
     *
     * @return list<Header>
     */
    private static function toHeaderList(array $headers): array
    {
        $list = [];
        foreach ($headers as $name => $values) {
            $list[] = new Header(['name' => (string) $name, 'value' => $values]);
        }

        return $list;
    }
}
