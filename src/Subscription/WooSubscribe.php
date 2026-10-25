<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

defined('ABSPATH') || exit;

/**
 * Front-end funnel for WooCommerce subscription products (Plan B). Such a product
 * is not a one-off purchase — instead of "Add to cart" it shows a "Subscribe"
 * button that takes the customer to the plan-scoped Lunixi portal, where the card
 * is collected (1-TL verification + 3DS) and the native subscription is started.
 * The WooCommerce catalog is the entry point; Lunixi owns the billing.
 */
final class WooSubscribe
{
    public function register(): void
    {
        // A subscription product is not bought through the normal one-off cart.
        add_filter('woocommerce_is_purchasable', [$this, 'notPurchasable'], 10, 2);

        // Replace the shop-loop add-to-cart with a Subscribe button.
        add_filter('woocommerce_loop_add_to_cart_link', [$this, 'loopButton'], 10, 2);

        // Single product page: render the Subscribe button (the add-to-cart form
        // is hidden because the product is not purchasable).
        add_action('woocommerce_single_product_summary', [$this, 'singleButton'], 31);
    }

    /**
     * @param bool        $purchasable
     * @param \WC_Product $product
     */
    public function notPurchasable($purchasable, $product)
    {
        return WooProduct::isSubscriptionProduct($product) ? false : $purchasable;
    }

    /**
     * @param string      $html
     * @param \WC_Product $product
     * @return string
     */
    public function loopButton($html, $product)
    {
        if (!WooProduct::isSubscriptionProduct($product)) {
            return $html;
        }
        return $this->button($product, false);
    }

    public function singleButton(): void
    {
        global $product;
        if (!WooProduct::isSubscriptionProduct($product)) {
            return;
        }
        echo $this->button($product, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param \WC_Product $product
     */
    private function button($product, bool $single): string
    {
        $planId = WooProduct::planIdForProduct($product);
        if ($planId === '') {
            return '';
        }
        $url = PortalController::redirectUrl($planId);
        $class = $single ? 'single_add_to_cart_button button alt lunixi-subscribe-button' : 'button lunixi-subscribe-button';

        return '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">'
            . esc_html__('Subscribe', 'lunixi') . '</a>';
    }
}
