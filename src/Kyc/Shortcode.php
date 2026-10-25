<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\Sdk\Kyc\AchievedAssurance;
use Lunixi\Sdk\Kyc\AssuranceLevel;
use Lunixi\Sdk\Kyc\SessionType;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * `[lunixi_kyc]` — the customer verification surface. Mounts the embedded Lunixi
 * KYC SDK (prebuilt UI: document + selfie/liveness) for the logged-in user. If
 * the user already meets the required assurance, it shows a "verified" state
 * instead of re-running. Attributes:
 *   type      kyc | kyb            (default kyc)
 *   identity  required identity assurance for the "already verified" check (default LOW)
 *   redirect  URL to send the user to on approval (default: reload)
 */
final class Shortcode
{
    private Settings $settings;
    private VerificationStore $store;

    public function __construct(Settings $settings, VerificationStore $store)
    {
        $this->settings = $settings;
        $this->store = $store;
    }

    public function register(): void
    {
        add_shortcode('lunixi_kyc', [$this, 'render']);
        add_shortcode('lunixi_evidence', [$this, 'render']);
    }

    /**
     * Handles both [lunixi_kyc] (identity/company verification) and
     * [lunixi_evidence purpose="…"] (a proof-of-X session that does NOT change the
     * user's identity state).
     *
     * @param array<string,mixed>|string $atts
     */
    public function render($atts = [], ?string $content = null, string $tag = 'lunixi_kyc'): string
    {
        $isEvidence = $tag === 'lunixi_evidence';
        $atts = shortcode_atts(
            ['type' => 'kyc', 'identity' => AssuranceLevel::LOW, 'redirect' => '', 'purpose' => ''],
            is_array($atts) ? $atts : [],
            $tag
        );

        $type = $isEvidence ? SessionType::EVIDENCE
            : (in_array(strtoupper((string) $atts['type']), [SessionType::KYC, SessionType::KYB], true) ? strtoupper((string) $atts['type']) : SessionType::KYC);
        $requiredIdentity = strtoupper((string) $atts['identity']);
        $purpose = strtoupper((string) $atts['purpose']);

        if (!is_user_logged_in()) {
            return '<div class="lunixi-kyc-notice"><a href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">'
                . esc_html__('Log in to continue.', 'lunixi') . '</a></div>';
        }

        $userId = get_current_user_id();

        // Identity verification short-circuits when already met; evidence sessions
        // are per-purpose, so they always offer a fresh capture.
        if (!$isEvidence) {
            if ($this->store->isVerified($userId, [AchievedAssurance::IDENTITY => $requiredIdentity])) {
                return '<div class="lunixi-kyc-verified"><span class="lunixi-kyc-badge">✓ '
                    . esc_html__('Identity verified', 'lunixi') . '</span></div>';
            }
            $state = $this->store->state($userId);
            if (in_array($state, [VerificationStore::STATE_SUSPENDED, VerificationStore::STATE_PENDING_RESCREEN], true)) {
                return '<div class="lunixi-kyc-notice">' . esc_html__('Your verification is under review. Please check back later.', 'lunixi') . '</div>';
            }
        }

        $mountId = $isEvidence ? 'lunixi-evidence' : 'lunixi-kyc';
        $handle = $isEvidence ? 'lunixi-evidence' : 'lunixi-kyc';

        // The prebuilt embed bootstraps the SDK token browser-direct against the
        // gateway's publishable-key endpoint; without a key it cannot start.
        if ($this->settings->kycPublishableKey() === '') {
            return '<div class="lunixi-kyc-notice">' . esc_html__('Verification is not configured yet.', 'lunixi') . '</div>';
        }

        wp_enqueue_script('lunixi-kyc-sdk', $this->settings->portalCdnUrl() . '/lunixi-kyc.min.js', [], LUNIXI_VERSION, true);
        wp_enqueue_script($handle, LUNIXI_URL . 'assets/js/kyc.js', ['lunixi-kyc-sdk'], LUNIXI_VERSION, true);
        wp_localize_script($handle, 'lunixiKyc', [
            'sessionUrl' => esc_url_raw(rest_url('lunixi/v1/kyc/session')),
            'nonce' => wp_create_nonce('wp_rest'),
            'apiBase' => esc_url_raw($this->settings->baseUrl()),
            'publishableKey' => $this->settings->kycPublishableKey(),
            'sdkMode' => 'prebuilt',
            'type' => $type,
            'purpose' => $purpose,
            'mountId' => $mountId,
            'locale' => str_replace('_', '-', get_locale()),
            'redirect' => $atts['redirect'] !== '' ? esc_url_raw((string) $atts['redirect']) : '',
            'i18n' => [
                'sdkMissing' => __('Verification could not be loaded.', 'lunixi'),
                'error' => __('Could not start verification. Please try again.', 'lunixi'),
            ],
        ]);

        return '<div id="' . esc_attr($mountId) . '" class="lunixi-kyc"></div>';
    }
}
