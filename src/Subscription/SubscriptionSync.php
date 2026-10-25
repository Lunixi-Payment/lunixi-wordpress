<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

use Lunixi\Sdk\Webhook\WebhookEvent;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Applies verified subscription/invoice webhooks to the local mirror and fires
 * WordPress actions other code can hook to grant/revoke access, send mail, etc.
 *
 * The gateway is authoritative: on each subscription.* event we refresh the full
 * record via GET /subscriptions/{id} (the webhook body is intentionally minimal).
 * Idempotent — the controller de-dupes by event id; re-application is harmless.
 *
 * Action hooks fired:
 *   do_action('lunixi_subscription_synced', $postId, $subscriptionId, $event)
 *   do_action('lunixi_subscription_status_changed', $postId, $old, $new, $subscriptionId)
 *   do_action('lunixi_subscription_renewed', $postId, $subscriptionId, $data)
 *   do_action('lunixi_subscription_payment_failed', $postId, $subscriptionId, $data)
 */
final class SubscriptionSync
{
    private SubscriptionStore $store;
    private CustomerService $customers;

    public function __construct(SubscriptionStore $store, CustomerService $customers)
    {
        $this->store = $store;
        $this->customers = $customers;
    }

    public function handle(WebhookEvent $event): void
    {
        $isSubscription = $event->matches('subscription.*');
        $isInvoice = $event->matches('invoice.*');
        if (!$isSubscription && !$isInvoice) {
            return;
        }

        $data = $event->data();
        $subscriptionId = isset($data['subscriptionId']) ? (string) $data['subscriptionId'] : '';
        if ($subscriptionId === '') {
            return;
        }

        if ($isInvoice) {
            $this->handleInvoice($event, $subscriptionId, $data);
            return;
        }
        $this->syncSubscription($event, $subscriptionId, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function syncSubscription(WebhookEvent $event, string $subscriptionId, array $data): void
    {
        $existing = $this->store->findBySubscriptionId($subscriptionId);
        $oldStatus = $existing ? (string) get_post_meta((int) $existing->ID, SubscriptionStore::META_STATUS, true) : '';

        $snapshot = $this->refresh($subscriptionId);

        $customerId = $snapshot['customerId'] ?? (isset($data['customerId']) ? (string) $data['customerId'] : '');
        $planId = $snapshot['planId'] ?? (isset($data['planId']) ? (string) $data['planId'] : '');
        $status = $snapshot['status'] ?? self::statusFromEvent($event->type(), $data);
        $userId = $customerId !== '' ? $this->customers->userIdForCustomer($customerId) : null;

        // Always-safe fields. The snapshot-derived ones are only written when
        // present — when the authoritative refresh() fails it returns [], and we
        // must NOT overwrite previously-correct currency/amount/plan/etc. with
        // empty strings (upsert skips null, not '').
        $fields = [
            'title' => $snapshot['referenceCode'] ?? $subscriptionId,
            SubscriptionStore::META_LAST_EVENT_TYPE => $event->type(),
            SubscriptionStore::META_LAST_EVENT_AT => $event->timestamp(),
        ];
        $nextBilling = $snapshot['nextBillingAt'] ?? (isset($data['nextBillingAt']) ? (string) $data['nextBillingAt'] : '');
        $optional = [
            SubscriptionStore::META_CUSTOMER_ID => $customerId,
            SubscriptionStore::META_PLAN_ID => $planId,
            SubscriptionStore::META_STATUS => $status,
            SubscriptionStore::META_CURRENCY => $snapshot['currency'] ?? '',
            SubscriptionStore::META_AMOUNT => $snapshot['amount'] ?? '',
            SubscriptionStore::META_NEXT_BILLING => $nextBilling,
        ];
        foreach ($optional as $key => $value) {
            if ((string) $value !== '') {
                $fields[$key] = $value;
            }
        }
        if ($userId !== null) {
            $fields[SubscriptionStore::META_USER_ID] = $userId;
        }

        $postId = $this->store->upsert($subscriptionId, $fields);
        if ($postId <= 0) {
            return;
        }

        do_action('lunixi_subscription_synced', $postId, $subscriptionId, $event);
        if ($status !== '' && $status !== $oldStatus) {
            do_action('lunixi_subscription_status_changed', $postId, $oldStatus, $status, $subscriptionId);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function handleInvoice(WebhookEvent $event, string $subscriptionId, array $data): void
    {
        // Ensure the subscription mirror exists so the invoice has a home.
        if (!$this->store->findBySubscriptionId($subscriptionId)) {
            $this->syncSubscription($event, $subscriptionId, $data);
        }
        $post = $this->store->findBySubscriptionId($subscriptionId);
        if (!$post) {
            return;
        }
        $postId = (int) $post->ID;

        $invoiceStatus = self::invoiceStatusFromEvent($event->type());
        $amount = isset($data['totalAmount']) ? (string) $data['totalAmount'] : '';

        $this->store->upsert($subscriptionId, [
            SubscriptionStore::META_LAST_INVOICE_STATUS => $invoiceStatus,
            SubscriptionStore::META_LAST_INVOICE_AMOUNT => $amount,
            SubscriptionStore::META_LAST_INVOICE_AT => $event->timestamp(),
            SubscriptionStore::META_LAST_EVENT_TYPE => $event->type(),
            SubscriptionStore::META_LAST_EVENT_AT => $event->timestamp(),
        ]);

        // The mirror write above is idempotent (overwrite), but the renewal /
        // payment-failed hooks have side effects (extend access, send receipt), so
        // guard them per event id — webhook delivery is at-least-once and a retry
        // re-runs every handler.
        $guard = 'lunixi_sub_inv_' . md5($event->id());
        if (!get_transient($guard)) {
            set_transient($guard, 1, WEEK_IN_SECONDS);
            if ($event->type() === 'invoice.paid') {
                do_action('lunixi_subscription_renewed', $postId, $subscriptionId, $data);
            } elseif ($event->type() === 'invoice.payment_failed') {
                do_action('lunixi_subscription_payment_failed', $postId, $subscriptionId, $data);
            }
        }
        do_action('lunixi_subscription_synced', $postId, $subscriptionId, $event);
    }

    /**
     * Best-effort authoritative refresh from the gateway.
     *
     * @return array<string,string> Snapshot fields (empty array on failure).
     */
    private function refresh(string $subscriptionId): array
    {
        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            return [];
        }
        try {
            $sub = $plugin->sdk()->client()->subscriptions()->get($subscriptionId);
            return [
                'referenceCode' => $sub->referenceCode() !== '' ? $sub->referenceCode() : $subscriptionId,
                'customerId' => $sub->customerId(),
                'planId' => $sub->planId(),
                'status' => $sub->status(),
                'currency' => $sub->currency(),
                'amount' => (string) $sub->priceAmount(),
                'nextBillingAt' => (string) ($sub->nextBillingAt() ?? ''),
            ];
        } catch (\Throwable $e) {
            Logger::error('subscription refresh failed', ['subscription' => $subscriptionId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function statusFromEvent(string $type, array $data): string
    {
        switch ($type) {
            case 'subscription.created':
                return isset($data['status']) ? (string) $data['status'] : 'ACTIVE';
            case 'subscription.activated':
            case 'subscription.resumed':
                return 'ACTIVE';
            case 'subscription.paused':
                return 'PAUSED';
            case 'subscription.canceled':
                return 'CANCELED';
            default:
                return isset($data['status']) ? (string) $data['status'] : '';
        }
    }

    private static function invoiceStatusFromEvent(string $type): string
    {
        switch ($type) {
            case 'invoice.paid':
                return 'PAID';
            case 'invoice.payment_failed':
                return 'PAYMENT_FAILED';
            case 'invoice.voided':
                return 'VOID';
            case 'invoice.created':
                return 'OPEN';
            default:
                return '';
        }
    }
}
