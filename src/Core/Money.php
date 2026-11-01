<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

use Lunixi\Sdk\Commerce\Money as ExactMoney;

defined('ABSPATH') || exit;

/** Exact decimal strings at the WooCommerce boundary; no floating point money. */
final class Money
{
    public static function exponent(string $currency): int
    {
        return ExactMoney::exponent($currency);
    }

    /** @param mixed $major Persisted WooCommerce decimal text, never a float. */
    public static function toMinor($major, string $currency): string
    {
        return ExactMoney::minor($major, self::exponent($currency));
    }

    public static function toMajor(string $minor, string $currency): string
    {
        return ExactMoney::decimal($minor, self::exponent($currency));
    }

    /** Format digits directly so large amounts cannot round during display. */
    public static function format(string $minor, string $currency): string
    {
        $parts = explode('.', self::toMajor($minor, $currency));
        $group = function_exists('wc_get_price_thousand_separator') ? wc_get_price_thousand_separator() : ',';
        $decimal = function_exists('wc_get_price_decimal_separator') ? wc_get_price_decimal_separator() : '.';
        $whole = preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', $group, $parts[0]);
        $number = $whole . (isset($parts[1]) ? $decimal . $parts[1] : '');
        if (function_exists('get_woocommerce_price_format') && function_exists('get_woocommerce_currency_symbol')) {
            return wp_strip_all_tags(sprintf(get_woocommerce_price_format(), get_woocommerce_currency_symbol($currency), $number));
        }
        return $number . ' ' . $currency;
    }
}
