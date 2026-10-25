<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\WP\Core\SdkFactory;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * KYC module. WP-native (works with or without WooCommerce). Phase 2 wires the
 * core: the verification lifecycle store, the embedded-SDK BFF + `[lunixi_kyc]`
 * shortcode, webhook-driven state sync (incl. rescreen / sanctions / expiry) and
 * the cron reconciler. Gating, admin, headless and KYB land in later phases.
 */
final class Module
{
    private VerificationStore $store;

    public function __construct()
    {
        $this->store = new VerificationStore();
    }

    public function init(SdkFactory $sdk, Settings $settings): void
    {
        // Verified webhook → verification lifecycle (the authority).
        $sync = new KycSync($this->store);
        add_action('lunixi_webhook_event', [$sync, 'handle'], 10, 1);

        // Embedded-SDK BFF + customer verification surface.
        (new KycController())->register();
        (new Shortcode($settings, $this->store))->register();

        // Backstop: converge missed webhooks + expiry sweep.
        (new Reconciler($this->store, $sync))->register();

        // Gating engine: checkout gate + account widget + role sync + content gate.
        (new Gating($settings, $this->store))->register();
        (new GateShortcode($settings))->register();

        // Order-time KYC snapshot (WooCommerce, audit).
        if (class_exists('WooCommerce')) {
            (new OrderSnapshot($this->store))->register();
        }

        // Admin surface (sessions list + per-user verification column).
        if (is_admin()) {
            (new Admin($this->store))->register();
        }
    }

    public function store(): VerificationStore
    {
        return $this->store;
    }
}
