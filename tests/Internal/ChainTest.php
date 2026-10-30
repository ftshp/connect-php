<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Internal;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;
use Ftshp\Connect\Interceptor;
use Ftshp\Connect\Internal\Chain;
use Ftshp\Connect\MethodDescriptor;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChainTest extends TestCase
{
    private function request(): UnaryRequest
    {
        $method = new MethodDescriptor('ftshp.connect.test.Echo', 'Echo', 'echo', EchoRequest::class, EchoResponse::class);

        return new UnaryRequest($method, new Context(), false, new EchoRequest());
    }

    /**
     * @param \ArrayObject<int, string> $log
     */
    private function recorder(string $name, \ArrayObject $log): Interceptor
    {
        return new class ($name, $log) implements Interceptor {
            /**
             * @param \ArrayObject<int, string> $log
             */
            public function __construct(private string $name, private \ArrayObject $log) {}

            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $this->log[] = $this->name . '-in';
                $response = $next($request);
                $this->log[] = $this->name . '-out';

                return $response;
            }
        };
    }

    #[Test]
    public function orderIsOuterToInner(): void
    {
        $log = new \ArrayObject();
        $chain = Chain::build(
            [$this->recorder('a', $log), $this->recorder('b', $log), $this->recorder('c', $log)],
            function () use ($log): Message {
                $log[] = 'inner';

                return new EchoResponse();
            },
        );

        $chain($this->request());

        self::assertSame(['a-in', 'b-in', 'c-in', 'inner', 'c-out', 'b-out', 'a-out'], $log->getArrayCopy());
    }

    #[Test]
    public function interceptorCanShortCircuit(): void
    {
        $called = false;
        $thrower = new class implements Interceptor {
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                throw new ConnectException(Code::PermissionDenied, 'no');
            }
        };
        $chain = Chain::build([$thrower], function () use (&$called): Message {
            $called = true;

            return new EchoResponse();
        });

        try {
            $chain($this->request());
            self::fail('expected exception');
        } catch (ConnectException $e) {
            self::assertSame(Code::PermissionDenied, $e->code());
        }

        self::assertFalse($called);
    }

    #[Test]
    public function innermostMayBeCalledTwice(): void
    {
        $calls = 0;
        $twice = new class implements Interceptor {
            public function intercept(UnaryRequest $request, \Closure $next): Message
            {
                $next($request);

                return $next($request);
            }
        };
        $chain = Chain::build([$twice], function () use (&$calls): Message {
            ++$calls;

            return new EchoResponse();
        });

        $chain($this->request());

        self::assertSame(2, $calls);
    }
}
