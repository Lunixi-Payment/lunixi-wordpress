<?php

declare(strict_types=1);

namespace Lunixi\WP\Marketplace;

use Lunixi\Sdk\Marketplace\MarketplaceSeller;
use Lunixi\WP\Core\Money;
use Lunixi\Sdk\Commerce\Money as ExactMoney;

defined('ABSPATH') || exit;

/**
 * Builds the per-vendor split for a WooCommerce order at checkout (Wave 3). Groups
 * the order's line items by their vendor's Lunixi sub-dealer, sums each vendor's
 * gross (line total + tax) into a seller leg, and hands them to the payment
 * Gateway via the `lunixi_payment_marketplace_sellers` filter. Items with no
 * vendor stay with the platform (not assigned to any seller — the gateway allows
 * sum(sellers) < total). The gateway computes commission/hold/net per leg.
 */
final class CheckoutSplit
{
    private VendorStore $vendors;

    public function __construct(VendorStore $vendors)
    {
        $this->vendors = $vendors;
    }

    public function register(): void
    {
        add_filter('lunixi_payment_marketplace_sellers', [$this, 'sellersForOrder'], 10, 2);
    }

    /**
     * @param array<int,mixed> $sellers
     * @param \WC_Order        $order
     * @return MarketplaceSeller[]
     */
    public function sellersForOrder($sellers, $order): array
    {
        if (!$order instanceof \WC_Order) {
            return is_array($sellers) ? $sellers : [];
        }
        $currency = (string) $order->get_currency();

        // dealerId => canonical minor-unit string
        $byDealer = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $dealerId = $this->vendors->dealerIdForProduct($product);
            if ($dealerId === '') {
                continue; // platform-owned line
            }
            $gross = ExactMoney::add(Money::toMinor($item->get_total(), $currency), Money::toMinor($item->get_total_tax(), $currency));
            $byDealer[$dealerId] = ExactMoney::add($byDealer[$dealerId] ?? '0', $gross);
        }

        if ($byDealer === []) {
            return is_array($sellers) ? $sellers : []; // not a marketplace order
        }

        // Per-dealer minor amounts.
        $amounts = [];
        foreach ($byDealer as $dealerId => $gross) {
            $minor = $gross;
            if ($minor !== '0') {
                $amounts[(string) $dealerId] = $minor;
            }
        }

        // Order-level discounts (cart coupons) reduce the order total but not the
        // per-line totals, so the legs can exceed what the buyer actually pays.
        // The gateway allows sum(sellers) <= total but NOT > total (ALL_OR_NOTHING
        // would reject, or vendors get over-paid). Scale the legs down to fit.
        $total = Money::toMinor($order->get_total(), $currency);
        $sum = '0';
        foreach ($amounts as $amount) { $sum = ExactMoney::add($sum, $amount); }
        if ($total !== '0' && ExactMoney::compare($sum, $total) > 0) {
            foreach ($amounts as $dealerId => $minor) {
                $amounts[$dealerId] = ExactMoney::prorate($minor, $total, $sum);
            }
        }

        $legs = [];
        foreach ($amounts as $dealerId => $minor) {
            if ($minor !== '0') {
                $legs[] = new MarketplaceSeller($dealerId, $minor);
            }
        }
        return $legs;
    }
}
