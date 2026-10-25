<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

defined('ABSPATH') || exit;

/**
 * Single source of Lunixi credentials, shared by every module (so the merchant
 * enters them once). Secrets (Ed25519 private key, webhook secret) are stored
 * encrypted via {@see SecretBox}. The admin screen lives under a top-level
 * "Lunixi" menu; the WooCommerce gateway reuses these credentials.
 *
 * v1 onboarding is manual key entry. A future "Connect with Lunixi" (OAuth/PKCE)
 * flow will provision these automatically once the platform exposes it.
 */
final class Settings
{
    private const OPTION = 'lunixi_settings';

    /**
     * 🔴 TEK TABAN ADRES. Ortam ayri bir HOST DEGILDIR — test ile canliyi ayiran
     *    sey anahtarin onekidir (`mk_test_` / `mk_live_`), adres degil.
     *
     * Eskiden `'TEST' => 'https://sandbox-api-gateway.lunixi.com'` yaziyordu ve
     * o adres API SERVIS ETMIYOR. Olculdu (2026-09-28):
     *     POST https://sandbox-api-gateway.lunixi.com/api/v1/auth/token -> 405
     *     POST https://api-gateway.lunixi.com/api/v1/auth/token         -> 401
     * Yani Environment=Test seceN her saticida eklenti HIC calismiyordu: ilk jeton
     * istegi 405 aliyor, ardindan odeme / abonelik / KYC / fraud cagrilarinin
     * TAMAMI dusuyordu. Adres vitrin gomulerine de basildigi icin (Kyc/Shortcode,
     * Identity/Collector, Subscription/Shortcode, Marketplace/OnboardingShortcode)
     * magaza on yuzu de kiriliyordu.
     *
     * Yayinlanmis dokumantasyon da bunu boyle anlatiyor: "tek taban adres, ortami
     * anahtar belirler".
     */
    private const DEFAULT_BASE_URL = 'https://api-gateway.lunixi.com';

    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = is_array($stored) ? $stored : [];
        }
        return $this->cache;
    }

    public function environment(): string
    {
        $env = strtoupper((string) ($this->all()['environment'] ?? 'LIVE'));
        return $env === 'TEST' ? 'TEST' : 'LIVE';
    }

    public function keyId(): string
    {
        return (string) ($this->all()['key_id'] ?? '');
    }

    public function privateKey(): string
    {
        return SecretBox::decrypt((string) ($this->all()['private_key'] ?? ''));
    }

    public function webhookSecret(): string
    {
        return SecretBox::decrypt((string) ($this->all()['webhook_secret'] ?? ''));
    }

    public function baseUrl(): string
    {
        $override = trim((string) ($this->all()['base_url'] ?? ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }
        return self::DEFAULT_BASE_URL;
    }

    public function isConfigured(): bool
    {
        return $this->keyId() !== '' && $this->privateKey() !== '';
    }

    /** Customer-portal mode for the [lunixi_subscriptions] surface. */
    public function portalMode(): string
    {
        $mode = (string) ($this->all()['portal_mode'] ?? 'embed');
        return in_array($mode, ['embed', 'hosted'], true) ? $mode : 'embed';
    }

    /** Base URL of the Lunixi CDN serving the portal SDK (loader + portal). */
    public function portalCdnUrl(): string
    {
        $url = trim((string) ($this->all()['portal_cdn_url'] ?? ''));
        return $url !== '' ? rtrim($url, '/') : 'https://cdn.lunixi.com';
    }

    /** Base URL of the hosted Lunixi portal (redirect mode). */
    public function portalHostedUrl(): string
    {
        $url = trim((string) ($this->all()['portal_hosted_url'] ?? ''));
        return $url !== '' ? rtrim($url, '/') : 'https://portal.lunixi.com';
    }

    // ── KYC gating ──────────────────────────────────────────────────────────

    public function kycCheckoutGate(): bool
    {
        return !empty($this->all()['kyc_checkout_gate']);
    }

    /** Required identity assurance for the checkout gate (NONE|LOW|SUBSTANTIAL|HIGH). */
    public function kycCheckoutLevel(): string
    {
        $level = strtoupper((string) ($this->all()['kyc_checkout_level'] ?? 'LOW'));
        return in_array($level, ['NONE', 'LOW', 'SUBSTANTIAL', 'HIGH'], true) ? $level : 'LOW';
    }

    /** URL of the page hosting the [lunixi_kyc] shortcode (for verify CTAs). */
    public function kycPageUrl(): string
    {
        $pageId = (int) ($this->all()['kyc_page_id'] ?? 0);
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url)) {
                return $url;
            }
        }
        return '';
    }

    /** Role granted on VERIFIED (and revoked on suspend/revoke/expire), or ''. */
    public function kycRole(): string
    {
        return (string) ($this->all()['kyc_role'] ?? '');
    }

    public function kycValidityDays(): int
    {
        $days = (int) ($this->all()['kyc_validity_days'] ?? 365);
        return $days > 0 ? $days : 365;
    }

    /**
     * Publishable key for the browser KYC SDK (safe to expose; origin-bound).
     * The prebuilt embed bootstraps `/kyc/sdk/init` directly with this key — the
     * gateway moved that endpoint to publishable-key auth (browser-direct).
     */
    public function kycPublishableKey(): string
    {
        return (string) ($this->all()['kyc_publishable_key'] ?? '');
    }

    // ── Automation (WooCommerce event bridge) ────────────────────────────────

    public function automationEnabled(): bool
    {
        return !empty($this->all()['automation_enabled']);
    }

    /**
     * The registered integration id for this site (the connection id the
     * merchant gets when registering the WordPress provider in the Lunixi
     * dashboard). The tenant/merchant is resolved server-side from
     * (provider=wordpress, integrationId) — the producer never sends a merchantId.
     * Falls back to the legacy `automation_site_id` key.
     */
    public function automationIntegrationId(): string
    {
        $all = $this->all();
        $id = (string) ($all['automation_integration_id'] ?? '');
        return $id !== '' ? $id : (string) ($all['automation_site_id'] ?? '');
    }

    /** The integration's shared secret (HMAC key), stored encrypted. */
    public function automationSecret(): string
    {
        return SecretBox::decrypt((string) ($this->all()['automation_secret'] ?? ''));
    }

    public function automationConfigured(): bool
    {
        return $this->automationEnabled()
            && $this->automationIntegrationId() !== ''
            && $this->automationSecret() !== '';
    }

    // ── Identity (device graph / multi-account) ──────────────────────────────

    public function identityEnabled(): bool
    {
        return !empty($this->all()['identity_enabled']);
    }

    /** Publishable key for the browser fingerprint SDK (safe to expose). */
    public function identityPublishableKey(): string
    {
        return (string) ($this->all()['identity_publishable_key'] ?? '');
    }

    /** What to do when a shared-device multi-account signup is detected. */
    public function identityPolicy(): string
    {
        $policy = (string) ($this->all()['identity_policy'] ?? 'flag');
        return in_array($policy, ['off', 'flag', 'block'], true) ? $policy : 'flag';
    }

    /** Number of distinct accounts on one device that counts as "shared". */
    public function identityThreshold(): int
    {
        $n = (int) ($this->all()['identity_threshold'] ?? 2);
        return $n >= 2 ? $n : 2;
    }

    /**
     * Whether identity collection runs on a given placement (registration, login,
     * checkout). Defaults ON for placements never explicitly saved.
     */
    public function identityPlacementEnabled(string $placement): bool
    {
        $key = 'identity_on_' . $placement;
        $all = $this->all();
        return array_key_exists($key, $all) ? !empty($all[$key]) : true;
    }

    /** @return array{baseUrl:string, keyId:string, privateKey:string, environment:string} */
    public function credentials(): array
    {
        return [
            'baseUrl' => $this->baseUrl(),
            'keyId' => $this->keyId(),
            'privateKey' => $this->privateKey(),
            'environment' => $this->environment(),
        ];
    }

    // ── Admin ──────────────────────────────────────────────────────────────

    public function registerAdmin(): void
    {
        // The menu + asset enqueue are owned by AdminMenu; here we only register
        // the option + its sanitizer.
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerSettings(): void
    {
        register_setting('lunixi_settings_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
        ]);
    }

    /**
     * Sanitizes + encrypts secrets before persistence. A blank secret field
     * keeps the previously stored value (so the page never round-trips secrets).
     *
     * @param mixed $input
     * @return array<string,mixed>
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $existing = $this->all();

        $out = [
            'environment' => (isset($input['environment']) && strtoupper((string) $input['environment']) === 'TEST') ? 'TEST' : 'LIVE',
            'key_id' => sanitize_text_field((string) ($input['key_id'] ?? '')),
            'base_url' => esc_url_raw((string) ($input['base_url'] ?? '')),
        ];

        $privateKey = trim((string) ($input['private_key'] ?? ''));
        $out['private_key'] = $privateKey !== ''
            ? SecretBox::encrypt($privateKey)
            : (string) ($existing['private_key'] ?? '');

        $webhookSecret = trim((string) ($input['webhook_secret'] ?? ''));
        $out['webhook_secret'] = $webhookSecret !== ''
            ? SecretBox::encrypt($webhookSecret)
            : (string) ($existing['webhook_secret'] ?? '');

        $out['debug'] = !empty($input['debug']) ? '1' : '';

        $portalMode = (string) ($input['portal_mode'] ?? 'embed');
        $out['portal_mode'] = in_array($portalMode, ['embed', 'hosted'], true) ? $portalMode : 'embed';
        $out['portal_cdn_url'] = esc_url_raw((string) ($input['portal_cdn_url'] ?? ''));
        $out['portal_hosted_url'] = esc_url_raw((string) ($input['portal_hosted_url'] ?? ''));

        $out['kyc_checkout_gate'] = !empty($input['kyc_checkout_gate']) ? '1' : '';
        $kycLevel = strtoupper((string) ($input['kyc_checkout_level'] ?? 'LOW'));
        $out['kyc_checkout_level'] = in_array($kycLevel, ['NONE', 'LOW', 'SUBSTANTIAL', 'HIGH'], true) ? $kycLevel : 'LOW';
        $out['kyc_page_id'] = (int) ($input['kyc_page_id'] ?? 0);
        $out['kyc_role'] = sanitize_key((string) ($input['kyc_role'] ?? ''));
        $out['kyc_validity_days'] = max(1, (int) ($input['kyc_validity_days'] ?? 365));
        $out['kyc_publishable_key'] = sanitize_text_field((string) ($input['kyc_publishable_key'] ?? ''));

        $out['automation_enabled'] = !empty($input['automation_enabled']) ? '1' : '';
        $out['automation_integration_id'] = sanitize_text_field((string) ($input['automation_integration_id'] ?? ''));
        $automationSecret = trim((string) ($input['automation_secret'] ?? ''));
        $out['automation_secret'] = $automationSecret !== ''
            ? SecretBox::encrypt($automationSecret)
            : (string) ($existing['automation_secret'] ?? '');

        $out['identity_enabled'] = !empty($input['identity_enabled']) ? '1' : '';
        $out['identity_publishable_key'] = sanitize_text_field((string) ($input['identity_publishable_key'] ?? ''));
        $identityPolicy = (string) ($input['identity_policy'] ?? 'flag');
        $out['identity_policy'] = in_array($identityPolicy, ['off', 'flag', 'block'], true) ? $identityPolicy : 'flag';
        $out['identity_threshold'] = max(2, (int) ($input['identity_threshold'] ?? 2));
        foreach (['registration', 'login', 'checkout'] as $placement) {
            $out['identity_on_' . $placement] = !empty($input['identity_on_' . $placement]) ? '1' : '';
        }

        $this->cache = null;

        return $out;
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $env = $this->environment();
        $keyId = $this->keyId();
        $hasPrivateKey = (string) ($this->all()['private_key'] ?? '') !== '';
        $hasWebhookSecret = (string) ($this->all()['webhook_secret'] ?? '') !== '';
        $webhookUrl = rest_url('lunixi/v1/webhook');
        $logo = LUNIXI_URL . 'assets/img/logo.svg';
        $pillClass = $env === 'TEST' ? 'lunixi-pill--test' : 'lunixi-pill--live';

        echo '<div class="wrap lunixi-admin">';

        // Branded header (mirrors the Lunixi panel).
        echo '<div class="lunixi-header">';
        echo '<img class="lunixi-logo" src="' . esc_url($logo) . '" alt="Lunixi" />';
        echo '<div class="lunixi-header-text">';
        echo '<h1 class="lunixi-title">Lunixi <span class="lunixi-accent">' . esc_html__('Settings', 'lunixi') . '</span></h1>';
        echo '<p class="lunixi-subtitle">' . esc_html__('Payments · Fraud · Subscription · Identity · Sanction · KYC', 'lunixi') . '</p>';
        echo '</div></div>';

        echo '<form method="post" action="options.php">';
        settings_fields('lunixi_settings_group');

        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('API Credentials', 'lunixi') . '</p>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Environment', 'lunixi') . '</label>';
        echo '<select name="lunixi_settings[environment]">';
        echo '<option value="LIVE"' . selected($env, 'LIVE', false) . '>' . esc_html__('Live', 'lunixi') . '</option>';
        echo '<option value="TEST"' . selected($env, 'TEST', false) . '>' . esc_html__('Test', 'lunixi') . '</option>';
        echo '</select> <span class="lunixi-pill ' . esc_attr($pillClass) . '">' . esc_html($env) . '</span>';
        echo '</div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Key ID', 'lunixi') . '</label>';
        echo '<input type="text" name="lunixi_settings[key_id]" value="' . esc_attr($keyId) . '" autocomplete="off" /></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Private Key (PEM)', 'lunixi') . '</label>';
        echo '<textarea rows="6" name="lunixi_settings[private_key]" autocomplete="off" placeholder="' . esc_attr($hasPrivateKey ? __('Stored — leave blank to keep', 'lunixi') : '-----BEGIN PRIVATE KEY-----') . '"></textarea>';
        echo '<p class="description">' . esc_html__('Stored encrypted. Paste the private key from your Lunixi dashboard.', 'lunixi') . '</p></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Webhook Secret', 'lunixi') . '</label>';
        echo '<input type="password" name="lunixi_settings[webhook_secret]" value="" autocomplete="off" placeholder="' . esc_attr($hasWebhookSecret ? __('Stored — leave blank to keep', 'lunixi') : '') . '" />';
        echo '<p class="description">' . wp_kses(sprintf(/* translators: %s = webhook endpoint URL */ esc_html__('Register this webhook URL in your Lunixi dashboard: %s', 'lunixi'), '<code>' . esc_html($webhookUrl) . '</code>'), ['code' => []]) . '</p></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Base URL (optional)', 'lunixi') . '</label>';
        echo '<input type="url" name="lunixi_settings[base_url]" value="' . esc_attr((string) ($this->all()['base_url'] ?? '')) . '" placeholder="' . esc_attr($this->baseUrl()) . '" /></div>';

        $debugOn = !empty($this->all()['debug']);
        echo '<div class="lunixi-field"><label>' . esc_html__('Debug logging', 'lunixi') . '</label>';
        echo '<label class="lunixi-checkbox"><input type="checkbox" name="lunixi_settings[debug]" value="1"' . checked($debugOn, true, false) . ' /> '
            . esc_html__('Record diagnostic traces to WooCommerce → Status → Logs (source: lunixi).', 'lunixi') . '</label></div>';

        echo '</div>';

        // Subscriptions / customer portal.
        $portalMode = $this->portalMode();
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Subscriptions — Customer portal', 'lunixi') . '</p>';
        echo '<p class="description">' . esc_html__('Used by the [lunixi_subscriptions] shortcode so customers can manage their subscriptions.', 'lunixi') . '</p>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Portal mode', 'lunixi') . '</label>';
        echo '<select name="lunixi_settings[portal_mode]">';
        echo '<option value="embed"' . selected($portalMode, 'embed', false) . '>' . esc_html__('Embedded (on your site)', 'lunixi') . '</option>';
        echo '<option value="hosted"' . selected($portalMode, 'hosted', false) . '>' . esc_html__('Hosted (redirect to Lunixi)', 'lunixi') . '</option>';
        echo '</select></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Portal CDN URL', 'lunixi') . '</label>';
        echo '<input type="url" name="lunixi_settings[portal_cdn_url]" value="' . esc_attr((string) ($this->all()['portal_cdn_url'] ?? '')) . '" placeholder="' . esc_attr($this->portalCdnUrl()) . '" />';
        echo '<p class="description">' . esc_html__('Serves the embedded portal SDK (loader + portal). Default is the Lunixi CDN; your store origin must be allow-listed for the portal.', 'lunixi') . '</p></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Hosted portal URL', 'lunixi') . '</label>';
        echo '<input type="url" name="lunixi_settings[portal_hosted_url]" value="' . esc_attr((string) ($this->all()['portal_hosted_url'] ?? '')) . '" placeholder="' . esc_attr($this->portalHostedUrl()) . '" /></div>';

        echo '</div>';

        // KYC gating.
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('KYC — Identity verification', 'lunixi') . '</p>';
        echo '<p class="description">' . esc_html__('Add the [lunixi_kyc] shortcode to a page so customers can verify, then configure gating below.', 'lunixi') . '</p>';

        $gateOn = $this->kycCheckoutGate();
        echo '<div class="lunixi-field"><label>' . esc_html__('Checkout gate', 'lunixi') . '</label>';
        echo '<label class="lunixi-checkbox"><input type="checkbox" name="lunixi_settings[kyc_checkout_gate]" value="1"' . checked($gateOn, true, false) . '> '
            . esc_html__('Require identity verification before checkout.', 'lunixi') . '</label></div>';

        $level = $this->kycCheckoutLevel();
        echo '<div class="lunixi-field"><label>' . esc_html__('Required level', 'lunixi') . '</label>';
        echo '<select name="lunixi_settings[kyc_checkout_level]">';
        foreach (['LOW' => __('Low', 'lunixi'), 'SUBSTANTIAL' => __('Substantial', 'lunixi'), 'HIGH' => __('High', 'lunixi')] as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($level, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Verification page', 'lunixi') . '</label>';
        wp_dropdown_pages([
            'name' => 'lunixi_settings[kyc_page_id]',
            'selected' => (int) ($this->all()['kyc_page_id'] ?? 0),
            'show_option_none' => esc_html__('— Select —', 'lunixi'),
            'option_none_value' => '0',
        ]);
        echo '<p class="description">' . esc_html__('The page containing [lunixi_kyc] — used for "Verify to continue" links.', 'lunixi') . '</p></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Role on verified', 'lunixi') . '</label>';
        echo '<select name="lunixi_settings[kyc_role]"><option value="">' . esc_html__('— None —', 'lunixi') . '</option>';
        if (function_exists('get_editable_roles')) {
            foreach (get_editable_roles() as $roleKey => $role) {
                echo '<option value="' . esc_attr($roleKey) . '"' . selected($this->kycRole(), $roleKey, false) . '>' . esc_html(translate_user_role($role['name'])) . '</option>';
            }
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Granted on VERIFIED, revoked on suspend/revoke/expire.', 'lunixi') . '</p></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Validity (days)', 'lunixi') . '</label>';
        echo '<input type="number" min="1" name="lunixi_settings[kyc_validity_days]" value="' . esc_attr((string) $this->kycValidityDays()) . '" /></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Publishable key', 'lunixi') . '</label>';
        echo '<input type="text" name="lunixi_settings[kyc_publishable_key]" value="' . esc_attr($this->kycPublishableKey()) . '" autocomplete="off" />';
        echo '<p class="description">' . esc_html__('Browser SDK key (safe to expose) — required for the [lunixi_kyc] verification embed. From your Lunixi dashboard.', 'lunixi') . '</p></div>';

        echo '</div>';

        // Automation — WooCommerce event bridge.
        $hasAutomationSecret = (string) ($this->all()['automation_secret'] ?? '') !== '';
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Automation — Event bridge', 'lunixi') . '</p>';
        echo '<p class="description">' . esc_html__('Forward WooCommerce events (order created/paid/cancelled, …) to your Lunixi automation flows. Register this site as a WordPress integration in your Lunixi dashboard to get the integration id + shared secret. (Your merchant is resolved automatically — no merchant id needed.)', 'lunixi') . '</p>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Enable', 'lunixi') . '</label>';
        echo '<label class="lunixi-checkbox"><input type="checkbox" name="lunixi_settings[automation_enabled]" value="1"' . checked($this->automationEnabled(), true, false) . '> '
            . esc_html__('Send WooCommerce events to Lunixi automation.', 'lunixi') . '</label></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Integration ID', 'lunixi') . '</label>';
        echo '<input type="text" name="lunixi_settings[automation_integration_id]" value="' . esc_attr($this->automationIntegrationId()) . '" autocomplete="off" /></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Shared secret', 'lunixi') . '</label>';
        echo '<input type="password" name="lunixi_settings[automation_secret]" value="" autocomplete="off" placeholder="' . esc_attr($hasAutomationSecret ? __('Stored — leave blank to keep', 'lunixi') : '') . '" />';
        echo '<p class="description">' . esc_html__('Stored encrypted. The HMAC key from your site registration.', 'lunixi') . '</p></div>';

        echo '</div>';

        // Identity — device graph / multi-account.
        echo '<div class="lunixi-card">';
        echo '<p class="lunixi-card-title">' . esc_html__('Identity — Multi-account protection', 'lunixi') . '</p>';
        echo '<p class="description">' . esc_html__('Detects when one device/fingerprint is used across many accounts (e.g. abuse at sign-up). Needs a publishable key + your origin allow-listed for the identity SDK.', 'lunixi') . '</p>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Enable', 'lunixi') . '</label>';
        echo '<label class="lunixi-checkbox"><input type="checkbox" name="lunixi_settings[identity_enabled]" value="1"' . checked($this->identityEnabled(), true, false) . '> '
            . esc_html__('Fingerprint sign-ups and detect shared-device multi-accounts.', 'lunixi') . '</label></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Publishable key', 'lunixi') . '</label>';
        echo '<input type="text" name="lunixi_settings[identity_publishable_key]" value="' . esc_attr($this->identityPublishableKey()) . '" autocomplete="off" />';
        echo '<p class="description">' . esc_html__('Browser SDK key (safe to expose). From your Lunixi dashboard.', 'lunixi') . '</p></div>';

        $policy = $this->identityPolicy();
        echo '<div class="lunixi-field"><label>' . esc_html__('When shared-device detected', 'lunixi') . '</label>';
        echo '<select name="lunixi_settings[identity_policy]">';
        foreach (['off' => __('Record only', 'lunixi'), 'flag' => __('Flag for review', 'lunixi'), 'block' => __('Block sign-up', 'lunixi')] as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($policy, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Account threshold', 'lunixi') . '</label>';
        echo '<input type="number" min="2" name="lunixi_settings[identity_threshold]" value="' . esc_attr((string) $this->identityThreshold()) . '" />';
        echo '<p class="description">' . esc_html__('Trigger when this many accounts share one device.', 'lunixi') . '</p></div>';

        echo '<div class="lunixi-field"><label>' . esc_html__('Collect on', 'lunixi') . '</label>';
        foreach ([
            'registration' => __('Registration (sign-up)', 'lunixi'),
            'login' => __('Login (record only)', 'lunixi'),
            'checkout' => __('Checkout (WooCommerce)', 'lunixi'),
        ] as $placement => $label) {
            echo '<label class="lunixi-checkbox"><input type="checkbox" name="lunixi_settings[identity_on_' . esc_attr($placement) . ']" value="1"'
                . checked($this->identityPlacementEnabled($placement), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '<p class="description">' . esc_html__('The block policy only applies to registration. Custom flows: [lunixi_identity] or lunixi_identity_field().', 'lunixi') . '</p></div>';

        echo '</div>';

        submit_button(__('Save changes', 'lunixi'));
        echo '</form></div>';
    }
}
