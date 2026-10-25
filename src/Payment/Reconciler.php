<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\Sdk\Payment\PaymentStatus;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Reconciliation backstop for missed webhooks. Verified webhooks are the primary,
 * real-time source of order state, and the gateway retries delivery 12 times over
 * ~52h (10s→24h backoff, ±20% jitter). But if the store is unreachable beyond that
 * retry window — or a delivery is exhausted to DEAD_LETTER and never replayed — an
 * order could sit "pending" forever. This cron closes that hole the way Stripe/iyzico do: it
 * polls the authoritative payment record (GET /payments/{id}) for orders stuck in
 * a non-terminal state and settles them. Idempotent and safe to run repeatedly.
 */
final class Reconciler
{
    private const HOOK = 'lunixi_payment_reconcile';
    private const SCHEDULE = 'lunixi_fifteen_minutes';

    /** Only touch orders older than this — gives the live webhook time to win. */
    private const STALE_MINUTES = 20;
    private const BATCH = 20;

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action(self::HOOK, [$this, 'run']);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, self::SCHEDULE, self::HOOK);
        }
    }

    /** Called from the plugin deactivation hook. */
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
            $schedules[self::SCHEDULE] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => __('Every 15 minutes (Lunixi)', 'lunixi'),
            ];
        }
        return $schedules;
    }

    public function run(): void
    {
        $plugin = Plugin::instance();
        if (!class_exists('WooCommerce') || !$plugin->sdk()->isReady()) {
            return;
        }

        $orders = wc_get_orders([
            'limit' => self::BATCH,
            'type' => 'shop_order',
            'status' => ['pending', 'on-hold'],
            'payment_method' => Gateway::GATEWAY_ID,
            'date_created' => '<' . (time() - self::STALE_MINUTES * MINUTE_IN_SECONDS),
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => [
                ['key' => '_lunixi_payment_order_id', 'compare' => 'EXISTS'],
            ],
        ]);

        if (!is_array($orders)) {
            return;
        }
        foreach ($orders as $order) {
            if ($order instanceof \WC_Order) {
                $this->reconcileOne($order);
            }
        }
    }

    private function reconcileOne(\WC_Order $order): void
    {
        $intentId = (string) $order->get_meta('_lunixi_payment_order_id');
        if ($intentId === '') {
            return;
        }

        try {
            $detail = Plugin::instance()->sdk()->client()->payments()->get($intentId);
        } catch (\Throwable $e) {
            Logger::error('reconcile fetch failed', ['order' => $order->get_id(), 'error' => $e->getMessage()]);
            return;
        }

        $status = $detail->status();

        // Record the authoritative payment attributes (the same set the live
        // webhook path enriches) so the order is fully described either way.
        $order->update_meta_data('_lunixi_is_3d', $detail->is3D() ? 'yes' : 'no');
        $order->update_meta_data('_lunixi_installment', (string) $detail->installment());
        $provider = (string) ($detail->providerCode() ?? '');
        if ($provider !== '') {
            $order->update_meta_data('_lunixi_provider_code', $provider);
        }
        $order->update_meta_data('_lunixi_detail_enriched', 'yes');
        $order->update_meta_data('_lunixi_reconciled_at', gmdate('c'));

        if ($detail->isCaptured()) {
            if (!$order->is_paid()) {
                $order->update_meta_data('_lunixi_attempt_status', 'CAPTURED');
                $order->save_meta_data();
                $order->payment_complete($intentId);
                $order->add_order_note(sprintf(
                    /* translators: %s = payment status */
                    __('Lunixi: settled by reconciliation (webhook missed) — %s.', 'lunixi'),
                    $status
                ));
                return;
            }
        } elseif ($detail->isFailed()) {
            if (!$order->is_paid() && !$order->has_status('failed')) {
                $order->update_meta_data('_lunixi_attempt_status', 'FAILED');
                $order->save_meta_data();
                $order->update_status('failed', __('Lunixi: marked failed by reconciliation (webhook missed).', 'lunixi'));
                return;
            }
        } elseif (in_array($status, [PaymentStatus::VOIDED, PaymentStatus::EXPIRED], true)) {
            if (!$order->is_paid() && !$order->has_status('cancelled')) {
                $order->update_meta_data('_lunixi_attempt_status', strtoupper($status));
                $order->save_meta_data();
                $order->update_status('cancelled', __('Lunixi: cancelled by reconciliation (webhook missed).', 'lunixi'));
                return;
            }
        }

        // AWAITING_3D / AUTHORIZED / PROCESSING / INITIATED → still in flight;
        // persist the enrichment and leave the state for a later pass or webhook.
        $order->save_meta_data();
    }
}
