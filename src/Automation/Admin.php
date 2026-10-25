<?php

declare(strict_types=1);

namespace Lunixi\WP\Automation;

use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Admin surface for the automation bridge: configuration status, the events the
 * plugin forwards, and a "Check connection" probe against the ingress. Adds an
 * "Automation" page to the Lunixi menu via `lunixi_admin_modules`.
 */
final class Admin
{
    private const SLUG = 'lunixi-automation';
    private const ACTION = 'lunixi_automation_action';

    private AutomationBridge $bridge;
    private Settings $settings;

    public function __construct(AutomationBridge $bridge, Settings $settings)
    {
        $this->bridge = $bridge;
        $this->settings = $settings;
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
                return $modules;
            }
        }
        $modules[] = [
            'slug' => self::SLUG,
            'title' => __('Automation', 'lunixi'),
            'icon' => 'dashicons-controls-repeat',
            'desc' => __('Forward WooCommerce events to your Lunixi automation flows.', 'lunixi'),
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
        $this->header(__('Automation', 'lunixi'), __('WooCommerce → Lunixi event bridge', 'lunixi'));
        $this->flash();

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Status', 'lunixi') . '</p>';
        echo '<ul class="lunixi-status-list">';
        echo '<li>' . $this->dot($this->settings->automationEnabled()) . esc_html__('Enabled', 'lunixi') . '</li>';
        echo '<li>' . $this->dot($this->settings->automationIntegrationId() !== '') . esc_html__('Integration ID', 'lunixi') . '</li>';
        echo '<li>' . $this->dot($this->settings->automationSecret() !== '') . esc_html__('Shared secret', 'lunixi') . '</li>';
        echo '</ul>';

        echo '<p style="margin-top:14px">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" /><input type="hidden" name="op" value="health" />';
        wp_nonce_field(self::ACTION);
        echo '<button type="submit" class="button-primary"' . ($this->settings->automationIntegrationId() !== '' ? '' : ' disabled') . '>' . esc_html__('Check connection', 'lunixi') . '</button>';
        echo '</form> ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('Configure', 'lunixi') . '</a>';
        echo '</p></div>';

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Forwarded events', 'lunixi') . '</p>';
        echo '<p>order.created · order.paid · order.cancelled · customer.created · product.low_stock · form.submitted</p>';
        echo '<p class="description">' . esc_html__('Fire a custom event from your code: lunixi_automation_send($event, $data). KYC and subscription events reach automation directly on the platform — no need to forward them here.', 'lunixi') . '</p>';
        echo '</div></div>';
    }

    public function handleAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'lunixi'));
        }
        check_admin_referer(self::ACTION);

        $key = 'lunixi_auto_flash_' . get_current_user_id();
        if ((isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '') === 'health') {
            $result = $this->bridge->health();
            $msg = !empty($result['ok'])
                ? __('Connection OK — the automation site is reachable.', 'lunixi')
                : sprintf(/* translators: %s = message */ __('Connection check failed: %s', 'lunixi'), (string) ($result['message'] ?? ''));
            set_transient($key, ['ok' => !empty($result['ok']), 'message' => $msg], 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
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

    private function flash(): void
    {
        $key = 'lunixi_auto_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (is_array($flash)) {
            delete_transient($key);
            $okClass = !empty($flash['ok']) ? ' lunixi-notice--ok' : '';
            echo '<div class="lunixi-notice' . esc_attr($okClass) . '">' . esc_html((string) ($flash['message'] ?? '')) . '</div>';
        }
    }
}
