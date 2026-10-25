<?php

declare(strict_types=1);

namespace Lunixi\WP\Fraud;

use Lunixi\WP\Core\SdkFactory;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Fraud module. Fraud runs automatically inside Lunixi payment authorization, so
 * this module is mostly an insight + control surface: it records the decision on
 * WooCommerce orders, and gives the merchant an admin view of recent decisions
 * plus allow/deny list management. WP-native admin; order insights only when
 * WooCommerce is active.
 */
final class Module
{
    public function init(SdkFactory $sdk, Settings $settings): void
    {
        // Admin surface (recent decisions + deny lists).
        if (is_admin()) {
            (new Admin())->register();
        }

        // Order-level fraud insight (WooCommerce only).
        if (class_exists('WooCommerce')) {
            (new OrderInsights())->register();
        }
    }
}
