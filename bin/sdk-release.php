<?php

/** Shared immutable public SDK pin for the merchant builder and artifact verifier. */

declare(strict_types=1);

$sdkRelease = json_decode(file_get_contents(__DIR__ . '/sdk-release.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($sdkRelease) || array_keys($sdkRelease) !== ['version', 'reference']
    || !is_string($sdkRelease['version']) || preg_match('/^1\.0\.(0|[1-9][0-9]*)$/D', $sdkRelease['version']) !== 1 || !is_string($sdkRelease['reference'])
    || ($sdkRelease['reference'] !== '' && preg_match('/^[a-f0-9]{40}$/D', $sdkRelease['reference']) !== 1)) {
    throw new RuntimeException('Invalid reviewed SDK release pin.');
}
define('LUNIXI_DIST_SDK_VERSION', $sdkRelease['version']);
define('LUNIXI_DIST_SDK_REFERENCE', $sdkRelease['reference']);
unset($sdkRelease);
