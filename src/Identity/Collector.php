<?php

declare(strict_types=1);

namespace Lunixi\WP\Identity;

use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * The shared front-end mount for device fingerprinting. Any placement
 * (registration, login, checkout, or a custom/headless payment flow) calls
 * {@see Collector::enqueue()} to load the browser identity SDK + collector and
 * {@see Collector::field()} to drop the hidden traceId input.
 *
 * `productContext` selects the init type the gateway records:
 *   live_session       generic web (sign-up / login)
 *   payment_form       checkout / payment surfaces
 *   hosted_payment_form, kyc, direct_api, manual_review, simulation (gateway enum)
 *
 * A `[lunixi_identity]` shortcode and the global `lunixi_identity_field()` expose
 * the same field to themes / custom flows.
 */
final class Collector
{
    public const FIELD = 'lunixi_identity_trace';

    /** Gateway productContext values (FraudProductContext). */
    public const CONTEXTS = ['live_session', 'payment_form', 'hosted_payment_form', 'kyc', 'direct_api', 'manual_review', 'simulation'];

    private Settings $settings;
    private bool $enqueued = false;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /** True once a publishable key is configured (otherwise the SDK is inert). */
    public function isProvisioned(): bool
    {
        return $this->settings->identityPublishableKey() !== '';
    }

    /**
     * Enqueues the SDK + collector for a given init type. Idempotent per request.
     *
     * @param array{subjectType?:string,subjectId?:string,accountId?:string} $subject
     */
    public function enqueue(string $context = 'live_session', array $subject = []): void
    {
        if (!$this->isProvisioned() || $this->enqueued) {
            return;
        }
        $this->enqueued = true;

        $context = in_array($context, self::CONTEXTS, true) ? $context : 'live_session';

        wp_enqueue_script('lunixi-identity-sdk', $this->settings->portalCdnUrl() . '/lunixi-identity.min.js', [], null, true);
        wp_enqueue_script('lunixi-identity', LUNIXI_URL . 'assets/js/identity.js', ['lunixi-identity-sdk'], LUNIXI_VERSION, true);
        wp_localize_script('lunixi-identity', 'lunixiIdentity', [
            'apiBase' => esc_url_raw($this->settings->baseUrl()),
            'publishableKey' => $this->settings->identityPublishableKey(),
            'fieldId' => self::FIELD,
            'context' => $context,
            'subjectType' => isset($subject['subjectType']) ? (string) $subject['subjectType'] : '',
            'subjectId' => isset($subject['subjectId']) ? (string) $subject['subjectId'] : '',
            'accountId' => isset($subject['accountId']) ? (string) $subject['accountId'] : '',
            'locale' => str_replace('_', '-', get_locale()),
        ]);
    }

    /** The hidden traceId input (echoed inside a form). */
    public function field(): string
    {
        if (!$this->isProvisioned()) {
            return '';
        }
        return '<input type="hidden" name="' . esc_attr(self::FIELD) . '" id="' . esc_attr(self::FIELD) . '" value="" />';
    }

    public function renderField(): void
    {
        echo $this->field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built from esc_attr above.
    }

    /** Reads the submitted traceId from the current request (bounded, sanitised). */
    public static function traceFromRequest(): string
    {
        // Registration/login/checkout POSTs are nonce-protected by WP/WooCommerce;
        // the traceId is opaque and server-verified — never trusted as an identity.
        $raw = isset($_POST[self::FIELD]) ? sanitize_text_field(wp_unslash((string) $_POST[self::FIELD])) : '';
        return strlen($raw) <= 128 ? $raw : '';
    }
}
