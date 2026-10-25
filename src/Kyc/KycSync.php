<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\Sdk\Kyc\SessionType;
use Lunixi\Sdk\Webhook\WebhookEvent;
use Lunixi\WP\Core\Logger;
use Lunixi\WP\Plugin;

defined('ABSPATH') || exit;

/**
 * Drives the verification lifecycle from verified webhooks (the authority).
 * Resolves the user via `externalCustomerId` (= WP user id), refreshes the
 * authoritative session for terminal events (per-domain assurance + expiry), and
 * transitions the state machine — so a rescreen / sanctions hit on an already
 * APPROVED user moves them to PENDING_RESCREEN / SUSPENDED and the gates close.
 *
 * Verification validity (expiry) defaults to 365 days (filterable) since the
 * session's own TTL is short-lived; gates honour it via VerificationStore.
 */
final class KycSync
{
    private VerificationStore $store;
    private KybStateMachine $kyb;

    public function __construct(VerificationStore $store)
    {
        $this->store = $store;
        $this->kyb = new KybStateMachine();
    }

    public function handle(WebhookEvent $event): void
    {
        if (!$event->matches('kyc.*') && !$event->matches('kyb.*') && !$event->matches('screening.*')) {
            return;
        }
        $data = $event->data();
        $sessionId = isset($data['sessionId']) ? (string) $data['sessionId'] : '';
        $userId = $this->resolveUser($data, $sessionId);
        if ($userId <= 0) {
            return;
        }

        $type = $event->type();

        // Track the KYB company/UBO lifecycle alongside the overall state.
        if (strpos($type, 'kyb.') === 0) {
            $this->kyb->apply($userId, $event->id(), $type, $data);
        }

        switch ($type) {
            case 'kyc.approved':
            case 'kyb.approved':
            case 'screening.false_positive.cleared':
                $this->onApproved($userId, $sessionId, $type);
                break;
            case 'kyc.rejected':
            case 'kyb.rejected':
                $this->store->transition($userId, VerificationStore::STATE_REJECTED, ['sessionId' => $sessionId, 'decision' => 'REJECTED']);
                do_action('lunixi_kyc_rejected', $userId, $sessionId, $data);
                break;
            case 'kyc.manual_review.required':
            case 'kyb.manual_review.required':
                $this->store->transition($userId, VerificationStore::STATE_IN_PROGRESS, ['sessionId' => $sessionId, 'decision' => 'MANUAL_REVIEW']);
                do_action('lunixi_kyc_manual_review', $userId, $sessionId, $data);
                break;
            case 'kyc.rescreen.required':
                $this->store->transition($userId, VerificationStore::STATE_PENDING_RESCREEN, ['sessionId' => $sessionId]);
                do_action('lunixi_kyc_rescreen_required', $userId, $sessionId, $data);
                break;
            case 'screening.match.detected':
                $this->store->transition($userId, VerificationStore::STATE_SUSPENDED, ['sessionId' => $sessionId]);
                do_action('lunixi_kyc_suspended', $userId, $sessionId, $data);
                break;
            case 'kyc.session.expired':
                $this->store->transition($userId, VerificationStore::STATE_EXPIRED, ['sessionId' => $sessionId]);
                break;
            case 'kyc.session.cancelled':
            case 'kyc.session.abandoned':
                // Non-terminal for our purposes; leave state unless already verified.
                if ($this->store->state($userId) === VerificationStore::STATE_NONE) {
                    $this->store->transition($userId, VerificationStore::STATE_NONE, ['sessionId' => $sessionId]);
                }
                break;
            default:
                // created / initiated / document.received / liveness.completed / processing.started
                if (in_array($this->store->state($userId), [VerificationStore::STATE_NONE, ''], true)) {
                    $this->store->transition($userId, VerificationStore::STATE_IN_PROGRESS, ['sessionId' => $sessionId]);
                }
        }
    }

    private function onApproved(int $userId, string $sessionId, string $eventType): void
    {
        // Re-delivery guard: if already VERIFIED for this same session and not
        // expired, don't re-extend the validity window or re-fire the approved
        // hook (re-mail / re-provision). A re-approval from SUSPENDED/PENDING is a
        // real state change and still proceeds.
        if ($this->store->state($userId) === VerificationStore::STATE_VERIFIED
            && $this->store->sessionId($userId) === $sessionId
            && !$this->store->isExpired($userId)) {
            return;
        }

        $attrs = ['sessionId' => $sessionId, 'decision' => 'APPROVED'];
        $attrs['subjectType'] = strpos($eventType, 'kyb.') === 0 ? 'company' : 'person';

        // Authoritative refresh: per-domain assurance + risk (no PII).
        $plugin = Plugin::instance();
        if ($sessionId !== '' && $plugin->sdk()->isReady()) {
            try {
                $session = $plugin->sdk()->client()->kyc()->getSession($sessionId);

                // EVIDENCE (proof-of-X) is NOT identity verification — never flip the
                // gate state; fire a dedicated hook and stop.
                if ($session->sessionType() === SessionType::EVIDENCE) {
                    do_action('lunixi_evidence_completed', $userId, $sessionId, $session->raw());
                    return;
                }

                $assurance = $session->assurance();
                if ($assurance !== null) {
                    $attrs['assurance'] = $assurance->toMap();
                }
                if ($session->riskLevel() !== '') {
                    $attrs['risk'] = $session->riskLevel();
                }
                if ($session->sessionType() === SessionType::KYB) {
                    $attrs['subjectType'] = 'company';
                }
            } catch (\Throwable $e) {
                Logger::error('kyc refresh failed', ['session' => $sessionId, 'error' => $e->getMessage()]);
            }
        }

        $attrs['expiresAt'] = self::computeExpiry();

        $this->store->transition($userId, VerificationStore::STATE_VERIFIED, $attrs);
        do_action('lunixi_kyc_approved', $userId, $sessionId, $attrs);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function resolveUser(array $data, string $sessionId): int
    {
        $external = isset($data['externalCustomerId']) ? (string) $data['externalCustomerId'] : '';
        if ($external !== '' && ctype_digit($external)) {
            $userId = (int) $external;
            if (get_userdata($userId)) {
                return $userId;
            }
        }
        // Fall back to a user already linked to this session.
        if ($sessionId !== '') {
            $users = get_users([
                'meta_key' => VerificationStore::META_SESSION_ID,
                'meta_value' => $sessionId,
                'number' => 1,
                'fields' => 'ID',
            ]);
            if (is_array($users) && isset($users[0])) {
                return (int) $users[0];
            }
        }
        return 0;
    }

    private static function computeExpiry(): string
    {
        /** Verification validity window in days (gate fails once passed). */
        $days = (int) apply_filters('lunixi_kyc_validity_days', 365);
        $days = $days > 0 ? $days : 365;
        return gmdate('c', time() + $days * DAY_IN_SECONDS);
    }
}
