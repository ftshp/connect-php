<?php

declare(strict_types=1);

namespace Ftshp\Connect\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Service
{
    public function __construct(
        public string $name,
    ) {}
}
