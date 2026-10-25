<?php

declare(strict_types=1);

namespace Lunixi\WP\Marketplace;

defined('ABSPATH') || exit;

/**
 * Attributes a WooCommerce product to a vendor (a WP user → Lunixi sub-dealer).
 * At checkout the cart is grouped by vendor and the payment is split per vendor.
 * The field appears on the product's "General" data panel.
 */
final class WooProductVendor
{
    public function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderField']);
        add_action('woocommerce_process_product_meta', [$this, 'save']);
    }

    public function renderField(): void
    {
        global $post;
        $current = $post ? (int) get_post_meta($post->ID, VendorStore::PRODUCT_META_VENDOR, true) : 0;

        $options = ['' => __('— Platform (no vendor) —', 'lunixi')];
        // Only users who are linked to a Lunixi sub-dealer can be vendors.
        $vendors = get_users([
            'meta_key' => VendorStore::USER_META_DEALER,
            'meta_compare' => 'EXISTS',
            'number' => 200,
            'fields' => ['ID', 'user_login'],
        ]);
        foreach ((array) $vendors as $user) {
            $options[(string) $user->ID] = $user->user_login;
        }

        echo '<div class="options_group">';
        woocommerce_wp_select([
            'id' => VendorStore::PRODUCT_META_VENDOR,
            'label' => __('Lunixi vendor', 'lunixi'),
            'description' => __('The vendor (sub-dealer) this product pays out to in a marketplace split. Vendors are WP users linked to a sub-dealer under Lunixi → Marketplace.', 'lunixi'),
            'desc_tip' => true,
            'options' => $options,
            'value' => (string) ($current > 0 ? $current : ''),
        ]);
        echo '</div>';
    }

    /**
     * @param int $postId
     */
    public function save($postId): void
    {
        $product = wc_get_product($postId);
        if (!$product) {
            return;
        }
        $value = isset($_POST[VendorStore::PRODUCT_META_VENDOR]) ? (int) $_POST[VendorStore::PRODUCT_META_VENDOR] : 0;
        if ($value > 0) {
            $product->update_meta_data(VendorStore::PRODUCT_META_VENDOR, $value);
        } else {
            $product->delete_meta_data(VendorStore::PRODUCT_META_VENDOR);
        }
        $product->save();
    }
}
