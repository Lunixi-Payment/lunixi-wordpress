<p align="center">
  <a href="https://lunixi.com">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/Lunixi-Payment/lunixi-wordpress/main/.github/assets/lunixi-logo-dark.svg">
      <img src="https://raw.githubusercontent.com/Lunixi-Payment/lunixi-wordpress/main/.github/assets/lunixi-logo.svg" alt="Lunixi" height="64">
    </picture>
  </a>
</p>

# Lunixi for WordPress

[![CI](https://github.com/Lunixi-Payment/lunixi-wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/Lunixi-Payment/lunixi-wordpress/actions/workflows/ci.yml) [![Version](https://img.shields.io/github/v/tag/Lunixi-Payment/lunixi-wordpress?label=version)](https://github.com/Lunixi-Payment/lunixi-wordpress/tags) [![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org) [![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588A?logo=woocommerce&logoColor=white)](https://woocommerce.com) [![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net) [![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](https://www.gnu.org/licenses/gpl-2.0.html) [![Docs](https://img.shields.io/badge/docs-docs.lunixi.com-FF6B35)](https://docs.lunixi.com/sdks/wordpress)

**[Documentation](https://docs.lunixi.com/sdks/wordpress) · [Changelog](readme.txt) · [Issues](https://github.com/Lunixi-Payment/lunixi-wordpress/issues) · [Security](https://github.com/Lunixi-Payment/lunixi-wordpress/blob/main/SECURITY.md)**

Lunixi payments for WooCommerce: one integration that connects your store to
the virtual POS (sanal POS) of every bank in Türkiye that offers one and to
payment institutions, with
3D Secure, installments (taksit), pre-authorization, refunds and order status
from signed webhooks.

The payment form opens on your own WooCommerce **order-pay page**. The plugin
loads the existing Lunixi Checkout SDK and mounts the form there; the normal
payment flow does not redirect the buyer to `pay.lunixi.com`. Enabled payment
methods and multi-card eligibility come from your Lunixi form settings. The
payment gateway has no embedded/hosted selector. Subscription portal and
marketplace onboarding links are separate features; bank/3D Secure navigation
may still occur when required.

- **All banks, one POS:** every bank in Türkiye that offers a virtual POS,
  including Akbank, Denizbank, Garanti BBVA, Halkbank, İş Bankası, QNB,
  VakıfBank, Yapı Kredi and Ziraat Bankası (including Ziraat Payflex). If your
  bank is not connected yet, send Lunixi a request and we add it.
- **Payment institutions:** iyzico, Moka, Paycell, Paynkolay, RubikPara.
- **Smart routing and failover:** Lunixi picks the most suitable connected
  provider for each payment and retries on the next one when a provider is down.
- **WooCommerce:** classic and block-based checkout, HPOS compatible.
- **PCI SAQ-A:** card details go straight to the Lunixi checkout form and never
  reach your server.
- **Beyond payments:** fraud screening, subscriptions, KYC, sanction screening,
  identity protection and marketplace split payments.

**Türkçe — tüm bankalar, tek POS:** Lunixi, WooCommerce mağazanızı tek
entegrasyonla sanal POS veren tüm bankalara (Akbank, Garanti BBVA, İş Bankası,
Yapı Kredi, Ziraat Bankası ve diğerleri) ve iyzico, Paycell gibi ödeme
kuruluşlarına bağlar. Bankanız henüz bağlı değilse bize talep iletin, ekleyelim. 3D Secure, taksit, ön provizyon,
iade ve akıllı yönlendirme hazır gelir; kart bilgisi sunucunuza hiç gelmez.

Ödeme formu mağazanızın kendi WooCommerce **sipariş ödeme sayfasında** açılır;
eklenti mevcut Lunixi Checkout SDK'sını bu sayfaya yerleştirir. Normal ödeme
akışında `pay.lunixi.com` yönlendirmesi gerekmez. Yöntemler ve çoklu kart
uygunluğu Lunixi form ayarlarından gelir; ödeme eklentisinde gömülü/yönlendirmeli
mod seçicisi yoktur. Abonelik portalı ve alt bayi kayıt bağlantıları ayrı
özelliklerdir; banka/3D Secure adımı gerektiğinde yönlendirme yapabilir.

## Requirements

| | |
|---|---|
| WordPress | 6.4 or newer |
| WooCommerce | 8.0 or newer (for the payment method) |
| PHP | 7.4 or newer, with `ext-sodium` |
| Composer | to install from source |

## Installation from source

This repository is the plugin's source. The plugin loads the
[Lunixi PHP SDK](https://github.com/Lunixi-Payment/lunixi-php) from its
`vendor/` folder, so install the dependencies with Composer. **v0.2.6 is published
as a [GitHub source tag](https://github.com/Lunixi-Payment/lunixi-wordpress/tree/v0.2.6)**;
this does not establish WordPress.org directory publication or certification.

```bash
cd wp-content/plugins
git clone --branch v0.2.6 https://github.com/Lunixi-Payment/lunixi-wordpress.git lunixi
cd lunixi
composer install --no-dev
```

Without Composer on the server, run these steps on your own machine and upload
the whole `lunixi` folder to `wp-content/plugins`. Then activate
**Lunixi for WordPress** on the **Plugins** screen.

## Upgrade to 0.2.6

The [0.2.6 source release](https://github.com/Lunixi-Payment/lunixi-wordpress/tree/v0.2.6) is published at commit `8db7f9ba7471c4c6cd0c971eeba9ea6d0770175b`; this is not a WordPress.org approval claim. Older pending orders keep their original payment, token and idempotency key. Their saved `_lunixi_checkout_script` is parsed as data and mounted through WordPress script enqueueing; the original HTML/JavaScript is never printed or evaluated. Existing new-format metadata takes precedence and is not rewritten. Available methods, multi-card and insurance options remain in the original form configuration.

Invalid, partial or conflicting saved checkout data stays on reconciliation hold. Retrying checkout for any retained attempt cannot allocate another intent or key. Native paid/authorized/failed holds remain in force. No browser request migrates financial state or marks an order paid. Keep the existing metadata through reconciliation; do not clear it to bypass the hold.

The SDK stays on the 0.28.x line with constraint `^0.28.1` and the verified minimum version **0.28.1**; this metadata correction does not upgrade the SDK minor line. The parser consumes the existing `CheckoutIntent::checkoutFormContent()` contract. **19 focused tests / 124 assertions** passed using native-API doubles and generated fixtures, not a full WordPress/WooCommerce merchant acceptance environment. Complete your existing store's HTTPS TEST checkout/webhook acceptance before deployment.

**Türkçe:** 0.2.6 GitHub kaynak etiketi yayımlandı; bu, WordPress.org yayını veya onayı değildir. Geçerli eski form kaydı aynı ödeme, token ve anahtarla yalnız veri olarak okunur; ham HTML çalıştırılmaz, yeni metadata veya finansal durum yazılmaz. Geçersiz ya da çakışan kayıt mutabakat bekletmesine alınır; tekrar checkout yeni ödeme/anahtar üretemez. Mevcut yöntem, çoklu kart ve sigorta ayarları korunur. SDK 0.28 serisinde kalır; bağımlılık doğrulanmış en düşük sürüm olan `^0.28.1` olarak tanımlanır. 19 odaklı test / 124 doğrulama geçti; canlı mağaza kabulü bu kontrolün parçası değildir.

## Setup

1. **Lunixi → Settings**: choose the environment and enter the Key ID, the
   private key (PEM) and the webhook secret from your Lunixi dashboard.
2. Register the webhook URL shown there
   (`https://your-site.com/wp-json/lunixi/v1/webhook`) in the Lunixi dashboard.
3. **WooCommerce → Settings → Payments**: enable **Lunixi**.

The full guide, settings and troubleshooting are in the
[documentation](https://docs.lunixi.com/sdks/wordpress).

## License

GPL-2.0-or-later.
