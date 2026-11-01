# Native WooCommerce acceptance

This runner uses actual WordPress REST headers, WooCommerce orders/products/stock,
native CPT or HPOS persistence, MariaDB named locks, two independent PHP workers
and a loopback HTTP server. Every Lunixi HTTP call is intercepted by a deterministic
WordPress test transport; all other outbound WordPress HTTP and email are blocked.
It generates an unregistered synthetic key in memory. It never needs merchant keys.
The actual merchant-page inline script is executed with an explicit Node VM SDK
stub. That establishes create/mount/configuration behavior, not bank, browser,
3DS, real CDN form rendering or live merchant acceptance.

## Disposable installation only

**Historical acceptance evidence:** the recorded 0.2.7 runs and old SDK floor below retain their original versions and counts; they do not certify a later installer.

Use PHP 8.2+ CLI with sodium, mysqli, curl, JSON and proc_open; Node.js; an isolated
MariaDB database and official WP-CLI. PHP 8.4.1, WordPress 7.1.4, WooCommerce 11.2.1,
MariaDB 11 and the public PHP SDK 0.28.1 floor were actually exercised.

Earlier core candidate 0.2.7 results: **58 native checks in CPT + 58 in HPOS with datastore
caching enabled**, including three real two-process races per storage mode;
The initial core follow-up had **34 isolated PHPUnit tests / 197 assertions**;
the final explicit split-refund evidence regression brings this to **35 / 203**.
Two additional two-process CREATE
races per mode cover successful and lost responses, one payment/key/body and no
new POST when the reserved attempt is retried. Default Gitleaks scan of the
source export reports zero findings; its synthetic negative control is detected.
These numbers describe the isolated acceptance run, not a live merchant store.

Create a NEW WordPress installation and an EMPTY, independently owned database.
Its name must match <code>lunixi_wp_accept*</code>, <code>lunixi_accept*</code>,
<code>lunixi_wp_test*</code> or <code>lunixi_test*</code>.
Set <code>WP_ENVIRONMENT_TYPE</code> to <code>local</code>,
<code>DISABLE_WP_CRON</code> to true, and use a free loopback HTTP port, for example
<code>http://127.0.0.1:18089</code>, as BOTH home and site URL.
Set <code>WP_DEBUG_DISPLAY</code> to false. This is a development server fixture,
not a production TLS configuration.

Install official WordPress and WooCommerce, complete WooCommerce's native tables
installation and activate only <code>woocommerce/woocommerce.php</code> and
<code>lunixi/lunixi.php</code>. Install this candidate source with its Composer
autoload/dependencies into the latter folder. Do not use an existing store,
merchant profile, shared database/schema, customer accounts or mail/provisioning
endpoints. The runner replaces fixture Lunixi settings and creates synthetic
customers, products, orders and status transitions. Destroy the whole owned
fixture/database after testing; it deliberately does not try to undo financial
history through ad-hoc SQL.

The runner refuses a nonlocal database, another active plugin or a nonfixture
merchant key. It must not be exposed as a public web script. Do not copy the
test transport to a store.

## Run both native storage modes

First test a fresh CPT installation with HPOS disabled. The installation and
configured home URL must agree. Run as the same OS user as the PHP worker so
cache files keep their correct ownership. Preserve PHP extensions and the
installation's secret/salt configuration; do not use chmod 777.

~~~bash
export LUNIXI_WP_NATIVE_ACCEPTANCE=1
export LUNIXI_WP_NATIVE_ROOT=/absolute/path/to/disposable/wordpress
fixture_private=$(mktemp -d)
chmod 700 "$fixture_private"
export LUNIXI_WP_NATIVE_STATE="$fixture_private/state.json"
export LUNIXI_WP_NATIVE_HOOKS="$fixture_private/hooks.log"
printf '%s' '{"payments":{},"details":{},"calls":[]}' > "$LUNIXI_WP_NATIVE_STATE"
: > "$LUNIXI_WP_NATIVE_HOOKS"
chmod 600 "$LUNIXI_WP_NATIVE_STATE" "$LUNIXI_WP_NATIVE_HOOKS"
mkdir -p /absolute/path/to/sanitized-evidence
php /path/to/lunixi/bin/native-acceptance.php /absolute/path/to/sanitized-evidence/cpt.json
~~~

Use the actual native WP-CLI HPOS commands, rather than flipping storage flags
under existing orders:

~~~bash
wp --path="$LUNIXI_WP_NATIVE_ROOT" wc hpos sync
wp --path="$LUNIXI_WP_NATIVE_ROOT" wc hpos enable
wp --path="$LUNIXI_WP_NATIVE_ROOT" wc hpos status
printf '%s' '{"payments":{},"details":{},"calls":[]}' > "$LUNIXI_WP_NATIVE_STATE"
: > "$LUNIXI_WP_NATIVE_HOOKS"
php /path/to/lunixi/bin/native-acceptance.php /absolute/path/to/sanitized-evidence/hpos.json
~~~

HPOS datastore caching can be exercised explicitly on versions supporting the
native option <code>woocommerce_hpos_datastore_caching_enabled</code>. The JSON
records whether the feature is actually enabled; do not assume an unavailable
cache backend was tested. An additional Redis/Memcached cross-host acceptance
test is separate.

The main runner creates the native MU directory when missing, verifies its
loader write and asserts a test-only HTTP response marker proving the transport
was loaded. It temporarily creates a uniquely reserved MU loader named
<code>lunixi-native-acceptance.php</code>, refuses to replace an existing file,
starts an owned loopback server and removes its loader/cookie jar in finally.
Parallel child workers share a private transport-state file with file locking;
payment-complete observations use atomic append, not a read/increment option
that could hide a race. Private stderr/server logs may contain native URLs.
Do not publish that temporary directory. Preserve only the sanitized JSON and
the runner source/version; stop and remove only your owned runtime, database,
container and private directory.

## What the assertions mean

The suite covers native create/resume with the same payment/key/config, a lost
create response without a fresh attempt, valid raw-body HMAC and native header
canonicalization, replay, invalid/duplicate critical headers, mismatching GET
payment/order/merchant/currency/amount, missing/partial/refunded balances, GET
timeout, signed-event/GET conflicts, retry without an event done-marker, guarded
final partial-capture, cancellation/manual hold/edited order, authorization,
decline, void and complete/incomplete/compensated split parents.

The original-principal extension is tested using synthetic authenticated
<code>details.orderAmountMinor</code>, a decimal string. This is a server-owned
creation snapshot, not merchant metadata. The test does not prove that an
external gateway deployment already exposes it. A legacy response without
that field may complete only when native and charged totals agree. Any
unexplained installment fee/premium remains on reconciliation hold. The native
invoice principal is not increased to the charged total.

Each storage run first races two native Gateway process_payment calls, after
both processes read that no attempt exists. Both a normal response and a lost
response must produce one created payment, one reserved key and one request
body; SDK network retries reuse that key/body. A later process_payment must
resume or hold the existing attempt and send no additional create request.
Reservation, retained-attempt lookup and the financial POST share the same
native per-order mutex.

Each storage run also races a genuine signed webhook handler against the genuine
reconciler in three rounds. Both independent PHP processes read pending before
a barrier is released. The native DB lock and fresh read must yield one
payment_complete hook, one stock decrement and one settlement note. Adversarial
cron cases invoke the actual per-order path and assert an authenticated GET
was observed, so the batch size cannot hide an unexercised assertion. A
separate test calls public cron selection. Complete split parents with a missed
webhook must be settled by cron; leg, partial and compensated results stay unpaid.
SDK 0.28.1's isCaptured only covers CAPTURED/PARTIALLY_CAPTURED, so the adapter
explicitly routes SETTLED_BY_SPLIT through the same strict parent proof.

HTTP checks exercise native customer login, the merchant order-pay page,
original form mount/configuration, missing/incorrect capability denial,
forged return fields with no financial effect and the real WordPress REST
webhook entry point. WooCommerce receipt links intentionally use the native
unguessable order-key capability, even without a login; the runner preserves
that native flow and never emits these URLs/tokens in its public JSON.

## Operational boundaries

Webhook 503 means the event was not marked done. Retry the SAME event/payment;
cron can also retry authenticated reads. Do not clear a pending attempt or
replace a key because a GET timed out, evidence disagrees or a fee is unexplained.
The native named lock hashes the database, table prefix, blog and order identity
so independent shops on the same server do not contend on the same order number.
No namespace contents are emitted. It requires MySQL/MariaDB GET_LOCK permission and one stable
connection per worker; unsupported lock routing or a failed lock defers the
transition. A crash between a WooCommerce database write and an external email
or third-party hook cannot be rolled back by this lock. Verify the site's own
extensions, external fulfillment and persistent cache under its real hosting
configuration before deploying.

## Türkçe

Bu araç yalnız yeni ve atılabilir yerel WordPress/WooCommerce veritabanında
çalıştırılır. Gerçek sipariş, stok, CPT/HPOS, REST, HTTP ve iki PHP süreci kullanır;
Lunixi yanıtları kontrollü taklittir, kayıtlı merchant anahtarı veya gerçek para
çekimi yoktur. WordPress.org onayı ve canlı banka/3DS kabulü anlamına gelmez.

Yukarıdaki değişkenleri kendi izole kurulumunuza göre ayarlayın. CPT koşusundan
sonra yerel WP-CLI HPOS sync/enable/status komutlarıyla veriyi taşıyıp HPOS
koşusunu çalıştırın. Her koşudan önce yalnız araç için oluşturulan state/hook
dosyalarını sıfırlayın. İki süreç yarışı tek tamamlama/stok düşümü/not üretmelidir.
Hatalı kimlik/tutar/döviz, GET zaman aşımı ve eksik tahsilat kanıtında sipariş
ödenmiş sayılmamalı; aynı olay yeniden denenebilmelidir.

Geçerli yerel sipariş anahtarı WooCommerce'un kendi erişim yetkisidir; URL'yi
gizli tutun. Tarayıcı dönüşü ödeme kanıtı değildir. Taksit/prim farkı ancak
sunucunun kanonik özgün tutarıyla açıklanır; alan yoksa fark mutabakat bekler.
Kanıt olarak yalnız temiz JSON'u saklayın; geçici özel dosyaları ve kendi test
kurulumunuzu kaldırın. Anahtar, token, çerez veya yanıt gövdesini yayımlamayın.

## Final scoped installer publication

[Release 0.2.7](https://github.com/Lunixi-Payment/lunixi-wordpress/releases/tag/v0.2.7) and [passing CI](https://github.com/Lunixi-Payment/lunixi-wordpress/actions/runs/41243895958) publish the standalone SDK 0.29.6 package. All **365** downloaded files match the final native-tested scoped ZIP byte for byte. Public checksum, GitHub digest and ZIP CRC match; default artifact Gitleaks has zero findings. SHA-256: `97617381478ad984a42c5bd546598ddb480185693c7fdcb6b633ec81a492a8e1`. CI also linted all **345 bundled PHP files on PHP 7.4**, separately from source syntax checks on PHP 7.4–8.4.

The final scoped ZIP's focused actual HPOS run exercised create/resume/form mount, authenticated GET/capture, signed replay and one stock effect. Complete split parent paid once; partial, leg and missing-refund cases stayed unpaid. The final unit suite passed **35 tests / 203 assertions**. These focused checks do not claim a rerun of the 116 earlier core checks, a PHP 7.4 native store, a real bank/card/3DS flow or WordPress.org certification.
