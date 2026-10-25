<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\Sdk\Kyc\SessionType;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Backstop for the verification lifecycle. Hourly cron, two sweeps:
 *   1. Converge — users stuck IN_PROGRESS: refresh the session and apply the
 *      authoritative state (covers missed webhooks).
 *   2. Expiry — users VERIFIED past their `expiresAt`: flip to EXPIRED so gates
 *      close and roles revoke even with no webhook.
 */
final class Reconciler
{
    private const HOOK = 'lunixi_kyc_reconcile';
    private const SCHEDULE = 'lunixi_hourly';
    private const BATCH = 50;

    private VerificationStore $store;
    private KycSync $sync;

    public function __construct(VerificationStore $store, KycSync $sync)
    {
        $this->store = $store;
        $this->sync = $sync;
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedule']);
        add_action(self::HOOK, [$this, 'run']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 900, self::SCHEDULE, self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public function addSchedule(array $schedules): array
    {
        if (!isset($schedules[self::SCHEDULE])) {
            $schedules[self::SCHEDULE] = ['interval' => HOUR_IN_SECONDS, 'display' => __('Hourly (Lunixi)', 'lunixi')];
        }
        return $schedules;
    }

    public function run(): void
    {
        $this->expirySweep();
        $this->convergeSweep();
    }

    private function expirySweep(): void
    {
        $users = get_users([
            'number' => self::BATCH,
            'fields' => 'ID',
            'meta_query' => [
                ['key' => VerificationStore::META_STATE, 'value' => VerificationStore::STATE_VERIFIED],
            ],
        ]);
        foreach ((array) $users as $userId) {
            $userId = (int) $userId;
            if ($this->store->isExpired($userId)) {
                $this->store->transition($userId, VerificationStore::STATE_EXPIRED, ['sessionId' => $this->store->sessionId($userId)]);
            }
        }
    }

    private function convergeSweep(): void
    {
        $plugin = Plugin::instance();
        if (!$plugin->sdk()->isReady()) {
            return;
        }
        $users = get_users([
            'number' => self::BATCH,
            'fields' => 'ID',
            'meta_query' => [
                ['key' => VerificationStore::META_STATE, 'value' => VerificationStore::STATE_IN_PROGRESS],
            ],
        ]);
        foreach ((array) $users as $userId) {
            $userId = (int) $userId;
            $sessionId = $this->store->sessionId($userId);
            if ($sessionId === '') {
                continue;
            }
            try {
                $session = $plugin->sdk()->client()->kyc()->getSession($sessionId);
            } catch (\Throwable $e) {
                Logger::error('kyc reconcile failed', ['session' => $sessionId, 'error' => $e->getMessage()]);
                continue;
            }
            if (!$session->isTerminal()) {
                continue;
            }
            // Reuse the sync mapping by replaying the terminal outcome.
            $this->applyTerminal($userId, $session);
        }
    }

    private function applyTerminal(int $userId, \Lunixi\Sdk\Kyc\KycSession $session): void
    {
        if ($session->isApproved()) {
            $attrs = [
                'sessionId' => $session->sessionId(),
                'decision' => 'APPROVED',
                'subjectType' => $session->sessionType() === SessionType::KYB ? 'company' : 'person',
                'risk' => $session->riskLevel(),
                'expiresAt' => gmdate('c', time() + (int) apply_filters('lunixi_kyc_validity_days', 365) * DAY_IN_SECONDS),
            ];
            $assurance = $session->assurance();
            if ($assurance !== null) {
                $attrs['assurance'] = $assurance->toMap();
            }
            $this->store->transition($userId, VerificationStore::STATE_VERIFIED, $attrs);
            do_action('lunixi_kyc_approved', $userId, $session->sessionId(), $attrs);
        } elseif ($session->isRejected()) {
            $this->store->transition($userId, VerificationStore::STATE_REJECTED, ['sessionId' => $session->sessionId(), 'decision' => 'REJECTED']);
        }
    }
}
