# Migrating to 1.0.0

Version 1.0.0 introduces a breaking cutover. Upgrade the API, PHP SDK and affected integration together; do not send numeric money to the new API.

All monetary minor units are strings: `"1000"`, not a JSON number. Request builders, array payloads, response accessors, basket prices, note schedules, split legs, payment links, subscription prices, premiums, fees and balances keep their existing field names. Counts, timestamps, installment counts, rates and quantities keep their existing types.

```php
declare(strict_types=1);

use Lunixi\Sdk\Commerce\Money;
use Lunixi\Sdk\Payment\CreateIntentRequest;

$currency = 'KWD'; // Use the order's persisted currency.
$minor = Money::minor('12.345', Money::exponent($currency)); // '12345'
$request = new CreateIntentRequest($minor, $currency, 'order-42');
$remaining = Money::subtract('9007199254740993', '1');
```

Use decimal text from the commerce platform's persisted DECIMAL fields. Never cast a float into a string, multiply by 100, or use PHP arithmetic/comparison operators for money. `Money::add`, `subtract`, `compare`, `minimum` and `prorate` operate exactly. `Money::decimal` and `minor` require an explicit exponent; JPY uses 0, TRY/USD 2, and KWD 3. Unsupported currencies fail closed.

Positive money matches `[1-9][0-9]*`; nonnegative amounts such as zero-price basket items use `0` or that positive form. Signed balances also permit a leading minus on nonzero values. Whitespace, leading zeroes, plus signs, negative zero, decimal/exponent notation, floats and numeric JSON values are rejected. Fiat payment amounts must not exceed `9223372036854775807`; providers and native platforms can impose smaller limits. The crypto wire domain is not globally limited to signed64.

When calling the PHP SDK directly, use `captureRemaining($intentId, $idempotencyKey)` or `refundRemaining($intentId, $reason, $idempotencyKey)` to request the remaining amount; these methods omit `amount` from the wire body. Amount-bearing `capture()` and `refund()` require a positive canonical string and reject explicit `null`, numeric values and `"0"`. Native commerce mutation helpers retain their requirement for a positive explicit amount when reserving an operation; these SDK methods do not bypass that reservation.

Retain signed webhook bytes unchanged for verification. Do not rewrite event payloads, clear deduplication records, regenerate keys, or reopen paid orders during this upgrade. Old numeric snapshots and unresolved operations are not silently converted: they remain held for reconciliation. Preserve their original request body, fingerprint and idempotency key. Deploy after reconciling old pending operations, or provide a separately reviewed legacy migration that preserves those identities; an unsafe historical numeric value cannot be recovered from its rounded form.

Composer consumers require `lunixi/php-sdk:^1.0`. The merchant ZIP builder resolves the exact SDK version and public commit in `bin/sdk-release.json` from Packagist, checks both source/dist references and records `published` provenance. Run `bash bin/build-dist.sh` and `php bin/check-dist.php build/dist/lunixi` to validate that registry-built artifact before distribution. A source tag alone is not a verified WordPress merchant ZIP. For explicit development acceptance only, `SDK_SOURCE` and `SDK_SOURCE_REFERENCE` can select the exact clean reviewed SDK checkout; that artifact records `reviewed-local` provenance and does not satisfy the public release gate.

WooCommerce refund callers must supply the persisted decimal string. A float-only custom caller is rejected; reconstructing exact cents from a binary float is unsupported. External refund reconciliation sums each persisted refund amount rather than WooCommerce’s floating-point aggregate.
