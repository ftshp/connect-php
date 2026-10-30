<?php

declare(strict_types=1);

use Connectrpc\Conformance\V1\ClientCompatRequest;
use Ftshp\Connect\Conformance\CompatClient;
use Ftshp\Connect\Conformance\Framing;

ini_set('display_errors', 'stderr');

require __DIR__ . '/vendor/autoload.php';

while (($request = Framing::read(new ClientCompatRequest())) !== null) {
    Framing::write(CompatClient::run($request));
}
