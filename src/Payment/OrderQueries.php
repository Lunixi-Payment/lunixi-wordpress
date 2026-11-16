<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

defined('ABSPATH') || exit;

/** Native HPOS and legacy CPT queries have different supported meta interfaces. */
final class OrderQueries
{
    private static function cpt(): bool
    {
        return function_exists('get_posts') && (!class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            || !\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled());
    }

    /** @return \WC_Order|null */
    public static function byPayment(string $paymentId)
    {
        if (self::cpt()) {
            $ids = get_posts(['post_type' => 'shop_order', 'post_status' => 'any', 'numberposts' => 2,
                'fields' => 'ids', 'meta_key' => '_lunixi_payment_order_id', 'meta_value' => $paymentId]);
            $orders = array_map('wc_get_order', $ids);
        } else {
            $orders = wc_get_orders(['limit' => 2, 'status' => 'any',
                'meta_query' => [['key' => '_lunixi_payment_order_id', 'value' => $paymentId]]]);
        }
        if (count($orders) > 1) {
            throw new \RuntimeException('Ambiguous native payment binding.');
        }
        return isset($orders[0]) && $orders[0] instanceof \WC_Order ? $orders[0] : null;
    }

    /**
     * A linked payment carries the merchant order number sent at checkout, not a
     * payment id this store saved. Only an order paid with this gateway that has
     * a Lunixi payment can match.
     *
     * @return \WC_Order|null
     */
    public static function byOrderNumber(string $orderNumber)
    {
        if ($orderNumber === '' || strlen($orderNumber) > 191) {
            return null;
        }
        // Core's number → id mapping; sequential-numbering plugins hook it.
        $id = (int) apply_filters('woocommerce_shortcode_order_tracking_order_id', $orderNumber);
        $order = $id > 0 ? wc_get_order($id) : null;
        if (
            !$order instanceof \WC_Order || (string) $order->get_order_number() !== $orderNumber
            || $order->get_payment_method() !== Gateway::GATEWAY_ID || (string) $order->get_meta('_lunixi_payment_order_id') === ''
        ) {
            return null;
        }
        return $order;
    }

    /** @return array<int,\WC_Order> Orders held for merchant review are not reconciled. */
    public static function stale(int $before, int $limit): array
    {
        if (self::cpt()) {
            $ids = get_posts(['post_type' => 'shop_order', 'post_status' => ['wc-pending', 'wc-on-hold'],
                'numberposts' => $limit, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'ASC',
                'date_query' => [['before' => gmdate('Y-m-d H:i:s', $before), 'column' => 'post_date_gmt']],
                'meta_query' => [['key' => '_payment_method', 'value' => Gateway::GATEWAY_ID],
                    ['key' => '_lunixi_payment_order_id', 'compare' => 'EXISTS'],
                    ['key' => PaymentReview::REASON_META, 'compare' => 'NOT EXISTS']]]);
            return array_values(array_filter(array_map('wc_get_order', $ids), static fn($order): bool => $order instanceof \WC_Order));
        }
        $orders = wc_get_orders(['limit' => $limit, 'type' => 'shop_order', 'status' => ['pending', 'on-hold'],
            'payment_method' => Gateway::GATEWAY_ID, 'date_created' => '<' . $before,
            'orderby' => 'date', 'order' => 'ASC', 'meta_query' => [['key' => '_lunixi_payment_order_id', 'compare' => 'EXISTS'],
                ['key' => PaymentReview::REASON_META, 'compare' => 'NOT EXISTS']]]);
        return is_array($orders) ? $orders : [];
    }
}
