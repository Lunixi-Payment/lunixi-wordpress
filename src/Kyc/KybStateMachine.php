<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

defined('ABSPATH') || exit;

/**
 * KYB (company) lifecycle (design §4.7). A company is verified only once its UBOs
 * are — so we model the lifecycle explicitly rather than collapsing it to a single
 * decision. This tracks the company sub-state + a UBO roll-up alongside the
 * overall verification state (which KycSync still drives to VERIFIED on
 * kyb.approved). Keyed on the initiating WP user.
 *
 * CREATED → COMPANY_VERIFIED → UBO_PENDING → UBO_IN_PROGRESS → READY_FOR_DECISION
 *         → APPROVED | REJECTED
 */
final class KybStateMachine
{
    public const META_STATE = '_lunixi_kyb_state';
    public const META_UBO_TOTAL = '_lunixi_kyb_ubo_total';
    public const META_UBO_APPROVED = '_lunixi_kyb_ubo_approved';
    public const META_UBO_PENDING = '_lunixi_kyb_ubo_pending';

    public const STATE_CREATED = 'CREATED';
    public const STATE_COMPANY_VERIFIED = 'COMPANY_VERIFIED';
    public const STATE_UBO_PENDING = 'UBO_PENDING';
    public const STATE_UBO_IN_PROGRESS = 'UBO_IN_PROGRESS';
    public const STATE_READY_FOR_DECISION = 'READY_FOR_DECISION';
    public const STATE_APPROVED = 'APPROVED';
    public const STATE_REJECTED = 'REJECTED';

    /**
     * @param array<string,mixed> $data
     */
    public function apply(int $userId, string $eventId, string $eventType, array $data): void
    {
        // Webhook delivery is at-least-once and the controller re-runs ALL handlers
        // on any failure, so the UBO counters must be idempotent per event id —
        // a blind re-apply would double-count and prematurely flip a company to
        // READY_FOR_DECISION before all owners are verified.
        $guard = $eventId !== '' ? 'lunixi_kyb_seen_' . md5($userId . '|' . $eventId) : '';
        if ($guard !== '' && get_transient($guard)) {
            return;
        }

        $old = (string) get_user_meta($userId, self::META_STATE, true);

        switch ($eventType) {
            case 'kyb.session.created':
                $this->set($userId, self::STATE_CREATED);
                break;
            case 'kyb.company.verified':
                $this->set($userId, self::STATE_COMPANY_VERIFIED);
                break;
            case 'kyb.company.not_found':
                $this->set($userId, self::STATE_REJECTED);
                break;
            case 'kyb.ubo.pending':
            case 'kyb.related_kyc.required':
                $this->bumpUbo($userId, +1);
                $this->set($userId, self::STATE_UBO_IN_PROGRESS);
                break;
            case 'kyb.related_kyc.completed':
                $this->completeOneUbo($userId);
                break;
            case 'kyb.approved':
                $this->set($userId, self::STATE_APPROVED);
                break;
            case 'kyb.rejected':
            case 'kyb.manual_review.required':
                $this->set($userId, $eventType === 'kyb.rejected' ? self::STATE_REJECTED : $this->state($userId));
                break;
            default:
                return;
        }

        if ($guard !== '') {
            set_transient($guard, 1, MONTH_IN_SECONDS);
        }

        $new = $this->state($userId);
        if ($new !== $old) {
            /** KYB lifecycle transition (company + UBO roll-up). */
            do_action('lunixi_kyb_state_changed', $userId, $old, $new, $data);
        }
    }

    public function state(int $userId): string
    {
        $state = (string) get_user_meta($userId, self::META_STATE, true);
        return $state !== '' ? $state : self::STATE_CREATED;
    }

    private function set(int $userId, string $state): void
    {
        update_user_meta($userId, self::META_STATE, $state);
    }

    private function bumpUbo(int $userId, int $delta): void
    {
        $total = (int) get_user_meta($userId, self::META_UBO_TOTAL, true) + max(0, $delta);
        $pending = (int) get_user_meta($userId, self::META_UBO_PENDING, true) + $delta;
        update_user_meta($userId, self::META_UBO_TOTAL, (string) $total);
        update_user_meta($userId, self::META_UBO_PENDING, (string) max(0, $pending));
    }

    private function completeOneUbo(int $userId): void
    {
        $approved = (int) get_user_meta($userId, self::META_UBO_APPROVED, true) + 1;
        $pending = max(0, (int) get_user_meta($userId, self::META_UBO_PENDING, true) - 1);
        update_user_meta($userId, self::META_UBO_APPROVED, (string) $approved);
        update_user_meta($userId, self::META_UBO_PENDING, (string) $pending);
        // All UBOs done → ready for the final company decision.
        if ($pending === 0 && $this->state($userId) !== self::STATE_APPROVED) {
            $this->set($userId, self::STATE_READY_FOR_DECISION);
        }
    }
}
