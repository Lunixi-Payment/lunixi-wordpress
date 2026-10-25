<?php

declare(strict_types=1);

namespace Lunixi\WP\Marketplace;

use Lunixi\WP\Core\SdkFactory;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Marketplace (alt bayi) module. Native vendor management — a WP user maps to a
 * Lunixi sub-dealer, products are attributed to vendors, and at checkout the
 * payment is split per vendor. Wave 2 ships sub-dealer management + product→vendor
 * mapping; split-at-checkout (the new gateway marketplace checkout-form) is Wave 3.
 */
final class Module
{
    private VendorStore $vendors;

    public function __construct()
    {
        $this->vendors = new VendorStore();
    }

    public function init(SdkFactory $sdk, Settings $settings): void
    {
        if (is_admin()) {
            (new Admin($this->vendors))->register();
        }

        // Vendor self-onboarding surface (embed + hosted).
        (new OnboardingShortcode($settings, $this->vendors))->register();

        if (class_exists('WooCommerce')) {
            (new WooProductVendor())->register();
            // Wave 3 — split-at-checkout: supply per-vendor seller legs to the
            // payment Gateway (which routes to the marketplace checkout-form).
            (new CheckoutSplit($this->vendors))->register();
        }
    }

    public function vendors(): VendorStore
    {
        return $this->vendors;
    }
}
