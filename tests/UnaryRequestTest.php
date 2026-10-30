<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests;

use Ftshp\Connect\Code;
use Ftshp\Connect\ConnectException;
use Ftshp\Connect\Context;
use Ftshp\Connect\MethodDescriptor;
use Ftshp\Connect\Test\EchoRequest;
use Ftshp\Connect\Test\EchoResponse;
use Ftshp\Connect\UnaryRequest;
use Google\Protobuf\Internal\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UnaryRequestTest extends TestCase
{
    /**
     * @return MethodDescriptor<Message, Message>
     */
    private function method(): MethodDescriptor
    {
        return new MethodDescriptor('ftshp.connect.test.Echo', 'Echo', 'echo', EchoRequest::class, EchoResponse::class);
    }

    #[Test]
    public function messageIsDecodedOnce(): void
    {
        $calls = 0;
        $request = new UnaryRequest($this->method(), new Context(), false, function () use (&$calls): EchoRequest {
            ++$calls;

            return new EchoRequest();
        });

        self::assertSame($request->message(), $request->message());
        self::assertSame(1, $calls);
    }

    #[Test]
    public function messageInstanceIsReturnedAsIs(): void
    {
        $message = new EchoRequest();
        $request = new UnaryRequest($this->method(), new Context(), true, $message);

        self::assertSame($message, $request->message());
    }

    #[Test]
    public function messageDecodeFailureIsNotCached(): void
    {
        $calls = 0;
        $message = new EchoRequest();
        $request = new UnaryRequest($this->method(), new Context(), false, function () use (&$calls, $message): EchoRequest {
            if (++$calls === 1) {
                throw new ConnectException(Code::InvalidArgument, 'bad');
            }

            return $message;
        });

        try {
            $request->message();
            self::fail('expected exception');
        } catch (ConnectException) {
        }

        self::assertSame($message, $request->message());
        self::assertSame(2, $calls);
    }
}
