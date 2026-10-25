<?php

declare(strict_types=1);

namespace Lunixi\WP\Identity {

    use Lunixi\WP\Core\SdkFactory;
    use Lunixi\WP\Core\Settings;

    defined('ABSPATH') || exit;

    /**
     * Identity module. Device-graph multi-account protection across sign-up, login
     * and checkout, plus a developer surface (`[lunixi_identity]` shortcode and the
     * global `lunixi_identity_field()`) for custom / headless payment flows. The
     * browser SDK fingerprints the device and the backend resolves the
     * linked-account graph to flag/block "one person, many accounts" abuse.
     * WP-native (works with or without WooCommerce). Inert until a publishable key
     * is set.
     */
    final class Module
    {
        private static ?Collector $collector = null;

        public function init(SdkFactory $sdk, Settings $settings): void
        {
            $collector = new Collector($settings);
            $resolver = new LinkResolver($sdk);
            self::$collector = $collector;

            if ($settings->identityEnabled()) {
                if ($settings->identityPlacementEnabled('registration')) {
                    (new Registration($collector, $resolver, $settings))->register();
                }
                if ($settings->identityPlacementEnabled('login')) {
                    (new Login($collector, $resolver, $settings))->register();
                }
                if ($settings->identityPlacementEnabled('checkout') && class_exists('WooCommerce')) {
                    (new Checkout($collector, $resolver, $settings))->register();
                }

                // Developer surface: collect on a custom/headless flow.
                add_shortcode('lunixi_identity', [$this, 'shortcode']);
            }

            // Admin surface (status + flagged users + Users column) is always present.
            if (is_admin()) {
                (new Admin($settings))->register();
            }
        }

        /**
         * `[lunixi_identity context="payment_form"]` — enqueues the collector and
         * emits the hidden traceId field for a custom form. Also reachable from PHP
         * via {@see lunixi_identity_field()}.
         *
         * @param array<string,mixed>|string $atts
         */
        public function shortcode($atts = []): string
        {
            $atts = shortcode_atts(['context' => 'live_session'], is_array($atts) ? $atts : [], 'lunixi_identity');
            return self::renderField((string) $atts['context']);
        }

        /** Enqueue + return the hidden field for an arbitrary init type. */
        public static function renderField(string $context = 'live_session'): string
        {
            if (self::$collector === null) {
                return '';
            }
            self::$collector->enqueue($context);
            return self::$collector->field();
        }
    }
}

namespace {

    /**
     * Emit the Lunixi device-fingerprint hidden field inside a custom form (e.g. a
     * bespoke payment flow). Enqueues the browser SDK + collector for the given
     * init type and returns the `<input>` markup. No-op unless the Identity module
     * is enabled and a publishable key is configured.
     *
     * @param string $context Gateway productContext (live_session, payment_form, …).
     */
    function lunixi_identity_field(string $context = 'live_session'): string
    {
        return \Lunixi\WP\Identity\Module::renderField($context);
    }
}
