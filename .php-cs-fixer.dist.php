<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = new Finder()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->exclude('Generated');

return new Config()
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/build/.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'fully_qualified_strict_types' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
