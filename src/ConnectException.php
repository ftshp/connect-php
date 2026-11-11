<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Google\Protobuf\Internal\Message;

final class ConnectException extends \RuntimeException
{
    /** @var list<ErrorDetail> */
    private array $details;

    private bool $received = false;

    /**
     * @param list<ErrorDetail|Message> $details
     * @param Headers $metadata sent as response headers when a server handler throws the exception
     */
    public function __construct(
        private readonly Code $errorCode,
        string $message = '',
        array $details = [],
        ?\Throwable $previous = null,
        private readonly Headers $metadata = new Headers(),
    ) {
        parent::__construct($message, 0, $previous);
        $this->details = array_map(
            static fn(ErrorDetail|Message $detail): ErrorDetail => $detail instanceof Message
                ? ErrorDetail::fromMessage($detail)
                : $detail,
            $details,
        );
    }

    /**
     * @internal the client builds the errors it reads from a response with it
     *
     * @param list<ErrorDetail> $details
     */
    public static function received(Code $code, string $message, array $details, Headers $metadata): self
    {
        $e = new self($code, $message, $details, null, $metadata);
        $e->received = true;

        return $e;
    }

    public static function fromThrowable(\Throwable $e): self
    {
        return $e instanceof self ? $e : new self(Code::Unknown, '', [], $e);
    }

    public function code(): Code
    {
        return $this->errorCode;
    }

    /**
     * @return list<ErrorDetail>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * On the client, the response headers and trailers of the failed call. On the server, headers to send
     * with the error, unless the exception came from a client call.
     */
    public function metadata(): Headers
    {
        return $this->metadata;
    }

    /**
     * @internal true for an error that the client read from a response
     */
    public function isWireError(): bool
    {
        return $this->received;
    }

    /**
     * @deprecated since 0.2, pass metadata: to the constructor or change the Headers that metadata() returns
     */
    #[\Deprecated(message: 'pass metadata: to the constructor or change the Headers that metadata() returns', since: '0.2')]
    public function withMetadata(Headers $metadata): self
    {
        $copy = new self($this->errorCode, $this->getMessage(), $this->details, $this->getPrevious(), $metadata);
        $copy->received = $this->received;
        $copy->file = $this->file;
        $copy->line = $this->line;
        new \ReflectionProperty(\Exception::class, 'trace')->setValue($copy, $this->getTrace());

        return $copy;
    }
}
