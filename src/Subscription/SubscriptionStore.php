<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

defined('ABSPATH') || exit;

/**
 * Local mirror of Lunixi subscriptions as a private custom post type
 * (`lunixi_subscription`). The authority is always the gateway — this mirror
 * exists so WordPress can list subscriptions, link them to users/orders, drive
 * entitlements and render history without a round-trip on every page load.
 *
 * Works with or without WooCommerce (it is WP-native).
 */
final class SubscriptionStore
{
    public const POST_TYPE = 'lunixi_subscription';

    public const META_ID = '_lunixi_subscription_id';
    public const META_CUSTOMER_ID = '_lunixi_customer_id';
    public const META_PLAN_ID = '_lunixi_plan_id';
    public const META_STATUS = '_lunixi_status';
    public const META_CURRENCY = '_lunixi_currency';
    public const META_AMOUNT = '_lunixi_amount';            // minor units
    public const META_NEXT_BILLING = '_lunixi_next_billing_at';
    public const META_USER_ID = '_lunixi_user_id';
    public const META_LAST_EVENT_TYPE = '_lunixi_last_event_type';
    public const META_LAST_EVENT_AT = '_lunixi_last_event_at';
    public const META_LAST_INVOICE_STATUS = '_lunixi_last_invoice_status';
    public const META_LAST_INVOICE_AMOUNT = '_lunixi_last_invoice_amount';
    public const META_LAST_INVOICE_AT = '_lunixi_last_invoice_at';

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => __('Lunixi Subscriptions', 'lunixi'),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    /** @return \WP_Post|null */
    public function findBySubscriptionId(string $subscriptionId)
    {
        if ($subscriptionId === '') {
            return null;
        }
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => 1,
            'meta_key' => self::META_ID,
            'meta_value' => $subscriptionId,
            'fields' => 'all',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ]);

        return (is_array($posts) && isset($posts[0]) && $posts[0] instanceof \WP_Post) ? $posts[0] : null;
    }

    /**
     * Creates or updates the mirror record for a subscription.
     *
     * @param array<string,scalar|null> $fields Meta keyed by the META_* constants (+ optional 'title').
     * @return int The post id.
     */
    public function upsert(string $subscriptionId, array $fields): int
    {
        $existing = $this->findBySubscriptionId($subscriptionId);
        $title = isset($fields['title']) && (string) $fields['title'] !== ''
            ? (string) $fields['title']
            : $subscriptionId;
        unset($fields['title']);

        if ($existing) {
            $postId = (int) $existing->ID;
            wp_update_post(['ID' => $postId, 'post_title' => $title]);
        } else {
            $postId = (int) wp_insert_post([
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $title,
            ]);
            if ($postId <= 0) {
                return 0;
            }
            update_post_meta($postId, self::META_ID, $subscriptionId);
        }

        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }
            update_post_meta($postId, (string) $key, $value);
        }

        return $postId;
    }
}
