<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

defined('ABSPATH') || exit;

/**
 * The data the order-pay page needs to mount the Lunixi card form: the checkout
 * SDK URL and the checkout config (one-time token + form settings).
 *
 * The gateway returns both embedded in a bootstrap `<script>`
 * (`checkoutFormContent`). The plugin never prints that markup: it reads the two
 * JSON literals out of it once, validates them, and stores only data. The page
 * then loads the SDK with `wp_enqueue_script()` and passes the config through
 * `wp_add_inline_script()` as re-encoded JSON.
 */
final class CheckoutBootstrap
{
    public const META_SDK_URL = '_lunixi_checkout_sdk_url';
    public const META_CONFIG = '_lunixi_checkout_config';
    public const LEGACY_SCRIPT = '_lunixi_checkout_script';

    /** Mount target the checkout SDK renders the card form into. */
    public const MOUNT_ID = 'lunixi-checkout-form';

    /** The SDK is only ever loaded over HTTPS from this domain or its subdomains. */
    private const SDK_DOMAIN = 'lunixi.com';

    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

    private string $sdkUrl;

    /** Decoded as objects so `{}` stays an object when re-encoded. */
    private object $config;

    private function __construct(string $sdkUrl, object $config)
    {
        $this->sdkUrl = $sdkUrl;
        $this->config = $config;
    }

    /** Reads the SDK URL and config out of the gateway's bootstrap script. */
    public static function fromGatewayScript(string $script): ?self
    {
        if (
            strlen($script) > 262144
            || preg_match_all('/var\s+sdkUrl\s*=\s*("(?:[^"\\\\]|\\\\.)*")\s*;/', $script, $urlMatch) !== 1
            || preg_match_all('/var\s+checkoutConfig\s*=\s*(\{.*\})\s*;\s*$/m', $script, $configMatch) !== 1
        ) {
            return null;
        }

        return self::fromParts(json_decode($urlMatch[1][0]), $configMatch[1][0]);
    }

    /** Reads data without rewriting an existing payment, key, script or metadata. */
    public static function fromOrder(\WC_Order $order): ?self
    {
        // A partial/invalid new record must never fall back to another legacy token.
        if ($order->meta_exists(self::META_SDK_URL) || $order->meta_exists(self::META_CONFIG)) {
            $url = $order->get_meta(self::META_SDK_URL);
            $config = $order->get_meta(self::META_CONFIG);
            return is_string($url) && is_string($config) ? self::fromParts($url, $config) : null;
        }
        $legacy = $order->get_meta(self::LEGACY_SCRIPT);
        return is_string($legacy) && $legacy !== '' ? self::fromGatewayScript($legacy) : null;
    }

    /** Any retained attempt requires resume/reconciliation, never a fresh key. */
    public static function hasOrderData(\WC_Order $order): bool
    {
        foreach ([self::META_SDK_URL, self::META_CONFIG, self::LEGACY_SCRIPT, '_lunixi_active_attempt_id', '_lunixi_payment_order_id', '_lunixi_attempt_idempotency_key', '_lunixi_attempt_count'] as $key) {
            if ($order->meta_exists($key)) {
                return true;
            }
        }
        return false;
    }

    public function store(\WC_Order $order): void
    {
        $order->update_meta_data(self::META_SDK_URL, $this->sdkUrl);
        $order->update_meta_data(self::META_CONFIG, (string) wp_json_encode($this->config, self::JSON_FLAGS));
    }

    public function sdkUrl(): string
    {
        return $this->sdkUrl;
    }

    /** Explicit backend identity fields, when present, must keep the native binding. */
    public function matchesPayment(string $paymentId): bool
    {
        foreach (['paymentId', 'Aut'] as $field) {
            if (
                property_exists($this->config->options, $field)
                && $this->config->options->{$field} !== $paymentId
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Inline script that mounts the form; runs right after the SDK script loads.
     * It removes itself afterwards so the token does not stay in the DOM.
     */
    public function mountScript(): string
    {
        return sprintf(
            '(function (self) {'
            . 'var checkout = window.Lunixi && window.Lunixi.checkout;'
            . 'if (!checkout || typeof checkout.create !== "function") { throw new Error("Lunixi checkout runtime not available"); }'
            . 'checkout.create(%s).mount(%s);'
            . 'self && self.parentNode && self.parentNode.removeChild(self);'
            . '})(document.currentScript);',
            (string) wp_json_encode($this->config, self::JSON_FLAGS),
            (string) wp_json_encode('#' . self::MOUNT_ID, self::JSON_FLAGS)
        );
    }

    /**
     * @param mixed $sdkUrl
     */
    private static function fromParts($sdkUrl, string $configJson): ?self
    {
        if (!is_string($sdkUrl) || !self::isTrustedSdkUrl($sdkUrl)) {
            return null;
        }
        if (strlen($configJson) > 262144) {
            return null;
        }
        $config = json_decode($configJson, false, 64);
        if (
            !is_object($config) || ($config->type ?? null) !== 'payment'
            || ($config->target ?? null) !== self::MOUNT_ID
            || !is_object($config->options ?? null)
            || !is_string($config->options->token ?? null)
            || !preg_match('/^[\x21-\x7e]{1,16384}$/D', $config->options->token)
        ) {
            return null;
        }

        return new self($sdkUrl, $config);
    }

    private static function isTrustedSdkUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $host === self::SDK_DOMAIN || substr($host, -strlen('.' . self::SDK_DOMAIN)) === '.' . self::SDK_DOMAIN;
    }
}
