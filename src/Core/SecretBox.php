<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

defined('ABSPATH') || exit;

/**
 * Authenticated encryption for secrets at rest (Ed25519 private key, webhook
 * secret) using libsodium secretbox, keyed from the site's `wp_salt('auth')`.
 * Secrets are never stored in plaintext options. If the site salts are rotated,
 * the stored secret can no longer be decrypted and the merchant re-enters it.
 */
final class SecretBox
{
    private const PREFIX = 'v1:';

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (strncmp($stored, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return $stored; // tolerate a value saved before encryption was enabled
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        $material = function_exists('wp_salt') ? wp_salt('auth') : 'lunixi-insecure-fallback';

        return sodium_crypto_generichash($material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
