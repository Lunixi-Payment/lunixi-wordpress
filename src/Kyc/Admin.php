<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Admin surface for KYC (Lunixi-panel styled): recent verification sessions +
 * a per-user verification-state column on the Users screen. Registers itself as
 * the "KYC" module page through the `lunixi_admin_modules` filter.
 */
final class Admin
{
    private const SLUG = 'lunixi-kyc';

    private VerificationStore $store;

    public function __construct(VerificationStore $store)
    {
        $this->store = $store;
    }

    public function register(): void
    {
        add_filter('lunixi_admin_modules', [$this, 'registerModulePage']);
        add_filter('manage_users_columns', [$this, 'usersColumn']);
        add_filter('manage_users_custom_column', [$this, 'usersColumnValue'], 10, 3);
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
        echo '<div class="wrap lunixi-admin">';
        $this->header(__('KYC', 'lunixi'), __('Identity verification · sessions', 'lunixi'));

        if (!Plugin::instance()->sdk()->isReady()) {
            echo '<div class="lunixi-notice">' . sprintf(
                /* translators: %s = settings link */
                esc_html__('Connect your store to view verification sessions — %s.', 'lunixi'),
                '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('add API credentials', 'lunixi') . '</a>'
            ) . '</div></div>';
            return;
        }

        try {
            $list = Plugin::instance()->sdk()->client()->kyc()->listSessions(['pageSize' => 50]);
            $items = $list->items();
        } catch (\Throwable $e) {
            $items = [];
        }

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Recent sessions', 'lunixi') . '</p>';
        if ($items === []) {
            echo '<p>' . esc_html__('No verification sessions yet.', 'lunixi') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('Session', 'lunixi'), __('Type', 'lunixi'), __('Status', 'lunixi'), __('Decision', 'lunixi'), __('User', 'lunixi'), __('Expires', 'lunixi')] as $col) {
                echo '<th>' . esc_html((string) $col) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($items as $s) {
                echo '<tr>';
                echo '<td style="word-break:break-all">' . esc_html($s->sessionId()) . '</td>';
                echo '<td>' . esc_html($s->sessionType()) . '</td>';
                echo '<td>' . wp_kses_post(self::badge($s->status())) . '</td>';
                echo '<td>' . esc_html($s->decision() !== '' ? $s->decision() : '—') . '</td>';
                echo '<td>' . esc_html(self::userLabel($s->externalCustomerId())) . '</td>';
                echo '<td>' . esc_html((string) ($s->expiresAt() ?? '—')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div>';
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function usersColumn(array $columns): array
    {
        $columns['lunixi_kyc'] = __('KYC', 'lunixi');
        return $columns;
    }

    /**
     * @param string $output
     * @param string $column
     * @param int    $userId
     */
    public function usersColumnValue($output, $column, $userId): string
    {
        if ($column !== 'lunixi_kyc') {
            return (string) $output;
        }
        $state = $this->store->state((int) $userId);
        return self::badge($state);
    }

    private static function userLabel(string $externalCustomerId): string
    {
        if ($externalCustomerId !== '' && ctype_digit($externalCustomerId)) {
            $user = get_userdata((int) $externalCustomerId);
            if ($user) {
                return (string) $user->user_login;
            }
        }
        return $externalCustomerId !== '' ? $externalCustomerId : '—';
    }

    private static function badge(string $value): string
    {
        $color = '#64748B';
        $green = ['APPROVED', VerificationStore::STATE_VERIFIED];
        $amber = ['MANUAL_REVIEW', 'PROCESSING', VerificationStore::STATE_IN_PROGRESS, VerificationStore::STATE_PENDING_RESCREEN];
        $red = ['REJECTED', VerificationStore::STATE_REJECTED, VerificationStore::STATE_SUSPENDED, VerificationStore::STATE_REVOKED, VerificationStore::STATE_EXPIRED];
        if (in_array($value, $green, true)) {
            $color = '#10B981';
        } elseif (in_array($value, $amber, true)) {
            $color = '#F97316';
        } elseif (in_array($value, $red, true)) {
            $color = '#EF4444';
        }
        if ($value === '' || $value === VerificationStore::STATE_NONE) {
            return '<span style="color:#94A3B8">—</span>';
        }
        return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;color:#fff;background:' . esc_attr($color) . '">' . esc_html($value) . '</span>';
    }

    private function header(string $title, string $subtitle): void
    {
        $logo = LUNIXI_URL . 'assets/img/logo.svg';
        echo '<div class="lunixi-header">';
        echo '<img class="lunixi-logo" src="' . esc_url($logo) . '" alt="Lunixi" />';
        echo '<div class="lunixi-header-text">';
        echo '<h1 class="lunixi-title">Lunixi <span class="lunixi-accent">' . esc_html($title) . '</span></h1>';
        echo '<p class="lunixi-subtitle">' . esc_html($subtitle) . '</p>';
        echo '</div></div>';
    }
}
