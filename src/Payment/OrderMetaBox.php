<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

defined('ABSPATH') || exit;

/**
 * "Lunixi payment" meta box on the order-edit screen (HPOS + legacy post tables),
 * giving the merchant an at-a-glance payment summary — intent id, current state,
 * capture mode, dispute flag and the last webhook event — alongside WooCommerce's
 * own order-notes history. Read-only; all state transitions happen via webhooks.
 */
final class OrderMetaBox
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add'], 30, 2);
    }

    /**
     * @param string $screenId
     * @param mixed  $screenObject
     */
    public function add($screenId, $screenObject = null): void
    {
        $screen = function_exists('wc_get_page_screen_id')
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'lunixi_payment',
            __('Lunixi payment', 'lunixi'),
            [$this, 'render'],
            [$screen, 'shop_order'],
            'side',
            'high'
        );
    }

    /**
     * @param mixed $post_or_order
     */
    public function render($post_or_order): void
    {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order($post_or_order);
        if (!$order || $order->get_payment_method() !== Gateway::GATEWAY_ID) {
            echo '<p>' . esc_html__('Not a Lunixi payment.', 'lunixi') . '</p>';
            return;
        }

        $intentId = (string) $order->get_meta('_lunixi_payment_order_id');
        if ($intentId === '') {
            echo '<p>' . esc_html__('No payment has been initiated for this order yet.', 'lunixi') . '</p>';
            return;
        }

        $status = (string) $order->get_meta('_lunixi_attempt_status');
        $lastEvent = (string) $order->get_meta('_lunixi_last_event_type');
        $lastAt = (string) $order->get_meta('_lunixi_last_event_at');
        $disputeState = (string) $order->get_meta('_lunixi_dispute_state');
        $hasDispute = $order->get_meta('_lunixi_has_dispute') === 'yes';

        $is3d = (string) $order->get_meta('_lunixi_is_3d');
        $installment = (string) $order->get_meta('_lunixi_installment');
        $provider = (string) $order->get_meta('_lunixi_provider_code');

        if ($is3d === 'yes') {
            $security = __('3-D Secure', 'lunixi');
        } elseif ($is3d === 'no') {
            $security = __('Non-secure (2D)', 'lunixi');
        } else {
            $security = '—';
        }
        if ($installment !== '' && (int) $installment > 1) {
            /* translators: %d = number of instalments */
            $instalmentLabel = sprintf(__('%dx instalments', 'lunixi'), (int) $installment);
        } elseif ($installment !== '') {
            $instalmentLabel = __('Single payment', 'lunixi');
        } else {
            $instalmentLabel = '—';
        }

        $rows = [
            __('Intent', 'lunixi') => $intentId,
            __('State', 'lunixi') => $status !== '' ? $status : '—',
            __('Security', 'lunixi') => $security,
            __('Instalment', 'lunixi') => $instalmentLabel,
            __('Provider', 'lunixi') => $provider !== '' ? $provider : '—',
            __('Last event', 'lunixi') => $lastEvent !== '' ? $lastEvent : '—',
            __('Last update', 'lunixi') => $lastAt !== '' ? $lastAt : '—',
        ];

        echo '<div class="lunixi-orderbox">';
        echo '<table class="widefat striped" style="border:0;">';
        foreach ($rows as $label => $value) {
            echo '<tr><th style="text-align:left;width:38%;">' . esc_html((string) $label)
                . '</th><td style="word-break:break-all;">' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</table>';

        if ($hasDispute) {
            echo '<p style="margin:10px 0 0;padding:8px 10px;background:#FEF2F2;border:1px solid #FECACA;border-radius:6px;color:#991B1B;">'
                . esc_html(sprintf(
                    /* translators: %s = dispute state */
                    __('⚠ Dispute open: %s', 'lunixi'),
                    $disputeState !== '' ? $disputeState : __('chargeback', 'lunixi')
                ))
                . '</p>';
        }

        echo '</div>';
    }
}
