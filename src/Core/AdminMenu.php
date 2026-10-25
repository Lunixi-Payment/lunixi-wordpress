<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

defined('ABSPATH') || exit;

/**
 * Owns the "Lunixi" admin menu and its service sub-pages (the left side menu),
 * mirroring the Lunixi panel: Dashboard, Payments, Orchestration, Fraud,
 * Identity, Sanction, Subscription, KYC, Settings. Built modules render their
 * own page; the rest show a branded "coming soon" placeholder so the structure
 * is visible. Every page shares the Lunixi-branded chrome.
 */
final class AdminMenu
{
    private const SLUG = 'lunixi';

    private Settings $settings;
    private SdkFactory $sdk;

    public function __construct(Settings $settings, SdkFactory $sdk)
    {
        $this->settings = $settings;
        $this->sdk = $sdk;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'buildMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_lunixi_test_connection', [$this, 'handleTestConnection']);
    }

    public function enqueueAssets(string $hook): void
    {
        if (strpos($hook, self::SLUG) === false) {
            return;
        }
        wp_enqueue_style('lunixi-admin', LUNIXI_URL . 'assets/css/admin.css', [], LUNIXI_VERSION);
    }

    public function buildMenu(): void
    {
        add_menu_page(
            __('Lunixi', 'lunixi'),
            __('Lunixi', 'lunixi'),
            'manage_options',
            self::SLUG,
            [$this, 'renderDashboard'],
            $this->menuIcon(),
            58
        );

        add_submenu_page(self::SLUG, __('Dashboard', 'lunixi'), __('Dashboard', 'lunixi'), 'manage_options', self::SLUG, [$this, 'renderDashboard']);

        foreach ($this->modules() as $module) {
            $callback = $module['ready']
                ? $module['callback']
                : [$this, 'renderComingSoon'];
            add_submenu_page(self::SLUG, $module['title'], $module['title'], 'manage_options', $module['slug'], $callback);
        }

        add_submenu_page(self::SLUG, __('Settings', 'lunixi'), __('Settings', 'lunixi'), 'manage_options', 'lunixi-settings', [$this->settings, 'renderPage']);
    }

    /**
     * The service modules in panel order. `ready` modules supply a callback;
     * the rest fall through to the coming-soon placeholder.
     *
     * @return array<int,array{slug:string,title:string,icon:string,desc:string,ready:bool,callback?:callable}>
     */
    private function modules(): array
    {
        $modules = [
            ['slug' => 'lunixi-payments', 'title' => __('Payments', 'lunixi'), 'icon' => 'dashicons-cart', 'desc' => __('Card checkout, 3-D Secure, instalments and refunds via WooCommerce.', 'lunixi'), 'ready' => true, 'callback' => [$this, 'renderPayments']],
            // NOTE: "Orchestration" (smart routing across providers) is intentionally NOT a
            // WP module — it has no standalone backend. The routing engine lives inside
            // payment-service (routing-v2, /payments/merchant/routing/*) and is operated
            // from the Lunixi panel. See docs/AUTOMATION-INTEGRATION-CONTRACT.md decisions.
            ['slug' => 'lunixi-fraud', 'title' => __('Fraud', 'lunixi'), 'icon' => 'dashicons-shield', 'desc' => __('Real-time fraud signals on checkout and login.', 'lunixi'), 'ready' => false],
            ['slug' => 'lunixi-identity', 'title' => __('Identity', 'lunixi'), 'icon' => 'dashicons-id', 'desc' => __('Identity verification for sign-up and checkout.', 'lunixi'), 'ready' => false],
            ['slug' => 'lunixi-sanction', 'title' => __('Sanction', 'lunixi'), 'icon' => 'dashicons-search', 'desc' => __('Sanctions / watchlist screening.', 'lunixi'), 'ready' => false],
            ['slug' => 'lunixi-subscription', 'title' => __('Subscription', 'lunixi'), 'icon' => 'dashicons-update', 'desc' => __('Recurring billing and subscriptions.', 'lunixi'), 'ready' => false],
            ['slug' => 'lunixi-kyc', 'title' => __('KYC', 'lunixi'), 'icon' => 'dashicons-id-alt', 'desc' => __('Customer onboarding and KYC.', 'lunixi'), 'ready' => false],
        ];

        /** Modules can register/override their own admin page. */
        return apply_filters('lunixi_admin_modules', $modules);
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public function renderDashboard(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap lunixi-admin">';
        $this->header(__('Overview', 'lunixi'), __('Payments · Fraud · Subscription · Identity · Sanction · KYC', 'lunixi'));

        if (!$this->settings->isConfigured()) {
            echo '<div class="lunixi-notice">' . sprintf(
                /* translators: %s = link to the settings page */
                esc_html__('Connect your store to get started — %s.', 'lunixi'),
                '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('enter your API credentials', 'lunixi') . '</a>'
            ) . '</div>';
        }

        echo '<div class="lunixi-grid">';
        foreach ($this->modules() as $module) {
            $url = admin_url('admin.php?page=' . $module['slug']);
            $pill = $module['ready']
                ? '<span class="lunixi-pill lunixi-pill--live">' . esc_html__('Ready', 'lunixi') . '</span>'
                : '<span class="lunixi-pill lunixi-pill--soon">' . esc_html__('Soon', 'lunixi') . '</span>';
            echo '<a class="lunixi-module-card" href="' . esc_url($url) . '">';
            echo '<div class="lunixi-module-top"><span class="lunixi-module-icon dashicons ' . esc_attr($module['icon']) . '"></span>' . wp_kses_post($pill) . '</div>';
            echo '<div class="lunixi-module-title">' . esc_html($module['title']) . '</div>';
            echo '<div class="lunixi-module-desc">' . esc_html($module['desc']) . '</div>';
            echo '</a>';
        }
        echo '</div></div>';
    }

    public function renderPayments(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap lunixi-admin">';
        $this->header(__('Payments', 'lunixi'), __('WooCommerce card gateway · 3-D Secure · refunds', 'lunixi'));

        $configured = $this->settings->isConfigured();
        $wcActive = class_exists('WooCommerce');

        // Result of a "Test connection" run (set just before the redirect back here).
        $resultKey = 'lunixi_conn_' . get_current_user_id();
        $result = get_transient($resultKey);
        if (is_array($result)) {
            delete_transient($resultKey);
            $okClass = !empty($result['ok']) ? ' lunixi-notice--ok' : '';
            echo '<div class="lunixi-notice' . esc_attr($okClass) . '">' . esc_html((string) ($result['message'] ?? '')) . '</div>';
        }

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Status', 'lunixi') . '</p>';
        echo '<ul class="lunixi-status-list">';
        echo '<li>' . wp_kses_post($this->statusDot($configured)) . esc_html__('API credentials', 'lunixi') . '</li>';
        echo '<li>' . wp_kses_post($this->statusDot($wcActive)) . esc_html__('WooCommerce active', 'lunixi') . '</li>';
        echo '</ul>';

        echo '<p class="lunixi-field" style="margin-top:16px">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="lunixi_test_connection" />';
        wp_nonce_field('lunixi_test_connection');
        echo '<button type="submit" class="button-primary"' . ($configured ? '' : ' disabled') . '>' . esc_html__('Test connection', 'lunixi') . '</button>';
        echo '</form> ';
        if (!$configured) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=lunixi-settings')) . '">' . esc_html__('Add credentials', 'lunixi') . '</a> ';
        }
        if ($wcActive) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=lunixi')) . '">' . esc_html__('Open WooCommerce gateway settings', 'lunixi') . '</a>';
        }
        echo '</p></div></div>';
    }

    /**
     * Validates the stored credentials by performing a real signed token
     * exchange via the SDK (Ed25519 sign → POST /auth/token). Exercises the
     * whole auth chain end-to-end. Result is flashed back to the Payments page.
     */
    public function handleTestConnection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'lunixi'));
        }
        check_admin_referer('lunixi_test_connection');

        $key = 'lunixi_conn_' . get_current_user_id();
        try {
            $this->sdk->client()->tokens()->getToken(true);
            set_transient($key, ['ok' => true, 'message' => __('Connection successful — credentials are valid.', 'lunixi')], 60);
        } catch (\Throwable $e) {
            set_transient($key, ['ok' => false, 'message' => __('Connection failed: ', 'lunixi') . $e->getMessage()], 60);
        }

        wp_safe_redirect(admin_url('admin.php?page=lunixi-payments'));
        exit;
    }

    public function renderComingSoon(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects which admin screen to show; changes nothing.
        $slug = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        $current = null;
        foreach ($this->modules() as $module) {
            if ($module['slug'] === $slug) {
                $current = $module;
                break;
            }
        }
        $title = $current['title'] ?? __('Module', 'lunixi');
        $desc = $current['desc'] ?? '';

        echo '<div class="wrap lunixi-admin">';
        $this->header($title, esc_html__('Coming soon', 'lunixi'));
        echo '<div class="lunixi-card lunixi-soon-card">';
        echo '<span class="lunixi-module-icon dashicons ' . esc_attr($current['icon'] ?? 'dashicons-clock') . '"></span>';
        echo '<p class="lunixi-soon-title">' . esc_html($title) . ' — ' . esc_html__('coming soon', 'lunixi') . '</p>';
        echo '<p class="lunixi-soon-desc">' . esc_html($desc) . '</p>';
        echo '</div></div>';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    private function statusDot(bool $ok): string
    {
        $class = $ok ? 'lunixi-dot--ok' : 'lunixi-dot--off';
        return '<span class="lunixi-dot ' . $class . '"></span>';
    }

    /** Monochrome menu icon (WordPress recolors it). */
    private function menuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M10 2l1.6 4.4L16 8l-4.4 1.6L10 14l-1.6-4.4L4 8l4.4-1.6z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
