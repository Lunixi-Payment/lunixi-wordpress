<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\Sdk\Kyc\AssuranceLevel;

defined('ABSPATH') || exit;

/**
 * Per-user verification state — a LIFECYCLE, not a boolean (owner review §1). The
 * gate authority is `_lunixi_kyc_state`, which is distinct from the raw session
 * status so an AML rescreen / sanctions hit on an already-APPROVED session closes
 * the gates. Assurance is stored per-domain (JSON map) and every gate honours
 * expiry. No PII is ever stored — only status, levels, ids and timestamps.
 */
final class VerificationStore
{
    // Lifecycle states (the gate authority).
    public const STATE_NONE = 'NONE';
    public const STATE_IN_PROGRESS = 'IN_PROGRESS';
    public const STATE_VERIFIED = 'VERIFIED';
    public const STATE_PENDING_RESCREEN = 'PENDING_RESCREEN';
    public const STATE_SUSPENDED = 'SUSPENDED';
    public const STATE_REVOKED = 'REVOKED';
    public const STATE_EXPIRED = 'EXPIRED';
    public const STATE_REJECTED = 'REJECTED';

    public const META_STATE = '_lunixi_kyc_state';
    public const META_ASSURANCE = '_lunixi_kyc_assurance'; // JSON per-domain map
    public const META_DECISION = '_lunixi_kyc_decision';
    public const META_RISK = '_lunixi_kyc_risk';
    public const META_SESSION_ID = '_lunixi_kyc_session_id';
    public const META_SUBJECT_TYPE = '_lunixi_kyc_subject_type';
    public const META_EXPIRES_AT = '_lunixi_kyc_expires_at';
    public const META_UPDATED_AT = '_lunixi_kyc_updated_at';

    public function state(int $userId): string
    {
        $state = (string) get_user_meta($userId, self::META_STATE, true);
        return $state !== '' ? $state : self::STATE_NONE;
    }

    public function decision(int $userId): string
    {
        return (string) get_user_meta($userId, self::META_DECISION, true);
    }

    public function sessionId(int $userId): string
    {
        return (string) get_user_meta($userId, self::META_SESSION_ID, true);
    }

    /** @return array<string,string> per-domain assurance map (domain => level). */
    public function assurance(int $userId): array
    {
        $raw = (string) get_user_meta($userId, self::META_ASSURANCE, true);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function assuranceFor(int $userId, string $domain): string
    {
        $map = $this->assurance($userId);
        $level = $map[$domain] ?? AssuranceLevel::NONE;
        return is_string($level) && $level !== '' ? $level : AssuranceLevel::NONE;
    }

    public function expiresAt(int $userId): ?string
    {
        $value = (string) get_user_meta($userId, self::META_EXPIRES_AT, true);
        return $value !== '' ? $value : null;
    }

    public function isExpired(int $userId): bool
    {
        $expires = $this->expiresAt($userId);
        if ($expires === null) {
            return false;
        }
        $ts = strtotime($expires);
        return $ts !== false && $ts <= time();
    }

    /**
     * The gate check. Passes only when state is VERIFIED, not expired, and every
     * required per-domain minimum is met.
     *
     * @param array<string,string> $requirements domain => minimum AssuranceLevel
     */
    public function isVerified(int $userId, array $requirements = []): bool
    {
        if ($this->state($userId) !== self::STATE_VERIFIED) {
            return false;
        }
        if ($this->isExpired($userId)) {
            return false;
        }
        if ($requirements === []) {
            $requirements = [\Lunixi\Sdk\Kyc\AchievedAssurance::IDENTITY => AssuranceLevel::LOW];
        }
        foreach ($requirements as $domain => $minLevel) {
            $minLevel = strtoupper((string) $minLevel);
            // A required level of NONE/NOT_PERFORMED is "no requirement" — skip it
            // rather than letting meets(NONE,NONE)=true read like a satisfied
            // domain. Effective requirements need LOW or higher.
            if ($minLevel === AssuranceLevel::NONE || $minLevel === AssuranceLevel::NOT_PERFORMED || $minLevel === '') {
                continue;
            }
            if (!AssuranceLevel::meets($this->assuranceFor($userId, (string) $domain), $minLevel)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The audit snapshot written onto gated objects (e.g. a WooCommerce order)
     * at decision time — survives a later revoke/expiry.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $userId): array
    {
        return [
            'state' => $this->state($userId),
            'assurance' => $this->assurance($userId),
            'decision' => $this->decision($userId),
            'sessionId' => $this->sessionId($userId),
            'expiresAt' => $this->expiresAt($userId),
            'capturedAt' => gmdate('c'),
        ];
    }

    /**
     * Applies a new state + (optional) attributes, fires the transition action.
     *
     * @param array<string,mixed> $attrs assurance (map), decision, risk, sessionId, subjectType, expiresAt
     */
    public function transition(int $userId, string $newState, array $attrs = []): void
    {
        $old = $this->state($userId);

        update_user_meta($userId, self::META_STATE, $newState);
        if (isset($attrs['assurance']) && is_array($attrs['assurance'])) {
            update_user_meta($userId, self::META_ASSURANCE, (string) wp_json_encode($attrs['assurance']));
        }
        foreach ([
            'decision' => self::META_DECISION,
            'risk' => self::META_RISK,
            'sessionId' => self::META_SESSION_ID,
            'subjectType' => self::META_SUBJECT_TYPE,
            'expiresAt' => self::META_EXPIRES_AT,
        ] as $key => $meta) {
            if (isset($attrs[$key]) && $attrs[$key] !== null && $attrs[$key] !== '') {
                update_user_meta($userId, $meta, (string) $attrs[$key]);
            }
        }
        update_user_meta($userId, self::META_UPDATED_AT, gmdate('c'));

        if ($newState !== $old) {
            /**
             * Verification lifecycle transition. Hook this to grant/revoke roles,
             * send mail, etc. A move OUT of VERIFIED (rescreen/suspend/expire) must
             * revoke access.
             */
            do_action('lunixi_kyc_state_changed', $userId, $old, $newState, $this->sessionId($userId));
        }
    }
}
