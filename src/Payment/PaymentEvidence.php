<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\WP\Core\Money;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Compatibility guard over PHP SDK 0.28.1's existing raw signed transport.
 * GET is merchant-scoped by the gateway. Neither browser claims nor a signed
 * captured notification replace identity, balance and split-parent evidence.
 */
final class PaymentEvidence
{
    /** @return array<string,mixed> */
    public static function snapshot(\WC_Order $order): array
    {
        $native = ['orderId' => (string) $order->get_order_number(), 'currency' => (string) $order->get_currency(),
            'amount' => self::minor($order->get_total(), Money::exponent((string) $order->get_currency()))];
        $items = [];
        if (method_exists($order, 'get_items')) {
            foreach ($order->get_items() as $id => $item) {
                $items[] = [(string) $id, (string) $item->get_product_id(), (string) $item->get_variation_id(),
                    (string) $item->get_quantity(), (string) $item->get_total(), (string) $item->get_total_tax()];
            }
        }
        $native['nativeFingerprint'] = hash('sha256', json_encode([$native, $items], JSON_THROW_ON_ERROR));
        return $native;
    }

    /** Strict decimal conversion at the native WooCommerce string boundary. */
    public static function minor(string $decimal, int $exponent): string
    {
        try { return \Lunixi\Sdk\Commerce\Money::minor($decimal, $exponent); }
        catch (\InvalidArgumentException $error) { throw new \RuntimeException($error->getMessage(), 0, $error); }
    }

    /** @param mixed $value */
    public static function integer($value): string
    {
        try { return \Lunixi\Sdk\Commerce\Money::integer($value); }
        catch (\InvalidArgumentException $error) { throw new \RuntimeException($error->getMessage(), 0, $error); }
    }

    /** @param array<string,mixed>|null $claims @param array<string,mixed>|null $response @return array<string,mixed> */
    public static function paid(\WC_Order $order, string $paymentId, ?array $claims = null, ?array $response = null): array
    {
        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            throw new \RuntimeException('Merchant transport unavailable.');
        }
        $client = $plugin->sdk()->client();
        if (
            !$order->has_status('pending') && !($order->has_status('on-hold')
            && in_array((string) $order->get_meta('_lunixi_attempt_status'), ['AUTHORIZED', 'PARTIALLY_CAPTURED'], true))
        ) {
            throw new \RuntimeException('Native order holds must be reviewed.');
        }
        $native = self::snapshot($order);
        $saved = $order->get_meta('_lunixi_order_snapshot');
        $expected = is_array($saved) && $saved !== [] ? $saved : $native;
        foreach (['orderId', 'currency', 'amount', 'nativeFingerprint'] as $field) {
            if (($expected[$field] ?? null) !== $native[$field]) {
                throw new \RuntimeException('Native order changed; reconciliation required.');
            }
        }
        if (
            $paymentId === '' || (string) $order->get_meta('_lunixi_payment_order_id') !== $paymentId
            || ((string) $order->get_meta('_lunixi_active_attempt_id') !== '' && (string) $order->get_meta('_lunixi_active_attempt_id') !== $paymentId)
        ) {
            throw new \RuntimeException('Saved payment identity mismatch.');
        }
        $environment = $order->get_meta('_lunixi_payment_environment');
        if ($environment !== '' && $environment !== $client->config()->environment()) {
            throw new \RuntimeException('Payment environment changed.');
        }
        // Use raw API response: PaymentIntent::raw() intentionally contains data
        // only, while canonical settlement amounts are in top-level details.
        $response = $response ?? $client->api()->request('GET', '/api/v1/payments/' . rawurlencode($paymentId));
        $payment = $response['data'] ?? null;
        if (!is_array($payment)) {
            throw new \RuntimeException('Authenticated payment data missing.');
        }
        $merchant = $order->get_meta('_lunixi_merchant_id');
        $profile = hash('sha256', $client->config()->baseUrl() . "\n" . $client->config()->keyId() . "\n" . $client->config()->environment());
        $bound = get_option('lunixi_merchant_binding_' . $profile, '');
        if ($merchant === '' && is_string($bound)) {
            $merchant = $bound;
        }
        if (!is_string($payment['merchantId'] ?? null) || $payment['merchantId'] === '') {
            throw new \RuntimeException('Merchant evidence missing.');
        }
        if ($merchant !== '' && $merchant !== $payment['merchantId']) {
            throw new \RuntimeException('Merchant identity mismatch.');
        }
        $expected['merchantId'] = $merchant !== '' ? $merchant : $payment['merchantId'];
        $split = null;
        if (is_array($payment['split'] ?? null)) {
            $reference = $payment['split'];
            if (($reference['role'] ?? '') !== 'PARENT' || !is_string($reference['splitPaymentId'] ?? null) || $reference['splitPaymentId'] === '') {
                throw new \RuntimeException('Split leg cannot complete a native order.');
            }
            $split = $client->payments()->getSplitPayment($reference['splitPaymentId'])->raw();
        }
        $proof = self::evaluate($expected, $paymentId, $response, $split, $claims);
        // Identity is learned only from a successful, authenticated merchant GET
        // matching the stored original payment and the current native order.
        if ($bound === '') {
            add_option('lunixi_merchant_binding_' . $profile, $proof['merchantId'], '', false);
        }
        $order->update_meta_data('_lunixi_merchant_id', $proof['merchantId']);
        return $proof;
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $response @param array<string,mixed>|null $split @param array<string,mixed>|null $claims @return array<string,mixed> */
    public static function evaluate(array $expected, string $paymentId, array $response, ?array $split = null, ?array $claims = null): array
    {
        $payment = $response['data'] ?? null;
        if (!is_array($payment)) {
            throw new \RuntimeException('Authenticated payment data missing.');
        }
        foreach (['id' => $paymentId, 'orderId' => $expected['orderId'], 'currency' => $expected['currency'], 'merchantId' => $expected['merchantId']] as $field => $value) {
            if (($payment[$field] ?? null) !== $value) {
                throw new \RuntimeException('Authenticated payment identity mismatch.');
            }
        }
        $amount = self::integer($payment['amount'] ?? null);
        $details = $response['details'] ?? [];
        if (!is_array($details)) {
            throw new \RuntimeException('Settlement balance evidence malformed.');
        }
        // Additive server-owned original-order evidence. Old servers do not
        // expose it: accept only the unchanged amount on that legacy path.
        // Merchant metadata/commission never authorizes a buyer fee/premium.
        if (array_key_exists('orderAmountMinor', $details) && !is_string($details['orderAmountMinor'])) {
            throw new \RuntimeException('Original order evidence must be a decimal string.');
        }
        $base = array_key_exists('orderAmountMinor', $details) ? self::integer($details['orderAmountMinor']) : $amount;
        if ($amount === '0' || $base !== $expected['amount'] || \Lunixi\Sdk\Commerce\Money::compare($amount, $base) < 0) {
            throw new \RuntimeException('Charged amount breakdown requires reconciliation.');
        }
        if (is_array($payment['split'] ?? null) || ($payment['status'] ?? '') === 'SETTLED_BY_SPLIT') {
            $reference = $payment['split'] ?? [];
            $data = isset($split['data']) && is_array($split['data']) ? $split['data'] : $split;
            if (
                ($reference['role'] ?? '') !== 'PARENT' || !is_array($data) || ($data['id'] ?? null) !== ($reference['splitPaymentId'] ?? null)
                || ($payment['status'] ?? '') !== 'SETTLED_BY_SPLIT' || ($data['status'] ?? '') !== 'COMPLETED'
                || ($data['currency'] ?? null) !== $expected['currency'] || ($data['orderId'] ?? null) !== $expected['orderId']
                || (isset($data['merchantId']) && $data['merchantId'] !== $expected['merchantId'])
                || ($data['needsReconciliation'] ?? true) !== false || ($data['aggregate']['amountsBalanced'] ?? false) !== true
                || self::integer($data['aggregate']['totalAmount'] ?? null) !== $amount
                || self::integer($data['aggregate']['legsAmount'] ?? null) !== $amount
                || self::integer($data['aggregate']['capturedAmount'] ?? null) !== $amount
                || self::integer($data['aggregate']['compensatedAmount'] ?? null) !== '0'
                || self::integer($data['refund']['refundedAmount'] ?? null) !== '0'
            ) {
                throw new \RuntimeException('Complete split-parent settlement not proven.');
            }
            $captured = $amount;
        } else {
            $details = $response['details'] ?? null;
            if (!is_array($details)) {
                throw new \RuntimeException('Settlement balance evidence missing.');
            }
            $captured = self::integer($details['capturedAmount'] ?? null);
            $refunded = self::integer($details['refundedAmount'] ?? null);
            if (($payment['status'] ?? '') !== 'CAPTURED' || $captured !== $amount || $refunded !== '0') {
                throw new \RuntimeException('Complete unrefunded capture not authoritative.');
            }
        }
        foreach (['intentId' => $paymentId, 'paymentId' => $paymentId, 'orderId' => $expected['orderId'], 'merchantId' => $expected['merchantId'], 'currency' => $expected['currency']] as $field => $value) {
            if (isset($claims[$field]) && $claims[$field] !== $value) {
                throw new \RuntimeException('Signed event and authenticated payment disagree.');
            }
        }
        foreach (['amountMinor' => $amount, 'capturedAmountMinor' => $captured, 'cumulativeCapturedMinor' => $captured, 'remainingCapturableMinor' => '0'] as $field => $value) {
            if (isset($claims[$field]) && self::integer($claims[$field]) !== $value) {
                throw new \RuntimeException('Signed settlement amount disagrees.');
            }
        }
        return ['paymentId' => $paymentId, 'merchantId' => $expected['merchantId'], 'currency' => $expected['currency'],
            'amount' => $amount, 'orderAmount' => $base, 'additionsAmount' => \Lunixi\Sdk\Commerce\Money::subtract($amount, $base),
            'capturedAmount' => $captured, 'data' => $payment];
    }
}
