<?php

declare(strict_types=1);

namespace Lunixi\WP\Marketplace;

use Lunixi\Sdk\Marketplace\CreateDealerRequest;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Admin surface for the marketplace (alt bayi): create + list sub-dealers, mint
 * self-onboarding links, link a dealer to a WP (vendor) user, and view the payout
 * ledger. Adds a "Marketplace" page to the Lunixi menu via `lunixi_admin_modules`.
 */
final class Admin
{
    private const SLUG = 'lunixi-marketplace';
    private const ACTION = 'lunixi_marketplace_action';

    private VendorStore $vendors;

    public function __construct(VendorStore $vendors)
    {
        $this->vendors = $vendors;
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
        foreach ($modules as $module) {
            if (($module['slug'] ?? '') === self::SLUG) {
                return $modules; // already present
            }
        }
        $modules[] = [
            'slug' => self::SLUG,
            'title' => __('Marketplace', 'lunixi'),
            'icon' => 'dashicons-networking',
            'desc' => __('Sub-dealer (altbayi) split payments, onboarding and payouts.', 'lunixi'),
            'ready' => true,
            'callback' => [$this, 'render'],
        ];
        return $modules;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap lunixi-admin">';
        $this->header(__('Marketplace', 'lunixi'), __('Sub-dealers · onboarding · payouts', 'lunixi'));
        $this->flash();

        if (!Plugin::instance()->sdk()->isReady()) {
            echo '<div class="lunixi-notice">' . sprintf(
                /* translators: %s = settings link */
                esc_html__('Connect your store to manage sub-dealers — %s.', 'lunixi'),
                '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('add API credentials', 'lunixi') . '</a>'
            ) . '</div></div>';
            return;
        }

        $this->renderCreate();
        $this->renderDealers();
        $this->renderPayouts();
        echo '</div>';
    }

    private function renderCreate(): void
    {
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Add sub-dealer', 'lunixi') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" /><input type="hidden" name="op" value="create" />';
        wp_nonce_field(self::ACTION);
        echo '<p class="lunixi-field"><input type="text" name="legal_name" placeholder="' . esc_attr__('Legal name', 'lunixi') . '" style="min-width:220px" required /> ';
        echo '<input type="text" name="tax_id" placeholder="' . esc_attr__('Tax ID', 'lunixi') . '" style="width:140px" /> ';
        echo '<input type="email" name="email" placeholder="' . esc_attr__('Email', 'lunixi') . '" /> ';
        echo '<select name="dealer_type"><option value="LEGAL_ENTITY">' . esc_html__('Company', 'lunixi') . '</option><option value="INDIVIDUAL">' . esc_html__('Individual', 'lunixi') . '</option></select></p>';
        echo '<p class="lunixi-field"><label>' . esc_html__('Link to WP user (vendor):', 'lunixi') . '</label> ';
        wp_dropdown_users(['name' => 'link_user', 'show_option_none' => __('— None —', 'lunixi'), 'option_none_value' => '0']);
        echo '</p>';
        echo '<button type="submit" class="button-primary">' . esc_html__('Create sub-dealer', 'lunixi') . '</button>';
        echo '</form></div>';
    }

    private function renderDealers(): void
    {
        try {
            $dealers = Plugin::instance()->sdk()->client()->marketplace()->listDealers(['limit' => 100]);
        } catch (\Throwable $e) {
            $dealers = [];
        }

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Sub-dealers', 'lunixi') . '</p>';
        if ($dealers === []) {
            echo '<p>' . esc_html__('No sub-dealers yet.', 'lunixi') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('Name', 'lunixi'), __('Status', 'lunixi'), __('WP user', 'lunixi'), __('Onboarding', 'lunixi')] as $col) {
                echo '<th>' . esc_html((string) $col) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($dealers as $dealer) {
                $userId = $this->vendors->userForDealer($dealer->id());
                $userLabel = $userId !== null && get_userdata($userId) ? get_userdata($userId)->user_login : '—';
                echo '<tr>';
                echo '<td>' . esc_html($dealer->legalName() !== '' ? $dealer->legalName() : $dealer->id()) . '</td>';
                echo '<td>' . self::badge($dealer->status()) . '</td>';
                echo '<td>' . esc_html((string) $userLabel) . '</td>';
                echo '<td>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" /><input type="hidden" name="op" value="onboarding" /><input type="hidden" name="dealer_id" value="' . esc_attr($dealer->id()) . '" />';
                wp_nonce_field(self::ACTION);
                echo '<button type="submit" class="button">' . esc_html__('Get link', 'lunixi') . '</button>';
                echo '</form>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private function renderPayouts(): void
    {
        try {
            $payouts = Plugin::instance()->sdk()->client()->marketplace()->payoutLedger(['limit' => 25]);
        } catch (\Throwable $e) {
            return;
        }
        if ($payouts === []) {
            return;
        }
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Payout ledger', 'lunixi') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Dealer', 'lunixi'), __('Net', 'lunixi'), __('Status', 'lunixi'), __('Valor', 'lunixi')] as $col) {
            echo '<th>' . esc_html((string) $col) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($payouts as $p) {
            echo '<tr>';
            echo '<td style="word-break:break-all">' . esc_html((string) ($p['subDealerId'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) ($p['netMinor'] ?? '—')) . '</td>';
            echo '<td>' . self::badge((string) ($p['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($p['valorDate'] ?? '—')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handleAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'lunixi'));
        }
        check_admin_referer(self::ACTION);

        $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
        $key = 'lunixi_mp_flash_' . get_current_user_id();

        try {
            $client = Plugin::instance()->sdk()->client()->marketplace();
            if ($op === 'create') {
                $name = isset($_POST['legal_name']) ? sanitize_text_field(wp_unslash((string) $_POST['legal_name'])) : '';
                if ($name !== '') {
                    $req = new CreateDealerRequest($name);
                    $taxId = isset($_POST['tax_id']) ? sanitize_text_field(wp_unslash((string) $_POST['tax_id'])) : '';
                    if ($taxId !== '') {
                        $req->withTaxId($taxId);
                    }
                    $email = isset($_POST['email']) ? sanitize_email(wp_unslash((string) $_POST['email'])) : '';
                    if ($email !== '') {
                        $req->withEmail($email);
                    }
                    $type = isset($_POST['dealer_type']) ? sanitize_text_field(wp_unslash((string) $_POST['dealer_type'])) : '';
                    if (in_array($type, [CreateDealerRequest::TYPE_LEGAL_ENTITY, CreateDealerRequest::TYPE_INDIVIDUAL], true)) {
                        $req->withDealerType($type);
                    }
                    $linkUser = isset($_POST['link_user']) ? (int) $_POST['link_user'] : 0;
                    if ($linkUser > 0) {
                        $req->withExternalRef((string) $linkUser);
                    }

                    $dealer = $client->createDealer($req);
                    if ($linkUser > 0 && $dealer->id() !== '') {
                        $this->vendors->linkUserDealer($linkUser, $dealer->id());
                    }
                    set_transient($key, ['ok' => true, 'message' => __('Sub-dealer created.', 'lunixi')], 60);
                }
            } elseif ($op === 'onboarding') {
                $dealerId = isset($_POST['dealer_id']) ? sanitize_text_field(wp_unslash((string) $_POST['dealer_id'])) : '';
                if ($dealerId !== '') {
                    $link = $client->onboardingToken($dealerId);
                    set_transient($key, ['ok' => true, 'message' => __('Onboarding link:', 'lunixi') . ' ' . $link->url()], 120);
                }
            }
        } catch (\Throwable $e) {
            set_transient($key, ['ok' => false, 'message' => __('Action failed: ', 'lunixi') . $e->getMessage()], 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private static function badge(string $value): string
    {
        $color = '#64748B';
        if (in_array($value, ['ACTIVE', 'PAID'], true)) {
            $color = '#10B981';
        } elseif (in_array($value, ['PENDING_KYC', 'DRAFT', 'PENDING', 'PLANNED', 'HOLD', 'HELD'], true)) {
            $color = '#F97316';
        } elseif (in_array($value, ['SUSPENDED', 'CLOSED', 'FAILED', 'CANCELLED'], true)) {
            $color = '#EF4444';
        }
        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;color:#fff;background:' . esc_attr($color) . '">' . esc_html($value !== '' ? $value : '—') . '</span>';
    }

    private function header(string $title, string $subtitle): void
    {
        $logo = esc_url(LUNIXI_URL . 'assets/img/logo.svg');
        echo '<div class="lunixi-header"><img class="lunixi-logo" src="' . $logo . '" alt="Lunixi" /><div class="lunixi-header-text">';
        echo '<h1 class="lunixi-title">Lunixi <span class="lunixi-accent">' . esc_html($title) . '</span></h1>';
        echo '<p class="lunixi-subtitle">' . esc_html($subtitle) . '</p></div></div>';
    }

    private function flash(): void
    {
        $key = 'lunixi_mp_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (is_array($flash)) {
            delete_transient($key);
            $okClass = !empty($flash['ok']) ? ' lunixi-notice--ok' : '';
            echo '<div class="lunixi-notice' . esc_attr($okClass) . '">' . esc_html((string) ($flash['message'] ?? '')) . '</div>';
        }
    }
}
