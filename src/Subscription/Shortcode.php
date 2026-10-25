<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * `[lunixi_subscriptions]` — the customer-facing subscription screen. Renders one
 * of two portal modes (per settings, overridable via the `mode` attribute):
 *
 *   embed  — mounts the Lunixi portal SDK in-page; the browser fetches a
 *            short-lived session from our BFF and the portal runs entirely here.
 *   hosted — a button that redirects the customer to the hosted Lunixi portal.
 *
 * The embed mount call is finalised against the published portal SDK during
 * browser QA (the SDK script URL is merchant-configured and may not exist yet).
 */
final class Shortcode
{
    private static int $instance = 0;

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        add_shortcode('lunixi_subscriptions', [$this, 'render']);
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public function render($atts = []): string
    {
        $atts = shortcode_atts(['mode' => ''], is_array($atts) ? $atts : [], 'lunixi_subscriptions');
        $mode = in_array($atts['mode'], ['embed', 'hosted'], true) ? (string) $atts['mode'] : $this->settings->portalMode();

        if (!is_user_logged_in()) {
            return '<div class="lunixi-portal-notice">'
                . '<a href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">'
                . esc_html__('Log in to manage your subscriptions.', 'lunixi') . '</a></div>';
        }

        if ($mode === 'hosted') {
            return $this->hostedButton();
        }

        // Embed mode: load the Lunixi portal SDK from the CDN — the loader must
        // load before the portal bundle (matches the Lunixi portal examples).
        $cdn = $this->settings->portalCdnUrl();
        wp_enqueue_script('lunixi-loader', $cdn . '/lunixi-loader.min.js', [], LUNIXI_VERSION, true);
        wp_enqueue_script('lunixi-portal-sdk', $cdn . '/lunixi-portal.min.js', ['lunixi-loader'], LUNIXI_VERSION, true);
        wp_enqueue_script('lunixi-portal', LUNIXI_URL . 'assets/js/portal.js', ['lunixi-portal-sdk'], LUNIXI_VERSION, true);
        // Shared config (same user for every instance on the page). portal.js
        // mounts each `.lunixi-portal` element, so multiple [lunixi_subscriptions]
        // on one page each render (no duplicate-id / single-mount bug).
        wp_localize_script('lunixi-portal', 'lunixiPortal', [
            'restUrl' => esc_url_raw(rest_url('lunixi/v1/subscription/portal-session')),
            'nonce' => wp_create_nonce('wp_rest'),
            'apiBase' => esc_url_raw($this->settings->baseUrl()),
            'locale' => str_replace('_', '-', get_locale()),
            'i18n' => [
                'sdkMissing' => __('The subscription portal could not be loaded.', 'lunixi'),
                'error' => __('Could not load your subscriptions. Please try again.', 'lunixi'),
            ],
        ]);

        self::$instance++;
        $id = 'lunixi-portal' . (self::$instance > 1 ? '-' . self::$instance : '');
        return '<div id="' . esc_attr($id) . '" class="lunixi-portal"></div>';
    }

    private function hostedButton(): string
    {
        return '<div class="lunixi-portal-hosted">'
            . '<a class="button lunixi-portal-button" href="' . esc_url(PortalController::redirectUrl()) . '">'
            . esc_html__('Manage my subscriptions', 'lunixi') . '</a></div>';
    }
}
