<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\WP\Core\SdkFactory;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Payment module. Active only when WooCommerce is present: registers the Lunixi
 * WC payment gateway (classic + Blocks, HPOS-safe) and binds verified webhooks
 * to order state. On a non-WooCommerce site this module is a no-op (other
 * modules — kyc/identity/… — will provide standalone surfaces).
 */
final class Module
{
    public static function isWooActive(): bool
    {
        return class_exists('WooCommerce');
    }

    public function init(SdkFactory $sdk, Settings $settings): void
    {
        if (!self::isWooActive()) {
            return;
        }

        // Register the classic gateway. WooCommerce instantiates Gateway itself;
        // it pulls shared services from the plugin singleton.
        add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
            $gateways[] = Gateway::class;
            return $gateways;
        });

        // Cart/Checkout Blocks integration.
        add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry): void {
            if (class_exists(BlocksSupport::class)) {
                $registry->register(new BlocksSupport());
            }
        });

        // Webhook → order state (the webhook is the source of truth).
        $orderSync = new OrderSync();
        add_action('lunixi_webhook_event', [$orderSync, 'handle'], 10, 1);

        // Merchant-driven capture/void wired to the order workflow (authorize-mode).
        (new OrderActions())->register();

        // Reconciliation backstop: settles orders whose webhook never arrived
        // (outage beyond the gateway retry window, or a DEAD_LETTER delivery).
        (new Reconciler())->register();

        // Order-edit "Lunixi payment" summary panel (HPOS + legacy).
        if (is_admin()) {
            (new OrderMetaBox())->register();
        }
    }
}
