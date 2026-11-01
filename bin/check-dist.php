<?php

/** Executed in an isolated process, with an intentionally conflicting SDK. */

declare(strict_types=1);

require_once __DIR__ . '/sdk-release.php';

if (($argv[1] ?? '') === '--negative-control') {
    $artifact = realpath($argv[2] ?? '');
    if (!$artifact || !is_file($artifact . '/vendor/scoped-autoload.php')) {
        throw new RuntimeException('Artifact required.');
    }
    $temporary = sys_get_temp_dir() . '/lunixi-scoped-negative-' . bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    $copy = static function (string $from, string $to) use (&$copy): void {
        if (is_link($from)) {
            throw new RuntimeException('Negative-control symlink rejected.');
        }
        if (is_dir($from)) {
            if (!is_dir($to)) {
                mkdir($to, 0700, true);
            }
            foreach (new FilesystemIterator($from) as $entry) {
                $copy($entry->getPathname(), $to . '/' . $entry->getFilename());
            }
        } else {
            copy($from, $to);
        }
    };
    $remove = static function (string $path) use (&$remove): void {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (new FilesystemIterator($path) as $entry) {
            $remove($entry->getPathname());
        } rmdir($path);
    };
    try {
        $copy($artifact, $temporary);
        $factory = $temporary . '/src/Core/SdkFactory.php';
        $code = file_get_contents($factory);
        $broken = str_replace('use Lunixi\\WP\\Vendor\\Lunixi\\Sdk\\LunixiClient;', 'use Lunixi\\Sdk\\LunixiClient;', $code, $count);
        if ($count !== 1) {
            throw new RuntimeException('Negative-control mutation did not match exactly once.');
        }
        file_put_contents($factory, $broken);
        $p = proc_open(
            [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', __FILE__, $temporary],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($p);
        if ($exit === 0 || strpos($output, 'Unscoped collision sentinel invoked.') === false) {
            throw new RuntimeException('Broken SDK import was not detected by the sentinel.');
        }
        echo json_encode(['negativeControlDetected' => true, 'originalArtifactUnchanged' => true]) . "\n";
    } finally {
        $remove($temporary);
    }
    exit(0);
}
$root = realpath($argv[1] ?? '');
if (
    !$root || !is_file($root . '/vendor/scoped-autoload.php') || is_file($root . '/vendor/autoload.php')
    || is_file($root . '/vendor/scoper-autoload.php')
) {
    throw new RuntimeException('Standalone scoped package required.');
}
define('ABSPATH', $root . '/');
function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}
function plugin_dir_url(string $file): string
{
    return 'https://merchant.example.test/wp-content/plugins/lunixi/';
}
function plugin_basename(string $file): string
{
    return 'lunixi/lunixi.php';
}
function add_action(...$arguments): void
{
}
function register_activation_hook(...$arguments): void
{
}
function register_deactivation_hook(...$arguments): void
{
}
function wp_salt(string $scheme): string
{
    return 'synthetic-unregistered-build-probe';
}
function get_option(string $key, $default = false)
{
    return $default;
}
// A broken import must hit this sentinel and fail rather than pass accidentally.
eval('namespace Lunixi\\Sdk; final class LunixiClient { public static function create(...$args) { throw new \\RuntimeException("Unscoped collision sentinel invoked."); } }');
require $root . '/lunixi.php';
class_exists(Lunixi\WP\Automation\Forward::class);
class_exists(Lunixi\WP\Identity\Module::class);
$prefix = 'Lunixi\\WP\\Vendor\\Lunixi\\Sdk\\';
$signer = $prefix . 'Auth\\Ed25519Signer';
$keys = $signer::generateKeyPair();
$settings = new Lunixi\WP\Core\Settings();
(new ReflectionProperty($settings, 'cache'))->setValue($settings, ['environment' => 'TEST', 'key_id' => 'mk_test_scoped_probe',
    'private_key' => Lunixi\WP\Core\SecretBox::encrypt($keys['privateKey'])]);
$client = (new Lunixi\WP\Core\SdkFactory($settings))->client();
if (get_class($client) !== $prefix . 'LunixiClient') {
    throw new RuntimeException('SDK namespace collision.');
}
$references = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $code = file_get_contents($file->getPathname());
    if (preg_match('~^\s*(?:use|namespace)\s+Lunixi\\\\Sdk(?:\\\\|;)~m', $code)) {
        throw new RuntimeException('Unscoped executable SDK reference.');
    }
    preg_match_all('~^\s*use\s+(Lunixi\\\\WP\\\\Vendor\\\\Lunixi\\\\Sdk\\\\[A-Za-z0-9_\\\\]+)(?:\s|;)~m', $code, $matches);
    foreach ($matches[1] as $name) {
        $references[$name] = true;
    }
}
foreach (array_keys($references) as $name) {
    if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
        throw new RuntimeException('Scoped symbol missing: ' . $name);
    }
}
if (
    count($references) < 20 || !function_exists('lunixi_kyc_is_verified')
    || !function_exists('lunixi_automation_send') || !function_exists('lunixi_identity_field')
) {
    throw new RuntimeException('Public facade or SDK reference scan incomplete: symbols=' . count($references)
        . '; facade=' . (function_exists('lunixi_kyc_is_verified') ? 'yes' : 'no'));
}
$metadata = json_decode(file_get_contents($root . '/vendor/scoped-package.json'), true, 512, JSON_THROW_ON_ERROR);
$expectedSdkReference = LUNIXI_DIST_SDK_REFERENCE;
if (($metadata['sdk']['sourceKind'] ?? '') === 'reviewed-local') {
    $expectedSdkReference = getenv('SDK_SOURCE_REFERENCE') ?: '';
    if (preg_match('/^[a-f0-9]{40}$/D', $expectedSdkReference) !== 1) {
        throw new RuntimeException('Local artifact validation requires the reviewed SDK_SOURCE_REFERENCE.');
    }
}
if (preg_match('/^[a-f0-9]{40}$/D', $expectedSdkReference) !== 1 || $metadata['sdk']['version'] !== LUNIXI_DIST_SDK_VERSION || $metadata['sdk']['sourceReference'] !== $expectedSdkReference) {
    throw new RuntimeException('SDK pin mismatch.');
}
if (!is_file($root . '/vendor/lunixi/php-sdk/LICENSE')) {
    throw new RuntimeException('SDK MIT license missing.');
}
echo json_encode(['scopedClientInstantiated' => true, 'preExistingUnscopedSdkPreserved' => true,
    'scopedSdkSymbolsResolved' => count($references), 'publicPluginFacadePreserved' => true, 'merchantComposerRequired' => false]) . "\n";
