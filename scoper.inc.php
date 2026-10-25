<?php

declare(strict_types=1);

/**
 * PHP-Scoper configuration for the distribution build.
 *
 * Prefixes the bundled Lunixi PHP SDK (and any future third-party deps) into
 * `Lunixi\WP\Vendor\…` so two plugins shipping the SDK at different versions can
 * never collide (WordPress.org loads them all in one PHP process). The plugin's
 * own `Lunixi\WP\` namespace and all WordPress/WooCommerce global symbols are
 * left untouched (exposed), so the prefixed code still calls core/WC normally.
 *
 * Build:  composer install --no-dev && vendor/bin/php-scoper add-prefix \
 *           --output-dir=build --force && composer dump-autoload \
 *           --working-dir=build --classmap-authoritative
 */

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'Lunixi\\WP\\Vendor',

    'finders' => [
        // The bundled SDK (and any other vendor packages we ship).
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE|.*\\.md|.*\\.dist|composer\\.(json|lock)/')
            ->exclude(['tests', 'Tests', 'test', 'doc', 'docs'])
            ->in('vendor/lunixi'),
    ],

    // Never prefix the plugin's own classes — they stay Lunixi\WP\*.
    'exclude-namespaces' => [
        'Lunixi\\WP',
        // WooCommerce Blocks integration base lives under Automattic\WooCommerce.
        'Automattic',
    ],

    // Global WordPress / WooCommerce classes the prefixed code references.
    'exclude-classes' => [
        '~^WC_~',
        '~^WP_~',
        '~^WooCommerce$~',
    ],

    // WordPress global functions/constants must remain callable from prefixed code.
    'expose-global-constants' => true,
    'expose-global-classes' => true,
    'expose-global-functions' => true,
];
