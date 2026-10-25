<?php

declare(strict_types=1);

namespace Lunixi\WP\Sanction;

use Lunixi\Sdk\Sanction\ScreenMode;
use Lunixi\Sdk\Sanction\ScreenNameRequest;
use Lunixi\Sdk\Sanction\SubjectType;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Admin surface for sanctions/watchlist screening (Lunixi-panel styled): an
 * on-demand name screen, the merchant deny list, and ongoing-monitoring alerts.
 * Registers itself as the "Sanction" module page via `lunixi_admin_modules`.
 */
final class Admin
{
    private const SLUG = 'lunixi-sanction';
    private const ACTION = 'lunixi_sanction_action';

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
        echo '<div class="wrap lunixi-admin">';
        $this->header(__('Sanction', 'lunixi'), __('Watchlist screening · monitoring · deny list', 'lunixi'));
        $this->flash();

        if (!Plugin::instance()->sdk()->isReady()) {
            echo '<div class="lunixi-notice">' . sprintf(
                /* translators: %s = settings link */
                esc_html__('Connect your store to screen entities — %s.', 'lunixi'),
                '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('add API credentials', 'lunixi') . '</a>'
            ) . '</div></div>';
            return;
        }

        $this->renderScreenForm();
        $this->renderAlerts();
        $this->renderDenyList();
        echo '</div>';
    }

    private function renderScreenForm(): void
    {
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Screen a name', 'lunixi') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" /><input type="hidden" name="op" value="screen" />';
        wp_nonce_field(self::ACTION);
        echo '<p class="lunixi-field"><input type="text" name="name" placeholder="' . esc_attr__('Full name', 'lunixi') . '" style="min-width:240px" required /> ';
        echo '<input type="text" name="country" placeholder="' . esc_attr__('Country (ISO)', 'lunixi') . '" style="width:120px" /> ';
        echo '<select name="subject_type"><option value="person">' . esc_html__('Person', 'lunixi') . '</option><option value="organization">' . esc_html__('Organization', 'lunixi') . '</option></select> ';
        echo '<select name="mode">';
        foreach ([ScreenMode::SANCTIONS_ONLY => __('Sanctions', 'lunixi'), ScreenMode::SANCTIONS_PEP => __('Sanctions + PEP', 'lunixi'), ScreenMode::FULL_RISK => __('Full risk', 'lunixi')] as $val => $label) {
            echo '<option value="' . esc_attr($val) . '">' . esc_html($label) . '</option>';
        }
        echo '</select> <button type="submit" class="button-primary">' . esc_html__('Screen', 'lunixi') . '</button></p>';
        echo '</form>';

        $result = get_transient('lunixi_sanction_result_' . get_current_user_id());
        if (is_array($result)) {
            delete_transient('lunixi_sanction_result_' . get_current_user_id());
            $matches = isset($result['matches']) && is_array($result['matches']) ? $result['matches'] : [];
            echo '<p><strong>' . esc_html(sprintf(/* translators: %s = name */ __('Result for "%s":', 'lunixi'), (string) ($result['name'] ?? ''))) . '</strong> ';
            echo $matches === []
                ? '<span style="color:#10B981;font-weight:600">' . esc_html__('CLEAR — no matches', 'lunixi') . '</span></p>'
                : '<span style="color:#EF4444;font-weight:600">' . esc_html(sprintf(/* translators: %d = count */ __('%d potential match(es)', 'lunixi'), count($matches))) . '</span></p>';
            if ($matches !== []) {
                echo '<table class="widefat striped"><thead><tr>';
                foreach ([__('Matched name', 'lunixi'), __('List', 'lunixi'), __('Type', 'lunixi'), __('Score', 'lunixi')] as $col) {
                    echo '<th>' . esc_html((string) $col) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ($matches as $m) {
                    echo '<tr>';
                    echo '<td>' . esc_html((string) ($m['matchedName'] ?? '—')) . '</td>';
                    echo '<td>' . esc_html((string) ($m['listCode'] ?? '—')) . '</td>';
                    echo '<td>' . esc_html((string) ($m['hitType'] ?? '—')) . '</td>';
                    echo '<td>' . esc_html(number_format((float) ($m['score'] ?? 0) * 100, 0) . '%') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
        echo '</div>';
    }

    private function renderAlerts(): void
    {
        try {
            $alerts = Plugin::instance()->sdk()->client()->sanction()->monitoringAlerts(['status' => 'open', 'limit' => 25]);
        } catch (\Throwable $e) {
            return;
        }
        if ($alerts === []) {
            return;
        }
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Monitoring alerts', 'lunixi') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([__('Severity', 'lunixi'), __('Title', 'lunixi'), __('Reason', 'lunixi'), __('Status', 'lunixi')] as $col) {
            echo '<th>' . esc_html((string) $col) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($alerts as $a) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($a['severity'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) ($a['title'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) ($a['reason'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) ($a['status'] ?? '—')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private function renderDenyList(): void
    {
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Deny list', 'lunixi') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" /><input type="hidden" name="op" value="deny" />';
        wp_nonce_field(self::ACTION);
        echo '<select name="deny_type" style="margin-right:6px"><option value="person">' . esc_html__('Person', 'lunixi') . '</option><option value="organization">' . esc_html__('Organization', 'lunixi') . '</option></select>';
        echo '<input type="text" name="deny_name" placeholder="' . esc_attr__('Name to block', 'lunixi') . '" style="min-width:220px;margin-right:6px" />';
        echo '<button type="submit" class="button">' . esc_html__('Add to deny list', 'lunixi') . '</button>';
        echo '</form>';

        try {
            $entries = Plugin::instance()->sdk()->client()->sanction()->listBlacklist(['limit' => 100]);
        } catch (\Throwable $e) {
            $entries = [];
        }
        if ($entries === []) {
            echo '<p>' . esc_html__('No deny-list entries.', 'lunixi') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Type', 'lunixi') . '</th><th>' . esc_html__('Label', 'lunixi') . '</th><th>' . esc_html__('Reason', 'lunixi') . '</th></tr></thead><tbody>';
            foreach ($entries as $e) {
                echo '<tr><td>' . esc_html((string) ($e['type'] ?? '—')) . '</td><td>' . esc_html((string) ($e['label'] ?? '—')) . '</td><td>' . esc_html((string) ($e['reason'] ?? '—')) . '</td></tr>';
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

        $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
        $flash = 'lunixi_sanction_flash_' . get_current_user_id();

        try {
            $client = Plugin::instance()->sdk()->client()->sanction();
            if ($op === 'screen') {
                $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
                if ($name !== '') {
                    $req = new ScreenNameRequest($name);
                    $country = isset($_POST['country']) ? sanitize_text_field(wp_unslash((string) $_POST['country'])) : '';
                    if ($country !== '') {
                        $req->withCountry($country);
                    }
                    $subjectType = isset($_POST['subject_type']) ? sanitize_key(wp_unslash((string) $_POST['subject_type'])) : SubjectType::PERSON;
                    if (in_array($subjectType, SubjectType::ALL, true)) {
                        $req->withSubjectType($subjectType);
                    }
                    $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash((string) $_POST['mode'])) : '';
                    if (in_array($mode, ScreenMode::ALL, true)) {
                        $req->withMode($mode);
                    }
                    $result = $client->screenName($req);
                    $matches = array_map(static fn ($m) => $m->raw(), $result->matches());
                    set_transient('lunixi_sanction_result_' . get_current_user_id(), ['name' => $name, 'matches' => $matches], 60);
                }
            } elseif ($op === 'deny') {
                $type = isset($_POST['deny_type']) ? sanitize_key(wp_unslash((string) $_POST['deny_type'])) : 'person';
                $name = isset($_POST['deny_name']) ? sanitize_text_field(wp_unslash((string) $_POST['deny_name'])) : '';
                if ($name !== '') {
                    $client->addBlacklist($type, $name, ['reason' => 'wordpress', 'addedBy' => (string) get_current_user_id()]);
                    set_transient($flash, ['ok' => true, 'message' => __('Added to deny list.', 'lunixi')], 60);
                }
            }
        } catch (\Throwable $e) {
            set_transient($flash, ['ok' => false, 'message' => __('Action failed: ', 'lunixi') . $e->getMessage()], 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private function header(string $title, string $subtitle): void
    {
        $logo = LUNIXI_URL . 'assets/img/logo.svg';
        echo '<div class="lunixi-header"><img class="lunixi-logo" src="' . esc_url($logo) . '" alt="Lunixi" /><div class="lunixi-header-text">';
        echo '<h1 class="lunixi-title">Lunixi <span class="lunixi-accent">' . esc_html($title) . '</span></h1>';
        echo '<p class="lunixi-subtitle">' . esc_html($subtitle) . '</p></div></div>';
    }

    private function flash(): void
    {
        $key = 'lunixi_sanction_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (is_array($flash)) {
            delete_transient($key);
            $okClass = !empty($flash['ok']) ? ' lunixi-notice--ok' : '';
            echo '<div class="lunixi-notice' . esc_attr($okClass) . '">' . esc_html((string) ($flash['message'] ?? '')) . '</div>';
        }
    }
}
