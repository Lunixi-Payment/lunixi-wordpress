<?php

/** Standalone/monorepo merchant ZIP builder; no user Composer config or HOME override. */

declare(strict_types=1);

const LUNIXI_DIST_SDK_VERSION = '0.29.6';
const LUNIXI_DIST_SDK_REFERENCE = '29fb3ac2f0fdff7a3825e189572c7aef4b212cc1';
const LUNIXI_DIST_PREFIX = 'Lunixi\\WP\\Vendor';
$plugin = dirname(__DIR__);
$build = $plugin . '/build';
if (!is_dir($build) && !mkdir($build, 0755, true)) {
    throw new RuntimeException('Build directory unavailable.');
}
$work = $build . '/.scoper-work-' . bin2hex(random_bytes(8));
mkdir($work, 0700);
$environment = getenv();
foreach (['COMPOSER', 'COMPOSER_AUTH', 'COMPOSER_VENDOR_DIR', 'COMPOSER_REPOSITORIES', 'GH_TOKEN', 'GITHUB_TOKEN', 'SSH_AUTH_SOCK', 'GIT_CONFIG_COUNT'] as $key) {
    unset($environment[$key]);
}
$environment = array_merge($environment, ['COMPOSER_HOME' => $work . '/composer-home', 'COMPOSER_CACHE_DIR' => $work . '/composer-cache',
    'COMPOSER_NO_INTERACTION' => '1', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
    'GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '/usr/bin/false']);
foreach (['composer-home', 'composer-cache', 'input', 'tools', 'sdk'] as $dir) {
    mkdir($work . '/' . $dir, 0700);
}
function distRun(array $command, string $cwd, array $environment): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Build process unavailable.');
    }
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Build failed: ' . basename($command[0]) . "\n" . $out . $error);
    }
    return $out;
}
function distCopy(string $from, string $to): void
{
    if (is_link($from)) {
        throw new RuntimeException('Symlinks are not distributed.');
    }
    if (is_dir($from)) {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }
        foreach (new FilesystemIterator($from) as $entry) {
            distCopy($entry->getPathname(), $to . '/' . $entry->getFilename());
        }
    } else {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0755, true);
        }
        if (!copy($from, $to)) {
            throw new RuntimeException('Build copy failed.');
        }
    }
}
function distRemove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path) as $entry) {
        distRemove($entry->getPathname());
    }
    rmdir($path);
}
try {
    $repo = trim(distRun(['git', 'rev-parse', '--show-toplevel'], $plugin, $environment));
    $relative = ltrim(substr($plugin, strlen($repo)), '/');
    $copied = 0;
    foreach (explode("\0", distRun(['git', 'ls-files', '-z', '--', $relative === '' ? '.' : $relative], $repo, $environment)) as $path) {
        if ($path === '') {
            continue;
        } $local = $relative === '' ? $path : substr($path, strlen($relative) + 1);
        if (
            $local === '' || preg_match('~^(?:tests|bin|docs|\.github|vendor|build|build-scoped)/~', $local)
            || in_array($local, ['composer.json', 'composer.lock', 'phpunit.xml.dist', 'scoper.inc.php', '.gitignore', 'README.md'], true)
        ) {
            continue;
        }
        distCopy($repo . '/' . $path, $work . '/input/' . $local);
        ++$copied;
    }
    if (!$copied || !is_file($work . '/input/lunixi.php')) {
        throw new RuntimeException('Tracked plugin source not found.');
    }
    preg_match('/^ \* Version:\s+([0-9]+\.[0-9]+\.[0-9]+)$/m', file_get_contents($plugin . '/lunixi.php'), $match);
    if (!isset($match[1])) {
        throw new RuntimeException('Plugin version unavailable.');
    } $version = $match[1];
    $composer = getenv('LUNIXI_COMPOSER_BINARY') ?: 'composer';
    $manifest = ['name' => 'lunixi-build/wordpress-sdk', 'require' => ['lunixi/php-sdk' => LUNIXI_DIST_SDK_VERSION],
        'config' => ['secure-http' => true, 'allow-plugins' => false]];
    file_put_contents($work . '/sdk/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    distRun([$composer, 'install', '--working-dir=' . $work . '/sdk', '--no-dev', '--no-plugins', '--no-scripts', '--prefer-dist', '--no-progress', '--no-interaction'], $plugin, $environment);
    distRun([$composer, 'audit', '--working-dir=' . $work . '/sdk', '--locked', '--no-plugins', '--format=json'], $plugin, $environment);
    $lock = json_decode(file_get_contents($work . '/sdk/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $sdk = null;
    foreach ($lock['packages'] as $package) {
        if ($package['name'] === 'lunixi/php-sdk') {
            $sdk = $package;
        }
    }
    if (
        !$sdk || ltrim($sdk['version'], 'v') !== LUNIXI_DIST_SDK_VERSION || count($lock['packages']) !== 1
        || $sdk['source']['reference'] !== LUNIXI_DIST_SDK_REFERENCE || $sdk['dist']['reference'] !== LUNIXI_DIST_SDK_REFERENCE
    ) {
        throw new RuntimeException('SDK reference/dependency graph changed; review the immutable build pin.');
    }
    distCopy($work . '/sdk/vendor/lunixi/php-sdk', $work . '/input/vendor/lunixi/php-sdk');
    $scoper = getenv('LUNIXI_SCOPER_BINARY') ?: $plugin . '/vendor/bin/php-scoper';
    if (!is_file($scoper)) {
        $tools = ['require' => ['humbug/php-scoper' => '^0.18'], 'config' => ['secure-http' => true, 'allow-plugins' => false, 'platform' => ['php' => '8.2.0']]];
        file_put_contents($work . '/tools/composer.json', json_encode($tools, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        distRun([$composer, 'install', '--working-dir=' . $work . '/tools', '--no-plugins', '--no-scripts', '--prefer-dist', '--no-progress', '--no-interaction'], $plugin, $environment);
        distRun([$composer, 'audit', '--working-dir=' . $work . '/tools', '--locked', '--no-plugins', '--format=json'], $plugin, $environment);
        $scoper = $work . '/tools/vendor/bin/php-scoper';
    }
    $toolVersion = trim(distRun([PHP_BINARY, $scoper, '--version'], $plugin, $environment));
    if (!preg_match('/version 0\.18\./', $toolVersion)) {
        throw new RuntimeException('PHP-Scoper 0.18.x is required.');
    }
    distRun([PHP_BINARY, $scoper, 'add-prefix', '--config=' . $plugin . '/scoper.inc.php', '--output-dir=' . $work . '/scoped', '--force'], $work . '/input', $environment);
    $prepared = $work . '/prepared';
    distCopy($work . '/input', $prepared);
    distCopy($work . '/scoped', $prepared);
    // PHP-Scoper's generated Composer bridge is deliberately not our loader.
    // It requires Composer files and global aliases which are not distributed.
    distRemove($prepared . '/vendor/scoper-autoload.php');
    $loader = <<<'LOADER'
<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
spl_autoload_register(static function (string $class): void {
    $prefix = 'Lunixi\\WP\\Vendor\\Lunixi\\Sdk\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $path = __DIR__ . '/lunixi/php-sdk/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) { require $path; }
});
LOADER;
    file_put_contents($prepared . '/vendor/scoped-autoload.php', $loader . "\n");
    $metadata = ['pluginVersion' => $version, 'sdk' => ['name' => 'lunixi/php-sdk', 'version' => LUNIXI_DIST_SDK_VERSION,
        'sourceReference' => LUNIXI_DIST_SDK_REFERENCE, 'license' => 'MIT'], 'prefix' => LUNIXI_DIST_PREFIX, 'scoper' => $toolVersion, 'merchantComposerRequired' => false];
    file_put_contents($prepared . '/vendor/scoped-package.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($prepared . '/THIRD-PARTY-NOTICES.txt', "Lunixi PHP SDK " . LUNIXI_DIST_SDK_VERSION . "\nMIT License: vendor/lunixi/php-sdk/LICENSE\nSource: https://github.com/Lunixi-Payment/lunixi-php/tree/" . LUNIXI_DIST_SDK_REFERENCE . "\nPrivately namespaced with PHP-Scoper; merchant Composer is not required.\n");
    distRun([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $plugin . '/bin/check-dist.php', $prepared], $plugin, $environment);
    $dist = $build . '/dist';
    if (!is_dir($dist)) {
        mkdir($dist, 0755, true);
    } $out = $dist . '/lunixi';
    $marker = $dist . '/.lunixi-dist-owner';
    if (is_dir($out) && (!is_file($marker) || trim(file_get_contents($marker)) !== $plugin)) {
        throw new RuntimeException('Refusing to replace an unowned output directory.');
    }
    distRemove($out);
    distCopy($prepared, $out);
    file_put_contents($marker, $plugin . "\n");
    $zipPath = $dist . '/lunixi-' . $version . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('ZIP creation failed.');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('ZIP symlink rejected.');
        }
        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), 'lunixi/' . substr($file->getPathname(), strlen($out) + 1));
        }
    }
    $zip->close();
    $hash = hash_file('sha256', $zipPath);
    file_put_contents($dist . '/SHA256SUMS', $hash . '  ' . basename($zipPath) . "\n");
    echo json_encode(['artifact' => $zipPath, 'bytes' => filesize($zipPath), 'sha256' => $hash, 'bundledSdk' => LUNIXI_DIST_SDK_VERSION,
        'sdkReference' => LUNIXI_DIST_SDK_REFERENCE, 'scoper' => $toolVersion, 'standaloneAutoloader' => true], JSON_THROW_ON_ERROR) . "\n";
} finally {
    distRemove($work);
}
