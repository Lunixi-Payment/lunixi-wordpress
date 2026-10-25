<?php

declare(strict_types=1);

namespace Lunixi\WP\Marketplace;

use Lunixi\WP\Core\Logger;
use Lunixi\WP\Core\Settings;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * `[lunixi_marketplace_onboarding]` — a vendor's self-onboarding surface (KYC +
 * IBAN + contract). Both modes (owner decision):
 *   embed  — mounts the Lunixi marketplace SDK in-page with a minted mp_token.
 *   hosted — a button to the hosted onboarding wizard (altbayi.lunixi.com).
 * The merchant API credentials never reach the browser; the WP server mints the
 * onboarding token for the logged-in vendor's sub-dealer.
 */
final class OnboardingShortcode
{
    private Settings $settings;
    private VendorStore $vendors;

    public function __construct(Settings $settings, VendorStore $vendors)
    {
        $this->settings = $settings;
        $this->vendors = $vendors;
    }

    public function register(): void
    {
        add_shortcode('lunixi_marketplace_onboarding', [$this, 'render']);
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public function render($atts = []): string
    {
        $atts = shortcode_atts(['mode' => 'embed'], is_array($atts) ? $atts : [], 'lunixi_marketplace_onboarding');
        $mode = in_array($atts['mode'], ['embed', 'hosted'], true) ? (string) $atts['mode'] : 'embed';

        if (!is_user_logged_in()) {
            return '<div class="lunixi-mp-notice"><a href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">'
                . esc_html__('Log in to onboard as a vendor.', 'lunixi') . '</a></div>';
        }
        $userId = get_current_user_id();
        $dealerId = $this->vendors->dealerIdForUser($userId);
        if ($dealerId === '') {
            return '<div class="lunixi-mp-notice">' . esc_html__('You are not a registered vendor yet. Please contact the store.', 'lunixi') . '</div>';
        }

        $token = $this->mintToken($dealerId, $userId);
        if ($token === '') {
            return '<div class="lunixi-mp-notice">' . esc_html__('Onboarding is unavailable right now. Please try again later.', 'lunixi') . '</div>';
        }

        if ($mode === 'hosted') {
            $base = rtrim($this->settings->portalHostedUrl() === 'https://portal.lunixi.com' ? 'https://altbayi.lunixi.com' : $this->settings->portalHostedUrl(), '/');
            $url = $base . '/m/' . rawurlencode($token);
            return '<div class="lunixi-mp-hosted"><a class="button" href="' . esc_url($url) . '">'
                . esc_html__('Continue onboarding', 'lunixi') . '</a></div>';
        }

        wp_enqueue_script('lunixi-loader', $this->settings->portalCdnUrl() . '/lunixi-loader.min.js', [], LUNIXI_VERSION, true);
        wp_enqueue_script('lunixi-marketplace', LUNIXI_URL . 'assets/js/marketplace.js', ['lunixi-loader'], LUNIXI_VERSION, true);
        wp_localize_script('lunixi-marketplace', 'lunixiMarketplace', [
            'token' => $token,
            'apiBase' => esc_url_raw($this->settings->baseUrl()),
            'cdnBase' => esc_url_raw($this->settings->portalCdnUrl()),
            'mountId' => 'lunixi-marketplace',
            'locale' => str_replace('_', '-', get_locale()),
            'i18n' => ['error' => __('Onboarding could not be loaded.', 'lunixi')],
        ]);

        return '<div id="lunixi-marketplace" class="lunixi-marketplace"></div>';
    }

    private function mintToken(string $dealerId, int $userId): string
    {
        $cacheKey = 'lunixi_mp_onb_' . md5($dealerId);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            return '';
        }
        try {
            $token = $plugin->sdk()->client()->marketplace()->onboardingToken($dealerId)->token();
            if ($token !== '') {
                set_transient($cacheKey, $token, 6 * HOUR_IN_SECONDS);
            }
            return $token;
        } catch (\Throwable $e) {
            Logger::error('marketplace onboarding token failed', ['dealer' => $dealerId, 'error' => $e->getMessage()]);
            return '';
        }
    }
}
