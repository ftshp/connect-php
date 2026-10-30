<?php

declare(strict_types=1);

namespace Ftshp\Connect;

enum Idempotency
{
    case Unknown;
    case NoSideEffects;
    case Idempotent;
}
