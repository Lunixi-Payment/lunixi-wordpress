/**
 * Lunixi — vendor (alt bayi) self-onboarding embed.
 *
 * Loads the Lunixi loader (cdn/lunixi-loader.min.js, which lazy-loads the
 * marketplace SDK) and mounts the 4-phase onboarding wizard (application → KYC →
 * terms → contract) with the mp_token minted server-side by the WP BFF. The
 * merchant API credentials never reach the browser.
 */
(function () {
	'use strict';

	var cfg = window.lunixiMarketplace || {};
	var mountId = cfg.mountId || 'lunixi-marketplace';
	var el = document.getElementById(mountId);
	if (!el) {
		return;
	}

	function fail(message) {
		el.innerHTML = '<p class="lunixi-mp-error">' + message + '</p>';
	}

	if (!cfg.token) {
		return fail((cfg.i18n && cfg.i18n.error) || 'Onboarding unavailable.');
	}
	if (!window.Lunixi || !window.Lunixi.marketplace || typeof window.Lunixi.marketplace.mount !== 'function') {
		return fail((cfg.i18n && cfg.i18n.error) || 'Onboarding unavailable.');
	}

	try {
		window.Lunixi.marketplace.mount('#' + mountId, {
			token: cfg.token,
			apiBaseUrl: cfg.apiBase,
			cdnBaseUrl: cfg.cdnBase,
			locale: cfg.locale || undefined,
			onError: function (err) {
				if (window.console && window.console.error) {
					window.console.error('[lunixi-marketplace]', err);
				}
			}
		});
	} catch (e) {
		fail((cfg.i18n && cfg.i18n.error) || 'Onboarding could not be loaded.');
	}
})();
