<?php

declare(strict_types=1);

namespace Lunixi\WP\Identity;

use Lunixi\WP\Core\Settings;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Login-time device signal. Renders the collector on the WP + WooCommerce login
 * forms and, after a successful sign-in, records which device the account came
 * from and whether that device is shared with other accounts. Never blocks — a
 * shared device at login (family/office machine) is informational and fires
 * `lunixi_identity_login_multi_account` for step-up / alerting.
 */
final class Login
{
    private Collector $collector;
    private LinkResolver $resolver;
    private Settings $settings;

    public function __construct(Collector $collector, LinkResolver $resolver, Settings $settings)
    {
        $this->collector = $collector;
        $this->resolver = $resolver;
        $this->settings = $settings;
    }

    public function register(): void
    {
        add_action('login_form', [$this, 'renderField']);
        add_action('woocommerce_login_form', [$this, 'renderField']);
        add_action('wp_login', [$this, 'onLogin'], 20, 2);
    }

    public function renderField(): void
    {
        $this->collector->enqueue('live_session');
        $this->collector->renderField();
    }

    /**
     * @param string  $userLogin
     * @param WP_User $user
     */
    public function onLogin($userLogin, $user): void
    {
        if (!($user instanceof WP_User)) {
            return;
        }
        $this->resolver->recordLogin($user->ID, Collector::traceFromRequest(), $this->settings->identityThreshold());
    }
}
