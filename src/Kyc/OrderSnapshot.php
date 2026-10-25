<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\Sdk\Kyc\AchievedAssurance;

defined('ABSPATH') || exit;

/**
 * Order-time KYC snapshot (owner review §4). When a gated checkout creates an
 * order, we freeze the buyer's verification state onto the order — so a later
 * revoke/expiry can't rewrite history and an auditor can answer "was the buyer
 * verified, at what level, when the order was placed?". Read-only meta box.
 */
final class OrderSnapshot
{
    public const META = '_lunixi_kyc_snapshot';

    private VerificationStore $store;

    public function __construct(VerificationStore $store)
    {
        $this->store = $store;
    }

    public function register(): void
    {
        add_action('woocommerce_checkout_create_order', [$this, 'capture'], 10, 2);
        if (is_admin()) {
            add_action('add_meta_boxes', [$this, 'addMetaBox'], 32, 2);
        }
    }

    /**
     * @param \WC_Order $order
     * @param array<string,mixed> $data
     */
    public function capture($order, $data = []): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $userId = (int) $order->get_customer_id();
        if ($userId <= 0) {
            return;
        }
        $snapshot = $this->store->snapshot($userId);
        $order->update_meta_data(self::META, (string) wp_json_encode($snapshot));
    }

    /**
     * @param string $screenId
     * @param mixed  $screenObject
     */
    public function addMetaBox($screenId, $screenObject = null): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('lunixi_kyc_snapshot', __('Lunixi KYC (at order time)', 'lunixi'), [$this, 'render'], [$screen, 'shop_order'], 'side', 'default');
    }

    /**
     * @param mixed $post_or_order
     */
    public function render($post_or_order): void
    {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order($post_or_order);
        if (!$order) {
            return;
        }
        $raw = (string) $order->get_meta(self::META);
        if ($raw === '') {
            echo '<p>' . esc_html__('No verification snapshot for this order.', 'lunixi') . '</p>';
            return;
        }
        $snap = json_decode($raw, true);
        if (!is_array($snap)) {
            echo '<p>—</p>';
            return;
        }
        $assurance = isset($snap['assurance']) && is_array($snap['assurance']) ? $snap['assurance'] : [];
        $identity = (string) ($assurance[AchievedAssurance::IDENTITY] ?? '—');

        $rows = [
            __('State', 'lunixi') => (string) ($snap['state'] ?? '—'),
            __('Identity', 'lunixi') => $identity,
            __('Decision', 'lunixi') => (string) ($snap['decision'] ?? '—'),
            __('Session', 'lunixi') => (string) ($snap['sessionId'] ?? '—'),
            __('Captured', 'lunixi') => (string) ($snap['capturedAt'] ?? '—'),
        ];
        echo '<table class="widefat striped" style="border:0">';
        foreach ($rows as $label => $value) {
            echo '<tr><th style="text-align:left;width:38%">' . esc_html((string) $label) . '</th><td style="word-break:break-all">' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</table>';
    }
}
