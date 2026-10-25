<?php

declare(strict_types=1);

namespace Lunixi\WP\Automation;

use Lunixi\WP\Core\Logger;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Forwards WooCommerce/WordPress events to the Lunixi automation **integration
 * ingress** as the `wordpress` provider:
 *
 *   POST /api/v1/automation/integrations/wordpress/{integrationId}/events
 *
 * No bearer auth — identity is the per-integration HMAC signature. The tenant
 * (merchant) is resolved server-side from (provider=wordpress, integrationId),
 * so the producer NEVER sends a merchantId.
 *
 *   X-Automation-Signature: sha256=<hex HMAC-SHA256(secret, "{timestamp}.{rawBody}")>
 *   X-Automation-Timestamp: <unix seconds>   (±300s freshness window)
 *   X-Automation-Nonce:     <uuid>           (replay defence)
 *
 * Body: { event, id?, occurredAt, data }. We send the platform-NATIVE event name
 * (order.created, …); the server maps it to the platform-agnostic canonical key
 * (commerce.order.created) that flows subscribe to. `id` (externalEventId) makes
 * delivery idempotent — the server de-dupes replays, so retries are safe.
 *
 * Sends are queued during the request and flushed on `shutdown` (after the
 * response) so they never add checkout latency. Transient failures (429 / 5xx /
 * network) are parked in a durable {@see RetryQueue} and re-sent by WP-cron with
 * exponential backoff; terminal results (2xx, 409 replay, 4xx config error) are
 * not retried.
 */
final class AutomationBridge
{
    private const PROVIDER = 'wordpress';

    private Settings $settings;

    /** @var array<int,array{event:string,id:string,occurredAt:string,data:array<string,mixed>}> */
    private array $queue = [];
    private bool $flushHooked = false;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function isConfigured(): bool
    {
        return $this->settings->automationConfigured();
    }

    /**
     * Queues an event for delivery on shutdown. `$id` is the source event id
     * (e.g. WC order id) used for idempotency — strongly recommended.
     *
     * @param array<string,mixed> $data
     */
    public function send(string $event, array $data = [], string $id = ''): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        $this->queue[] = [
            'event' => $event,
            'id' => $id,
            // occurredAt is stamped now (when the event happened), preserved across retries.
            'occurredAt' => gmdate('c'),
            'data' => $data,
        ];
        if (!$this->flushHooked) {
            $this->flushHooked = true;
            add_action('shutdown', [$this, 'flush'], 100);
        }
    }

    /** Delivers all queued events (runs after the response is sent). */
    public function flush(): void
    {
        if ($this->queue === [] || !$this->isConfigured()) {
            return;
        }
        $events = $this->queue;
        $this->queue = [];

        foreach ($events as $e) {
            $this->deliver($this->buildBody($e['event'], $e['data'], $e['id'], $e['occurredAt']));
        }
    }

    /**
     * WP-cron tick: re-attempt parked deliveries that are due. Idempotent thanks
     * to the per-event `id`.
     */
    public function retryFlush(): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        $store = new RetryQueue();
        $rows = $store->all();
        if ($rows === []) {
            return;
        }

        $now = time();
        $next = [];
        foreach ($rows as $row) {
            $body = is_array($row['body'] ?? null) ? $row['body'] : [];
            $attempts = (int) ($row['attempts'] ?? 1);

            if ((int) ($row['nextAt'] ?? 0) > $now) {
                $next[] = $row;            // not due yet — keep as-is
                continue;
            }
            if ($body === []) {
                continue;                 // corrupt entry — drop
            }

            if ($this->attemptSend($body) !== 'retry') {
                continue;                 // ok / terminal — drop
            }

            $attempts++;
            if ($attempts >= RetryQueue::maxAttempts()) {
                Logger::error('automation retry exhausted', ['event' => (string) ($body['event'] ?? ''), 'id' => (string) ($body['id'] ?? ''), 'attempts' => $attempts]);
                continue;                 // give up
            }
            $next[] = ['body' => $body, 'attempts' => $attempts, 'nextAt' => $now + RetryQueue::backoffSeconds($attempts)];
        }

        $store->save($next);
    }

    /**
     * Liveness probe against the integration ingress (admin "check connection").
     *
     * @return array{ok:bool,status:int,message:string}
     */
    public function health(): array
    {
        $integrationId = $this->settings->automationIntegrationId();
        if ($integrationId === '') {
            return ['ok' => false, 'status' => 0, 'message' => __('No integration id configured.', 'lunixi')];
        }
        $response = wp_remote_get($this->endpoint($integrationId, 'health'), ['timeout' => 5, 'redirection' => 0]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'status' => 0, 'message' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        return ['ok' => $code === 200, 'status' => $code, 'message' => $code === 200 ? __('Reachable', 'lunixi') : __('Unexpected status', 'lunixi')];
    }

    /**
     * Canonical event body per the ingress contract.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function buildBody(string $event, array $data, string $id, string $occurredAt): array
    {
        $body = ['event' => $event];
        if ($id !== '') {
            $body['id'] = $id;
        }
        $body['occurredAt'] = $occurredAt;
        $body['data'] = (object) $data; // always a JSON object, even when empty
        return $body;
    }

    /** Sign + POST one body; park on transient failure. */
    private function deliver(array $body): void
    {
        $outcome = $this->attemptSend($body);
        if ($outcome === 'retry') {
            (new RetryQueue())->push($body, 1, time());
        }
    }

    /**
     * Sends one prepared body and classifies the result:
     *   'ok'       2xx accepted / 200 duplicate / 409 replay — done
     *   'terminal' 4xx config/payload error (401/403/404/422) — logged, no retry
     *   'retry'    429 / 5xx / network — caller parks for backoff retry
     *
     * @param array<string,mixed> $body
     */
    private function attemptSend(array $body): string
    {
        $rawBody = (string) wp_json_encode($body);
        $timestamp = (string) time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->settings->automationSecret());

        $response = wp_remote_post($this->endpoint($this->settings->automationIntegrationId(), 'events'), [
            'timeout' => 5,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Automation-Signature' => $signature,
                'X-Automation-Timestamp' => $timestamp,
                'X-Automation-Nonce' => wp_generate_uuid4(),
            ],
            'body' => $rawBody,
        ]);

        $event = (string) ($body['event'] ?? '');

        if (is_wp_error($response)) {
            Logger::warn('automation send network error (will retry)', ['event' => $event, 'error' => $response->get_error_message()]);
            return 'retry';
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 200 || $code === 202 || $code === 409) {
            return 'ok'; // accepted, duplicate, or replay — all benign
        }
        if ($code === 429 || $code >= 500) {
            Logger::warn('automation send transient (will retry)', ['event' => $event, 'status' => $code]);
            return 'retry';
        }

        // 401 signature_failed / 403 revoked / 404 not_registered / 422 validation_failed:
        // a configuration or payload problem — retrying won't help.
        Logger::error('automation send rejected (config error)', [
            'event' => $event,
            'status' => $code,
            'reason' => $this->reasonOf($response),
        ]);
        return 'terminal';
    }

    private function endpoint(string $integrationId, string $tail): string
    {
        return rtrim($this->settings->baseUrl(), '/')
            . '/api/v1/automation/integrations/' . self::PROVIDER . '/' . rawurlencode($integrationId) . '/' . $tail;
    }

    /** @param array<string,mixed>|\WP_Error $response */
    private function reasonOf($response): string
    {
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (is_array($decoded)) {
            return (string) ($decoded['error'] ?? ($decoded['reason'] ?? ''));
        }
        return '';
    }
}
