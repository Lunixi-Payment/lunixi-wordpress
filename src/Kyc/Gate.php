<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc {

    defined('ABSPATH') || exit;

    /**
     * Verification gate facade — a stable entry point for themes / membership
     * plugins, decoupled from the store wiring. The module sets the store at boot;
     * callers use the static API or the global `lunixi_kyc_is_verified()`.
     */
    final class Gate
    {
        private static ?VerificationStore $store = null;

        public static function setStore(VerificationStore $store): void
        {
            self::$store = $store;
        }

        /**
         * @param int|null              $userId       defaults to the current user
         * @param array<string,string>  $requirements domain => minimum AssuranceLevel
         */
        public static function isVerified(?int $userId = null, array $requirements = []): bool
        {
            if (self::$store === null) {
                return false;
            }
            $userId = $userId ?? get_current_user_id();
            if ($userId <= 0) {
                return false;
            }
            return self::$store->isVerified($userId, $requirements);
        }

        public static function state(?int $userId = null): string
        {
            if (self::$store === null) {
                return VerificationStore::STATE_NONE;
            }
            $userId = $userId ?? get_current_user_id();
            return $userId > 0 ? self::$store->state($userId) : VerificationStore::STATE_NONE;
        }
    }
}

namespace {

    /**
     * Theme/membership helper: is the user identity-verified (optionally to a
     * per-domain requirement)?
     *
     * @param int|null             $userId
     * @param array<string,string> $requirements domain => minimum AssuranceLevel
     */
    function lunixi_kyc_is_verified($userId = null, array $requirements = []): bool
    {
        return \Lunixi\WP\Kyc\Gate::isVerified($userId !== null ? (int) $userId : null, $requirements);
    }
}
