<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Binds a WooCommerce product to a Lunixi subscription plan. A product flagged
 * this way is sold as a recurring subscription billed by the native Lunixi engine
 * (Plan B) — not a one-off WooCommerce charge. The merchant picks the plan on the
 * product's "General" data panel.
 */
final class WooProduct
{
    public const PLAN_META = '_lunixi_subscription_plan_id';

    public function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderField']);
        add_action('woocommerce_process_product_meta', [$this, 'save']);
    }

    public function renderField(): void
    {
        $options = ['' => __('— Not a Lunixi subscription —', 'lunixi')];
        foreach ($this->planMap() as $id => $name) {
            $options[$id] = $name;
        }

        echo '<div class="options_group">';
        woocommerce_wp_select([
            'id' => self::PLAN_META,
            'label' => __('Lunixi subscription plan', 'lunixi'),
            'description' => __('Sell this product as a recurring Lunixi subscription on the selected plan. Billing is handled by Lunixi.', 'lunixi'),
            'desc_tip' => true,
            'options' => $options,
        ]);
        echo '</div>';
    }

    /**
     * @param int $postId
     */
    public function save($postId): void
    {
        // Saved within WooCommerce's own nonce-verified product save handler.
        $value = isset($_POST[self::PLAN_META]) ? sanitize_text_field(wp_unslash((string) $_POST[self::PLAN_META])) : '';
        $product = wc_get_product($postId);
        if (!$product) {
            return;
        }
        if ($value !== '') {
            $product->update_meta_data(self::PLAN_META, $value);
        } else {
            $product->delete_meta_data(self::PLAN_META);
        }
        $product->save();
    }

    /** @param \WC_Product $product */
    public static function isSubscriptionProduct($product): bool
    {
        return $product instanceof \WC_Product && (string) $product->get_meta(self::PLAN_META) !== '';
    }

    /** @param \WC_Product $product */
    public static function planIdForProduct($product): string
    {
        return $product instanceof \WC_Product ? (string) $product->get_meta(self::PLAN_META) : '';
    }

    /** @return array<string,string> planId → display name (from the gateway). */
    private function planMap(): array
    {
        $sdk = Plugin::instance()->sdk();
        if (!$sdk->isReady()) {
            return [];
        }
        try {
            $list = $sdk->client()->subscriptions()->plans()->list(['pageSize' => 100]);
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($list->items() as $plan) {
            $label = $plan->name() !== '' ? $plan->name() : $plan->code();
            if ($plan->currency() !== '') {
                $label .= ' — ' . \Lunixi\WP\Core\Money::format((string) $plan->price(), $plan->currency());
            }
            $map[$plan->id()] = $label;
        }
        return $map;
    }
}
