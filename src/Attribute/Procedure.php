<?php

declare(strict_types=1);

namespace Ftshp\Connect\Attribute;

use Ftshp\Connect\Idempotency;

#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Procedure
{
    public function __construct(
        public string $name,
        public Idempotency $idempotency = Idempotency::Unknown,
    ) {}
}
