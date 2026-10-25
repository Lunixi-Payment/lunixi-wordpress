<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

use Lunixi\Sdk\Auth\TokenStoreInterface;

defined('ABSPATH') || exit;

/**
 * Persists the gateway bearer token in a WordPress transient, so each stateless
 * web request reuses it instead of re-signing and re-fetching a fresh token.
 */
final class WpTransientTokenStore implements TokenStoreInterface
{
    public function get(string $key): ?string
    {
        $value = get_transient($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        set_transient($key, $value, max(0, $ttlSeconds));
    }

    public function delete(string $key): void
    {
        delete_transient($key);
    }
}
