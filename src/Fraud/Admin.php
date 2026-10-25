<?php

declare(strict_types=1);

namespace Lunixi\WP\Fraud;

use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Admin surface for fraud (Lunixi-panel styled): recent decisions. Registers
 * itself as the "Fraud" module page through the `lunixi_admin_modules` filter.
 *
 * 🔴 DENY-LIST YUZEYI KALDIRILDI. Iki bagimsiz sebep, ikisi de olculdu:
 *   1. SDK 0.11.0 `Fraud\BlacklistClient`i kaldirdi — liste yonetimi musteri API
 *      yuzeyinin parcasi degil (panel rotasi).
 *   2. Gateway o rotayi makine anahtarina KAPATTI (`FraudSurfaceGuard`): rota
 *      `@FraudApiSurface()` tasimadigi icin API anahtariyla cagrilamaz.
 * Yani yuzey iki yonden birden olu. Liste yonetimi fraud konsolunda yapilir.
 */
final class Admin
{
    private const SLUG = 'lunixi-fraud';

    public function register(): void
    {
        add_filter('lunixi_admin_modules', [$this, 'registerModulePage']);
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
        $this->header(__('Fraud', 'lunixi'), __('Recent fraud decisions', 'lunixi'));

        if (!Plugin::instance()->sdk()->isReady()) {
            echo '<div class="lunixi-notice">' . sprintf(
                /* translators: %s = settings link */
                esc_html__('Connect your store to view fraud insights — %s.', 'lunixi'),
                '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('add API credentials', 'lunixi') . '</a>'
            ) . '</div></div>';
            return;
        }

        $this->renderDecisions();
        echo '</div>';
    }

    private function renderDecisions(): void
    {
        try {
            $list = Plugin::instance()->sdk()->client()->fraud()->decisionLogs(['limit' => 25]);
        } catch (\Throwable $e) {
            return;
        }

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Recent decisions', 'lunixi') . '</p>';
        $items = $list->items();
        if ($items === []) {
            echo '<p>' . esc_html__('No fraud decisions yet.', 'lunixi') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('Decision', 'lunixi'), __('Score', 'lunixi'), __('Payment', 'lunixi'), __('Reasons', 'lunixi'), __('Trace', 'lunixi')] as $col) {
                echo '<th>' . esc_html((string) $col) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($items as $d) {
                echo '<tr>';
                echo '<td>' . OrderInsights::badge($d->decision()) . '</td>';
                echo '<td>' . esc_html((string) $d->score()) . '</td>';
                echo '<td style="word-break:break-all">' . esc_html($d->paymentId() !== '' ? $d->paymentId() : '—') . '</td>';
                echo '<td>' . esc_html(implode(', ', $d->reasonCodes())) . '</td>';
                echo '<td style="color:#64748B;font-size:11px;word-break:break-all">' . esc_html($d->traceId()) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

}
