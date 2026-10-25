<?php

declare(strict_types=1);

namespace Lunixi\WP\Marketplace;

defined('ABSPATH') || exit;

/**
 * Native vendor ↔ Lunixi sub-dealer mapping (owner decision: native, no Dokan/WC
 * Vendors dependency). A WooCommerce product is attributed to a vendor (a WP
 * user); that vendor maps to a Lunixi sub-dealer who receives the split. Used at
 * checkout to group the cart by vendor and build the seller legs.
 */
final class VendorStore
{
    /** User meta: the Lunixi sub-dealer id linked to a WP (vendor) user. */
    public const USER_META_DEALER = '_lunixi_dealer_id';

    /** Product meta: the WP user id that is the vendor for this product. */
    public const PRODUCT_META_VENDOR = '_lunixi_vendor_user';

    public function dealerIdForUser(int $userId): string
    {
        return (string) get_user_meta($userId, self::USER_META_DEALER, true);
    }

    public function linkUserDealer(int $userId, string $dealerId): void
    {
        if ($userId > 0 && $dealerId !== '') {
            update_user_meta($userId, self::USER_META_DEALER, $dealerId);
        }
    }

    /** @return int|null WP user linked to a sub-dealer, or null. */
    public function userForDealer(string $dealerId): ?int
    {
        if ($dealerId === '') {
            return null;
        }
        $users = get_users([
            'meta_key' => self::USER_META_DEALER,
            'meta_value' => $dealerId,
            'number' => 1,
            'fields' => 'ID',
        ]);
        return (is_array($users) && isset($users[0])) ? (int) $users[0] : null;
    }

    /** @param \WC_Product $product */
    public static function vendorUserForProduct($product): int
    {
        if (!$product instanceof \WC_Product) {
            return 0;
        }
        // A variation inherits its parent's vendor.
        $id = (int) $product->get_meta(self::PRODUCT_META_VENDOR);
        if ($id <= 0 && $product->get_parent_id() > 0) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $id = (int) $parent->get_meta(self::PRODUCT_META_VENDOR);
            }
        }
        return $id;
    }

    /** @param \WC_Product $product Resolve the sub-dealer id a product pays out to. */
    public function dealerIdForProduct($product): string
    {
        $vendorUser = self::vendorUserForProduct($product);
        return $vendorUser > 0 ? $this->dealerIdForUser($vendorUser) : '';
    }
}
