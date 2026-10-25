<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

defined('ABSPATH') || exit;

/**
 * Thin, debug-gated wrapper over WC_Logger (source "lunixi"). No-op unless
 * WooCommerce logging is available and the merchant enabled "Debug logging" in
 * the Lunixi settings — keeps production logs clean while giving support a
 * switchable trace under WooCommerce → Status → Logs.
 */
final class Logger
{
    private static ?bool $enabled = null;

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        // Errors are always recorded when WC logging exists, regardless of the flag.
        self::log('error', $message, $context, true);
    }

    private static function log(string $level, string $message, array $context, bool $always = false): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        if (!$always && !self::isEnabled()) {
            return;
        }
        if ($context !== []) {
            $message .= ' ' . wp_json_encode($context);
        }
        wc_get_logger()->log($level, $message, ['source' => 'lunixi']);
    }

    private static function isEnabled(): bool
    {
        if (self::$enabled === null) {
            $settings = get_option('lunixi_settings', []);
            self::$enabled = is_array($settings) && !empty($settings['debug']);
        }
        return self::$enabled;
    }
}
