<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$configuration = new Configuration()
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true);

// The runtime packages have no consumers until src/ has code.
foreach (['google/protobuf', 'psr/http-client', 'psr/http-factory', 'psr/http-message', 'psr/http-server-handler'] as $package) {
    $configuration->ignoreErrorsOnPackage($package, [ErrorType::UNUSED_DEPENDENCY]);
}

return $configuration;
