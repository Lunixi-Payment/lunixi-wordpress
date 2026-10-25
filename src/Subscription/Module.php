<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

use Lunixi\WP\Core\SdkFactory;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Subscription module. Unlike payment, this is WP-native — it works with or
 * without WooCommerce: a private mirror post type tracks Lunixi subscriptions,
 * verified webhooks keep it current, and a cron backstop reconciles missed
 * events. The customer-facing subscribe/manage surface (portal SDK embed) and
 * the admin screens build on this foundation.
 */
final class Module
{
    private SubscriptionStore $store;
    private CustomerService $customers;

    public function __construct()
    {
        $this->store = new SubscriptionStore();
        $this->customers = new CustomerService();
    }

    public function init(SdkFactory $sdk, Settings $settings): void
    {
        // Local mirror post type.
        add_action('init', [$this->store, 'registerPostType']);

        // Verified webhook → mirror + WP action hooks (the gateway is authority).
        $sync = new SubscriptionSync($this->store, $this->customers);
        add_action('lunixi_webhook_event', [$sync, 'handle'], 10, 1);

        // Backstop for missed subscription/invoice webhooks.
        (new Reconciler($this->store, $this->customers))->register();

        // Customer-facing portal: BFF (embed session + hosted redirect) + shortcode.
        (new PortalController($settings, $this->customers))->register();
        (new Shortcode($settings))->register();

        // Admin management surface (merchant self-service from WP-admin).
        if (is_admin()) {
            (new Admin($this->store))->register();
        }

        // WooCommerce catalog → native subscription (Plan B), only when WC is active.
        if (class_exists('WooCommerce')) {
            (new WooProduct())->register();
            (new WooSubscribe())->register();
        }
    }

    public function store(): SubscriptionStore
    {
        return $this->store;
    }

    public function customers(): CustomerService
    {
        return $this->customers;
    }
}
