<?php

declare(strict_types=1);

namespace Lunixi\WP\Sanction;

use Lunixi\WP\Core\SdkFactory;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Sanction module — watchlist screening as an operational surface: an on-demand
 * name screen, the merchant deny list, and ongoing-monitoring alerts. Admin-only;
 * screening runs server-side through the SDK.
 */
final class Module
{
    public function init(SdkFactory $sdk, Settings $settings): void
    {
        if (is_admin()) {
            (new Admin())->register();
        }
    }
}
