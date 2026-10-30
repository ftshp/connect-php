<?php

declare(strict_types=1);

namespace Ftshp\Connect\Tests\Fixture;

use Psr\Http\Client\ClientExceptionInterface;

final class FakeClientException extends \RuntimeException implements ClientExceptionInterface {}
