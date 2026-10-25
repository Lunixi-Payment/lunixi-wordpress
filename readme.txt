=== Lunixi ===
Contributors: lunixi
Tags: payment gateway, woocommerce, sanal pos, 3d secure, taksit
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lunixi payments for WooCommerce: card checkout with 3D Secure, pre-authorization, refunds and order status from signed webhooks.

== Description ==

**Lunixi** is a payment platform. This plugin adds Lunixi to WooCommerce as a payment method and connects your store to the virtual POS (sanal POS) of many Turkish banks and payment institutions through a single integration.

= One integration, many banks =

Connect the virtual POS accounts you already have and take card payments through all of them from one checkout:

* **Banks:** Akbank, Denizbank, Garanti BBVA, Halkbank, İş Bankası, QNB, VakıfBank, Yapı Kredi, Ziraat Bankası (including Ziraat Payflex).
* **Payment institutions:** iyzico, Moka, Paycell, Paynkolay, RubikPara.
* **Smart routing and failover:** Lunixi picks the most suitable connected provider for each payment and, when a provider is down or times out, retries on the next one. Declines such as insufficient funds are never retried.

= Payments =

* **Card payments with 3D Secure** on the classic and the block-based WooCommerce checkout, compatible with High-Performance Order Storage (HPOS).
* **Installments (taksit):** the customer chooses the number of installments on the checkout form, with the options your banks offer.
* **More payment methods:** the checkout shows every method you enable in your Lunixi form settings and connect a provider for.
* **Pre-authorization:** hold the amount at checkout and capture it when you complete the order, or release it by cancelling the order.
* **Refunds:** full or partial, from the WooCommerce admin.
* **Order status from signed webhooks,** not from the browser return, with a periodic status check for any missed webhook.
* **PCI SAQ-A:** card details are typed into the Lunixi checkout form and never reach your server. Your private key and webhook secret are stored encrypted.

= Beyond payments =

Turn on only what you need: fraud screening, subscriptions, identity (multi-account) protection, KYC identity verification, sanction screening, marketplace split payments for vendors and an automation bridge for WooCommerce events.

= Türkçe =

**Lunixi** bir ödeme altyapısıdır. Bu eklenti WooCommerce mağazanıza Lunixi'yi ödeme yöntemi olarak ekler ve mağazanızı tek bir entegrasyonla birçok bankanın ve ödeme kuruluşunun **sanal POS**'una bağlar.

* **Tek entegrasyon, birçok banka:** Akbank, Denizbank, Garanti BBVA, Halkbank, İş Bankası, QNB, VakıfBank, Yapı Kredi ve Ziraat Bankası (Ziraat Payflex dahil) sanal POS'ları ile iyzico, Moka, Paycell, Paynkolay ve RubikPara.
* **Akıllı yönlendirme:** Lunixi her ödeme için bağlı sağlayıcılardan en uygununu seçer; sağlayıcı yanıt vermezse ödemeyi sıradakine aktarır. Yetersiz bakiye gibi retler yeniden denenmez.
* **3D Secure ile kartla ödeme** ve **taksit:** müşteri taksit sayısını ödeme formunda seçer.
* **Ön provizyon, tam ve kısmi iade** WooCommerce yönetim panelinden.
* **Sipariş durumu imzalı webhook'larla** güncellenir; kart bilgisi sunucunuza hiç gelmez (PCI SAQ-A).
* Fraud kontrolü, abonelik, KYC kimlik doğrulama, yaptırım taraması ve pazaryeri (alt bayi) ödemeleri gibi Lunixi hizmetlerini ihtiyacınıza göre açabilirsiniz.

== Installation ==

1. Install and activate the plugin.
2. Go to **Lunixi** in the admin menu and enter your API Key ID, Private Key (PEM) and Webhook Secret from your Lunixi dashboard.
3. Register the shown webhook URL in your Lunixi dashboard.
4. In **WooCommerce → Settings → Payments**, enable **Lunixi**.

== External services ==

This plugin connects your site to Lunixi, a payment platform, and does not work without a Lunixi account. It talks to the following Lunixi services; nothing is sent until you enter your Lunixi credentials.

* **Lunixi API** (`api-gateway.lunixi.com`, or `sandbox-api-gateway.lunixi.com` in test mode). The plugin signs in with your API key, then sends:
  * when a customer pays: the order amount, currency, order number, the return URL of your site and the shop language, so Lunixi can open the checkout form;
  * when you capture, void or refund an order in the WooCommerce admin: the Lunixi payment id and the amount;
  * periodically, for orders still pending: the Lunixi payment id, to read the payment status (in case a webhook was missed);
  * only for the modules you enable: subscription plans and customers (Subscriptions), verification sessions (KYC), the names you screen (Sanction screening), vendor records (Marketplace), WooCommerce order and customer events including the customer e-mail address (Automation), and a device signal collected at sign-up, login and checkout (Identity).
* **Lunixi Checkout** (`cdn.lunixi.com`). The payment page loads Lunixi's checkout script, which shows the card form. The customer types card details directly into Lunixi; they never reach your server. The KYC, Identity, Subscription and Marketplace shortcodes load their Lunixi scripts from the same address.
* **Lunixi portal** (`portal.lunixi.com`, `altbayi.lunixi.com`). Links in the Subscription and Marketplace screens open the hosted Lunixi portal in the customer's or vendor's browser.

Lunixi also sends signed webhook notifications to your site (`/wp-json/lunixi/v1/webhook`) to update order status.

Lunixi [Terms of Service](https://lunixi.com/terms) · [Privacy Policy](https://lunixi.com/privacy)

== Frequently Asked Questions ==

= Does it require WooCommerce? =
The payment gateway requires WooCommerce. Other services work on plain WordPress too.

= Where is card data processed? =
The card form is rendered and tokenised by Lunixi's JS SDK; card data never reaches your server.

== Changelog ==

= 0.2.5 =
* Plugin name is now "Lunixi" (slug `lunixi`) for the WordPress.org plugin directory.
* Tested with WordPress 7.1 and WooCommerce 11.2.
* Admin screens escape all output at the point of printing; Lunixi scripts are enqueued with the plugin version.
* Translations load through WordPress (the plugin no longer calls `load_plugin_textdomain`).
* The readme lists the external Lunixi services the plugin uses and describes the plugin in English and Turkish.

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
