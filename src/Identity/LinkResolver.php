<?php

declare(strict_types=1);

namespace Lunixi\WP\Identity;

use Lunixi\Sdk\Identity\AccountLinks;
use Lunixi\WP\Core\SdkFactory;
use Throwable;

defined('ABSPATH') || exit;

/**
 * Resolves a browser-collected traceId to its linked-account graph, memoised per
 * request and fail-open (SDK/network errors never break a sign-up, login or
 * checkout). Shared by every placement.
 */
final class LinkResolver
{
    private SdkFactory $sdk;

    /** @var array<string,?AccountLinks> */
    private array $cache = [];

    public function __construct(SdkFactory $sdk)
    {
        $this->sdk = $sdk;
    }

    public function resolve(string $traceId): ?AccountLinks
    {
        if ($traceId === '') {
            return null;
        }
        if (array_key_exists($traceId, $this->cache)) {
            return $this->cache[$traceId];
        }

        $links = null;
        if ($this->sdk->isReady()) {
            try {
                $links = $this->sdk->client()->identity()->accountLinks($traceId);
            } catch (Throwable $e) {
                $links = null; // fail-open
            }
        }

        return $this->cache[$traceId] = $links;
    }

    /**
     * Records the device-link outcome on a user and fires the shared action when
     * the device is shared. Returns the resolved links (or null).
     */
    public function recordOnUser(int $userId, string $traceId, int $threshold): ?AccountLinks
    {
        if ($traceId === '') {
            update_user_meta($userId, '_lunixi_identity_signal', 'none');
            return null;
        }

        $links = $this->resolve($traceId);
        if ($links === null) {
            update_user_meta($userId, '_lunixi_identity_signal', 'error');
            return null;
        }

        $shared = $links->isShared($threshold);

        update_user_meta($userId, '_lunixi_identity_signal', 'ok');
        update_user_meta($userId, '_lunixi_identity_trace', $traceId);
        update_user_meta($userId, '_lunixi_identity_account_count', $links->accountCount());
        update_user_meta($userId, '_lunixi_identity_reasons', $links->linkReasons());
        update_user_meta($userId, '_lunixi_identity_flagged', $shared ? '1' : '');

        if ($shared) {
            /**
             * One device tied to several accounts. Listeners can hold the order,
             * route to manual review, notify staff, etc.
             *
             * @param int          $userId
             * @param AccountLinks $links
             */
            do_action('lunixi_identity_multi_account', $userId, $links);
        }

        return $links;
    }

    /**
     * Records a login-time device signal (never blocks) on a separate set of
     * meta + action, so it doesn't clobber the sign-up decision or trigger
     * sign-up listeners. Shared devices are common at login (family/office
     * machines), so this is informational by design.
     */
    public function recordLogin(int $userId, string $traceId, int $threshold): ?AccountLinks
    {
        $links = $this->resolve($traceId);
        if ($links === null) {
            return null;
        }

        update_user_meta($userId, '_lunixi_identity_login_account_count', $links->accountCount());
        update_user_meta($userId, '_lunixi_identity_login_trace', $traceId);

        if ($links->isShared($threshold)) {
            /**
             * A user signed in from a device shared with other accounts. Useful
             * for step-up auth, alerts or risk scoring — not a block.
             *
             * @param int          $userId
             * @param AccountLinks $links
             */
            do_action('lunixi_identity_login_multi_account', $userId, $links);
        }

        return $links;
    }
}
