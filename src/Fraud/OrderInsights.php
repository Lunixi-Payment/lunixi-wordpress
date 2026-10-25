<?php

declare(strict_types=1);

namespace Lunixi\WP\Fraud;

use Lunixi\Sdk\Webhook\WebhookEvent;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Surfaces the Lunixi fraud decision on WooCommerce orders. Fraud runs
 * automatically inside payment authorization, so we read the decision back from
 * the fraud decision-logs (by payment intent id) when an order is first paid /
 * authorized, record it as order meta + a note, and render a "Lunixi fraud"
 * meta box on the order screen. HPOS-safe; one fetch per order.
 */
final class OrderInsights
{
    public const META_DECISION = '_lunixi_fraud_decision';
    public const META_SCORE = '_lunixi_fraud_score';
    public const META_REASONS = '_lunixi_fraud_reasons';
    public const META_TRACE = '_lunixi_fraud_trace';
    private const META_RECORDED = '_lunixi_fraud_recorded';

    public function register(): void
    {
        add_action('lunixi_webhook_event', [$this, 'onPaymentEvent'], 20, 1);
        if (is_admin()) {
            add_action('add_meta_boxes', [$this, 'addMetaBox'], 31, 2);
        }
    }

    public function onPaymentEvent(WebhookEvent $event): void
    {
        if (!in_array($event->type(), ['payment.captured', 'payment.authorized'], true)) {
            return;
        }
        $data = $event->data();
        $intentId = isset($data['intentId']) ? (string) $data['intentId'] : '';
        if ($intentId === '') {
            return;
        }
        $order = self::findOrderByIntent($intentId);
        if (!$order || $order->get_meta(self::META_RECORDED) === 'yes') {
            return;
        }

        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            return;
        }

        try {
            $decision = $plugin->sdk()->client()->fraud()->latestForPayment($intentId);
        } catch (\Throwable $e) {
            Logger::error('fraud lookup failed', ['intent' => $intentId, 'error' => $e->getMessage()]);
            return;
        }
        if ($decision === null) {
            return;
        }

        $reasons = $decision->reasonCodes();
        $order->update_meta_data(self::META_DECISION, $decision->decision());
        $order->update_meta_data(self::META_SCORE, (string) $decision->score());
        $order->update_meta_data(self::META_REASONS, implode(',', $reasons));
        $order->update_meta_data(self::META_TRACE, $decision->traceId());
        $order->update_meta_data(self::META_RECORDED, 'yes');
        $order->save_meta_data();

        $order->add_order_note(sprintf(
            /* translators: 1: decision, 2: score, 3: reason codes */
            __('Lunixi fraud: %1$s (score %2$s)%3$s.', 'lunixi'),
            $decision->decision() !== '' ? $decision->decision() : '—',
            (string) $decision->score(),
            $reasons !== [] ? ' — ' . implode(', ', $reasons) : ''
        ));
    }

    /**
     * @param string $screenId
     * @param mixed  $screenObject
     */
    public function addMetaBox($screenId, $screenObject = null): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('lunixi_fraud', __('Lunixi fraud', 'lunixi'), [$this, 'renderMetaBox'], [$screen, 'shop_order'], 'side', 'default');
    }

    /**
     * @param mixed $post_or_order
     */
    public function renderMetaBox($post_or_order): void
    {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order($post_or_order);
        if (!$order || $order->get_payment_method() !== 'lunixi') {
            echo '<p>' . esc_html__('Not a Lunixi payment.', 'lunixi') . '</p>';
            return;
        }
        $decision = (string) $order->get_meta(self::META_DECISION);
        if ($decision === '') {
            echo '<p>' . esc_html__('No fraud decision recorded yet.', 'lunixi') . '</p>';
            return;
        }
        $score = (string) $order->get_meta(self::META_SCORE);
        $reasons = (string) $order->get_meta(self::META_REASONS);
        $trace = (string) $order->get_meta(self::META_TRACE);

        echo '<p>' . wp_kses_post(self::badge($decision)) . ' <strong>' . esc_html__('Score', 'lunixi') . ':</strong> ' . esc_html($score) . '</p>';
        if ($reasons !== '') {
            echo '<p><strong>' . esc_html__('Reasons', 'lunixi') . ':</strong><br/>' . esc_html(str_replace(',', ', ', $reasons)) . '</p>';
        }
        if ($trace !== '') {
            echo '<p style="color:#64748B;font-size:11px;word-break:break-all">' . esc_html__('Trace', 'lunixi') . ': ' . esc_html($trace) . '</p>';
        }
    }

    public static function badge(string $decision): string
    {
        $color = '#64748B';
        if ($decision === 'allow') {
            $color = '#10B981';
        } elseif ($decision === 'review' || $decision === 'force_3d') {
            $color = '#F97316';
        } elseif ($decision === 'block') {
            $color = '#EF4444';
        }
        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;color:#fff;background:' . esc_attr($color) . '">' . esc_html($decision !== '' ? $decision : '—') . '</span>';
    }

    /**
     * @return \WC_Order|null
     */
    private static function findOrderByIntent(string $intentId)
    {
        $orders = wc_get_orders([
            'limit' => 1,
            'status' => 'any',
            'meta_query' => [['key' => '_lunixi_payment_order_id', 'value' => $intentId]],
        ]);
        return (is_array($orders) && isset($orders[0]) && $orders[0] instanceof \WC_Order) ? $orders[0] : null;
    }
}
