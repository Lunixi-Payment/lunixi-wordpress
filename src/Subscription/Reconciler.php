<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

use Lunixi\Sdk\Subscription\SubscriptionStatus;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Backstop for missed subscription/invoice webhooks (outage beyond the gateway
 * retry window, a DEAD_LETTER delivery, or the invoice.* entitlement not yet
 * provisioned). An hourly cron refreshes every non-terminal mirrored
 * subscription from the gateway and applies status changes — so local state and
 * entitlements converge even if a webhook never arrived.
 */
final class Reconciler
{
    private const HOOK = 'lunixi_subscription_reconcile';
    private const SCHEDULE = 'lunixi_hourly';
    private const BATCH = 50;

    private SubscriptionStore $store;
    private CustomerService $customers;

    public function __construct(SubscriptionStore $store, CustomerService $customers)
    {
        $this->store = $store;
        $this->customers = $customers;
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action(self::HOOK, [$this, 'run']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 600, self::SCHEDULE, self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public function addSchedule(array $schedules): array
    {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = ['interval' => HOUR_IN_SECONDS, 'display' => __('Hourly (Lunixi)', 'lunixi')];
        }
        return $schedules;
    }

    public function run(): void
    {
        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            return;
        }

        $posts = get_posts([
            'post_type' => SubscriptionStore::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => self::BATCH,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => [
                [
                    'key' => SubscriptionStore::META_STATUS,
                    'value' => SubscriptionStatus::TERMINAL_STATES,
                    'compare' => 'NOT IN',
                ],
            ],
        ]);
        if (!is_array($posts)) {
            return;
        }

        foreach ($posts as $postId) {
            $this->reconcileOne((int) $postId);
        }
    }

    private function reconcileOne(int $postId): void
    {
        $subscriptionId = (string) get_post_meta($postId, SubscriptionStore::META_ID, true);
        if ($subscriptionId === '') {
            return;
        }

        try {
            $sub = Plugin::instance()->sdk()->client()->subscriptions()->get($subscriptionId);
        } catch (\Throwable $e) {
            Logger::error('subscription reconcile failed', ['subscription' => $subscriptionId, 'error' => $e->getMessage()]);
            return;
        }

        $oldStatus = (string) get_post_meta($postId, SubscriptionStore::META_STATUS, true);
        $newStatus = $sub->status();

        $customerId = $sub->customerId();
        $userId = $customerId !== '' ? $this->customers->userIdForCustomer($customerId) : null;

        $fields = [
            'title' => $sub->referenceCode() !== '' ? $sub->referenceCode() : $subscriptionId,
            SubscriptionStore::META_CUSTOMER_ID => $customerId,
            SubscriptionStore::META_PLAN_ID => $sub->planId(),
            SubscriptionStore::META_STATUS => $newStatus,
            SubscriptionStore::META_CURRENCY => $sub->currency(),
            SubscriptionStore::META_AMOUNT => (string) $sub->priceAmount(),
            SubscriptionStore::META_NEXT_BILLING => (string) ($sub->nextBillingAt() ?? ''),
        ];
        if ($userId !== null) {
            $fields[SubscriptionStore::META_USER_ID] = $userId;
        }

        $this->store->upsert($subscriptionId, $fields);

        if ($newStatus !== '' && $newStatus !== $oldStatus) {
            do_action('lunixi_subscription_status_changed', $postId, $oldStatus, $newStatus, $subscriptionId);
        }
    }
}
