/**
 * Lunixi — device-fingerprint collection (sign-up / login / checkout / custom).
 *
 * Loads the browser identity SDK (window.Lunixi.identity) and runs the real
 * session flow: bootstrap a signed SDK session against the publishable-key
 * endpoint (POST /api/v1/identity/sdk/init, origin-bound, CORS `*`), then
 * analyze() to fingerprint the device and obtain a `traceId`. The traceId is
 * written into a hidden field and POSTed with the form; the WP backend resolves
 * it via identity()->accountLinks() to detect a shared device used across many
 * accounts.
 *
 * The publishable key is browser-safe by design (origin-bound, server-validated),
 * so there is no BFF: the SDK talks to the gateway directly. `productContext`
 * selects the init type (live_session for sign-up/login, payment_form at
 * checkout, …). Fail-open: if the SDK is blocked the field stays empty.
 *
 * A global `window.lunixiIdentityCollect(opts)` is exposed so custom/headless
 * payment flows can collect a traceId themselves; it returns a Promise<string>.
 */
(function () {
	'use strict';

	var cfg = window.lunixiIdentity || {};

	function bootstrapUrl(apiBase) {
		return String(apiBase || '').replace(/\/+$/, '') + '/api/v1/identity/sdk/init';
	}

	function extractTrace(result) {
		if (!result) {
			return '';
		}
		return result.traceId || result.trace_id || (result.data && result.data.traceId) || '';
	}

	/**
	 * Collect a device traceId. Returns a Promise that resolves to the traceId
	 * (or '' on any failure — never rejects, so callers stay fail-open).
	 *
	 * opts: { apiBase, publishableKey, context, subjectType, subjectId, accountId,
	 *         locale }  — all default to the page config (window.lunixiIdentity).
	 */
	function collect(opts) {
		opts = opts || {};
		var apiBase = opts.apiBase || cfg.apiBase;
		var publishableKey = opts.publishableKey || cfg.publishableKey;

		if (!apiBase || !publishableKey) {
			return Promise.resolve('');
		}
		if (!window.Lunixi || !window.Lunixi.identity || typeof window.Lunixi.identity.createClient !== 'function') {
			return Promise.resolve('');
		}

		try {
			var client = window.Lunixi.identity.createClient({
				apiBaseUrl: apiBase,
				sessionBootstrapUrl: bootstrapUrl(apiBase),
				sessionBootstrapHeaders: { Authorization: 'PublishableKey ' + publishableKey },
				integrationMode: 'gateway_integrated',
				productContext: opts.context || cfg.context || 'live_session',
				subjectType: opts.subjectType || cfg.subjectType || undefined,
				subjectId: opts.subjectId || cfg.subjectId || undefined,
				account: opts.accountId || cfg.accountId ? { accountId: opts.accountId || cfg.accountId } : undefined,
				locale: opts.locale || cfg.locale || undefined
			});

			return Promise.resolve(client.init())
				.then(function () {
					return typeof client.analyze === 'function' ? client.analyze() : null;
				})
				.then(extractTrace)
				.catch(function () { return ''; });
		} catch (e) {
			return Promise.resolve('');
		}
	}

	// Public helper for custom / headless payment flows.
	window.lunixiIdentityCollect = collect;

	// Auto-run for the configured hidden field on this page (if any).
	var field = document.getElementById(cfg.fieldId || 'lunixi_identity_trace');
	if (field) {
		collect().then(function (traceId) {
			if (traceId) {
				field.value = traceId;
			}
		});
	}
})();
