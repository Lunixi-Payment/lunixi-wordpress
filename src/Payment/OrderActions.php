<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Merchant-driven capture/void for AUTHORIZE-only payments, wired to the native
 * WooCommerce order workflow (Stripe/iyzico-style):
 *
 *   • Order → Completed  → capture the held authorization.
 *   • Order → Cancelled  → void (release) the held authorization.
 *
 * The webhook (OrderSync) remains the source of truth: these calls only ASK the
 * gateway to act; the resulting payment.captured / payment.voided event finalises
 * order state. Guard meta keys make each request fire at most once.
 */
final class OrderActions
{
    public function register(): void
    {
        add_action('woocommerce_order_status_completed', [$this, 'onCompleted'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'onCancelled'], 10, 1);
    }

    /** Capture the authorization when the merchant completes the order. */
    public function onCompleted($orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order || $order->get_payment_method() !== Gateway::GATEWAY_ID) {
            return;
        }
        if ($order->get_meta('_lunixi_attempt_status') !== 'AUTHORIZED') {
            return; // nothing held to capture (already captured, sale-mode, or failed)
        }
        if ($order->get_meta('_lunixi_capture_requested') === 'yes') {
            return; // idempotent: requested already
        }
        $intentId = (string) $order->get_meta('_lunixi_payment_order_id');
        if ($intentId === '') {
            return;
        }

        $order->update_meta_data('_lunixi_capture_requested', 'yes');
        $order->save_meta_data();

        try {
            $key = 'wc-capture-' . $order->get_id() . '-' . substr(md5($intentId), 0, 12);
            $result = Plugin::instance()->sdk()->client()->payments()->capture($intentId, null, $key);
            $order->add_order_note(sprintf(
                /* translators: %s = resulting status */
                __('Lunixi: capture requested on completion — status %s.', 'lunixi'),
                $result->status()
            ));
        } catch (\Throwable $e) {
            $order->update_meta_data('_lunixi_capture_requested', 'failed');
            $order->save_meta_data();
            $order->add_order_note(sprintf(
                /* translators: %s = error message */
                __('Lunixi: capture failed — %s.', 'lunixi'),
                $e->getMessage()
            ));
        }
    }

    /** Void the authorization when the merchant cancels the order. */
    public function onCancelled($orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order || $order->get_payment_method() !== Gateway::GATEWAY_ID) {
            return;
        }
        if ($order->get_meta('_lunixi_attempt_status') !== 'AUTHORIZED') {
            return; // only an uncaptured authorization can be voided here
        }
        if ($order->get_meta('_lunixi_void_requested') === 'yes') {
            return;
        }
        $intentId = (string) $order->get_meta('_lunixi_payment_order_id');
        if ($intentId === '') {
            return;
        }

        $order->update_meta_data('_lunixi_void_requested', 'yes');
        $order->save_meta_data();

        try {
            $key = 'wc-void-' . $order->get_id() . '-' . substr(md5($intentId), 0, 12);
            $result = Plugin::instance()->sdk()->client()->payments()->void($intentId, $key);
            $order->add_order_note(sprintf(
                /* translators: %s = resulting status */
                __('Lunixi: void requested on cancellation — status %s.', 'lunixi'),
                $result->status()
            ));
        } catch (\Throwable $e) {
            $order->update_meta_data('_lunixi_void_requested', 'failed');
            $order->save_meta_data();
            $order->add_order_note(sprintf(
                /* translators: %s = error message */
                __('Lunixi: void failed — %s.', 'lunixi'),
                $e->getMessage()
            ));
        }
    }
}
