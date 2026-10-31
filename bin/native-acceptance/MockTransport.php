<?php
declare(strict_types=1);
/**
 * Deliberately synthetic native WordPress HTTP transport. Never use on a store.
 * All gateway traffic and email are intercepted; no registered merchant exists.
 */
defined('ABSPATH') || exit;
if (getenv('LUNIXI_WP_NATIVE_ACCEPTANCE') !== '1' || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Native fixture requires an explicitly local disposable installation.');
}
if (defined('LUNIXI_NATIVE_ACCEPTANCE_TRANSPORT')) { return; }
define('LUNIXI_NATIVE_ACCEPTANCE_TRANSPORT', true);
if (PHP_SAPI === 'cli-server') { header('X-Lunixi-Native-Fixture: active'); }
$observeNativeFailure = static function (): void {
    // Preserve the real registered handler. Record only class/file/line when it
    // throws so an HTTP fail-closed result remains diagnosable without logging
    // a remote exception message, response body, buyer data or credentials.
    $hook = $GLOBALS['wp_filter']['lunixi_webhook_event'] ?? null;
    if (!is_object($hook)) { return; }
    foreach ($hook->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $entry) {
            $callback = $entry['function'];
            if (!is_array($callback) || !($callback[0] instanceof \Lunixi\WP\Payment\OrderSync)) { continue; }
            remove_action('lunixi_webhook_event', $callback, $priority);
            add_action('lunixi_webhook_event', static function ($event) use ($callback): void {
                try { $callback($event); }
                catch (\Throwable $e) {
                    $h = fopen((string) getenv('LUNIXI_WP_NATIVE_STATE'), 'r+'); flock($h, LOCK_EX);
                    try {
                        $state = json_decode(stream_get_contents($h), true, 512, JSON_THROW_ON_ERROR);
                        $state['lastProcessingFailure'] = ['exceptionClass' => get_class($e), 'sourceFile' => basename($e->getFile()), 'sourceLine' => $e->getLine()];
                        rewind($h); ftruncate($h, 0); fwrite($h, json_encode($state, JSON_THROW_ON_ERROR)); fflush($h);
                    } finally { flock($h, LOCK_UN); fclose($h); }
                    throw $e;
                }
            }, (int) $priority, 1);
        }
    }
};
add_action('lunixi_register_modules', $observeNativeFailure, 999, 0);
$observeNativeFailure();
add_filter('pre_wp_mail', static function () { return true; });
add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    if (parse_url($url, PHP_URL_HOST) !== 'api-gateway.lunixi.com') {
        return new WP_Error('acceptance_network_blocked', 'All external HTTP is disabled in native acceptance.');
    }
    $file = getenv('LUNIXI_WP_NATIVE_STATE');
    if (!$file || !is_file($file)) { return new WP_Error('acceptance_state_missing', 'Fixture state unavailable.'); }
    $handle = fopen($file, 'r+');
    if (!$handle || !flock($handle, LOCK_EX)) { throw new RuntimeException('Fixture state lock unavailable.'); }
    try {
        $state = json_decode(stream_get_contents($handle), true, 512, JSON_THROW_ON_ERROR);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $method = strtoupper($args['method'] ?? 'GET');
        $headers = $args['headers'] ?? [];
        $key = (string) ($headers['Idempotency-Key'] ?? $headers['idempotency-key'] ?? '');
        $state['calls'][] = ['method' => $method, 'path' => $path,
            'keyHash' => $key === '' ? null : hash('sha256', $key), 'keyLength' => strlen($key),
            'authenticated' => isset($headers['Authorization'])];
        $reply = static function (array $body, int $status = 200): array {
            return ['headers' => ['content-type' => 'application/json'], 'body' => json_encode($body, JSON_THROW_ON_ERROR),
                'response' => ['code' => $status, 'message' => 'Synthetic acceptance'], 'cookies' => [], 'filename' => null];
        };
        if (str_ends_with($path, '/auth/token')) {
            $result = $reply(['status' => 'success', 'data' => ['access_token' => 'unregistered-native-fixture-only', 'expires_in' => 900]]);
        } elseif ($method === 'POST' && str_ends_with($path, '/payments/intents')) {
            if (strlen($key) < 16 || strlen($key) > 128) {
                $result = $reply(['status' => 'error', 'code' => 'IDEMPOTENCY_KEY_INVALID'], 400);
            } else {
                $body = json_decode((string) $args['body'], true, 512, JSON_THROW_ON_ERROR);
                // Different keys model different real intents, even for one
                // order, so a reservation race cannot hide behind a mock ID.
                $id = 'pi_fixture_' . $body['orderId'] . '_' . substr(hash('sha256', $key), 0, 16);
                $state['calls'][count($state['calls']) - 1]['orderId'] = (string) $body['orderId'];
                $state['calls'][count($state['calls']) - 1]['bodyHash'] = hash('sha256', (string) $args['body']);
                $token = 'unregistered-embedded-fixture-' . $id;
                $config = ['type' => 'payment', 'target' => 'lunixi-checkout-form',
                    'options' => ['token' => $token, 'paymentId' => $id, 'fixtureMultiCard' => true]];
                $script = "<script>(function(){\nvar sdkUrl = \"https://cdn.lunixi.com/lunixi-checkout.min.js\";\nvar checkoutConfig = "
                    . json_encode($config, JSON_THROW_ON_ERROR) . ";\n})();</script>";
                $state['payments'][$id] = ['id' => $id, 'orderId' => (string) $body['orderId'], 'merchantId' => 'fixture-merchant-a',
                    'amount' => $body['amount'], 'currency' => $body['currency'], 'status' => 'INITIATED'];
                $state['details'][$id] = ['capturedAmount' => '0', 'refundedAmount' => '0'];
                // The real gateway de-duplicates the same key. Network retries
                // must remain visible as calls, not invent additional intents.
                if (!isset($state['createKeys'][hash('sha256', $key)])) {
                    $state['createKeys'][hash('sha256', $key)] = $id;
                    $state['creates'][] = ['paymentId' => $id, 'orderId' => $body['orderId'], 'amount' => $body['amount'],
                        'currency' => $body['currency'], 'keyHash' => hash('sha256', $key), 'keyLength' => strlen($key)];
                }
                $result = ($state['loseCreateResponse'] ?? false)
                    ? new WP_Error('synthetic_timeout', 'Synthetic lost create response.')
                    : $reply(['status' => 'success', 'data' => ['paymentId' => $id, 'token' => $token, 'checkoutFormContent' => $script]], 201);
            }
        } elseif ($method === 'GET' && preg_match('~/payments/split/([^/]+)$~D', $path, $matches)) {
            $id = rawurldecode($matches[1]);
            $result = isset($state['splits'][$id]) ? $reply(['status' => 'success', 'data' => $state['splits'][$id]])
                : $reply(['status' => 'error', 'code' => 'split.not_found'], 404);
        } elseif ($method === 'GET' && preg_match('~/payments/([^/]+)$~D', $path, $matches)) {
            $id = rawurldecode($matches[1]);
            $result = isset($state['errors'][$id]) ? new WP_Error('synthetic_timeout', 'Synthetic authenticated GET timeout.')
                : (isset($state['payments'][$id]) ? $reply(['status' => 'success', 'data' => $state['payments'][$id],
                    'details' => $state['details'][$id] ?? []]) : $reply(['status' => 'error', 'code' => 'payment.not_found'], 404));
        } else {
            $result = $reply(['status' => 'error', 'code' => 'fixture.operation_not_allowed'], 400);
        }
        $delay = (int) ($state['getDelayMicros'] ?? 0);
        $createDelay = (int) ($state['createDelayMicros'] ?? 0);
        rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)); fflush($handle);
    } finally { flock($handle, LOCK_UN); fclose($handle); }
    if ($method === 'GET' && $delay > 0) { usleep(min($delay, 500000)); }
    if ($method === 'POST' && str_ends_with($path, '/payments/intents') && $createDelay > 0) { usleep(min($createDelay, 500000)); }
    return $result;
}, PHP_INT_MAX, 3);
add_action('woocommerce_payment_complete', static function ($id): void {
    $file = getenv('LUNIXI_WP_NATIVE_HOOKS');
    if (!$file) { throw new RuntimeException('Native completion observation unavailable.'); }
    file_put_contents($file, (int) $id . "\n", FILE_APPEND | LOCK_EX);
});
