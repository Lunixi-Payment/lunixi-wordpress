<?php

declare(strict_types=1);

namespace Lunixi\WP\Identity;

use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Checkout-time device signal (WooCommerce). Renders the collector on the
 * checkout form with the `payment_form` init type, stores the traceId on the
 * order, and resolves the linked-account graph at order placement so a
 * multi-account device can be flagged before payment. Does not block checkout —
 * merchants hook `lunixi_identity_checkout_multi_account` to hold/review.
 *
 * The hosted payment form the gateway returns runs its own device collection at
 * pay time; this earlier signal is about catching account-sharing at order time.
 */
final class Checkout
{
    private Collector $collector;
    private LinkResolver $resolver;
    private Settings $settings;

    public function __construct(Collector $collector, LinkResolver $resolver, Settings $settings)
    {
        $this->collector = $collector;
        $this->resolver = $resolver;
        $this->settings = $settings;
    }

    public function register(): void
    {
        // Render inside the checkout form but OUTSIDE the #order_review fragment,
        // which WooCommerce re-renders via AJAX (that would wipe the JS-set value).
        add_action('woocommerce_checkout_after_customer_details', [$this, 'renderField']);
        add_action('woocommerce_checkout_create_order', [$this, 'onCreateOrder'], 20, 2);
    }

    public function renderField(): void
    {
        $this->collector->enqueue('payment_form');
        $this->collector->renderField();
    }

    /**
     * @param \WC_Order             $order
     * @param array<string,mixed>   $data
     */
    public function onCreateOrder($order, $data): void
    {
        if (!is_object($order) || !method_exists($order, 'update_meta_data')) {
            return;
        }

        $traceId = Collector::traceFromRequest();
        if ($traceId === '') {
            $order->update_meta_data('_lunixi_identity_signal', 'none');
            return;
        }

        $links = $this->resolver->resolve($traceId);
        if ($links === null) {
            $order->update_meta_data('_lunixi_identity_signal', 'error');
            return;
        }

        $shared = $links->isShared($this->settings->identityThreshold());
        $order->update_meta_data('_lunixi_identity_trace', $traceId);
        $order->update_meta_data('_lunixi_identity_account_count', $links->accountCount());
        $order->update_meta_data('_lunixi_identity_flagged', $shared ? '1' : '0');
        $order->update_meta_data('_lunixi_identity_signal', 'ok');

        if ($shared) {
            $order->add_order_note(sprintf(
                /* translators: %d = number of accounts sharing the device */
                __('Lunixi Identity: device shared across %d accounts.', 'lunixi'),
                $links->accountCount()
            ));

            /**
             * A checkout from a device shared with other accounts. Listeners can
             * hold the order for review, require step-up, or score risk.
             *
             * @param \WC_Order                              $order
             * @param \Lunixi\Sdk\Identity\AccountLinks      $links
             */
            do_action('lunixi_identity_checkout_multi_account', $order, $links);
        }
    }
}
