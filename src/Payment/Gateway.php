<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Core\Money;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Lunixi WooCommerce payment gateway (classic checkout; Blocks handled by
 * {@see BlocksSupport}). HPOS-safe — all order access goes through the CRUD API.
 *
 * Flow: checkout submit → process_payment() creates a payment intent server-side
 * (SDK, step-up auth) and stores the order-metadata contract → buyer is sent to
 * the order-pay page where the embedded Lunixi JS SDK renders the card form with
 * the one-time token → the buyer pays (incl. 3DS) → the verified webhook
 * (OrderSync) is the authority that completes/fails the order.
 *
 * @method void init_settings()
 */
final class Gateway extends \WC_Payment_Gateway
{
    public const GATEWAY_ID = 'lunixi';

    public function __construct()
    {
        $this->id = self::GATEWAY_ID;
        $this->method_title = __('Lunixi', 'lunixi');
        $this->method_description = __('Accept cards via Lunixi (3-D Secure, instalments, refunds).', 'lunixi');
        $this->has_fields = false;
        $this->supports = ['products', 'refunds'];

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('title', __('Credit / Debit Card', 'lunixi'));
        $this->description = $this->get_option('description', '');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
    }

    public function init_form_fields(): void
    {
        // Credentials live on the shared "Lunixi" settings screen, used by all
        // modules — this gateway only toggles its checkout presentation.
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'lunixi'),
                'type' => 'checkbox',
                'label' => __('Enable Lunixi payments', 'lunixi'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'lunixi'),
                'type' => 'text',
                'description' => __('Payment method title shown at checkout.', 'lunixi'),
                'default' => __('Credit / Debit Card', 'lunixi'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'lunixi'),
                'type' => 'textarea',
                'default' => '',
            ],
            CaptureMode::OPTION => [
                'title' => __('Capture', 'lunixi'),
                'type' => 'select',
                'options' => [
                    CaptureMode::SALE => __('Capture immediately (recommended)', 'lunixi'),
                    CaptureMode::PREAUTH => __('Pre-authorization (preauth): hold now, capture later', 'lunixi'),
                ],
                'default' => CaptureMode::SALE,
                'description' => __('Pre-authorization places a hold; capture it by setting the order to Completed, or release it by cancelling the order.', 'lunixi'),
                'desc_tip' => true,
            ],
            'credentials_notice' => [
                'title' => __('API credentials', 'lunixi'),
                'type' => 'title',
                'description' => sprintf(
                    /* translators: %s = link to the Lunixi settings page */
                    __('Your API keys are shared across all Lunixi services — enter them once on the %s screen.', 'lunixi'),
                    '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('Lunixi → Settings', 'lunixi') . '</a>'
                ),
            ],
        ];
    }

    public function is_available(): bool
    {
        return $this->enabled === 'yes' && Plugin::instance()->sdk()->isReady();
    }

    /**
     * @param int $order_id
     * @return array<string,string>
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            wc_add_notice(__('Payment is temporarily unavailable. Please try again later.', 'lunixi'), 'error');
            return ['result' => 'failure'];
        }

        try {
            $currency = (string) $order->get_currency();
            $amountMinor = Money::toMinor((float) $order->get_total(), $currency);
            $idempotencyKey = self::nextAttemptKey($order);

            $request = new CreateIntentRequest($amountMinor, $currency, (string) $order->get_order_number());
            // The 3DS return redirect may append informational query params to this
            // URL (e.g. `_status`, `_signature` — now `v2=<hex>`, was bare base64url —
            // and `_timestamp` — now ISO 8601, was epoch ms). They are INFORMATIONAL
            // only: the signing secret is platform-internal and never distributed, so
            // the plugin deliberately does NOT parse or verify them (tolerant of both
            // old and new formats by construction). Order state is confirmed solely
            // via the verified webhook (OrderSync) and the GET /payments/{id}
            // reconciliation backstop — never from the browser redirect.
            $request
                ->withCallbackUrl($this->get_return_url($order))
                ->withLocale(self::localeCode());
            $captureMode = (string) $this->get_option(CaptureMode::OPTION, CaptureMode::SALE);
            CaptureMode::apply($request, $captureMode);

            // Marketplace split: a module may supply per-vendor seller legs for this
            // order. When present the payment routes through the marketplace
            // checkout-form (split gated + persisted at init); otherwise it's a
            // normal intent. Keeps the payment module decoupled from marketplace.
            $sellers = apply_filters('lunixi_payment_marketplace_sellers', [], $order);
            if (is_array($sellers) && $sellers !== []) {
                $policy = (string) apply_filters('lunixi_marketplace_policy', 'ALL_OR_NOTHING', $order);
                $intent = $plugin->sdk()->client()->marketplace()->createCheckoutIntent($request, $sellers, $policy, $idempotencyKey);
            } else {
                $intent = $plugin->sdk()->client()->payments()->createIntent($request, $idempotencyKey);
            }

            // Order-metadata contract (HPOS-safe; WC order-id is NOT a safe key on its own).
            $order->update_meta_data('_lunixi_payment_order_id', $intent->paymentId());
            $order->update_meta_data('_lunixi_active_attempt_id', $intent->paymentId());
            $order->update_meta_data('_lunixi_attempt_idempotency_key', $idempotencyKey);
            $order->update_meta_data('_lunixi_attempt_status', 'INITIATED');
            // The gateway returns a self-contained bootstrap <script> (token + config
            // embedded) that loads the Lunixi SDK and mounts the form — iyzico-style.
            // We render it as-is on the pay page; no token/bundle managed by the plugin.
            $order->update_meta_data('_lunixi_checkout_script', $intent->checkoutFormContent());
            $order->update_status('pending', sprintf(
                /* translators: 1: intent id, 2: capture mode */
                __('Lunixi: payment initiated (intent %1$s, %2$s).', 'lunixi'),
                $intent->paymentId(),
                CaptureMode::isPreauth($captureMode) ? __('preauth', 'lunixi') : __('sale', 'lunixi')
            ));
            $order->save();

            if (WC()->cart) {
                WC()->cart->empty_cart();
            }

            // Render the embedded form (with the token) on the order-pay page.
            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        } catch (\Throwable $e) {
            Logger::error('createIntent failed', ['order' => $order->get_id(), 'error' => $e->getMessage()]);
            $order->add_order_note('Lunixi createIntent failed: ' . $e->getMessage());
            wc_add_notice(__('Could not start the payment. Please try again.', 'lunixi'), 'error');
            return ['result' => 'failure'];
        }
    }

    /**
     * Order-pay page: render the gateway-returned checkout bootstrap.
     *
     * `checkoutFormContent` is a self-contained <script> produced by the gateway
     * (token + checkout config embedded) that loads the Lunixi SDK and mounts the
     * card form into `#lunixi-checkout-form` — the same iyzico-style model. We
     * output the mount target and the trusted script verbatim; the plugin manages
     * no token, bundle URL or mount logic.
     *
     * @param int $order_id
     */
    public function receipt_page($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $script = (string) $order->get_meta('_lunixi_checkout_script');
        if ($script === '') {
            echo '<p>' . esc_html__('Payment session could not be started. Please return to checkout.', 'lunixi') . '</p>';
            return;
        }

        // The SDK mounts into this element (the gateway script targets #lunixi-checkout-form).
        echo '<div id="lunixi-checkout-form" class="lunixi-checkout-form"></div>';

        // Trusted, server-generated bootstrap from the Lunixi gateway (contains the
        // SDK loader + the one-time token). It is HTML/JS to render, not data to escape.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $script;
    }

    /**
     * @param int        $order_id
     * @param float|null $amount
     * @param string     $reason
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('lunixi_refund', __('Order not found.', 'lunixi'));
        }
        $intentId = (string) $order->get_meta('_lunixi_payment_order_id');
        if ($intentId === '') {
            return new \WP_Error('lunixi_refund', __('This order has no Lunixi payment to refund.', 'lunixi'));
        }

        try {
            $amountMinor = $amount !== null
                ? Money::toMinor((float) $amount, (string) $order->get_currency())
                : null;
            $key = 'wc-refund-' . $order->get_id() . '-' . substr(md5((string) $amount . $reason . microtime()), 0, 12);

            $result = Plugin::instance()->sdk()->client()->payments()->refund($intentId, $amountMinor, $reason, $key);
            $order->add_order_note(sprintf(
                /* translators: 1: amount, 2: resulting status */
                __('Lunixi refund processed (%1$s) — status %2$s.', 'lunixi'),
                $amount !== null ? wc_price((float) $amount) : __('full', 'lunixi'),
                $result->status()
            ));
            return true;
        } catch (\Throwable $e) {
            Logger::error('refund failed', ['order' => $order->get_id(), 'error' => $e->getMessage()]);
            return new \WP_Error('lunixi_refund', $e->getMessage());
        }
    }

    private static function nextAttemptKey(\WC_Order $order): string
    {
        $count = (int) $order->get_meta('_lunixi_attempt_count');
        $count++;
        $order->update_meta_data('_lunixi_attempt_count', (string) $count);

        return 'wc-' . $order->get_id() . '-' . $count;
    }

    private static function localeCode(): string
    {
        $locale = get_locale();
        return strtolower(substr($locale, 0, 2)) ?: 'en';
    }
}
