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

Lunixi payments for WordPress and WooCommerce: card checkout with 3D Secure,
pre-authorization, refunds and order status from signed webhooks.

- **WooCommerce payment method**: classic and block-based checkout, compatible
  with High-Performance Order Storage (HPOS).
- **Pre-authorization**: hold the amount at checkout, capture it when you
  complete the order or release it by cancelling the order.
- **Refunds**: full or partial, from the WooCommerce admin.
- **Signed webhooks**: the order state comes from signature-verified webhooks,
  not from the browser return.
- **Encrypted storage**: the private key and the webhook secret are stored
  encrypted.
- **PCI SAQ-A**: the card form is shown and tokenised by Lunixi; card data
  never reaches your server.

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
`vendor/` folder, so install the dependencies with Composer. Pick the latest
version from [tags](https://github.com/Lunixi-Payment/lunixi-wordpress/tags):

```bash
cd wp-content/plugins
git clone --branch vX.Y.Z https://github.com/Lunixi-Payment/lunixi-wordpress.git lunixi
cd lunixi
composer install --no-dev
```

Without Composer on the server, run these steps on your own machine and upload
the whole `lunixi` folder to `wp-content/plugins`. Then activate
**Lunixi for WordPress** on the **Plugins** screen.

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
