<?php
/**
 * Uninstall cleanup. Runs only when the user deletes the plugin.
 *
 * @package Lunixi\WP
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Remove stored settings (encrypted secrets included). Transients expire on their own.
delete_option('lunixi_settings');

// Drop the reconciliation cron events if any are still scheduled.
wp_clear_scheduled_hook('lunixi_payment_reconcile');
wp_clear_scheduled_hook('lunixi_subscription_reconcile');
wp_clear_scheduled_hook('lunixi_kyc_reconcile');
