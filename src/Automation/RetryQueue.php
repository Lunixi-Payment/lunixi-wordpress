<?php

declare(strict_types=1);

namespace Lunixi\WP\Automation;

defined('ABSPATH') || exit;

/**
 * Durable, option-backed retry queue for automation deliveries. The ingress
 * contract asks producers to retry 429 / 5xx with exponential backoff, made safe
 * by the per-event `id` (the server de-dupes replays). Transient send failures
 * are parked here and re-attempted by a WP-cron tick; terminal results (2xx,
 * 409 replay, or a 4xx config error) never enter the queue.
 *
 * Each entry: { body: array, attempts: int, nextAt: int(unix) }.
 */
final class RetryQueue
{
    public const OPTION = 'lunixi_automation_retry_queue';
    public const HOOK = 'lunixi_automation_retry_flush';
    public const SCHEDULE = 'lunixi_five_minutes';

    private const MAX_ATTEMPTS = 6;
    private const MAX_QUEUE = 200;

    /** @return array<int,array{body:array<string,mixed>,attempts:int,nextAt:int}> */
    public function all(): array
    {
        $rows = get_option(self::OPTION, []);
        return is_array($rows) ? array_values($rows) : [];
    }

    /** @param array<int,array{body:array<string,mixed>,attempts:int,nextAt:int}> $rows */
    public function save(array $rows): void
    {
        if ($rows === []) {
            delete_option(self::OPTION);
            return;
        }
        // Newest-wins cap: keep the most recent MAX_QUEUE entries.
        if (count($rows) > self::MAX_QUEUE) {
            $rows = array_slice($rows, -self::MAX_QUEUE);
        }
        update_option(self::OPTION, array_values($rows), false);
    }

    /**
     * Parks a delivery for a later attempt. `$attempts` is the count already
     * made (≥1). Drops the entry once it exceeds MAX_ATTEMPTS.
     *
     * @param array<string,mixed> $body
     */
    public function push(array $body, int $attempts, int $now): bool
    {
        if ($attempts >= self::MAX_ATTEMPTS) {
            return false; // give up — caller logs the drop
        }
        $rows = $this->all();
        $rows[] = ['body' => $body, 'attempts' => $attempts, 'nextAt' => $now + self::backoffSeconds($attempts)];
        $this->save($rows);
        return true;
    }

    /** Exponential backoff with a 1h ceiling: 2,4,8,16,32,60 minutes. */
    public static function backoffSeconds(int $attempts): int
    {
        return (int) min(3600, 60 * (2 ** max(1, $attempts)));
    }

    public static function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /** Registers the 5-minute cron schedule + event. Idempotent. */
    public static function schedule(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            if (!isset($schedules[self::SCHEDULE])) {
                $schedules[self::SCHEDULE] = ['interval' => 300, 'display' => __('Every 5 minutes (Lunixi)', 'lunixi')];
            }
            return $schedules;
        });

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 300, self::SCHEDULE, self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::HOOK);
        }
        wp_clear_scheduled_hook(self::HOOK);
    }
}
