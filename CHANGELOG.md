# Changelog

## 1.1.0 — 2026-11-16

- Built on PHP SDK 1.6.0.
- Bank transfer (Para Transferi) orders are no longer cancelled by WooCommerce's unpaid-order timeout (hold-stock minutes). An order with a Lunixi payment stays pending until Lunixi reports the result; it is cancelled only from an authenticated expired or voided payment.
- A captured payment that arrives for a cancelled or failed order puts the order on-hold with an order note and the `_lunixi_review_reason` meta, instead of failing the webhook until it is dead-lettered. The order is never marked paid automatically.
- A late bank transfer that Lunixi accepts as a new payment for the same order is verified with an authenticated payment read (merchant, order, currency, amount) and puts the order on-hold with a note naming the new payment id. Repeated deliveries add no further notes; a payment for a different order is still rejected.
- Orders held for review are skipped by the payment reconciliation job.

## 1.0.0 — 2026-11-01

- Breaking: monetary minor units are canonical integer strings in requests, responses and platform settlement records. Numeric money is rejected; counts, rates and quantities retain their existing types.
- Use explicit `captureRemaining` / `refundRemaining` SDK operations for full remaining funds; reject null monetary arguments before transport. Native WooCommerce full-operation hooks call these helpers.
- Convert persisted decimal text with the currency exponent; retain exact values above the JavaScript safe-integer limit up to signed64 for fiat. Capture/refund omission retains its existing meaning; explicit zero is invalid.
- Align with PHP SDK 1.0.0 and preserve raw webhook signatures, merchant identity checks, operation keys and order-state ownership. See [MIGRATION-1.0.md](MIGRATION-1.0.md) before upgrading.
