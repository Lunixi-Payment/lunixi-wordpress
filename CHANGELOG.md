# Changelog

## 1.0.0 — 2026-11-01

- Breaking: monetary minor units are canonical integer strings in requests, responses and platform settlement records. Numeric money is rejected; counts, rates and quantities retain their existing types.
- Use explicit `captureRemaining` / `refundRemaining` SDK operations for full remaining funds; reject null monetary arguments before transport. Native WooCommerce full-operation hooks call these helpers.
- Convert persisted decimal text with the currency exponent; retain exact values above the JavaScript safe-integer limit up to signed64 for fiat. Capture/refund omission retains its existing meaning; explicit zero is invalid.
- Align with PHP SDK 1.0.0 and preserve raw webhook signatures, merchant identity checks, operation keys and order-state ownership. See [MIGRATION-1.0.md](MIGRATION-1.0.md) before upgrading.
