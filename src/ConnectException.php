<?php

declare(strict_types=1);

namespace Ftshp\Connect;

use Google\Protobuf\Internal\Message;

final class ConnectException extends \RuntimeException
{
    /** @var list<ErrorDetail> */
    private array $details;

    private Headers $metadata;

    /**
     * @param list<ErrorDetail|Message> $details
     */
    public function __construct(
        private readonly Code $errorCode,
        string $message = '',
        array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->details = array_map(
            static fn(ErrorDetail|Message $detail): ErrorDetail => $detail instanceof Message
                ? ErrorDetail::fromMessage($detail)
                : $detail,
            $details,
        );
        $this->metadata = new Headers();
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

    public function metadata(): Headers
    {
        return $this->metadata;
    }

    public function withMetadata(Headers $metadata): self
    {
        $copy = new self($this->errorCode, $this->getMessage(), $this->details, $this->getPrevious());
        $copy->metadata = $metadata;

        return $copy;
    }
}
