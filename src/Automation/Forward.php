<?php

declare(strict_types=1);

namespace Lunixi\WP\Automation {

    defined('ABSPATH') || exit;

    /**
     * Static facade over the automation bridge so theme/plugin code (e.g. a form
     * plugin) can forward a supported event without holding the module instance.
     * The module wires the bridge at boot; calls before that (or when automation
     * is disabled) are silently dropped.
     */
    final class Forward
    {
        private static ?AutomationBridge $bridge = null;

        public static function setBridge(AutomationBridge $bridge): void
        {
            self::$bridge = $bridge;
        }

        /**
         * @param array<string,mixed> $data
         */
        public static function send(string $event, array $data = [], string $eventId = ''): void
        {
            if (self::$bridge !== null) {
                self::$bridge->send($event, $data, $eventId);
            }
        }
    }
}

namespace {

    /**
     * Forward a custom event to Lunixi automation. `$event` must be one the
     * gateway maps (order.created/paid/cancelled, customer.created,
     * form.submitted, product.low_stock). No-op unless automation is enabled.
     *
     * @param array<string,mixed> $data
     */
    function lunixi_automation_send(string $event, array $data = [], string $eventId = ''): void
    {
        \Lunixi\WP\Automation\Forward::send($event, $data, $eventId);
    }
}
