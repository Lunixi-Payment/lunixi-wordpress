<?php

/** Scope plugin SDK references and bundled SDK together, preserving WP APIs. */

declare(strict_types=1);

$finder = class_exists('Isolated\\Symfony\\Component\\Finder\\Finder')
    ? 'Isolated\\Symfony\\Component\\Finder\\Finder' : 'Symfony\\Component\\Finder\\Finder';
$root = getcwd();
return [
    'prefix' => 'Lunixi\\WP\\Vendor', 'php-version' => '7.4',
    'finders' => [
        $finder::create()->files()->name('*.php')->in([$root . '/src', $root . '/vendor/lunixi/php-sdk/src']),
        $finder::create()->append([$root . '/lunixi.php']),
    ],
    'exclude-namespaces' => ['Lunixi\\WP', 'Automattic'],
    // Global facades reference only excluded plugin classes/WP APIs, never SDK
    // symbols. Preserve their actual global declarations, not just call sites.
    'exclude-files' => [$root . '/src/Kyc/Gate.php', $root . '/src/Automation/Forward.php', $root . '/src/Identity/Module.php'],
    'exclude-classes' => ['~^WC_~', '~^WP_~', '~^WooCommerce$~'],
    'exclude-functions' => ['~.*~'], 'exclude-constants' => ['~.*~'],
    'expose-global-constants' => false, 'expose-global-classes' => false, 'expose-global-functions' => false,
];
