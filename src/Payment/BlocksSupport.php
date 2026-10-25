<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Cart/Checkout Blocks integration for the Lunixi gateway. Registers the
 * block-checkout payment method so the gateway works with the new (React)
 * WooCommerce checkout as well as the classic one.
 *
 * NOTE (owner QA): the front-end block script (`assets/js/blocks.js`) registers
 * the method with `wc.wcBlocksRegistry.registerPaymentMethod` and mounts the
 * Lunixi JS SDK. That asset is finalised during browser QA.
 */
final class BlocksSupport extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
{
    /** @var string */
    protected $name = Gateway::GATEWAY_ID;

    public function initialize(): void
    {
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        $this->settings = isset($gateways[Gateway::GATEWAY_ID]) ? $gateways[Gateway::GATEWAY_ID]->settings : [];
    }

    public function is_active(): bool
    {
        return ($this->get_setting('enabled') === 'yes') && Plugin::instance()->sdk()->isReady();
    }

    /**
     * @return string[]
     */
    public function get_payment_method_script_handles(): array
    {
        $handle = 'lunixi-blocks';
        $src = LUNIXI_URL . 'assets/js/blocks.js';
        $asset = LUNIXI_PATH . 'assets/js/blocks.asset.php';
        $deps = ['wc-blocks-registry', 'wp-element', 'wp-i18n'];
        $version = LUNIXI_VERSION;
        if (is_readable($asset)) {
            $meta = include $asset;
            $deps = isset($meta['dependencies']) ? (array) $meta['dependencies'] : $deps;
            $version = isset($meta['version']) ? (string) $meta['version'] : $version;
        }

        wp_register_script($handle, esc_url($src), $deps, $version, true);
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'lunixi');
        }

        return [$handle];
    }

    /**
     * @return array<string,mixed>
     */
    public function get_payment_method_data(): array
    {
        return [
            'title' => $this->get_setting('title', __('Credit / Debit Card', 'lunixi')),
            'description' => $this->get_setting('description', ''),
            'supports' => ['products', 'refunds'],
        ];
    }
}
