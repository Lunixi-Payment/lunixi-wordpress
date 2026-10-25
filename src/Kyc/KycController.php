<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\Sdk\Kyc\CreateSessionRequest;
use Lunixi\Sdk\Kyc\EvidencePurpose;
use Lunixi\Sdk\Kyc\SessionType;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Browser backend-for-frontend for the embedded KYC SDK. One REST route for the
 * logged-in user (cookie auth + X-WP-Nonce):
 *
 *   POST /wp-json/lunixi/v1/kyc/session → create (or resume) a session for this
 *        user (externalCustomerId = WP user id) → {kycSessionId, merchantId}.
 *
 * The merchant API credentials never reach the browser, and the session is bound
 * to this WP user server-side. The SDK token itself is bootstrapped browser-direct
 * against the gateway's publishable-key `/kyc/sdk/init` (see assets/js/kyc.js) —
 * the gateway moved that endpoint to publishable-key auth, like the identity SDK.
 */
final class KycController
{
    private const META_ACTIVE = '_lunixi_kyc_active_session';

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            // Session create/resume only (merchant bearer, binds the WP user).
            // The SDK-token bootstrap is browser-direct against the gateway's
            // publishable-key endpoint — see assets/js/kyc.js — so there is no
            // server-side /kyc/init route any more.
            register_rest_route('lunixi/v1', '/kyc/session', [
                'methods' => 'POST',
                'callback' => [$this, 'session'],
                'permission_callback' => [$this, 'permission'],
            ]);
        });
    }

    public function permission(): bool
    {
        return is_user_logged_in();
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function session($request)
    {
        $userId = get_current_user_id();
        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            return new \WP_Error('lunixi_kyc', __('Verification is unavailable right now.', 'lunixi'), ['status' => 503]);
        }

        $type = (string) $request->get_param('type');
        $type = in_array(strtoupper($type), SessionType::ALL, true) ? strtoupper($type) : SessionType::KYC;
        $purpose = strtoupper((string) $request->get_param('purpose'));
        $kyc = $plugin->sdk()->client()->kyc();

        // Resume an in-flight identity/company session (evidence is per-purpose →
        // always fresh, never resumed).
        $activeId = (string) get_user_meta($userId, self::META_ACTIVE, true);
        if ($activeId !== '' && $type !== SessionType::EVIDENCE) {
            try {
                $existing = $kyc->getSession($activeId);
                if (!$existing->isTerminal() && $existing->sessionType() === $type) {
                    return new \WP_REST_Response(['kycSessionId' => $existing->sessionId(), 'merchantId' => $existing->merchantId()], 200);
                }
            } catch (\Throwable $e) {
                // fall through to create a fresh one
            }
        }

        try {
            $req = (new CreateSessionRequest((string) $userId, $type))
                ->withExternalCustomerId((string) $userId);
            $jurisdiction = self::defaultJurisdiction();
            if ($jurisdiction !== '') {
                $req->withJurisdiction($jurisdiction);
            }
            if ($type === SessionType::EVIDENCE && in_array($purpose, EvidencePurpose::ALL, true)) {
                $req->withEvidencePurpose($purpose);
            }

            $session = $kyc->createSession($req);
            update_user_meta($userId, self::META_ACTIVE, $session->sessionId());

            return new \WP_REST_Response(['kycSessionId' => $session->sessionId(), 'merchantId' => $session->merchantId()], 200);
        } catch (\Throwable $e) {
            Logger::error('kyc session create failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return new \WP_Error('lunixi_kyc', __('Could not start verification.', 'lunixi'), ['status' => 502]);
        }
    }

    private static function defaultJurisdiction(): string
    {
        $country = '';
        if (function_exists('wc_get_base_location')) {
            $base = wc_get_base_location();
            $country = is_array($base) ? (string) ($base['country'] ?? '') : '';
        }
        /** Override the KYC jurisdiction (ISO country) used for new sessions. */
        return (string) apply_filters('lunixi_kyc_jurisdiction', $country);
    }
}
