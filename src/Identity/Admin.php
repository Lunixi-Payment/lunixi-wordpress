<?php

declare(strict_types=1);

namespace Lunixi\WP\Identity;

use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Admin surface for multi-account protection: configuration status, the
 * shared-device users flagged at sign-up, and a "Device" column on the Users
 * list. Adds an "Identity" page to the Lunixi menu via `lunixi_admin_modules`.
 */
final class Admin
{
    private const SLUG = 'lunixi-identity';

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
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
        foreach ($modules as $module) {
            if (($module['slug'] ?? '') === self::SLUG) {
                return $modules;
            }
        }
        $modules[] = [
            'slug' => self::SLUG,
            'title' => __('Identity', 'lunixi'),
            'icon' => 'dashicons-id-alt',
            'desc' => __('Detect shared-device multi-account abuse at sign-up.', 'lunixi'),
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
        $this->header(__('Identity', 'lunixi'), __('Multi-account protection · device graph', 'lunixi'));

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Status', 'lunixi') . '</p>';
        echo '<ul class="lunixi-status-list">';
        echo '<li>' . $this->dot($this->settings->identityEnabled()) . esc_html__('Enabled', 'lunixi') . '</li>';
        echo '<li>' . $this->dot($this->settings->identityPublishableKey() !== '') . esc_html__('Publishable key', 'lunixi') . '</li>';
        echo '<li>' . $this->dot(true) . sprintf(
            /* translators: %s = policy label */
            esc_html__('Policy: %s', 'lunixi'),
            esc_html($this->policyLabel($this->settings->identityPolicy()))
        ) . '</li>';
        echo '</ul>';
        echo '<p style="margin-top:14px"><a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('Configure', 'lunixi') . '</a></p>';
        echo '</div>';

        $flagged = $this->flaggedUsers();
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Shared-device accounts', 'lunixi') . '</p>';
        if ($flagged === []) {
            echo '<p>' . esc_html__('No shared-device sign-ups detected.', 'lunixi') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ([__('User', 'lunixi'), __('Accounts on device', 'lunixi'), __('Reasons', 'lunixi'), __('Registered', 'lunixi')] as $col) {
                echo '<th>' . esc_html((string) $col) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($flagged as $user) {
                $count = (int) get_user_meta($user->ID, '_lunixi_identity_account_count', true);
                $reasons = get_user_meta($user->ID, '_lunixi_identity_reasons', true);
                $reasons = is_array($reasons) ? implode(', ', array_map('strval', $reasons)) : '';
                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_user_link($user->ID)) . '">' . esc_html($user->user_login) . '</a></td>';
                echo '<td>' . esc_html((string) $count) . '</td>';
                echo '<td>' . esc_html($reasons !== '' ? $reasons : '—') . '</td>';
                echo '<td>' . esc_html((string) $user->user_registered) . '</td>';
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
        $columns['lunixi_identity'] = __('Device', 'lunixi');
        return $columns;
    }

    /**
     * @param string $output
     * @param string $column
     * @param int    $userId
     */
    public function usersColumnValue($output, $column, $userId): string
    {
        if ($column !== 'lunixi_identity') {
            return (string) $output;
        }
        if (get_user_meta($userId, '_lunixi_identity_flagged', true) === '1') {
            $count = (int) get_user_meta($userId, '_lunixi_identity_account_count', true);
            return '<span class="lunixi-dot lunixi-dot--off"></span> ' . esc_html(sprintf(
                /* translators: %d = number of accounts sharing the device */
                _n('%d account', '%d accounts', $count, 'lunixi'),
                $count
            ));
        }
        return '<span class="lunixi-dot lunixi-dot--ok"></span>';
    }

    /** @return array<int,\WP_User> */
    private function flaggedUsers(): array
    {
        $query = new \WP_User_Query([
            'meta_key' => '_lunixi_identity_flagged',
            'meta_value' => '1',
            'number' => 100,
            'orderby' => 'registered',
            'order' => 'DESC',
        ]);
        $results = $query->get_results();
        return is_array($results) ? $results : [];
    }

    private function policyLabel(string $policy): string
    {
        $labels = [
            'off' => __('Record only', 'lunixi'),
            'flag' => __('Flag for review', 'lunixi'),
            'block' => __('Block sign-up', 'lunixi'),
        ];
        return $labels[$policy] ?? $policy;
    }

    private function dot(bool $ok): string
    {
        return '<span class="lunixi-dot ' . ($ok ? 'lunixi-dot--ok' : 'lunixi-dot--off') . '"></span>';
    }

    private function header(string $title, string $subtitle): void
    {
        $logo = esc_url(LUNIXI_URL . 'assets/img/logo.svg');
        echo '<div class="lunixi-header"><img class="lunixi-logo" src="' . $logo . '" alt="Lunixi" /><div class="lunixi-header-text">';
        echo '<h1 class="lunixi-title">Lunixi <span class="lunixi-accent">' . esc_html($title) . '</span></h1>';
        echo '<p class="lunixi-subtitle">' . esc_html($subtitle) . '</p></div></div>';
    }
}
