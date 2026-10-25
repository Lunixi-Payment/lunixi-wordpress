<?php

declare(strict_types=1);

namespace Lunixi\WP;

use Lunixi\WP\Core\AdminMenu;
use Lunixi\WP\Core\SdkFactory;
use Lunixi\WP\Core\Settings;
use Lunixi\WP\Core\WebhookController;
use Lunixi\WP\Automation\Module as AutomationModule;
use Lunixi\WP\Fraud\Module as FraudModule;
use Lunixi\WP\Identity\Module as IdentityModule;
use Lunixi\WP\Kyc\Module as KycModule;
use Lunixi\WP\Marketplace\Module as MarketplaceModule;
use Lunixi\WP\Payment\Module as PaymentModule;
use Lunixi\WP\Sanction\Module as SanctionModule;
use Lunixi\WP\Subscription\Module as SubscriptionModule;

defined('ABSPATH') || exit;

/**
 * Plugin bootstrap. Wires shared services (settings, SDK factory, webhook
 * router) and boots the per-service modules. Payment is the first module;
 * fraud / subscription / identity / sanction / kyc plug in here later, each a
 * thin adapter over the shared Lunixi PHP SDK.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private Settings $settings;
    private SdkFactory $sdk;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->settings = new Settings();
        $this->sdk = new SdkFactory($this->settings);
    }

    public function boot(): void
    {
        $this->settings->registerAdmin();
        (new AdminMenu($this->settings, $this->sdk))->register();
        (new WebhookController($this->settings))->register();

        // Payment module (no-op unless WooCommerce is active).
        (new PaymentModule())->init($this->sdk, $this->settings);

        // Subscription module (WP-native; works with or without WooCommerce).
        (new SubscriptionModule())->init($this->sdk, $this->settings);

        // Fraud module (insight + control surface; payment runs fraud automatically).
        (new FraudModule())->init($this->sdk, $this->settings);

        // KYC module (WP-native; embedded verification + lifecycle).
        (new KycModule())->init($this->sdk, $this->settings);

        // Sanction module (watchlist screening; operational surface).
        (new SanctionModule())->init($this->sdk, $this->settings);

        // Marketplace module (alt bayi split payments; native vendor mgmt).
        (new MarketplaceModule())->init($this->sdk, $this->settings);

        // Automation module (WooCommerce → Lunixi event bridge; inert unless enabled).
        (new AutomationModule())->init($this->sdk, $this->settings);

        // Identity module (device-graph multi-account protection at sign-up).
        (new IdentityModule())->init($this->sdk, $this->settings);

        /**
         * Extension point for future service modules (fraud, subscription, …).
         * Each receives the shared SDK factory + settings.
         */
        do_action('lunixi_register_modules', $this->sdk, $this->settings);
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function sdk(): SdkFactory
    {
        return $this->sdk;
    }

    public static function onActivate(): void
    {
        // The webhook REST route is registered on rest_api_init; flush so it resolves.
        flush_rewrite_rules();
    }

    public static function onDeactivate(): void
    {
        \Lunixi\WP\Payment\Reconciler::unschedule();
        \Lunixi\WP\Subscription\Reconciler::unschedule();
        \Lunixi\WP\Kyc\Reconciler::unschedule();
        \Lunixi\WP\Automation\RetryQueue::unschedule();
        flush_rewrite_rules();
    }
}
