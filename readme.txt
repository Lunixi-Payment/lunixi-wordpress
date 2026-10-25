=== Lunixi for WordPress ===
Contributors: lunixi
Tags: payments, woocommerce, payment gateway, 3d secure, webhooks
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lunixi payments for WordPress and WooCommerce — secure card checkout (3-D Secure), refunds and signed webhooks. Modular: payment first; fraud, subscription, identity, sanction and KYC to follow.

== Description ==

Lunixi for WordPress connects your site to the Lunixi platform.

* **WooCommerce payment gateway** — card checkout with 3-D Secure, on both the classic and the new block-based checkout, fully compatible with High-Performance Order Storage (HPOS).
* **Pre-authorization (preauth)** — optionally hold the amount at checkout and capture it when you complete the order, or release it by cancelling the order.
* **Refunds** — issue full or partial refunds from the WooCommerce admin.
* **Signed webhooks** — order status is driven by cryptographically verified webhooks (the source of truth), not by browser redirects.
* **Secrets encrypted at rest** — your private key and webhook secret are stored encrypted.
* **Card data never touches your server** — the card form is rendered by the embedded Lunixi JS SDK and tokenised client-side (PCI SAQ-A).

Other Lunixi services (fraud, subscriptions, identity, sanction screening, KYC) plug into the same module as they are released.

== Installation ==

1. Install and activate the plugin.
2. Go to **Lunixi** in the admin menu and enter your API Key ID, Private Key (PEM) and Webhook Secret from your Lunixi dashboard.
3. Register the shown webhook URL in your Lunixi dashboard.
4. In **WooCommerce → Settings → Payments**, enable **Lunixi**.

== Frequently Asked Questions ==

= Does it require WooCommerce? =
The payment gateway requires WooCommerce. Other services work on plain WordPress too.

= Where is card data processed? =
The card form is rendered and tokenised by Lunixi's JS SDK; card data never reaches your server.

== Changelog ==

= 0.2.4 =
* Requires Lunixi PHP SDK 0.28, now installed from Packagist (`lunixi/php-sdk`).

= 0.2.3 =
* Requires Lunixi PHP SDK 0.21, which adds promissory-note payments (Senetle Ödeme) on the server side. The plugin opens the hosted checkout without pinning a payment method, so promissory notes appear there when you enable them in your form settings; checkout behaviour is otherwise unchanged.

= 0.2.2 =
* Requires Lunixi PHP SDK 0.19, which removed the direct 2D/3D card methods. The plugin does not use them (card payments go through the hosted checkout); checkout behaviour is unchanged.

= 0.2.1 =
* Requires Lunixi PHP SDK 0.18, where `amount` is the only amount field of a payment. The plugin already sends the order total only as `amount`; checkout behaviour is unchanged.

= 0.2.0 =
* Pre-authorization: the "Pre-authorization (preauth)" capture setting now sends the typed `preauth` field to Lunixi, for normal and marketplace checkouts. Saved settings keep working; no action is needed. Capture on Completed and release on Cancelled work as before.
* Requires Lunixi PHP SDK 0.16.

= 0.1.0 =
* Initial scaffold: shared SDK bridge (WordPress HTTP + transient token store), encrypted credential storage, WooCommerce gateway (classic + Blocks, HPOS-safe), and a verified webhook receiver.
