/**
 * Lunixi — embedded customer KYC verification.
 *
 * Loads the self-contained Lunixi KYC SDK (cdn/lunixi-kyc.min.js). The flow is
 * split by auth model:
 *   1. SESSION create stays server-side — our WP BFF (/kyc/session) creates or
 *      resumes the session over the merchant bearer token, binding it to the
 *      logged-in WP user (externalCustomerId). Returns { kycSessionId, merchantId }.
 *   2. SDK token bootstrap is browser-direct — the SDK's `sessionBootstrap`
 *      fetches a signed SDK token from the gateway's POST /api/v1/kyc/sdk/init
 *      using the publishable key (the gateway moved that endpoint to
 *      PublishableKeyGuard / origin-bound, exactly like the identity SDK). The
 *      merchant API credentials never reach the browser; the publishable key is
 *      safe to expose.
 *
 * The verified `kyc.*` webhook is the authority for the user's verification
 * state; on approval we redirect/reload so gates re-evaluate.
 */
(function () {
	'use strict';

	var cfg = window.lunixiKyc || {};
	var mountId = cfg.mountId || 'lunixi-kyc';
	var el = document.getElementById(mountId);
	if (!el) {
		return;
	}

	function fail(message) {
		el.innerHTML = '<p class="lunixi-kyc-error">' + message + '</p>';
	}

	if (!window.Lunixi || !window.Lunixi.kyc || typeof window.Lunixi.kyc.form !== 'function') {
		return fail((cfg.i18n && cfg.i18n.sdkMissing) || 'Verification unavailable.');
	}
	if (!cfg.publishableKey) {
		return fail((cfg.i18n && cfg.i18n.sdkMissing) || 'Verification unavailable.');
	}

	var initEndpoint = String(cfg.apiBase || '').replace(/\/+$/, '') + '/api/v1/kyc/sdk/init';

	// WP BFF call (session create/resume) — same-origin, nonce-protected.
	function postBff(url, body) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify(body || {})
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('http ' + response.status);
			}
			return response.json();
		});
	}

	// Gateway SDK-init call — cross-origin, publishable-key + Origin, NO cookies
	// (so the gateway can serve Access-Control-Allow-Origin: *).
	function bootstrapToken(sessionId) {
		return fetch(initEndpoint, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				'Content-Type': 'application/json',
				'Authorization': 'PublishableKey ' + cfg.publishableKey
			},
			body: JSON.stringify({
				sessionId: sessionId,
				sdkMode: cfg.sdkMode || 'prebuilt',
				sdkFamily: 'web'
			})
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('http ' + response.status);
			}
			return response.json();
		});
	}

	function done() {
		if (cfg.redirect) {
			window.location.href = cfg.redirect;
		} else {
			window.location.reload();
		}
	}

	postBff(cfg.sessionUrl, { type: cfg.type, purpose: cfg.purpose || '' })
		.then(function (session) {
			var handle = window.Lunixi.kyc.form({
				apiBaseUrl: cfg.apiBase,
				platformBaseUrl: cfg.apiBase,
				merchantId: session.merchantId,
				kycSessionId: session.kycSessionId,
				locale: cfg.locale || undefined,
				sessionBootstrap: function () {
					return bootstrapToken(session.kycSessionId);
				},
				onApproved: function () { done(); },
				onRejected: function () { /* the SDK renders its own rejected state */ },
				onManualReview: function () { /* the SDK renders its own pending state */ },
				onStatusChange: function () {}
			});
			handle.mount('#' + mountId);
		})
		.catch(function () {
			fail((cfg.i18n && cfg.i18n.error) || 'Could not start verification.');
		});
})();
