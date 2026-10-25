<?php

declare(strict_types=1);

namespace Lunixi\WP\Automation;

use Lunixi\WP\Core\SdkFactory;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Automation module — a WooCommerce → Lunixi event bridge. Forwards WP-native
 * commerce events to the merchant's automation flows so they can react ("when an
 * order is paid, do X"). Inert unless enabled + configured in settings. First
 * slice: order created/paid/cancelled + customer created. (More events —
 * form.submitted, product.low_stock — land in later slices.)
 */
final class Module
{
    private ?AutomationBridge $bridge = null;

    public function init(SdkFactory $sdk, Settings $settings): void
    {
        $this->bridge = new AutomationBridge($settings);
        Forward::setBridge($this->bridge);

        // Durable retry tick (WP-cron) for transient ingress failures (429/5xx).
        // The handler is always registered so a cron request can resolve it; the
        // schedule is only created once the integration is configured.
        add_action(RetryQueue::HOOK, [$this->bridge, 'retryFlush']);
        if ($settings->automationConfigured()) {
            RetryQueue::schedule();
        }

        // Admin status page is always available so the merchant can configure +
        // check the connection; event forwarding only wires when enabled.
        if (is_admin()) {
            (new Admin($this->bridge, $settings))->register();
        }

        if (!$settings->automationEnabled() || !class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_checkout_order_created', [$this, 'onOrderCreated'], 10, 1);
        add_action('woocommerce_payment_complete', [$this, 'onOrderPaid'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'onOrderCancelled'], 10, 1);
        add_action('woocommerce_created_customer', [$this, 'onCustomerCreated'], 10, 1);

        // Stock.
        add_action('woocommerce_low_stock', [$this, 'onLowStock'], 10, 1);
        add_action('woocommerce_no_stock', [$this, 'onLowStock'], 10, 1);

        // Forms: a generic action any code can fire, plus Contact Form 7 if present.
        add_action('lunixi_automation_form_submitted', [$this, 'onFormSubmitted'], 10, 1);
        add_action('wpcf7_mail_sent', [$this, 'onCf7Sent'], 10, 1);
    }

    /**
     * @param \WC_Product $product
     */
    public function onLowStock($product): void
    {
        if (!$this->bridge || !$product instanceof \WC_Product) {
            return;
        }
        $stock = $product->get_stock_quantity();
        $this->bridge->send('product.low_stock', [
            'productId' => $product->get_id(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'stock' => $stock,
        ], 'wc-product-' . $product->get_id() . '-stock-' . (string) $stock);
    }

    /**
     * Generic form hook: do_action('lunixi_automation_form_submitted', [...]).
     *
     * @param array<string,mixed> $data
     */
    public function onFormSubmitted($data): void
    {
        if ($this->bridge && is_array($data)) {
            $this->bridge->send('form.submitted', $data);
        }
    }

    /**
     * @param mixed $contactForm Contact Form 7 form object.
     */
    public function onCf7Sent($contactForm): void
    {
        if (!$this->bridge || !class_exists('WPCF7_Submission')) {
            return;
        }
        $submission = \WPCF7_Submission::get_instance();
        $posted = $submission ? (array) $submission->get_posted_data() : [];
        // Drop non-scalar / file fields; keep simple values.
        $fields = [];
        foreach ($posted as $key => $value) {
            if (is_scalar($value)) {
                $fields[(string) $key] = (string) $value;
            }
        }
        $formId = (is_object($contactForm) && method_exists($contactForm, 'id')) ? (string) $contactForm->id() : '';
        $this->bridge->send('form.submitted', [
            'source' => 'contact-form-7',
            'formId' => $formId,
            'fields' => $fields,
        ]);
    }

    /**
     * @param \WC_Order $order
     */
    public function onOrderCreated($order): void
    {
        if ($this->bridge && $order instanceof \WC_Order) {
            $this->bridge->send('order.created', self::orderPayload($order), 'wc-order-' . $order->get_id() . '-created');
        }
    }

    /**
     * @param int $orderId
     */
    public function onOrderPaid($orderId): void
    {
        $order = wc_get_order($orderId);
        if ($this->bridge && $order) {
            $this->bridge->send('order.paid', self::orderPayload($order), 'wc-order-' . (int) $orderId . '-paid');
        }
    }

    /**
     * @param int $orderId
     */
    public function onOrderCancelled($orderId): void
    {
        $order = wc_get_order($orderId);
        if ($this->bridge && $order) {
            $this->bridge->send('order.cancelled', self::orderPayload($order), 'wc-order-' . (int) $orderId . '-cancelled');
        }
    }

    /**
     * @param int $customerId
     */
    public function onCustomerCreated($customerId): void
    {
        $user = get_userdata((int) $customerId);
        if ($this->bridge && $user) {
            $this->bridge->send('customer.created', [
                'customerId' => (int) $customerId,
                'email' => (string) $user->user_email,
                'name' => (string) $user->display_name,
            ], 'wc-customer-' . (int) $customerId);
        }
    }

    /**
     * @param \WC_Order $order
     * @return array<string,mixed>
     */
    private static function orderPayload($order): array
    {
        return [
            'orderId' => $order->get_id(),
            'orderNumber' => $order->get_order_number(),
            'status' => $order->get_status(),
            'total' => (string) $order->get_total(),
            'currency' => $order->get_currency(),
            'email' => $order->get_billing_email(),
            'customerId' => $order->get_customer_id(),
        ];
    }
}
