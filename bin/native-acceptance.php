<?php
declare(strict_types=1);
/**
 * Native WooCommerce acceptance runner for a NEW disposable local database only.
 * Usage and destructive-fixture boundaries: ../docs/ACCEPTANCE.md
 */
if (PHP_SAPI !== 'cli' || getenv('LUNIXI_WP_NATIVE_ACCEPTANCE') !== '1') {
    throw new RuntimeException('Explicit CLI disposable fixture opt-in is required.');
}
$runtime = realpath((string) getenv('LUNIXI_WP_NATIVE_ROOT'));
if (!$runtime || !is_file($runtime . '/wp-load.php')) { throw new RuntimeException('WordPress fixture root missing.'); }
require $runtime . '/wp-load.php';
if (wp_get_environment_type() !== 'local' || !preg_match('/^lunixi_(?:wp_)?(?:accept|test)[a-zA-Z0-9_]*$/D', DB_NAME)) {
    throw new RuntimeException('Refusing a nonlocal or nonfixture database.');
}
if (!class_exists('WooCommerce') || !class_exists(\Lunixi\WP\Payment\PaymentEvidence::class)) {
    throw new RuntimeException('Install WooCommerce and this candidate plugin before running.');
}
foreach ((array) get_option('active_plugins', []) as $active) {
    if (!in_array($active, ['woocommerce/woocommerce.php', 'lunixi/lunixi.php'], true)) {
        throw new RuntimeException('Only WooCommerce and Lunixi may be active in this disposable fixture.');
    }
}
require __DIR__ . '/native-acceptance/MockTransport.php';

use Lunixi\WP\Payment\{CheckoutBootstrap, Gateway, OrderMutex, PaymentEvidence, Reconciler};
use Lunixi\WP\Core\SecretBox;
use Lunixi\WP\Plugin;

final class LunixiNativeAcceptance
{
    private array $rows = [];
    private string $state;
    private string $hooks;
    private string $private;
    private int $customer;
    private string $password;
    private WC_Product_Simple $product;

    public function __construct()
    {
        $this->state = (string) getenv('LUNIXI_WP_NATIVE_STATE');
        $this->hooks = (string) getenv('LUNIXI_WP_NATIVE_HOOKS');
        if (!is_file($this->state) || !is_file($this->hooks)) { throw new RuntimeException('Create explicit private fixture state/hook files first.'); }
        $this->private = dirname($this->state);
    }
    private function state(?callable $mutate = null): array
    {
        $h = fopen($this->state, 'r+'); flock($h, LOCK_EX);
        try {
            $s = json_decode(stream_get_contents($h), true, 512, JSON_THROW_ON_ERROR);
            if ($mutate) { $s = $mutate($s); rewind($h); ftruncate($h, 0); fwrite($h, json_encode($s, JSON_THROW_ON_ERROR)); fflush($h); }
            return $s;
        } finally { flock($h, LOCK_UN); fclose($h); }
    }
    private function check(string $case, bool $pass, array $evidence = []): void
    {
        $this->rows[] = ['case' => $case, 'pass' => $pass] + $evidence;
        if (!$pass) { throw new RuntimeException('Native acceptance failed: ' . $case); }
    }
    private function order(bool $saveAttempt = true): WC_Order
    {
        $o = wc_create_order(['customer_id' => $this->customer, 'status' => 'pending']);
        $o->set_currency('TRY'); $o->set_payment_method('lunixi'); $o->set_billing_email('fixture@example.test');
        $o->add_product($this->product, 1); $o->calculate_totals(); $o->set_date_created(time() - 3600);
        $o->save();
        if ($saveAttempt) {
            $id = 'pi_fixture_' . $o->get_id();
            $o->update_meta_data('_lunixi_payment_order_id', $id); $o->update_meta_data('_lunixi_active_attempt_id', $id);
            $o->update_meta_data('_lunixi_merchant_id', 'fixture-merchant-a');
            $o->update_meta_data('_lunixi_attempt_idempotency_key', 'fixture-retained-key-' . $o->get_id());
            $o->update_meta_data('_lunixi_attempt_status', 'INITIATED'); $o->update_meta_data('_lunixi_payment_environment', 'TEST');
            $o->update_meta_data('_lunixi_order_snapshot', PaymentEvidence::snapshot($o)); $o->save();
            $this->payment($o);
        }
        return $o;
    }
    private function payment(WC_Order $o, array $data = [], array $details = [], bool $timeout = false): void
    {
        $id = (string) $o->get_meta('_lunixi_payment_order_id');
        $this->state(static function (array $s) use ($id, $o, $data, $details, $timeout): array {
            $s['payments'][$id] = array_merge(['id' => $id, 'orderId' => (string) $o->get_order_number(), 'merchantId' => 'fixture-merchant-a',
                'amount' => 1250, 'currency' => 'TRY', 'status' => 'CAPTURED'], $data);
            $s['details'][$id] = array_merge(['capturedAmount' => '1250', 'refundedAmount' => '0'], $details);
            unset($s['errors'][$id]); if ($timeout) { $s['errors'][$id] = 'timeout'; }
            return $s;
        });
    }
    private function completeCount(int $id): int
    {
        return count(array_filter(file($this->hooks, FILE_IGNORE_NEW_LINES) ?: [], static fn(string $line): bool => $line === (string) $id));
    }
    private function fresh(WC_Order $o): WC_Order { return OrderMutex::reread($o->get_id()); }
    private function callsFor(WC_Order $o): int
    {
        $path = '/api/v1/payments/' . $o->get_meta('_lunixi_payment_order_id');
        return count(array_filter($this->state()['calls'] ?? [], static fn(array $call): bool => $call['path'] === $path && $call['method'] === 'GET' && $call['authenticated']));
    }
    public function event(WC_Order $o, string $type = 'payment.captured', array $changes = [], ?string $eventId = null, string $environment = 'TEST'): array
    {
        $eventId = $eventId ?? 'evt_fixture_' . $o->get_id() . '_' . str_replace('.', '_', $type);
        $time = gmdate('Y-m-d\TH:i:s\Z');
        $data = array_merge(['intentId' => (string) $o->get_meta('_lunixi_payment_order_id'), 'merchantId' => 'fixture-merchant-a',
            'orderId' => (string) $o->get_order_number(), 'currency' => 'TRY', 'amountMinor' => '1250', 'capturedAmountMinor' => '1250'], $changes);
        $body = json_encode(['id' => $eventId, 'type' => $type, 'environment' => $environment, 'data' => $data], JSON_THROW_ON_ERROR);
        return ['body' => $body, 'headers' => ['x-lunixi-event-id' => $eventId, 'x-lunixi-event-type' => $type,
            'x-lunixi-signature-timestamp' => $time, 'x-lunixi-signature' => 'v2=' . hash_hmac('sha256',
                $eventId . '.' . $time . '.' . $body, Plugin::instance()->settings()->webhookSecret())]];
    }
    private function webhook(array $e): WP_REST_Response
    {
        $r = new WP_REST_Request('POST', '/lunixi/v1/webhook'); $r->set_body($e['body']);
        foreach ($e['headers'] as $name => $value) { $r->set_header($name, $value); }
        return rest_do_request($r);
    }
    private function reconcile(WC_Order $o): void { (new ReflectionMethod(Reconciler::class, 'reconcileOne'))->invoke(new Reconciler(), $o); }
    private function held(string $case, array $data = [], array $details = [], bool $timeout = false, array $claims = []): void
    {
        $o = $this->order(); $this->payment($o, $data, $details, $timeout);
        $key = $o->get_meta('_lunixi_attempt_idempotency_key'); $e = $this->event($o, 'payment.captured', $claims);
        $response = $this->webhook($e); $fresh = $this->fresh($o);
        $eventId = $e['headers']['x-lunixi-event-id'];
        $this->check($case, $response->get_status() === 503 && !$fresh->is_paid() && $this->completeCount($o->get_id()) === 0
            && $this->callsFor($o) > 0 && $fresh->get_meta('_lunixi_attempt_idempotency_key') === $key
            && !get_transient('lunixi_wh_done_' . md5($eventId)), ['httpStatus' => $response->get_status(), 'authenticatedGetObserved' => $this->callsFor($o) > 0]);
    }
    public function worker(string $role, int $id, string $barrier): void
    {
        $o = wc_get_order($id);
        if (!$o instanceof WC_Order || $o->is_paid()) { throw new RuntimeException('Race must start from a native pending snapshot.'); }
        if (str_starts_with($role, 'create-') && CheckoutBootstrap::hasOrderData($o)) {
            throw new RuntimeException('Create race must start before an attempt exists.');
        }
        touch($barrier . '.' . $role . '.ready');
        $deadline = microtime(true) + 15;
        while (!is_file($barrier . '.go')) { if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timeout.'); } usleep(10000); }
        if (str_starts_with($role, 'create-')) {
            if (!WC()->session) { WC()->initialize_session(); }
            $result = (new Gateway())->process_payment($id); $fresh = $this->fresh($o);
            $bootstrap = CheckoutBootstrap::fromOrder($fresh);
            echo json_encode(['role' => $role, 'completed' => true, 'result' => $result['result'],
                'paymentHash' => hash('sha256', (string) $fresh->get_meta('_lunixi_payment_order_id')),
                'keyHash' => hash('sha256', (string) $fresh->get_meta('_lunixi_attempt_idempotency_key')),
                'bootstrapHash' => hash('sha256', $bootstrap ? $bootstrap->mountScript() : '')]) . "\n";
            return;
        }
        if ($role === 'webhook') { $r = $this->webhook($this->event($o)); if ($r->get_status() !== 200) { throw new RuntimeException('Race webhook deferred.'); } }
        else { $this->reconcile($o); }
        echo json_encode(['role' => $role, 'completed' => true]) . "\n";
    }
    private function createRaces(): void
    {
        foreach (['success', 'unknown-response'] as $mode) {
            $o = $this->order(false);
            $this->state(static function (array $s) use ($mode): array {
                $s['createDelayMicros'] = 250000; $s['loseCreateResponse'] = $mode === 'unknown-response'; return $s;
            });
            $barrier = $this->private . '/create-barrier-' . bin2hex(random_bytes(6)); $processes = [];
            try {
                foreach (['create-a', 'create-b'] as $role) {
                    $p = proc_open([PHP_BINARY, __FILE__, 'worker', $role, (string) $o->get_id(), $barrier],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->private . '/worker-stderr.log', 'a']], $pipes);
                    if (!is_resource($p)) { throw new RuntimeException('Native create worker unavailable.'); }
                    fclose($pipes[0]); $processes[] = [$p, $pipes[1]];
                }
                $deadline = microtime(true) + 20;
                while (!is_file($barrier . '.create-a.ready') || !is_file($barrier . '.create-b.ready')) {
                    if (microtime(true) > $deadline) { throw new RuntimeException('Both native create workers did not reach the barrier.'); } usleep(10000);
                }
                touch($barrier . '.go'); $ok = true; $results = [];
                foreach ($processes as [$p, $stdout]) {
                    $result = json_decode(stream_get_contents($stdout), true); fclose($stdout);
                    $ok = proc_close($p) === 0 && ($result['completed'] ?? false) && $ok; $results[] = $result;
                }
                $processes = []; $fresh = $this->fresh($o); $state = $this->state();
                $created = array_values(array_filter($state['creates'] ?? [], static fn(array $r): bool => (string) $r['orderId'] === (string) $o->get_order_number()));
                $calls = array_values(array_filter($state['calls'] ?? [], static fn(array $r): bool => ($r['orderId'] ?? '') === (string) $o->get_order_number() && $r['method'] === 'POST'));
                $before = count($calls);
                $this->state(static function (array $s): array { unset($s['loseCreateResponse'], $s['createDelayMicros']); return $s; });
                $retained = (new Gateway())->process_payment($o->get_id()); $after = $this->fresh($o);
                $afterCalls = array_filter($this->state()['calls'] ?? [], static fn(array $r): bool => ($r['orderId'] ?? '') === (string) $o->get_order_number() && $r['method'] === 'POST');
                $want = $mode === 'success' ? 'success' : 'failure';
                $this->check('two-process-create-' . $mode, $ok && count($created) === 1 && $before >= 1
                    && count(array_unique(array_column($calls, 'keyHash'))) === 1 && count(array_unique(array_column($calls, 'bodyHash'))) === 1
                    && $results[0]['result'] === $want && $results[1]['result'] === $want && $retained['result'] === $want
                    && $results[0]['keyHash'] === $results[1]['keyHash'] && $results[0]['paymentHash'] === $results[1]['paymentHash']
                    && $results[0]['bootstrapHash'] === $results[1]['bootstrapHash']
                    && $fresh->get_meta('_lunixi_attempt_idempotency_key') === $after->get_meta('_lunixi_attempt_idempotency_key')
                    && count($afterCalls) === $before && !$after->is_paid() && strlen($after->get_meta('_lunixi_attempt_idempotency_key')) >= 16,
                    ['nativeWorkers' => 2, 'createdPayments' => count($created), 'uniqueKeys' => count(array_unique(array_column($calls, 'keyHash'))),
                        'uniqueRequestBodies' => count(array_unique(array_column($calls, 'bodyHash'))), 'repeatSentAnotherCreate' => count($afterCalls) !== $before]);
            } finally {
                foreach ($processes as [$p, $stdout]) { proc_terminate($p); fclose($stdout); proc_close($p); }
                foreach (glob($barrier . '.*') ?: [] as $file) { unlink($file); }
            }
        }
    }
    private function races(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            $o = $this->order(); $productBefore = (int) wc_get_product($this->product->get_id())->get_stock_quantity();
            $this->state(static function (array $s): array { $s['getDelayMicros'] = 250000; return $s; });
            $barrier = $this->private . '/barrier-' . bin2hex(random_bytes(6)); $processes = [];
            try {
                foreach (['webhook', 'cron'] as $role) {
                    $pipes = [];
                    $p = proc_open([PHP_BINARY, __FILE__, 'worker', $role, (string) $o->get_id(), $barrier],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->private . '/worker-stderr.log', 'a']], $pipes);
                    if (!is_resource($p)) { throw new RuntimeException('Native worker unavailable.'); }
                    fclose($pipes[0]); $processes[] = [$p, $pipes[1]];
                }
                $deadline = microtime(true) + 20;
                while (!is_file($barrier . '.webhook.ready') || !is_file($barrier . '.cron.ready')) {
                    if (microtime(true) > $deadline) { throw new RuntimeException('Both native workers did not reach the pending barrier.'); } usleep(10000);
                }
                touch($barrier . '.go'); $ok = true;
                foreach ($processes as [$p, $stdout]) { $result = json_decode(stream_get_contents($stdout), true); fclose($stdout); $ok = proc_close($p) === 0 && ($result['completed'] ?? false) && $ok; }
                $processes = []; $fresh = $this->fresh($o); clean_post_cache($this->product->get_id());
                $productAfter = (int) wc_get_product($this->product->get_id())->get_stock_quantity();
                $notes = wc_get_order_notes(['order_id' => $o->get_id(), 'type' => 'internal']);
                $settlementNotes = array_filter($notes, static fn($note): bool => str_contains($note->content, 'Lunixi: payment captured')
                    || str_contains($note->content, 'Lunixi: settled by reconciliation'));
                $this->check('two-process-webhook-cron-round-' . $round, $ok && $fresh->is_paid() && $this->completeCount($o->get_id()) === 1
                    && $productBefore - $productAfter === 1 && count($settlementNotes) === 1,
                    ['nativeWorkers' => 2, 'completionHooks' => $this->completeCount($o->get_id()), 'stockDelta' => $productBefore - $productAfter, 'settlementNotes' => count($settlementNotes)]);
            } finally {
                foreach ($processes as [$p, $stdout]) { proc_terminate($p); fclose($stdout); proc_close($p); }
                foreach (glob($barrier . '.*') ?: [] as $file) { unlink($file); }
            }
        }
        $this->state(static function (array $s): array { unset($s['getDelayMicros']); return $s; });
    }
    private function http(WC_Order $o): void
    {
        $origin = home_url(); $parts = parse_url($origin);
        if (($parts['host'] ?? '') !== '127.0.0.1' || ($parts['scheme'] ?? '') !== 'http' || !isset($parts['port'])) { throw new RuntimeException('HTTP probe requires an explicit loopback development origin and port.'); }
        WC_Install::create_pages();
        wp_update_post(['ID' => wc_get_page_id('checkout'), 'post_content' => '[woocommerce_checkout]']);
        $mu = ABSPATH . 'wp-content/mu-plugins/lunixi-native-acceptance.php';
        if (is_file($mu)) { throw new RuntimeException('Refusing to replace an existing MU fixture.'); }
        $madeMuDirectory = !is_dir(dirname($mu));
        if ($madeMuDirectory && !mkdir(dirname($mu), 0755, true) && !is_dir(dirname($mu))) {
            throw new RuntimeException('Native MU fixture directory unavailable.');
        }
        $loader = '<?php require ' . var_export(__DIR__ . '/native-acceptance/MockTransport.php', true) . ';';
        if (file_put_contents($mu, $loader) !== strlen($loader)) { throw new RuntimeException('Native MU fixture installation failed.'); }
        $server = null; $cookie = $this->private . '/http-cookies';
        try {
            $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $parts['port'], '-t', ABSPATH, __DIR__ . '/native-acceptance/router.php'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->private . '/http-server.log', 'a'], 2 => ['file', $this->private . '/http-server.log', 'a']], $pipes);
            if (!is_resource($server)) { throw new RuntimeException('Native HTTP server unavailable.'); }
            $request = static function (string $url, ?array $post = null, bool $owner = true, array $headers = [], ?string $raw = null) use ($cookie): array {
                $fixtureLoaded = false;
                $c = curl_init($url); $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$fixtureLoaded): int {
                        if (strtolower(trim($line)) === 'x-lunixi-native-fixture: active') { $fixtureLoaded = true; } return strlen($line);
                    }];
                if ($owner) { $options[CURLOPT_COOKIEJAR] = $cookie; $options[CURLOPT_COOKIEFILE] = $cookie; }
                if ($post !== null || $raw !== null) { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $raw ?? http_build_query($post); }
                curl_setopt_array($c, $options); $body = curl_exec($c); $status = curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
                return ['status' => $status, 'body' => is_string($body) ? $body : '', 'fixtureTransportLoaded' => $fixtureLoaded];
            };
            $ready = false;
            for ($i = 0; $i < 40; $i++) { if ($request($origin)['status'] > 0) { $ready = true; break; } usleep(100000); }
            $this->check('actual-http-server-ready', $ready);
            $request($origin . '/wp-login.php');
            $login = $request($origin . '/wp-login.php', ['log' => get_userdata($this->customer)->user_login, 'pwd' => $this->password,
                'wp-submit' => 'Log In', 'redirect_to' => $origin, 'testcookie' => '1']);
            $this->check('actual-http-native-customer-login', $login['status'] === 302);
            $url = $o->get_checkout_payment_url(true); $page = $request($url);
            $this->check('merchant-origin-owned-page-mounts-existing-form', $page['status'] === 200
                && $page['fixtureTransportLoaded']
                && str_contains($page['body'], 'id="lunixi-checkout-form"') && str_contains($page['body'], 'https://cdn.lunixi.com/lunixi-checkout.min.js')
                && str_contains($page['body'], 'checkout.create('), ['httpStatus' => $page['status'], 'externalSdkFetched' => false]);
            preg_match('~<script[^>]*>([^<]*checkout\.create\([\s\S]*?)</script>~', $page['body'], $match);
            $p = proc_open(['node', __DIR__ . '/native-acceptance/mount-check.js'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'],
                2 => ['file', $this->private . '/node-stderr.log', 'a']], $pipes);
            fwrite($pipes[0], json_encode(['script' => $match[1] ?? '', 'paymentId' => $o->get_meta('_lunixi_payment_order_id')], JSON_THROW_ON_ERROR));
            fclose($pipes[0]); $result = json_decode(stream_get_contents($pipes[1]), true); fclose($pipes[1]); $exit = proc_close($p);
            $this->check('actual-inline-script-create-and-mount', $exit === 0 && ($result['mountedOnce'] ?? false) && ($result['originalConfigurationPreserved'] ?? false),
                ['sdkImplementation' => 'explicit Node VM stub, not a bank/browser test']);
            // Native receipt links are bearer capabilities, including for a
            // registered customer's order. A valid native key is intentional.
            $capability = $request($url, null, false);
            $this->check('valid-native-receipt-capability-preserves-woocommerce-flow', str_contains($capability['body'], 'id="lunixi-checkout-form"'));
            $anonymous = $request(remove_query_arg('key', $url), null, false);
            $this->check('missing-native-order-capability-cannot-read-form', !str_contains($anonymous['body'], 'id="lunixi-checkout-form"') && !str_contains($anonymous['body'], 'unregistered-embedded-fixture-'));
            $wrongKey = $request(preg_replace('/([?&]key=)[^&]*/', '$1invalid-fixture-key', $url));
            $this->check('invalid-order-capability-cannot-read-form', !str_contains($wrongKey['body'], 'id="lunixi-checkout-form"') && !str_contains($wrongKey['body'], 'unregistered-embedded-fixture-'));
            $request(add_query_arg(['_status' => 'CAPTURED', '_signature' => 'v2=forged', 'amount' => '1250', 'paymentId' => 'other'], $o->get_checkout_order_received_url()));
            $this->check('forged-browser-return-has-no-payment-authority', !$this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 0);
            $this->payment($o); $event = $this->event($o);
            global $wpdb;
            $lockName = (new ReflectionMethod(OrderMutex::class, 'lockName'))->invoke(null, $o->get_id(), DB_NAME, $wpdb->prefix, get_current_blog_id());
            $lockOwner = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $lockName));
            $sameWorkerLock = $lockOwner !== null && (string) $lockOwner === (string) $wpdb->get_var('SELECT CONNECTION_ID()');
            $headers = ['Content-Type: application/json'];
            foreach ($event['headers'] as $name => $value) { $headers[] = $name . ': ' . $value; }
            $response = $request(add_query_arg('rest_route', '/lunixi/v1/webhook', $origin . '/'), null, false, $headers, $event['body']);
            $error = json_decode($response['body'], true)['error'] ?? null;
            $this->check('actual-http-valid-signed-webhook-completes-once', $response['status'] === 200 && $this->fresh($o)->is_paid()
                && $response['fixtureTransportLoaded'] && $this->completeCount($o->get_id()) === 1,
                ['httpStatus' => $response['status'], 'fixtureTransportLoaded' => $response['fixtureTransportLoaded'], 'lockHeldByCaller' => $sameWorkerLock]
                    + ($response['status'] === 200 ? [] : ['safeFailureLocation' => $this->state()['lastProcessingFailure'] ?? null,
                        'errorCode' => in_array($error, ['not_configured', 'reconciliation_required'], true) ? $error : 'unrecognized-response']));
        } finally {
            if (is_resource($server)) { proc_terminate($server); proc_close($server); }
            if (is_file($mu)) { unlink($mu); }
            if ($madeMuDirectory && is_dir(dirname($mu))) { rmdir(dirname($mu)); }
            if (is_file($cookie)) { unlink($cookie); }
        }
    }
    public function run(string $output): void
    {
        $settings = get_option('lunixi_settings', []);
        if (!empty($settings['key_id']) && !in_array($settings['key_id'], ['mk_test_local_acceptance_only', 'mk_test_native_acceptance'], true)) {
            throw new RuntimeException('Refusing to replace a pre-existing merchant profile.');
        }
        $pair = \Lunixi\Sdk\Auth\Ed25519Signer::generateKeyPair();
        update_option('lunixi_settings', ['environment' => 'TEST', 'key_id' => 'mk_test_native_acceptance',
            'private_key' => SecretBox::encrypt($pair['privateKey']), 'webhook_secret' => SecretBox::encrypt(bin2hex(random_bytes(32)))], false);
        (new ReflectionProperty(Plugin::instance()->settings(), 'cache'))->setValue(Plugin::instance()->settings(), null);
        update_option('woocommerce_lunixi_settings', ['enabled' => 'yes', 'capture_mode' => 'sale']);
        update_option('woocommerce_currency', 'TRY'); update_option('woocommerce_manage_stock', 'yes');
        if (!WC()->session) { WC()->initialize_session(); }
        $this->password = bin2hex(random_bytes(24));
        $this->customer = (int) wp_create_user('lunixi_fixture_' . bin2hex(random_bytes(5)), $this->password, 'fixture-' . bin2hex(random_bytes(5)) . '@example.test');
        wp_set_current_user($this->customer);
        $this->product = new WC_Product_Simple(); $this->product->set_name('Disposable Lunixi native acceptance');
        $this->product->set_regular_price('12.50'); $this->product->set_virtual(true); $this->product->set_manage_stock(true);
        $this->product->set_stock_quantity(1000); $this->product->save();
        try {
            $o = $this->order(); $o->set_date_created('2000-01-01 00:00:00'); $o->save();
            (new Reconciler())->run();
            $this->check('public-cron-query-selects-and-completes-real-order', $this->fresh($o)->is_paid() && $this->callsFor($o) > 0 && $this->completeCount($o->get_id()) === 1);
            $o = $this->order(false); $gateway = new Gateway(); $one = $gateway->process_payment($o->get_id()); $first = $this->fresh($o);
            $two = $gateway->process_payment($o->get_id()); $again = $this->fresh($o); $creates = $this->state()['creates'] ?? [];
            $this->check('native-create-and-retained-embedded-attempt', $one['result'] === 'success' && $two['result'] === 'success'
                && $first->get_meta('_lunixi_payment_order_id') === $again->get_meta('_lunixi_payment_order_id')
                && $first->get_meta('_lunixi_attempt_idempotency_key') === $again->get_meta('_lunixi_attempt_idempotency_key')
                && strlen($first->get_meta('_lunixi_attempt_idempotency_key')) >= 16
                && str_starts_with($one['redirect'], home_url()) && count($creates) === 1, ['idempotencyLength' => strlen($first->get_meta('_lunixi_attempt_idempotency_key'))]);
            $httpOrder = $again;
            $lost = $this->order(false);
            $this->state(static function (array $s): array { $s['loseCreateResponse'] = true; return $s; });
            $r1 = $gateway->process_payment($lost->get_id()); $key = $this->fresh($lost)->get_meta('_lunixi_attempt_idempotency_key');
            $r2 = $gateway->process_payment($lost->get_id());
            $this->check('unknown-create-does-not-mint-second-key-or-intent', $r1['result'] === 'failure' && $r2['result'] === 'failure'
                && $this->fresh($lost)->get_meta('_lunixi_attempt_idempotency_key') === $key && count($this->state()['creates']) === 2);
            $this->state(static function (array $s): array { unset($s['loseCreateResponse']); return $s; });
            $o = $this->order(); $event = $this->event($o); $r = $this->webhook($event); $r2 = $this->webhook($event);
            $this->check('valid-native-header-capture-and-exact-replay', $r->get_status() === 200 && $r2->get_status() === 200
                && $this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 1);
            foreach (['bad-signature', 'duplicate-event-id', 'duplicate-timestamp', 'duplicate-signature'] as $mode) {
                $o = $this->order(); $e = $this->event($o);
                if ($mode === 'bad-signature') { $e['headers']['x-lunixi-signature'] = 'v2=' . str_repeat('0', 64); }
                else { $header = ['duplicate-event-id' => 'x-lunixi-event-id', 'duplicate-timestamp' => 'x-lunixi-signature-timestamp', 'duplicate-signature' => 'x-lunixi-signature'][$mode]; $e['headers'][$header] = [$e['headers'][$header], 'conflicting']; }
                $r = $this->webhook($e);
                $this->check($mode, $r->get_status() === 400 && !$this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 0 && $this->callsFor($o) === 0);
            }
            $this->held('get-timeout-retains-retry', [], [], true);
            $o = $this->order(); $r = $this->webhook($this->event($o, 'payment.captured', [], null, 'LIVE'));
            $this->check('signed-wrong-environment-rejected-before-get', $r->get_status() === 400 && !$this->fresh($o)->is_paid() && $this->callsFor($o) === 0);
            foreach (['wrong-payment' => ['id' => 'other'], 'wrong-order' => ['orderId' => 'other'], 'wrong-merchant' => ['merchantId' => 'other'],
                'wrong-currency' => ['currency' => 'USD'], 'wrong-amount' => ['amount' => 1], 'authorized-is-not-captured' => ['status' => 'AUTHORIZED']] as $case => $data) { $this->held($case, $data); }
            $this->held('partial-balance-held', [], ['capturedAmount' => '1249']);
            $this->held('missing-capture-evidence-held', [], ['capturedAmount' => null]);
            $this->held('refunded-balance-held', [], ['refundedAmount' => '1']);
            $this->held('signed-claim-disagrees-with-authenticated-get', [], [], false, ['currency' => 'USD']);
            $this->held('unexplained-extra-charge-held', ['amount' => 1400], ['capturedAmount' => '1400']);
            $this->held('wrong-canonical-original-amount-held', ['amount' => 1400], ['capturedAmount' => '1400', 'orderAmountMinor' => '1200']);
            $o = $this->order(); $this->payment($o, ['status' => 'PARTIALLY_CAPTURED'], ['capturedAmount' => '1000']);
            $e = $this->event($o, 'payment.partially_captured', ['capturedAmountMinor' => '1250', 'cumulativeCapturedMinor' => '1250', 'remainingCapturableMinor' => '0']);
            $r = $this->webhook($e);
            $this->check('signed-zero-remaining-does-not-override-get-partial-balance', $r->get_status() === 503 && !$this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 0);
            $this->payment($o); $r = $this->webhook($e);
            $this->check('same-partial-capture-event-completes-only-after-full-get-proof', $r->get_status() === 200 && $this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 1);
            $o = $this->order(); $this->payment($o, ['amount' => 1400], ['capturedAmount' => '1400', 'orderAmountMinor' => '1250']);
            $r = $this->webhook($this->event($o, 'payment.captured', ['amountMinor' => '1400', 'capturedAmountMinor' => '1400'])); $f = $this->fresh($o);
            $this->check('canonical-principal-allows-charged-fee-without-invoice-overcredit', $r->get_status() === 200 && $f->is_paid()
                && (string) $f->get_total() === '12.50' && $f->get_meta('_lunixi_order_amount_minor') === '1250'
                && $f->get_meta('_lunixi_charged_amount_minor') === '1400' && $f->get_meta('_lunixi_additions_amount_minor') === '150',
                ['backendContract' => 'synthetic optional details.orderAmountMinor; deployment not assumed']);
            foreach (['get-timeout' => [[], [], true], 'wrong-order' => [['orderId' => 'other'], [], false],
                'wrong-currency' => [['currency' => 'USD'], [], false], 'wrong-amount' => [['amount' => 1], [], false]] as $case => [$data, $details, $timeout]) {
                $o = $this->order(); $this->payment($o, $data, $details, $timeout); $this->reconcile($o);
                $this->check('cron-' . $case, $this->callsFor($o) > 0 && !$this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 0,
                    ['authenticatedGetObserved' => $this->callsFor($o) > 0]);
            }
            $o = $this->order(); $this->payment($o, [], [], true); $e = $this->event($o); $first = $this->webhook($e);
            $this->payment($o); $retry = $this->webhook($e);
            $this->check('same-event-recovers-after-get-timeout', $first->get_status() === 503 && $retry->get_status() === 200
                && $this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 1);
            foreach (['cancelled', 'on-hold', 'edited'] as $mode) {
                $o = $this->order();
                if ($mode === 'edited') { $o->set_total('13.00'); $o->save(); }
                else { $o->update_status($mode); }
                $r = $this->webhook($this->event($o));
                $this->check('native-' . $mode . '-cannot-be-silently-paid', $r->get_status() === 503 && !$this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 0);
            }
            foreach (['payment.authorized' => 'on-hold', 'payment.failed' => 'failed', 'payment.voided' => 'cancelled'] as $type => $status) {
                $o = $this->order(); $r = $this->webhook($this->event($o, $type));
                $this->check($type . '-never-paid', $r->get_status() === 200 && $this->fresh($o)->get_status() === $status && !$this->fresh($o)->is_paid() && $this->completeCount($o->get_id()) === 0);
            }
            foreach (['complete', 'leg', 'partial', 'compensated'] as $mode) {
                $o = $this->order(); $splitId = 'split_fixture_' . $o->get_id();
                $this->payment($o, ['status' => 'SETTLED_BY_SPLIT', 'split' => ['role' => $mode === 'leg' ? 'LEG' : 'PARENT', 'splitPaymentId' => $splitId]],
                    ['capturedAmount' => '0']);
                $this->state(static function (array $s) use ($o, $splitId, $mode): array {
                    $s['splits'][$splitId] = ['id' => $splitId, 'orderId' => (string) $o->get_order_number(), 'merchantId' => 'fixture-merchant-a',
                        'currency' => 'TRY', 'status' => 'COMPLETED', 'needsReconciliation' => false,
                        'aggregate' => ['amountsBalanced' => true, 'totalAmount' => '1250', 'legsAmount' => '1250',
                            'capturedAmount' => $mode === 'partial' ? '1200' : '1250', 'compensatedAmount' => $mode === 'compensated' ? '1' : '0'],
                        'refund' => ['refundedAmount' => '0']]; return $s;
                });
                $r = $this->webhook($this->event($o));
                $this->check('split-' . $mode, $r->get_status() === ($mode === 'complete' ? 200 : 503)
                    && $this->fresh($o)->is_paid() === ($mode === 'complete') && $this->completeCount($o->get_id()) === ($mode === 'complete' ? 1 : 0));
                // A separate order receives NO webhook. SDK 0.28.1 does not
                // classify SETTLED_BY_SPLIT as captured; cron must still verify
                // the canonical complete aggregate, never a leg/partial total.
                $missed = $this->order(); $missedSplit = 'split_missed_' . $missed->get_id();
                $this->payment($missed, ['status' => 'SETTLED_BY_SPLIT', 'split' => ['role' => $mode === 'leg' ? 'LEG' : 'PARENT', 'splitPaymentId' => $missedSplit]],
                    ['capturedAmount' => '0']);
                $this->state(static function (array $s) use ($splitId, $missedSplit, $missed): array {
                    $s['splits'][$missedSplit] = array_merge($s['splits'][$splitId], ['id' => $missedSplit, 'orderId' => (string) $missed->get_order_number()]); return $s;
                });
                $this->reconcile($missed);
                $this->check('missed-webhook-cron-split-' . $mode, $this->callsFor($missed) > 0
                    && $this->fresh($missed)->is_paid() === ($mode === 'complete') && $this->completeCount($missed->get_id()) === ($mode === 'complete' ? 1 : 0),
                    ['authenticatedGetObserved' => $this->callsFor($missed) > 0]);
            }
            $this->createRaces();
            $this->races();
            $this->http($httpOrder);
        } finally {
            $result = ['platform' => ['wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION,
                'plugin' => LUNIXI_VERSION, 'sdk' => \Composer\InstalledVersions::getPrettyVersion('lunixi/php-sdk'),
                'storage' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'CPT',
                'hposDatastoreCache' => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_datastore_cache_enabled()],
                'nativeWordPressWooCommerceMariaDB' => true, 'gatewayTransport' => 'fully intercepted WordPress HTTP; unregistered synthetic merchant only',
                'liveMerchantOrCharge' => false, 'licensedWHMCS' => false,
                'cases' => $this->rows, 'caseCount' => count($this->rows), 'failedCases' => count(array_filter($this->rows, static fn(array $r): bool => !$r['pass']))];
            file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            echo json_encode(['caseCount' => $result['caseCount'], 'failedCases' => $result['failedCases'], 'platform' => $result['platform']]) . "\n";
        }
    }
}
$runner = new LunixiNativeAcceptance();
if (($argv[1] ?? '') === 'worker') { $runner->worker($argv[2], (int) $argv[3], $argv[4]); }
else {
    $output = $argv[1] ?? '';
    if ($output === '' || !is_dir(dirname($output))) { throw new RuntimeException('Provide an existing output directory.'); }
    $runner->run($output);
}
