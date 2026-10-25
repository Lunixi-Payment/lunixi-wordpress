<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

use Lunixi\Sdk\Subscription\SubscriptionStatus;
use Lunixi\WP\Core\Money;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Admin surface for subscriptions — the merchant manages everything from
 * WP-admin (Lunixi-panel styled). A fast list comes from the local mirror; the
 * detail view + actions (pause / resume / cancel) hit the gateway via the SDK,
 * and invoice history is fetched live. Registers itself as the "Subscription"
 * module page through the `lunixi_admin_modules` filter.
 */
final class Admin
{
    private const SLUG = 'lunixi-subscription';
    private const ACTION = 'lunixi_subscription_action';

    private SubscriptionStore $store;

    public function __construct(SubscriptionStore $store)
    {
        $this->store = $store;
    }

    public function register(): void
    {
        add_filter('lunixi_admin_modules', [$this, 'registerModulePage']);
        add_action('admin_post_' . self::ACTION, [$this, 'handleAction']);
    }

    /**
     * @param array<int,array<string,mixed>> $modules
     * @return array<int,array<string,mixed>>
     */
    public function registerModulePage(array $modules): array
    {
        foreach ($modules as &$module) {
            if (($module['slug'] ?? '') === self::SLUG) {
                $module['ready'] = true;
                $module['callback'] = [$this, 'render'];
            }
        }
        return $modules;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $subscriptionId = isset($_GET['subscription']) ? sanitize_text_field(wp_unslash((string) $_GET['subscription'])) : '';

        echo '<div class="wrap lunixi-admin">';
        $this->header(
            __('Subscriptions', 'lunixi'),
            __('Recurring billing · plans · invoices', 'lunixi')
        );
        $this->flash();

        if ($subscriptionId !== '') {
            $this->renderDetail($subscriptionId);
        } else {
            $this->renderList();
        }
        echo '</div>';
    }

    private function renderList(): void
    {
        if (!Plugin::instance()->sdk()->isReady()) {
            echo '<div class="lunixi-notice">' . sprintf(
                /* translators: %s = settings link */
                esc_html__('Connect your store to manage subscriptions — %s.', 'lunixi'),
                '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('add API credentials', 'lunixi') . '</a>'
            ) . '</div>';
        }

        $planNames = $this->planNameMap();

        $posts = get_posts([
            'post_type' => SubscriptionStore::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => 50,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Subscriptions', 'lunixi') . '</p>';

        if (!is_array($posts) || $posts === []) {
            echo '<p>' . esc_html__('No subscriptions yet. They appear here as customers subscribe (and via webhooks).', 'lunixi') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('Reference', 'lunixi'), __('Customer', 'lunixi'), __('Plan', 'lunixi'), __('Status', 'lunixi'), __('Next billing', 'lunixi'), __('Amount', 'lunixi')] as $col) {
                echo '<th>' . esc_html((string) $col) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($posts as $post) {
                if (!$post instanceof \WP_Post) {
                    continue;
                }
                $id = (int) $post->ID;
                $subId = (string) get_post_meta($id, SubscriptionStore::META_ID, true);
                $planId = (string) get_post_meta($id, SubscriptionStore::META_PLAN_ID, true);
                $status = (string) get_post_meta($id, SubscriptionStore::META_STATUS, true);
                $amount = (string) get_post_meta($id, SubscriptionStore::META_AMOUNT, true);
                $currency = (string) get_post_meta($id, SubscriptionStore::META_CURRENCY, true);
                $next = (string) get_post_meta($id, SubscriptionStore::META_NEXT_BILLING, true);
                $detailUrl = esc_url(admin_url('admin.php?page=' . self::SLUG . '&subscription=' . rawurlencode($subId)));

                echo '<tr>';
                echo '<td><a href="' . $detailUrl . '">' . esc_html($post->post_title) . '</a></td>';
                echo '<td>' . esc_html($this->customerLabel($id)) . '</td>';
                echo '<td>' . esc_html($planNames[$planId] ?? $planId) . '</td>';
                echo '<td>' . $this->statusBadge($status) . '</td>';
                echo '<td>' . esc_html(self::formatDate($next)) . '</td>';
                echo '<td>' . esc_html($amount !== '' && $currency !== '' ? Money::format($amount, $currency) : '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        $this->renderPlansCard($planNames);
    }

    private function renderDetail(string $subscriptionId): void
    {
        $backUrl = esc_url(admin_url('admin.php?page=' . self::SLUG));
        echo '<p><a href="' . $backUrl . '">&larr; ' . esc_html__('All subscriptions', 'lunixi') . '</a></p>';

        $sdk = Plugin::instance()->sdk();
        $sub = null;
        if ($sdk->isReady()) {
            try {
                $sub = $sdk->client()->subscriptions()->get($subscriptionId);
            } catch (\Throwable $e) {
                echo '<div class="lunixi-notice">' . esc_html(__('Could not load subscription: ', 'lunixi') . $e->getMessage()) . '</div>';
            }
        }
        if ($sub === null) {
            return;
        }

        $status = $sub->status();
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html($sub->referenceCode() !== '' ? $sub->referenceCode() : $subscriptionId) . ' ' . $this->statusBadge($status) . '</p>';
        echo '<table class="widefat striped" style="border:0">';
        $rows = [
            __('Subscription ID', 'lunixi') => $subscriptionId,
            __('Plan', 'lunixi') => $sub->planId(),
            __('Amount', 'lunixi') => $sub->currency() !== '' ? Money::format((string) $sub->priceAmount(), $sub->currency()) : '—',
            __('Current period', 'lunixi') => self::formatDate((string) ($sub->currentPeriodStart() ?? '')) . ' – ' . self::formatDate((string) ($sub->currentPeriodEnd() ?? '')),
            __('Next billing', 'lunixi') => self::formatDate((string) ($sub->nextBillingAt() ?? '')),
            __('Collection', 'lunixi') => $sub->collectionMethod(),
        ];
        foreach ($rows as $label => $value) {
            echo '<tr><th style="text-align:left;width:30%">' . esc_html((string) $label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</table>';

        // Merchant actions.
        echo '<p style="margin-top:16px">';
        if (!in_array($status, SubscriptionStatus::TERMINAL_STATES, true)) {
            if ($status === SubscriptionStatus::PAUSED) {
                $this->actionButton($subscriptionId, 'resume', __('Resume', 'lunixi'));
            } elseif (in_array($status, SubscriptionStatus::ACTIVE_STATES, true)) {
                $this->actionButton($subscriptionId, 'pause', __('Pause', 'lunixi'));
            }
            $this->actionButton($subscriptionId, 'cancel', __('Cancel at period end', 'lunixi'), true);
        } else {
            echo '<em>' . esc_html__('This subscription is in a terminal state.', 'lunixi') . '</em>';
        }
        echo '</p></div>';

        $this->renderInvoices($subscriptionId);
    }

    private function renderInvoices(string $subscriptionId): void
    {
        $sdk = Plugin::instance()->sdk();
        if (!$sdk->isReady()) {
            return;
        }
        try {
            $list = $sdk->client()->subscriptions()->invoices()->list(['subscriptionId' => $subscriptionId, 'pageSize' => 20]);
        } catch (\Throwable $e) {
            return;
        }

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Invoices', 'lunixi') . '</p>';
        $items = $list->items();
        if ($items === []) {
            echo '<p>' . esc_html__('No invoices yet.', 'lunixi') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('Number', 'lunixi'), __('Status', 'lunixi'), __('Amount', 'lunixi'), __('Period', 'lunixi')] as $col) {
                echo '<th>' . esc_html((string) $col) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($items as $inv) {
                $amount = $inv->currency() !== '' ? Money::format((string) $inv->totalAmount(), $inv->currency()) : '—';
                echo '<tr>';
                echo '<td>' . esc_html($inv->invoiceNumber() !== '' ? $inv->invoiceNumber() : $inv->id()) . '</td>';
                echo '<td>' . $this->statusBadge($inv->status()) . '</td>';
                echo '<td>' . esc_html($amount) . '</td>';
                echo '<td>' . esc_html(self::formatDate((string) ($inv->billingPeriodStart() ?? '')) . ' – ' . self::formatDate((string) ($inv->billingPeriodEnd() ?? ''))) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private function renderPlansCard(array $planNames): void
    {
        $sdk = Plugin::instance()->sdk();
        if (!$sdk->isReady()) {
            return;
        }
        try {
            $list = $sdk->client()->subscriptions()->plans()->list(['pageSize' => 50]);
        } catch (\Throwable $e) {
            return;
        }

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Plans', 'lunixi') . '</p>';
        $items = $list->items();
        if ($items === []) {
            echo '<p>' . esc_html__('No plans yet. Create plans in your Lunixi dashboard.', 'lunixi') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('Plan', 'lunixi'), __('Code', 'lunixi'), __('Price', 'lunixi'), __('Trial', 'lunixi'), __('Active', 'lunixi')] as $col) {
                echo '<th>' . esc_html((string) $col) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($items as $plan) {
                $price = $plan->currency() !== '' ? Money::format((string) $plan->price(), $plan->currency()) : '—';
                echo '<tr>';
                echo '<td>' . esc_html($plan->name()) . '</td>';
                echo '<td>' . esc_html($plan->code()) . '</td>';
                echo '<td>' . esc_html($price) . '</td>';
                echo '<td>' . esc_html($plan->trialPeriodDays() > 0 ? sprintf(/* translators: %d days */ __('%d days', 'lunixi'), $plan->trialPeriodDays()) : '—') . '</td>';
                echo '<td>' . ($plan->isActive() ? '✓' : '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public function handleAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'lunixi'));
        }
        check_admin_referer(self::ACTION);

        $subscriptionId = isset($_POST['subscription']) ? sanitize_text_field(wp_unslash((string) $_POST['subscription'])) : '';
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
        $key = 'lunixi_sub_flash_' . get_current_user_id();

        if ($subscriptionId === '' || !in_array($op, ['pause', 'resume', 'cancel'], true)) {
            set_transient($key, ['ok' => false, 'message' => __('Invalid request.', 'lunixi')], 60);
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
            exit;
        }

        try {
            $client = Plugin::instance()->sdk()->client()->subscriptions();
            switch ($op) {
                case 'pause':
                    $client->pause($subscriptionId);
                    $msg = __('Subscription paused.', 'lunixi');
                    break;
                case 'resume':
                    $client->resume($subscriptionId);
                    $msg = __('Subscription resumed.', 'lunixi');
                    break;
                default:
                    $client->cancel($subscriptionId, true);
                    $msg = __('Subscription set to cancel at period end.', 'lunixi');
            }
            set_transient($key, ['ok' => true, 'message' => $msg], 60);
        } catch (\Throwable $e) {
            set_transient($key, ['ok' => false, 'message' => __('Action failed: ', 'lunixi') . $e->getMessage()], 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&subscription=' . rawurlencode($subscriptionId)));
        exit;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function actionButton(string $subscriptionId, string $op, string $label, bool $confirm = false): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline" '
            . ($confirm ? 'onsubmit="return confirm(\'' . esc_js(__('Are you sure?', 'lunixi')) . '\')"' : '') . '>';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';
        echo '<input type="hidden" name="op" value="' . esc_attr($op) . '" />';
        echo '<input type="hidden" name="subscription" value="' . esc_attr($subscriptionId) . '" />';
        wp_nonce_field(self::ACTION);
        echo '<button type="submit" class="button" style="margin-right:8px">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    /** @return array<string,string> planId → display name */
    private function planNameMap(): array
    {
        $sdk = Plugin::instance()->sdk();
        if (!$sdk->isReady()) {
            return [];
        }
        try {
            $list = $sdk->client()->subscriptions()->plans()->list(['pageSize' => 100]);
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($list->items() as $plan) {
            $map[$plan->id()] = $plan->name() !== '' ? $plan->name() : $plan->code();
        }
        return $map;
    }

    private function customerLabel(int $postId): string
    {
        $userId = (int) get_post_meta($postId, SubscriptionStore::META_USER_ID, true);
        if ($userId > 0) {
            $user = get_userdata($userId);
            if ($user) {
                return (string) $user->display_name . ' (' . (string) $user->user_email . ')';
            }
        }
        $customerId = (string) get_post_meta($postId, SubscriptionStore::META_CUSTOMER_ID, true);
        return $customerId !== '' ? $customerId : '—';
    }

    private function statusBadge(string $status): string
    {
        $color = '#64748B'; // neutral
        if (in_array($status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::TRIALING], true) || $status === 'PAID') {
            $color = '#10B981';
        } elseif (in_array($status, [SubscriptionStatus::PAST_DUE, SubscriptionStatus::UNPAID], true) || $status === 'PAYMENT_FAILED') {
            $color = '#F97316';
        } elseif (in_array($status, [SubscriptionStatus::CANCELED, SubscriptionStatus::EXPIRED, 'VOID'], true)) {
            $color = '#EF4444';
        }
        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;color:#fff;background:' . esc_attr($color) . '">' . esc_html($status !== '' ? $status : '—') . '</span>';
    }

    private static function formatDate(string $iso): string
    {
        if ($iso === '') {
            return '—';
        }
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        return date_i18n((string) get_option('date_format', 'Y-m-d'), $ts);
    }

    private function header(string $title, string $subtitle): void
    {
        $logo = esc_url(LUNIXI_URL . 'assets/img/logo.svg');
        echo '<div class="lunixi-header">';
        echo '<img class="lunixi-logo" src="' . $logo . '" alt="Lunixi" />';
        echo '<div class="lunixi-header-text">';
        echo '<h1 class="lunixi-title">Lunixi <span class="lunixi-accent">' . esc_html($title) . '</span></h1>';
        echo '<p class="lunixi-subtitle">' . esc_html($subtitle) . '</p>';
        echo '</div></div>';
    }

    private function flash(): void
    {
        $key = 'lunixi_sub_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (is_array($flash)) {
            delete_transient($key);
            $okClass = !empty($flash['ok']) ? ' lunixi-notice--ok' : '';
            echo '<div class="lunixi-notice' . esc_attr($okClass) . '">' . esc_html((string) ($flash['message'] ?? '')) . '</div>';
        }
    }
}
