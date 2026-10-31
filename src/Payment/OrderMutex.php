<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

defined('ABSPATH') || exit;

/** Same native MySQL connection as WooCommerce; shared across PHP workers. */
final class OrderMutex
{
    /** @return mixed */
    public static function synchronized(int $orderId, callable $apply)
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            throw new \RuntimeException('Native order lock unavailable.');
        }
        $database = defined('DB_NAME') ? (string) DB_NAME : '';
        $prefix = (string) ($wpdb->prefix ?? '');
        if ($database === '' || $prefix === '') {
            throw new \RuntimeException('Native order lock namespace unavailable.');
        }
        $blog = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        $name = self::lockName($orderId, $database, $prefix, $blog);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, 5)) !== '1') {
            throw new \RuntimeException('Native order lock unavailable.');
        }
        try {
            return $apply();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private static function lockName(int $orderId, string $database, string $prefix, int $blog): string
    {
        // Named locks belong to the MySQL server, not its selected database.
        // Hash native database + table/site identity; never expose that identity
        // in the lock name and never serialize unrelated shops by order ID.
        return 'lunixi-order-' . substr(hash('sha256', json_encode(
            [$database, $prefix, $blog, $orderId],
            JSON_THROW_ON_ERROR
        )), 0, 51);
    }

    public static function reread(int $id): \WC_Order
    {
        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException('Native order unavailable.');
        }
        if (method_exists($order, 'get_data_store')) {
            $store = $order->get_data_store();
            // HPOS can cache database rows separately from the WC_Order object.
            // Invalidate only this order before the read under our native lock.
            if (method_exists($store, 'has_callable') && $store->has_callable('clear_cached_data')) {
                $store->clear_cached_data([$id]);
            }
            $store->read($order);
        }
        if (method_exists($order, 'read_meta_data')) {
            $order->read_meta_data(true);
        }
        return $order;
    }
}
