<?php

declare(strict_types=1);

namespace Lunixi\WP\Payment;

use Lunixi\Sdk\Payment\CreateIntentRequest;

defined('ABSPATH') || exit;

/**
 * The gateway's "Capture" setting and how it reaches the payment intent.
 *
 * The stored option values stay `sale` / `authorize` so settings saved by
 * earlier plugin versions keep their meaning; on every customer-facing surface
 * the second mode is called pre-authorization (`preauth`).
 *
 * Pre-authorization is sent as the typed `preauth` field (SDK withPreauth()),
 * never through `metadata`. Sale sends no field, so a sale-mode request body is
 * the same as before. Normal and marketplace intents go through apply() alike.
 */
final class CaptureMode
{
    public const OPTION = 'capture_mode';
    public const SALE = 'sale';
    public const PREAUTH = 'authorize';

    public static function isPreauth(string $mode): bool
    {
        return $mode === self::PREAUTH;
    }

    public static function apply(CreateIntentRequest $request, string $mode): void
    {
        if (self::isPreauth($mode)) {
            $request->withPreauth(true);
        }
    }
}
