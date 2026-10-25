<?php

declare(strict_types=1);

namespace Lunixi\WP\Identity;

use Lunixi\WP\Core\Settings;
use WP_Error;

defined('ABSPATH') || exit;

/**
 * Multi-account protection at sign-up. The browser collector fingerprints the
 * device and drops a `traceId` into a hidden form field; here we resolve that
 * traceId to its linked-account graph and enforce the configured policy:
 *
 *   off    record the link count on the user, never interfere
 *   flag   record + mark the user for review + fire `lunixi_identity_multi_account`
 *   block  reject the sign-up before the account is created
 *
 * Fail-open: SDK/network errors or a missing traceId never break a sign-up — a
 * legitimate user with JS disabled must still register. A missing signal is
 * recorded as such (`_lunixi_identity_signal`) so it can be reviewed, not
 * silently trusted. Works for both core WP and the WooCommerce my-account form.
 */
final class Registration
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
        add_action('register_form', [$this, 'renderField']);
        add_action('woocommerce_register_form', [$this, 'renderField']);

        // Block before account creation (policy=block only).
        add_filter('registration_errors', [$this, 'validate'], 20, 1);
        add_filter('woocommerce_registration_errors', [$this, 'validate'], 20, 1);

        // Always record the device-link outcome on the new user.
        add_action('user_register', [$this, 'onUserRegister'], 20, 1);
    }

    /** Sign-up is a generic web session (no payment context). */
    public function renderField(): void
    {
        $this->collector->enqueue('live_session');
        $this->collector->renderField();
    }

    /**
     * @param WP_Error $errors
     * @return WP_Error
     */
    public function validate($errors)
    {
        if ($this->settings->identityPolicy() !== 'block') {
            return $errors;
        }

        $links = $this->resolver->resolve(Collector::traceFromRequest());
        if ($links !== null && $links->isShared($this->settings->identityThreshold())) {
            $errors->add(
                'lunixi_identity_multi_account',
                __('We could not create this account. Please contact support if you believe this is an error.', 'lunixi')
            );
        }

        return $errors;
    }

    public function onUserRegister($userId): void
    {
        $this->resolver->recordOnUser((int) $userId, Collector::traceFromRequest(), $this->settings->identityThreshold());
    }
}
