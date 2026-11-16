<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\WP\Core\Money;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Money that arrives when the order can no longer simply be completed: a
 * captured payment for an order that is already cancelled/failed, or a linked
 * late payment (bank transfer accepted after its deadline as a NEW payment for
 * the same merchant order). Both are verified with an authenticated merchant GET
 * and put the order on-hold for the merchant to review. They never mark the
 * order paid, and they answer the webhook with success so it is not retried.
 */
final class PaymentReview
{
    public const REASON_META = '_lunixi_review_reason';
    public const PAYMENT_IDS_META = '_lunixi_review_payment_ids';

    /** The order's own payment was captured after the order was cancelled or failed. */
    public const AFTER_CLOSE = 'payment_after_close';
    /** A different payment for the same merchant order (late bank transfer) was captured. */
    public const LINKED_PAYMENT = 'linked_late_payment';

    /** Orders closed before their money arrived; WooCommerce may have restocked them. */
    public static function closed(\WC_Order $order): bool
    {
        return $order->has_status('cancelled') || $order->has_status('failed');
    }

    /** The merchant owns this order's state until the review is resolved. */
    public static function flagged(\WC_Order $order): bool
    {
        return (string) $order->get_meta(self::REASON_META) !== '';
    }

    public static function recorded(\WC_Order $order, string $paymentId): bool
    {
        return in_array($paymentId, self::paymentIds($order), true);
    }

    /**
     * Records one reviewed payment and puts the order on-hold. Idempotent per
     * payment id: webhook delivery is at-least-once.
     */
    public static function hold(\WC_Order $order, string $paymentId, string $capturedMinor, string $reason): void
    {
        $ids = self::paymentIds($order);
        if (in_array($paymentId, $ids, true)) {
            return;
        }
        $ids[] = $paymentId;
        $order->update_meta_data(self::PAYMENT_IDS_META, (string) wp_json_encode(array_values($ids)));
        $order->update_meta_data(self::REASON_META, $reason);
        if ($reason === self::AFTER_CLOSE) {
            $order->update_meta_data('_lunixi_attempt_status', 'CAPTURED');
        }
        $order->save_meta_data();

        $amount = Money::format($capturedMinor, (string) $order->get_currency());
        $note = $reason === self::LINKED_PAYMENT
            ? sprintf(
                /* translators: 1: Lunixi payment id, 2: captured amount */
                __('Lunixi: a late payment %1$s (%2$s) arrived for this order. Review it before shipping or refund it.', 'lunixi'),
                $paymentId,
                $amount
            )
            : sprintf(
                /* translators: 1: Lunixi payment id, 2: captured amount */
                __('Lunixi: payment %1$s (%2$s) arrived after this order was cancelled or failed. Review it before shipping or refund it.', 'lunixi'),
                $paymentId,
                $amount
            );

        // A paid order keeps its fulfilment status; anything else waits on-hold.
        if ($order->is_paid() || $order->has_status('on-hold')) {
            $order->add_order_note($note);
            $order->save();
            return;
        }
        $order->update_status('on-hold', $note);
    }

    /**
     * Authenticated merchant GET for a payment that arrived for $order.
     *
     * @param array<string,mixed> $claims Signed webhook data.
     * @return string|null Captured minor amount, or null when no money was captured.
     */
    public static function evidence(\WC_Order $order, string $paymentId, array $claims): ?string
    {
        $plugin = Plugin::instance();
        if ($paymentId === '' || !$plugin->sdk()->isReady()) {
            throw new \RuntimeException('Merchant transport unavailable.');
        }
        $client = $plugin->sdk()->client();
        $environment = $order->get_meta('_lunixi_payment_environment');
        if ($environment !== '' && $environment !== $client->config()->environment()) {
            throw new \RuntimeException('Payment environment changed.');
        }
        $merchant = (string) $order->get_meta('_lunixi_merchant_id');
        if ($merchant === '') {
            $bound = get_option(PaymentEvidence::bindingOption($client), '');
            $merchant = is_string($bound) ? $bound : '';
        }
        $response = $client->api()->request('GET', '/api/v1/payments/' . rawurlencode($paymentId));

        return self::evaluate(self::expected($order, $merchant), $paymentId, $response, $claims);
    }

    /**
     * Pure check of the authenticated payment against the native order. Identity
     * (payment id, merchant, merchant order id, currency) must match exactly — a
     * payment for another order is rejected, never reviewed on this one.
     *
     * @param array{orderId:string,currency:string,merchantId:string} $expected merchantId '' = not yet learned
     * @param array<string,mixed>      $response
     * @param array<string,mixed>|null $claims
     * @return string|null Captured minor amount, or null when nothing was captured.
     */
    public static function evaluate(array $expected, string $paymentId, array $response, ?array $claims = null): ?string
    {
        $payment = $response['data'] ?? null;
        if (!is_array($payment)) {
            throw new \RuntimeException('Authenticated payment data missing.');
        }
        if (!is_string($payment['merchantId'] ?? null) || $payment['merchantId'] === '') {
            throw new \RuntimeException('Merchant evidence missing.');
        }
        // The GET is merchant-scoped by the gateway; a learned binding must still agree.
        $merchant = $expected['merchantId'] !== '' ? $expected['merchantId'] : $payment['merchantId'];
        $identity = ['id' => $paymentId, 'orderId' => $expected['orderId'], 'currency' => $expected['currency'], 'merchantId' => $merchant];
        foreach ($identity as $field => $value) {
            if ($value === '' || ($payment[$field] ?? null) !== $value) {
                throw new \RuntimeException('Authenticated payment identity mismatch.');
            }
        }
        foreach (['intentId' => $paymentId, 'paymentId' => $paymentId, 'orderId' => $expected['orderId'], 'merchantId' => $merchant, 'currency' => $expected['currency']] as $field => $value) {
            if (isset($claims[$field]) && $claims[$field] !== $value) {
                throw new \RuntimeException('Signed event and authenticated payment disagree.');
            }
        }
        $amount = PaymentEvidence::integer($payment['amount'] ?? null);
        $details = $response['details'] ?? [];
        if (!is_array($details)) {
            throw new \RuntimeException('Settlement balance evidence malformed.');
        }
        $status = (string) ($payment['status'] ?? '');
        if ($status === 'SETTLED_BY_SPLIT') {
            $captured = $amount; // a split parent owns no captured balance itself
        } elseif (array_key_exists('capturedAmount', $details)) {
            $captured = PaymentEvidence::integer($details['capturedAmount']);
        } else {
            $captured = $status === 'CAPTURED' ? $amount : '0';
        }
        if ($captured === '0') {
            return null;
        }
        if (\Lunixi\Sdk\Commerce\Money::compare($captured, $amount) > 0) {
            throw new \RuntimeException('Captured amount exceeds the payment amount.');
        }

        return $captured;
    }

    /** @return array{orderId:string,currency:string,merchantId:string} */
    private static function expected(\WC_Order $order, string $merchant): array
    {
        // The merchant order id sent at checkout is the saved snapshot's, not a
        // number a sequential-numbering plugin may have changed since.
        $saved = $order->get_meta('_lunixi_order_snapshot');
        $orderId = is_array($saved) && is_string($saved['orderId'] ?? null) && $saved['orderId'] !== ''
            ? $saved['orderId'] : (string) $order->get_order_number();

        return ['orderId' => $orderId, 'currency' => (string) $order->get_currency(), 'merchantId' => $merchant];
    }

    /** @return list<string> */
    private static function paymentIds(\WC_Order $order): array
    {
        $ids = json_decode((string) $order->get_meta(self::PAYMENT_IDS_META), true);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }
}
