/**
 * Lunixi — customer subscription portal (embed mode) bootstrap.
 *
 * Mounts the Lunixi portal SDK (loaded from the CDN: lunixi-loader.min.js +
 * lunixi-portal.min.js) into EVERY `.lunixi-portal` element on the page (so two
 * [lunixi_subscriptions] shortcodes both render). Each mount is given a
 * `sessionBootstrap` callback that fetches a short-lived SDK session from the
 * WordPress BFF — the merchant API credentials stay server-side; the browser
 * only ever sees the session proof.
 */
(function () {
	'use strict';

	var cfg = window.lunixiPortal || {};
	var els = document.querySelectorAll('.lunixi-portal');
	if (!els.length) {
		return;
	}

	function fail(el, message) {
		el.innerHTML = '<p class="lunixi-portal-error">' + message + '</p>';
	}

	var sdkReady = window.Lunixi && window.Lunixi.portal && typeof window.Lunixi.portal.mount === 'function';

	// The SDK calls this whenever it needs a (fresh) session. Our BFF returns
	// { portalToken, sessionProof, proofNonce, proofExpiresAt }. Shared across
	// instances (same logged-in user).
	function sessionBootstrap() {
		return fetch(cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			}
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('Portal session bootstrap failed');
			}
			return response.json();
		});
	}

	Array.prototype.forEach.call(els, function (el, i) {
		if (!sdkReady) {
			return fail(el, (cfg.i18n && cfg.i18n.sdkMissing) || 'Portal unavailable.');
		}
		if (!el.id) {
			el.id = 'lunixi-portal-auto-' + i;
		}
		try {
			window.Lunixi.portal.mount('#' + el.id, {
				apiBaseUrl: cfg.apiBase,
				locale: cfg.locale || undefined,
				sessionStorageKey: 'lunixi:portal:' + (cfg.apiBase || '') + ':' + window.location.origin,
				sessionBootstrap: sessionBootstrap,
				onError: function (err) {
					if (window.console && window.console.error) {
						window.console.error('[lunixi-portal]', err);
					}
				}
			});
		} catch (e) {
			fail(el, (cfg.i18n && cfg.i18n.error) || 'Could not load your subscriptions. Please try again.');
		}
	});
})();
