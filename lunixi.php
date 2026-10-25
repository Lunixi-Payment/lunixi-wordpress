<?php
/**
 * Plugin Name:       Lunixi for WordPress
 * Plugin URI:        https://lunixi.com/wordpress
 * Description:        Lunixi payments for WordPress & WooCommerce — secure checkout, refunds and webhooks. Modular: payment first, with fraud, subscription, identity, sanction and KYC to follow.
 * Version:           0.2.4
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Lunixi
 * Author URI:        https://lunixi.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lunixi
 * Domain Path:       /languages
 *
 * WC requires at least: 8.0
 * WC tested up to:      9.9
 *
 * @package Lunixi\WP
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (defined('LUNIXI_VERSION')) {
    return; // already loaded
}

define('LUNIXI_VERSION', '0.2.4');
define('LUNIXI_FILE', __FILE__);
define('LUNIXI_PATH', plugin_dir_path(__FILE__));
define('LUNIXI_URL', plugin_dir_url(__FILE__));
define('LUNIXI_BASENAME', plugin_basename(__FILE__));

/**
 * Autoloading.
 *
 * Distribution builds ship a PHP-Scoper-prefixed, standalone autoloader at
 * `vendor/scoped-autoload.php` (no Composer needed on the host, no class-name
 * collisions with other plugins bundling the same SDK). In development we fall
 * back to Composer's autoloader. The plugin's own `Lunixi\WP\` classes always
 * load via the lightweight PSR-4 autoloader below.
 */
(static function (): void {
    $scoped = LUNIXI_PATH . 'vendor/scoped-autoload.php';
    $composer = LUNIXI_PATH . 'vendor/autoload.php';
    if (is_readable($scoped)) {
        require $scoped;
    } elseif (is_readable($composer)) {
        require $composer;
    }

    // Plugin classes (Lunixi\WP\ => src/). Independent of Composer.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Lunixi\\WP\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = LUNIXI_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require $file;
        }
    });
})();

// Eagerly load the KYC gate facade so the public `lunixi_kyc_is_verified()` theme
// helper is defined as soon as the plugin loads — even if a theme or another
// plugin calls it before the KYC module boots (it fails closed until the store is
// wired). Defining the global fn is a side effect of loading this file.
(static function (): void {
    $gate = LUNIXI_PATH . 'src/Kyc/Gate.php';
    if (is_readable($gate)) {
        require_once $gate;
    }
})();

// Declare HPOS (custom order tables) + Cart/Checkout Blocks compatibility.
add_action('before_woocommerce_init', static function (): void {
    if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', LUNIXI_FILE, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', LUNIXI_FILE, true);
});

// Boot once all plugins (incl. WooCommerce, if present) are loaded.
add_action('plugins_loaded', static function (): void {
    \Lunixi\WP\Plugin::instance()->boot();
}, 20);

register_activation_hook(__FILE__, static function (): void {
    \Lunixi\WP\Plugin::onActivate();
});
register_deactivation_hook(__FILE__, static function (): void {
    \Lunixi\WP\Plugin::onDeactivate();
});
