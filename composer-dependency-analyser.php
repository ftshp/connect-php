<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$configuration = new Configuration()
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true);

// The PSR-18 client package has no consumer until the client exists.
$configuration->ignoreErrorsOnPackage('psr/http-client', [ErrorType::UNUSED_DEPENDENCY]);

return $configuration;
