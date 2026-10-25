<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\Sdk\Webhook\WebhookEvent;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Core\Money;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Applies the full Lunixi payment lifecycle to WooCommerce orders from verified
 * webhooks — the AUTHORITATIVE source of order state — and records a rich,
 * Stripe/iyzico-grade payment history as order notes + meta. Idempotent (the
 * controller de-dupes by event id; handlers no-op when already applied).
 *
 * Events handled (all amounts arrive as minor-unit strings under `data`):
 *   succeeded · captured · partially_captured · authorized · failed ·
 *   refunded · partially_refunded · voided · expired · disputed
 */
final class OrderSync
{
    public function handle(WebhookEvent $event): void
    {
        if (!$event->matches('payment.*')) {
            return;
        }
        $data = $event->data();
        $intentId = isset($data['intentId']) ? (string) $data['intentId'] : '';
        if ($intentId === '') {
            return;
        }
        $order = self::findOrderByIntent($intentId);
        if (!$order) {
            return;
        }
        $currency = isset($data['currency']) && $data['currency'] !== '' ? (string) $data['currency'] : (string) $order->get_currency();

        $order->update_meta_data('_lunixi_intent_id', $intentId);
        $order->update_meta_data('_lunixi_last_event_type', $event->type());
        $order->update_meta_data('_lunixi_last_event_at', $event->timestamp());

        switch ($event->type()) {
            // `payment.succeeded` KALDIRILDI (platform, 2026-09): `payment.captured`
            // ile aynı ödemeyi anlatıyordu, ikisine birden abone olan kurulumlarda
            // onPaid() tek satış için İKİ KEZ çalışıyordu — payment_complete()
            // idempotenttir ama add_order_note() değildi, sipariş notu çiftleniyordu.
            case 'payment.captured':
                $this->onPaid($order, $intentId, $data, $currency);
                break;
            case 'payment.partially_captured':
                $this->onPartiallyCaptured($order, $intentId, $data, $currency);
                break;
            case 'payment.authorized':
                $this->onAuthorized($order, $data, $currency);
                break;
            case 'payment.failed':
                $this->onFailed($order, $data, $currency);
                break;
            case 'payment.refunded':
            case 'payment.partially_refunded':
                $this->onRefunded($order, $data, $currency);
                break;
            case 'payment.voided':
                $this->onVoided($order, $data, $currency);
                break;
            case 'payment.expired':
                $this->onExpired($order, $data, $currency);
                break;
            case 'payment.disputed':
                $this->onDisputed($order, $data, $currency);
                break;
            default:
                $order->save();
                return;
        }
    }

    private function onPaid(\WC_Order $order, string $intentId, array $data, string $currency): void
    {
        $amount = self::amountOf($data, ['amountMinor', 'capturedAmountMinor']);
        $order->update_meta_data('_lunixi_attempt_status', 'CAPTURED');
        $order->save_meta_data();

        if (!$order->is_paid()) {
            $order->payment_complete($intentId);
        }
        $fragment = self::enrichDetail($order, $intentId);
        $order->add_order_note(sprintf(
            /* translators: 1: formatted amount, 2: secure/installment/provider fragment */
            __('Lunixi: payment captured — %1$s%2$s.', 'lunixi'),
            $amount !== null ? Money::format($amount, $currency) : '—',
            $fragment
        ));
    }

    private function onPartiallyCaptured(\WC_Order $order, string $intentId, array $data, string $currency): void
    {
        $this_ = self::amountOf($data, ['capturedThisCaptureMinor']);
        $cum = self::amountOf($data, ['cumulativeCapturedMinor']);
        $rem = self::amountOf($data, ['remainingCapturableMinor']);
        $order->update_meta_data('_lunixi_attempt_status', 'PARTIALLY_CAPTURED');
        $order->save_meta_data();

        $order->add_order_note(sprintf(
            /* translators: 1: this capture, 2: cumulative, 3: remaining */
            __('Lunixi: partial capture — %1$s (captured %2$s, remaining %3$s).', 'lunixi'),
            $this_ !== null ? Money::format($this_, $currency) : '—',
            $cum !== null ? Money::format($cum, $currency) : '—',
            $rem !== null ? Money::format($rem, $currency) : '—'
        ));

        if ($rem !== null && (int) $rem === 0 && !$order->is_paid()) {
            $order->payment_complete($intentId);
        }
    }

    private function onAuthorized(\WC_Order $order, array $data, string $currency): void
    {
        $amount = self::amountOf($data, ['amountMinor']);
        $order->update_meta_data('_lunixi_attempt_status', 'AUTHORIZED');
        $order->save_meta_data();

        $fragment = self::enrichDetail($order, isset($data['intentId']) ? (string) $data['intentId'] : '');
        if (!$order->is_paid()) {
            $order->update_status('on-hold', sprintf(
                /* translators: 1: amount, 2: secure/installment/provider fragment */
                __('Lunixi: payment authorized (awaiting capture) — %1$s%2$s.', 'lunixi'),
                $amount !== null ? Money::format($amount, $currency) : '—',
                $fragment
            ));
        }
    }

    private function onFailed(\WC_Order $order, array $data, string $currency): void
    {
        if ($order->is_paid()) {
            return; // a late failure for an already-settled order is stale — ignore
        }
        $code = isset($data['normalizedErrorCode']) ? (string) $data['normalizedErrorCode']
            : (isset($data['canonicalKey']) ? (string) $data['canonicalKey'] : '');
        $order->update_meta_data('_lunixi_attempt_status', 'FAILED');
        $order->save_meta_data();

        $order->update_status('failed', sprintf(
            /* translators: %s = error code */
            __('Lunixi: payment failed%s.', 'lunixi'),
            $code !== '' ? ' (' . $code . ')' : ''
        ));
    }

    private function onRefunded(\WC_Order $order, array $data, string $currency): void
    {
        $refundAmount = self::amountOf($data, ['refundAmountMinor']);
        $totalRefunded = self::amountOf($data, ['totalRefundedMinor']);
        $refundId = isset($data['refundId']) ? (string) $data['refundId'] : '';

        // Idempotency by refundId — webhook delivery is at-least-once and the
        // controller re-runs all handlers on any failure, so without this guard a
        // retry would add a duplicate note and (under a read race on the cumulative
        // total) could create a second WC refund for the same gateway refund.
        if ($refundId !== '') {
            $applied = (array) json_decode((string) $order->get_meta('_lunixi_applied_refund_ids'), true);
            if (in_array($refundId, $applied, true)) {
                return;
            }
            $applied[] = $refundId;
            $order->update_meta_data('_lunixi_applied_refund_ids', (string) wp_json_encode(array_values($applied)));
            $order->save_meta_data();
        }

        // Reconcile against what WooCommerce already recorded. If WC's refunded
        // total is below the gateway's cumulative total, an externally-initiated
        // refund (e.g. from the Lunixi panel) needs a matching WC refund record
        // for the unrecorded delta. refund_payment=false → no gateway call, no loop.
        if ($totalRefunded !== null) {
            $wcRefundedMinor = Money::toMinor((float) $order->get_total_refunded(), $currency);
            $delta = (int) $totalRefunded - $wcRefundedMinor;
            if ($delta > 0) {
                wc_create_refund([
                    'order_id' => $order->get_id(),
                    'amount' => Money::toMajor((string) $delta, $currency),
                    'reason' => __('Lunixi refund (gateway)', 'lunixi'),
                    'refund_payment' => false,
                    'restock_items' => false,
                ]);
            }
        }

        $order->add_order_note(sprintf(
            /* translators: 1: this refund, 2: cumulative refunded, 3: refund id */
            __('Lunixi: refund processed — %1$s (total refunded %2$s)%3$s.', 'lunixi'),
            $refundAmount !== null ? Money::format($refundAmount, $currency) : '—',
            $totalRefunded !== null ? Money::format($totalRefunded, $currency) : '—',
            $refundId !== '' ? ' [' . $refundId . ']' : ''
        ));
        $order->save();
    }

    private function onVoided(\WC_Order $order, array $data, string $currency): void
    {
        $amount = self::amountOf($data, ['amountMinor']);
        $order->update_meta_data('_lunixi_attempt_status', 'VOIDED');
        $order->save_meta_data();
        if (!$order->has_status('cancelled')) {
            $order->update_status('cancelled', sprintf(
                /* translators: %s = amount */
                __('Lunixi: authorization voided — %s.', 'lunixi'),
                $amount !== null ? Money::format($amount, $currency) : '—'
            ));
        }
    }

    private function onExpired(\WC_Order $order, array $data, string $currency): void
    {
        $amount = self::amountOf($data, ['amountMinor']);
        $order->update_meta_data('_lunixi_attempt_status', 'EXPIRED');
        $order->save_meta_data();
        if (!$order->is_paid() && !$order->has_status('cancelled')) {
            $order->update_status('cancelled', sprintf(
                /* translators: %s = amount */
                __('Lunixi: authorization expired — %s.', 'lunixi'),
                $amount !== null ? Money::format($amount, $currency) : '—'
            ));
        }
    }

    private function onDisputed(\WC_Order $order, array $data, string $currency): void
    {
        $state = isset($data['disputeState']) ? (string) $data['disputeState'] : '';
        $chargebackId = isset($data['chargebackId']) ? (string) $data['chargebackId'] : '';
        $amount = self::amountOf($data, ['disputedAmountMinor']);
        $openCount = isset($data['openChargebackCount']) ? (int) $data['openChargebackCount'] : 0;

        $order->update_meta_data('_lunixi_dispute_state', $state);
        $order->update_meta_data('_lunixi_has_dispute', $openCount > 0 ? 'yes' : 'no');
        $order->save_meta_data();

        $order->add_order_note(sprintf(
            /* translators: 1: dispute state, 2: amount, 3: chargeback id, 4: open count */
            __('Lunixi: dispute %1$s — %2$s [chargeback %3$s, %4$d open].', 'lunixi'),
            $state !== '' ? $state : '—',
            $amount !== null ? Money::format($amount, $currency) : '—',
            $chargebackId !== '' ? $chargebackId : '—',
            $openCount
        ));
        $order->save();
    }

    /**
     * One-time enrichment from the gateway payment-detail endpoint. The delivered
     * webhook payload does NOT carry the 3-D-Secure flag, instalment count or
     * provider — those live only on the authoritative record — so on first
     * settlement/authorization we fetch GET /payments/{id} once and record:
     *   _lunixi_is_3d (yes|no) · _lunixi_installment · _lunixi_provider_code
     * Best-effort: any failure is logged and never blocks order state.
     *
     * @return string Human fragment for the order note (e.g. " (3-D Secure · 3x · NESTPAY)"), or ''.
     */
    private static function enrichDetail(\WC_Order $order, string $intentId): string
    {
        if ($intentId === '' || $order->get_meta('_lunixi_detail_enriched') === 'yes') {
            return '';
        }
        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            return '';
        }

        try {
            $detail = $plugin->sdk()->client()->payments()->get($intentId);
            $is3d = $detail->is3D();
            $installment = $detail->installment();
            $provider = (string) ($detail->providerCode() ?? '');

            $order->update_meta_data('_lunixi_is_3d', $is3d ? 'yes' : 'no');
            $order->update_meta_data('_lunixi_installment', (string) $installment);
            if ($provider !== '') {
                $order->update_meta_data('_lunixi_provider_code', $provider);
            }
            $order->update_meta_data('_lunixi_detail_enriched', 'yes');
            $order->save_meta_data();

            $bits = [$is3d ? __('3-D Secure', 'lunixi') : __('non-secure (2D)', 'lunixi')];
            if ($installment > 1) {
                /* translators: %d = number of instalments */
                $bits[] = sprintf(__('%d instalments', 'lunixi'), $installment);
            }
            if ($provider !== '') {
                $bits[] = $provider;
            }

            return ' (' . implode(' · ', $bits) . ')';
        } catch (\Throwable $e) {
            Logger::error('payment detail enrich failed', ['intent' => $intentId, 'error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param string[]            $keys
     * @return string|null First present minor-unit value (as string), or null.
     */
    private static function amountOf(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return (string) $data[$key];
            }
        }
        return null;
    }

    /**
     * @return \WC_Order|null
     */
    private static function findOrderByIntent(string $intentId)
    {
        $orders = wc_get_orders([
            'limit' => 1,
            'status' => 'any',
            'meta_query' => [
                [
                    'key' => '_lunixi_payment_order_id',
                    'value' => $intentId,
                ],
            ],
        ]);

        if (is_array($orders) && isset($orders[0]) && $orders[0] instanceof \WC_Order) {
            return $orders[0];
        }
        return null;
    }
}
