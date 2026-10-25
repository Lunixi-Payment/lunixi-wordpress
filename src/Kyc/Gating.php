<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\Sdk\Kyc\AchievedAssurance;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * Config-driven KYC enforcement (design §4.4). Every gate consults
 * VerificationStore::isVerified() (state + expiry + per-domain) — never a bool.
 * Covers: WooCommerce checkout gate, the account-page widget, and role
 * grant-on-VERIFIED / revoke-on-suspend/revoke/expire. Also feeds the verification
 * validity window from settings into the lifecycle.
 */
final class Gating
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
        Gate::setStore($this->store);

        // Verification validity window (days) from settings → lifecycle filter.
        add_filter('lunixi_kyc_validity_days', function ($days) {
            $configured = $this->settings->kycValidityDays();
            return $configured > 0 ? $configured : $days;
        });

        // Role grant/revoke driven by the lifecycle.
        add_action('lunixi_kyc_state_changed', [$this, 'syncRole'], 10, 4);

        if (class_exists('WooCommerce')) {
            // Checkout gate — classic + Blocks/Store-API.
            if ($this->settings->kycCheckoutGate()) {
                add_action('woocommerce_checkout_process', [$this, 'classicCheckoutGuard']);
                add_action('woocommerce_store_api_cart_errors', [$this, 'blocksCheckoutGuard'], 10, 1);
            }
            // Account dashboard widget.
            add_action('woocommerce_account_dashboard', [$this, 'accountNotice']);
        }
    }

    /** @return array<string,string> the per-domain requirement for the checkout gate. */
    private function checkoutRequirement(): array
    {
        $level = $this->settings->kycCheckoutLevel();
        if ($level === 'NONE') {
            return [];
        }
        return [AchievedAssurance::IDENTITY => $level];
    }

    private function passesCheckout(): bool
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return false; // a gated checkout requires a logged-in, verified user
        }
        return $this->store->isVerified($userId, $this->checkoutRequirement());
    }

    public function classicCheckoutGuard(): void
    {
        if (!$this->passesCheckout()) {
            wc_add_notice($this->message(), 'error');
        }
    }

    /**
     * @param \WP_Error $errors
     */
    public function blocksCheckoutGuard($errors): void
    {
        if (is_wp_error($errors) && !$this->passesCheckout()) {
            $errors->add('lunixi_kyc_required', wp_strip_all_tags($this->message()));
        }
    }

    public function accountNotice(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return;
        }
        $state = $this->store->state($userId);
        if ($state === VerificationStore::STATE_VERIFIED && !$this->store->isExpired($userId)) {
            echo '<p class="lunixi-kyc-account">' . esc_html__('✓ Your identity is verified.', 'lunixi') . '</p>';
            return;
        }
        if (in_array($state, [VerificationStore::STATE_SUSPENDED, VerificationStore::STATE_PENDING_RESCREEN], true)) {
            echo '<p class="lunixi-kyc-account">' . esc_html__('Your verification is under review.', 'lunixi') . '</p>';
            return;
        }
        $page = $this->settings->kycPageUrl();
        if ($page !== '') {
            echo '<p class="lunixi-kyc-account"><a class="button" href="' . esc_url($page) . '">'
                . esc_html__('Verify your identity', 'lunixi') . '</a></p>';
        }
    }

    /**
     * @param int    $userId
     * @param string $old
     * @param string $new
     */
    public function syncRole($userId, $old, $new, $sessionId = ''): void
    {
        $role = $this->settings->kycRole();
        if ($role === '') {
            return;
        }
        $user = get_userdata((int) $userId);
        if (!$user) {
            return;
        }
        if ($new === VerificationStore::STATE_VERIFIED) {
            if (!in_array($role, (array) $user->roles, true)) {
                $user->add_role($role);
            }
        } elseif (in_array($new, [
            VerificationStore::STATE_SUSPENDED,
            VerificationStore::STATE_REVOKED,
            VerificationStore::STATE_EXPIRED,
            VerificationStore::STATE_REJECTED,
        ], true)) {
            $roles = (array) $user->roles;
            // Never strip a user's only role (would orphan the account / lock
            // them out of capability checks). Only revoke when another role remains.
            if (in_array($role, $roles, true) && count($roles) > 1) {
                $user->remove_role($role);
            }
        }
    }

    private function message(): string
    {
        $page = $this->settings->kycPageUrl();
        if ($page !== '') {
            return sprintf(
                /* translators: %s = verification page URL */
                __('Please verify your identity before checking out. %s', 'lunixi'),
                '<a href="' . esc_url($page) . '">' . esc_html__('Verify now', 'lunixi') . '</a>'
            );
        }
        return __('Please verify your identity before checking out.', 'lunixi');
    }
}
