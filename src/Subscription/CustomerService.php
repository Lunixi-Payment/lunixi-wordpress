<?php

declare(strict_types=1);

namespace Lunixi\WP\Subscription;

use Lunixi\Sdk\Subscription\UpsertCustomerRequest;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Maps a WordPress user to a Lunixi subscription customer. The WP user id is the
 * stable `externalId`, so the same user always resolves to one customer. The
 * resolved customer id is cached in user meta to avoid repeat upserts.
 */
final class CustomerService
{
    private const USER_META = '_lunixi_customer_id';

    /** Cached customer id for a user, or null if not yet linked. */
    public function customerIdForUser(int $userId): ?string
    {
        $id = (string) get_user_meta($userId, self::USER_META, true);
        return $id !== '' ? $id : null;
    }

    /** @return int|null WP user id linked to a Lunixi customer, or null. */
    public function userIdForCustomer(string $customerId): ?int
    {
        if ($customerId === '') {
            return null;
        }
        $users = get_users([
            'meta_key' => self::USER_META,
            'meta_value' => $customerId,
            'number' => 1,
            'fields' => 'ID',
        ]);

        return (is_array($users) && isset($users[0])) ? (int) $users[0] : null;
    }

    /**
     * Ensures the user has a Lunixi customer, creating (idempotent upsert) one if
     * needed. Returns the customer id, or '' if the SDK is not ready / the call
     * failed (caller decides how to surface that).
     */
    public function ensureCustomer(int $userId): string
    {
        $cached = $this->customerIdForUser($userId);
        if ($cached !== null) {
            return $cached;
        }

        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            return '';
        }
        $user = get_userdata($userId);
        if (!$user) {
            return '';
        }

        try {
            $request = new UpsertCustomerRequest((string) $userId);
            if ((string) $user->user_email !== '') {
                $request->withEmail((string) $user->user_email);
            }
            $name = trim((string) $user->display_name);
            if ($name !== '') {
                $request->withName($name);
            }

            $customer = $plugin->sdk()->client()->subscriptions()->customers()->upsert($request);
            $id = $customer->id();
            if ($id !== '') {
                update_user_meta($userId, self::USER_META, $id);
            }
            return $id;
        } catch (\Throwable $e) {
            Logger::error('customer upsert failed', ['user' => $userId, 'error' => $e->getMessage()]);
            return '';
        }
    }

    /** Links an existing customer id to a user (e.g. discovered from a webhook). */
    public function link(int $userId, string $customerId): void
    {
        if ($userId > 0 && $customerId !== '') {
            update_user_meta($userId, self::USER_META, $customerId);
        }
    }
}
