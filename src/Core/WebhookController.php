<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

use Lunixi\Sdk\Exception\WebhookVerificationException;
use Lunixi\Sdk\Webhook\WebhookVerifier;

defined('ABSPATH') || exit;

/**
 * Receives Lunixi webhooks at `POST /wp-json/lunixi/v1/webhook`, verifies the
 * signature with the SDK (fail-closed), de-duplicates by event id, then fans the
 * verified event out to modules via the `lunixi_webhook_event` action.
 *
 * No WP auth on the route — the HMAC signature IS the authentication.
 */
final class WebhookController
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('lunixi/v1', '/webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle($request)
    {
        $secret = $this->settings->webhookSecret();
        if ($secret === '') {
            return new \WP_REST_Response(['error' => 'not_configured'], 503);
        }

        $body = (string) $request->get_body();
        $headers = self::flattenHeaders($request->get_headers());

        try {
            $event = (new WebhookVerifier())->verify($body, $headers, $secret);
        } catch (WebhookVerificationException $e) {
            return new \WP_REST_Response(['error' => 'invalid_signature'], 400);
        }

        // Delivery is at-least-once with a stable event id across retries
        // (gateway retries any non-2xx: 12 attempts over ~52h — 10s→24h backoff
        // ±20% jitter — then DEAD_LETTER; a dashboard replay resets the budget).
        // The done-marker is set ONLY after
        // successful processing, so a mid-processing failure/crash is retried and
        // genuinely re-applied instead of being skipped as a "duplicate" — which
        // would silently lose the result.
        $doneKey = 'lunixi_wh_done_' . md5($event->id());
        if (get_transient($doneKey)) {
            return new \WP_REST_Response(['status' => 'duplicate'], 200);
        }

        /**
         * Verified, authentic webhook. Modules (payment, …) hook this to update
         * their records. The event is the source of truth for order state.
         * Handlers MUST be idempotent (re-delivery is expected).
         *
         * @param \Lunixi\Sdk\Webhook\WebhookEvent $event
         */
        try {
            do_action('lunixi_webhook_event', $event);
        } catch (\Throwable $e) {
            Logger::error('webhook processing failed', ['event' => $event->id(), 'type' => $event->type(), 'error' => $e->getMessage()]);
            // 5xx → gateway retries the SAME event id; not marked done, so we re-apply.
            return new \WP_REST_Response(['error' => 'processing_failed'], 500);
        }

        // Processed successfully — record the marker so future retries short-circuit.
        set_transient($doneKey, 1, WEEK_IN_SECONDS);

        return new \WP_REST_Response(['status' => 'ok'], 200);
    }

    /**
     * WP gives headers as name => [values]; the verifier wants name => value.
     *
     * @param array<string,array<int,string>|string> $headers
     * @return array<string,string>
     */
    private static function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[(string) $name] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        return $out;
    }
}
