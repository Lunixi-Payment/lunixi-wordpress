<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

defined('ABSPATH') || exit;

/**
 * Minor-unit money helpers. Gateway amounts are integers in the currency's minor
 * unit (and arrive over webhooks as STRINGS). Never use floats for the conversion
 * boundary; format for display only.
 */
final class Money
{
    /** ISO-4217 minor-unit exponent (digits after the decimal). */
    public static function exponent(string $currency): int
    {
        $currency = strtoupper($currency);
        if ($currency === 'JPY') {
            return 0;
        }
        if (in_array($currency, ['KWD', 'BHD', 'OMR'], true)) {
            return 3;
        }
        return 2;
    }

    /** WooCommerce major amount (float) → minor-unit integer. */
    public static function toMinor(float $major, string $currency): int
    {
        return (int) round($major * (10 ** self::exponent($currency)));
    }

    /** Minor-unit value (int or numeric string from a webhook) → major amount (float). */
    public static function toMajor($minor, string $currency): float
    {
        $minorInt = is_string($minor) ? (int) $minor : (int) $minor;

        return $minorInt / (10 ** self::exponent($currency));
    }

    /** Localised, currency-formatted display string for a minor-unit value. */
    public static function format($minor, string $currency): string
    {
        $major = self::toMajor($minor, $currency);
        if (function_exists('wc_price')) {
            return wp_strip_all_tags(wc_price($major, ['currency' => strtoupper($currency)]));
        }

        return number_format($major, self::exponent($currency)) . ' ' . strtoupper($currency);
    }
}
