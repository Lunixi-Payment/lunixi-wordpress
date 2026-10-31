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
| Merchant Composer | not required for the installer ZIP |
| Build tools only | PHP 8.2+, Composer 2, ext-zip and PHP-Scoper 0.18.x |

## Install the merchant ZIP

Use the reviewed installer ZIP and SHA256SUMS from [GitHub Releases](https://github.com/Lunixi-Payment/lunixi-wordpress/releases). In WordPress choose **Plugins → Add New → Upload Plugin**, select the ZIP, install and activate it. Composer is not needed on the store server. The 0.2.7 ZIP described here is a review candidate until its release is published; this is separate from WordPress.org approval.

The installer privately namespaces the bundled **PHP SDK 0.29.6**, pinned to source `29fb3ac2f0fdff7a3825e189572c7aef4b212cc1`, under `Lunixi\WP\Vendor`. Its standalone `vendor/scoped-autoload.php` preserves the public plugin classes and theme helpers while avoiding another plugin's SDK classes. Source development still declares the independently tested **`^0.28.1`** minimum; the distribution's explicit SDK pin is recorded in `vendor/scoped-package.json` with its MIT license notice.

**Türkçe:** Mağazada Composer gerekmez. İncelenmiş sürüm ZIP'ini ve SHA256SUMS dosyasını Releases sayfasından alın; WordPress → Eklentiler → Yeni Ekle → Eklenti Yükle ile kurun. 0.2.7 henüz inceleme adayıdır; yayımlanmadan hazır indirilebilir sürüm veya WordPress.org onayı sayılmaz. ZIP, SDK 0.29.6'yı eklentiye özel namespace ve bağımsız autoloader ile taşır. Kaynak geliştirme bağımlılığının `^0.28.1` alt sınırı ile ZIP'in sabit gömülü sürümü farklıdır.

## Development checkout and local build

This repository is the development source. The development checkout loads the
[Lunixi PHP SDK](https://github.com/Lunixi-Payment/lunixi-php) from its
`vendor/` folder, so install the dependencies with Composer. Pick the latest
version from [tags](https://github.com/Lunixi-Payment/lunixi-wordpress/tags):

```bash
cd wp-content/plugins
git clone --branch vX.Y.Z https://github.com/Lunixi-Payment/lunixi-wordpress.git lunixi
cd lunixi
composer install --no-dev
```

Keep that unscoped development checkout in a disposable development environment. For a merchant installer, build on your own machine with the build-only requirements above:

```bash
bash bin/build-dist.sh
php bin/check-dist.php build/dist/lunixi
php bin/check-dist.php --negative-control build/dist/lunixi
```

The same command works in the public repository root or under the monorepo's WordPress directory. It resolves the immutable public SDK pin using a new Composer config/cache with plugins/scripts disabled, preserves HOME, runs PHP-Scoper and verifies every SDK import and an intentionally conflicting unscoped client. Output: `build/dist/lunixi-0.2.7.zip` and `build/dist/SHA256SUMS`. Upload that ZIP. Build tools, tests, acceptance harness, Composer autoload files and developer docs are excluded from the installer.

## Upgrade to 0.2.7 (review candidate)

Version 0.2.7 is a review candidate based on the published 0.2.6 source. It fixes native WordPress header normalization and requires a merchant-authenticated payment GET matching the original payment, order number, currency, principal and complete unrefunded settlement before marking an order paid. Failed or conflicting evidence returns a retryable webhook response and keeps the same attempt available for reconciliation.

CREATE lookup/reservation/request, webhook, final partial-capture and cron paid transitions share a native per-order database lock and re-read. The hashed lock scope includes database, table prefix and blog identity; unrelated shops on one MySQL server do not share an order-number lock. CPT and HPOS use their supported native payment lookup paths. New attempts reserve a UUID idempotency key and the original native snapshot before the create request; concurrent checkout, a lost response or an existing attempt cannot mint another key. Existing keys, tokens and checkout configuration remain unchanged. The SDK dependency remains <code>^0.28.1</code>, tested at its 0.28.1 floor. Cron explicitly verifies complete split parents missed by webhooks, including when that SDK version does not classify their status as captured.

The order principal stays separate from the charged final amount. Optional authenticated <code>details.orderAmountMinor</code> must match the frozen native principal; captured/refunded balances and complete split-parent evidence are checked separately. Servers without that field can complete only unchanged-amount legacy payments; unexplained installment/premium differences stay on reconciliation hold. Client metadata and merchant commission never authorize a difference. A split leg, partial aggregate, compensation or refund cannot prove a complete paid order.

The [portable native acceptance procedure](docs/ACCEPTANCE.md) covers real isolated WordPress/WooCommerce/MariaDB, CPT/HPOS, actual merchant-page HTTP and two-process webhook/cron races with a fully synthetic Lunixi transport. It is separate from a live merchant/bank/3DS test and WordPress.org approval. WooCommerce receipt links retain the native order-key capability boundary; keep these URLs private. No browser return parameter proves payment.

**Türkçe:** 0.2.7, yayımlanmış 0.2.6 üzerinden hazırlanan inceleme adayıdır. WordPress başlık normalizasyonu düzeltilir; ödeme tamamlanmadan önce imzalı olay ile kimliği doğrulanmış GET sonucu özgün ödeme, sipariş, döviz, ana tutar ve kesin tahsilat açısından eşleştirilir. Eksik/çelişkili kanıt tekrar denenebilir durumda kalır. İlk ödeme oluşturma, anahtar ayırma, webhook ve cron aynı sipariş kilidini kullanır; iki eşzamanlı checkout tek ödeme/anahtar, iki tamamlama süreci tek stok düşümü üretmelidir. Kilit veritabanı/tablo/site kimliğine göre ayrılır. Kaçırılmış tamamlanmış split-parent olayı cron ile doğrulanır; leg veya eksik tahsilat ödenmiş sayılmaz. Ana sepet tutarı ile taksit/prim içeren tahsilat ayrı tutulur; yalnız kanonik <code>details.orderAmountMinor</code> açıklaması kabul edilir. Bu alanı sunmayan sunucuda açıklanamayan fark mutabakat bekler. SDK <code>^0.28.1</code>, eski ödeme/token/anahtar ve form ayarları korunur. Gerçek yerel platform + taklit ağ testleri canlı banka/3DS kabulü veya WordPress.org onayı değildir.

## Previous 0.2.6 checkout compatibility

The 0.2.6 source tag is published on GitHub; this is separate from WordPress.org directory approval. Older pending orders keep their original payment, token and idempotency key. Their saved `_lunixi_checkout_script` is parsed as data and mounted through WordPress script enqueueing; the original HTML/JavaScript is never printed or evaluated. Existing new-format metadata takes precedence and is not rewritten. Available methods, multi-card and insurance options remain in the original form configuration.

Invalid, partial or conflicting saved checkout data stays on reconciliation hold. Retrying checkout for any retained attempt cannot allocate another intent or key. Native paid/authorized/failed holds remain in force. No browser request migrates financial state or marks an order paid. Keep the existing metadata through reconciliation; do not clear it to bypass the hold.

The SDK stays on the 0.28.x line with constraint `^0.28.1` and the verified minimum version **0.28.1**; this metadata correction does not upgrade the SDK minor line. The parser consumes the existing `CheckoutIntent::checkoutFormContent()` contract. The original 0.2.6 focused checks used native-API doubles and generated fixtures. The additional native isolated 0.2.7 procedure above still does not replace your existing store's HTTPS TEST checkout/webhook acceptance.

**Türkçe:** 0.2.6 GitHub kaynak etiketi yayımlanmıştır; WordPress.org onayı ayrı süreçtir. Geçerli eski form kaydı aynı ödeme, token ve anahtarla yalnız veri olarak okunur; ham HTML çalıştırılmaz, yeni metadata veya finansal durum yazılmaz. Geçersiz ya da çakışan kayıt mutabakat bekletmesine alınır; tekrar checkout yeni ödeme/anahtar üretemez. Mevcut yöntem, çoklu kart ve sigorta ayarları korunur. SDK 0.28 serisinde kalır; bağımlılık doğrulanmış en düşük sürüm olan `^0.28.1` olarak tanımlanır; canlı mağaza kabulü bu odaklı uyumluluk kontrolünün parçası değildir.

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
