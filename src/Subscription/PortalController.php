<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

use Lunixi\WP\Core\Logger;
use Lunixi\WP\Core\Settings;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Browser backend-for-frontend for the customer portal. Two REST routes, both
 * for the logged-in WP user (cookie auth + nonce):
 *
 *   POST /wp-json/lunixi/v1/subscription/portal-session   (REST, X-WP-Nonce)
 *        → mints an embed SDK session (portalToken + sessionProof + nonce) the
 *          browser passes to window.Lunixi.portal.mount().
 *   admin-post lunixi_portal_redirect                      (full-page nav)
 *        → mints a hosted portal link and redirects the customer to it.
 *
 * The merchant never exposes API credentials to the browser — the WP server
 * mints the short-lived session/link on the user's behalf.
 */
final class PortalController
{
    private Settings $settings;
    private CustomerService $customers;

    public function __construct(Settings $settings, CustomerService $customers)
    {
        $this->settings = $settings;
        $this->customers = $customers;
    }

    public const REDIRECT_ACTION = 'lunixi_portal_redirect';

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('lunixi/v1', '/subscription/portal-session', [
                'methods' => 'POST',
                'callback' => [$this, 'session'],
                'permission_callback' => [$this, 'permission'],
            ]);
        });
        add_action('admin_post_' . self::REDIRECT_ACTION, [$this, 'handleRedirect']);
    }

    public function permission(): bool
    {
        return is_user_logged_in();
    }

    /**
     * URL the hosted portal button points at (nonce-protected). An optional plan
     * id scopes the portal to a single plan (used by WooCommerce subscription
     * products).
     */
    public static function redirectUrl(string $planId = ''): string
    {
        $url = admin_url('admin-post.php?action=' . self::REDIRECT_ACTION);
        if ($planId !== '') {
            $url = add_query_arg('plan', rawurlencode($planId), $url);
        }
        return wp_nonce_url($url, self::REDIRECT_ACTION);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function session($request)
    {
        $userId = get_current_user_id();
        $customerId = $this->customers->ensureCustomer($userId);
        if ($customerId === '') {
            return new \WP_Error('lunixi_portal', __('Subscriptions are unavailable right now.', 'lunixi'), ['status' => 503]);
        }

        try {
            $session = Plugin::instance()->sdk()->client()->subscriptions()->portal()
                ->createSdkSession($customerId, self::origin());
            return new \WP_REST_Response($session->toBootstrap(), 200);
        } catch (\Throwable $e) {
            Logger::error('portal session failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return new \WP_Error('lunixi_portal', __('Could not start the subscription portal.', 'lunixi'), ['status' => 502]);
        }
    }

    /** admin-post handler: mint a hosted portal link and redirect the customer. */
    public function handleRedirect(): void
    {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url());
            exit;
        }
        check_admin_referer(self::REDIRECT_ACTION);

        $userId = get_current_user_id();
        $customerId = $this->customers->ensureCustomer($userId);
        $fallback = home_url('/');

        if ($customerId === '') {
            wp_safe_redirect($fallback);
            exit;
        }

        $planId = isset($_GET['plan']) ? sanitize_text_field(wp_unslash((string) $_GET['plan'])) : '';
        $options = $planId !== '' ? ['allowedPlanIds' => [$planId], 'defaultPlanId' => $planId] : [];

        try {
            $link = Plugin::instance()->sdk()->client()->subscriptions()->portal()
                ->createPortalLink($customerId, $options);
            // External, gateway-issued hosted URL — not a local redirect.
            wp_redirect($link->hostedUrl($this->settings->portalHostedUrl())); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
            exit;
        } catch (\Throwable $e) {
            Logger::error('portal redirect failed', ['user' => $userId, 'error' => $e->getMessage()]);
            wp_safe_redirect($fallback);
            exit;
        }
    }

    /** The browser origin of this store (must be allow-listed for the portal). */
    private static function origin(): string
    {
        $home = home_url();
        $parts = wp_parse_url($home);
        if (!is_array($parts) || empty($parts['host'])) {
            return $home;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
}
