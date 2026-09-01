/**
 * Katla Cookie Consent — WordPress runtime.
 *
 * Runs immediately after the Katla consent script. Four jobs:
 *   1. Let strictly necessary cookies through the cookie guard.
 *   2. Turn consent changes into `katla:consent` events (SDK contract).
 *   3. Activate scripts and embeds that were held back server-side.
 *   4. Render the built-in banner when running headless.
 *
 * Written as ES5 so it can run in the document head without transpilation.
 */
(function (window, document) {
	'use strict';

	var wp = window.katlaWP || (window.katlaWP = {});
	var config = wp.config || {};
	var i18n = config.i18n || {};

	var CATEGORY_ALL = 'functional';

	function log() {
		if (!config.debug || !window.console) {
			return;
		}
		var args = Array.prototype.slice.call(arguments);
		args.unshift('%c Katla WP ', 'background:#6366f1;color:#fff;padding:2px 6px;border-radius:3px;font-weight:bold;');
		window.console.log.apply(window.console, args);
	}

	// -----------------------------------------------------------------
	// 1. Essential cookie allowlist
	// -----------------------------------------------------------------

	function matchesAllowlist(name) {
		var list = config.allowlist || [];

		for (var i = 0; i < list.length; i++) {
			var pattern = list[i];

			if (pattern.charAt(pattern.length - 1) === '*') {
				if (name.indexOf(pattern.slice(0, -1)) === 0) {
					return true;
				}
			} else if (name === pattern) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Re-wrap `document.cookie` so allowlisted names reach the browser
	 * untouched while everything else still goes through Katla's guard.
	 */
	function installAllowlist() {
		if (!config.protectEssential || !(config.allowlist || []).length) {
			return;
		}

		var guarded = Object.getOwnPropertyDescriptor(document, 'cookie');
		var native =
			Object.getOwnPropertyDescriptor(Document.prototype, 'cookie') ||
			(window.HTMLDocument && Object.getOwnPropertyDescriptor(window.HTMLDocument.prototype, 'cookie'));

		// No instance-level guard means Katla is in CCPA (opt-out) mode and is
		// not intercepting writes at all — nothing to work around.
		if (!guarded || !guarded.set || !guarded.configurable || !native || !native.set) {
			log('Cookie guard not present, allowlist not needed');
			return;
		}

		try {
			Object.defineProperty(document, 'cookie', {
				configurable: true,
				get: function () {
					return guarded.get ? guarded.get.call(document) : native.get.call(document);
				},
				set: function (value) {
					var name = String(value).split('=')[0].trim();

					if (name && matchesAllowlist(name)) {
						log('Essential cookie allowed', name);
						return native.set.call(document, value);
					}

					return guarded.set.call(document, value);
				}
			});
			log('Essential cookie allowlist installed', config.allowlist);
		} catch (error) {
			log('Could not install allowlist', error);
		}
	}

	// -----------------------------------------------------------------
	// 2. Consent events
	// -----------------------------------------------------------------

	var handlers = [];

	function allowedCategories() {
		if (!window.KatlaConsent) {
			return [CATEGORY_ALL];
		}

		try {
			return window.KatlaConsent.getAllowedCategories() || [CATEGORY_ALL];
		} catch (error) {
			return [CATEGORY_ALL];
		}
	}

	function isAllowed(category) {
		if (!category || category === CATEGORY_ALL) {
			return true;
		}

		var allowed = allowedCategories();

		return allowed.indexOf(category) !== -1;
	}

	function toConsentState(categories) {
		var ids = (config.categories || []).map(function (category) {
			return category.id;
		});

		// Always report the full category set, like the SDK does.
		['functional', 'personalization', 'analytics', 'marketing', 'security', 'unknown'].forEach(function (id) {
			if (ids.indexOf(id) === -1) {
				ids.push(id);
			}
		});

		var state = {};
		ids.forEach(function (id) {
			state[id] = categories.indexOf(id) !== -1;
		});

		return state;
	}

	function dispatch(data) {
		for (var i = 0; i < handlers.length; i++) {
			try {
				handlers[i](data);
			} catch (error) {
				log('Consent handler failed', error);
			}
		}
	}

	function onConsent(data) {
		var categories = (data && data.categories) || allowedCategories();

		log('Consent changed', data && data.type, categories);

		activateBlocked();
		updateGates();

		if (config.events !== false) {
			var detail = toConsentState(categories);
			detail.type = (data && data.type) || 'unknown';

			window.dispatchEvent(new CustomEvent('katla:consent', { detail: detail }));
		}

		if (config.googleConsentMode) {
			pushGoogleConsent(data, categories);
		}
	}

	/**
	 * Own `KatlaConsent.onConsentChange` as a subscriber list, so the Katla SDK
	 * (or any other integration) assigning to it adds a listener instead of
	 * replacing ours.
	 */
	function installConsentBridge() {
		if (!window.KatlaConsent) {
			return false;
		}

		var existing = window.KatlaConsent.onConsentChange;

		handlers.push(onConsent);

		if (typeof existing === 'function' && existing !== onConsent) {
			handlers.push(existing);
		}

		try {
			Object.defineProperty(window.KatlaConsent, 'onConsentChange', {
				configurable: true,
				get: function () {
					return dispatch;
				},
				set: function (fn) {
					if (typeof fn === 'function' && handlers.indexOf(fn) === -1) {
						handlers.push(fn);
					}
				}
			});
		} catch (error) {
			window.KatlaConsent.onConsentChange = dispatch;
		}

		return true;
	}

	// -----------------------------------------------------------------
	// Google Consent Mode v2
	// -----------------------------------------------------------------

	function gtag() {
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(arguments);
	}

	function consentParams(categories) {
		var analytics = categories.indexOf('analytics') !== -1;
		var marketing = categories.indexOf('marketing') !== -1;

		return {
			analytics_storage: analytics ? 'granted' : 'denied',
			ad_storage: marketing ? 'granted' : 'denied',
			ad_user_data: marketing ? 'granted' : 'denied',
			ad_personalization: marketing ? 'granted' : 'denied'
		};
	}

	function pushGoogleConsent(data, categories) {
		// A CCPA opt-out denies advertising but leaves analytics granted.
		if (data && 'optedOut' === data.type) {
			gtag('consent', 'update', {
				ad_storage: 'denied',
				ad_user_data: 'denied',
				ad_personalization: 'denied',
				analytics_storage: 'granted'
			});
			return;
		}

		gtag('consent', 'update', consentParams(categories));
	}

	function initGoogleConsentMode() {
		if (!config.googleConsentMode) {
			return;
		}

		var consent = window.KatlaConsent && window.KatlaConsent.getConsent();

		gtag('consent', 'default', consent ? consentParams(consent.categories || []) : {
			analytics_storage: 'denied',
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied'
		});
	}

	// -----------------------------------------------------------------
	// 3. Activating blocked scripts, embeds and gated content
	// -----------------------------------------------------------------

	var COPY_SKIP = { type: 1, 'data-katla-src': 1, 'data-katla-category': 1, 'data-katla-handle': 1, 'data-katla-async': 1, 'data-katla-defer': 1, 'data-katla-inline': 1 };

	function activateScript(node, done) {
		var replacement = document.createElement('script');
		var src = node.getAttribute('data-katla-src');

		for (var i = 0; i < node.attributes.length; i++) {
			var attribute = node.attributes[i];
			if (!COPY_SKIP[attribute.name]) {
				replacement.setAttribute(attribute.name, attribute.value);
			}
		}

		if (node.getAttribute('data-katla-async')) {
			replacement.async = true;
		}
		if (node.getAttribute('data-katla-defer')) {
			replacement.defer = true;
		}

		if (src) {
			// Keep source order: wait for each external script before the next.
			replacement.onload = done;
			replacement.onerror = done;
			replacement.src = src;
		} else {
			replacement.text = node.textContent || node.innerHTML || '';
		}

		if (node.parentNode) {
			node.parentNode.replaceChild(replacement, node);
		} else {
			document.head.appendChild(replacement);
		}

		if (!src) {
			done();
		}
	}

	function activateEmbeds() {
		var embeds = document.querySelectorAll('[data-katla-src]:not(script)');

		Array.prototype.forEach.call(embeds, function (node) {
			var wrapper = node.closest ? node.closest('[data-katla-blocked]') : null;
			var category = wrapper ? wrapper.getAttribute('data-katla-blocked') : config.contentBlocker && config.contentBlocker.category;

			if (!isAllowed(category)) {
				return;
			}

			node.setAttribute('src', node.getAttribute('data-katla-src'));
			node.removeAttribute('data-katla-src');
			node.removeAttribute('hidden');

			if (wrapper) {
				var notice = wrapper.querySelector('.katla-blocked__notice');
				if (notice && notice.parentNode) {
					notice.parentNode.removeChild(notice);
				}
				wrapper.classList.add('katla-blocked--released');
			}
		});
	}

	var activating = false;

	function activateBlocked() {
		activateEmbeds();

		if (activating) {
			return;
		}

		var queue = Array.prototype.filter.call(
			document.querySelectorAll('script[type="text/plain"][data-katla-category]'),
			function (node) {
				return isAllowed(node.getAttribute('data-katla-category'));
			}
		);

		if (!queue.length) {
			return;
		}

		activating = true;

		(function next() {
			var node = queue.shift();

			if (!node) {
				activating = false;
				// Blocked scripts can print more blocked markup; sweep again.
				activateEmbeds();
				return;
			}

			log('Activating script', node.getAttribute('data-katla-handle') || node.getAttribute('data-katla-src') || 'inline');
			activateScript(node, next);
		})();
	}

	function updateGates() {
		var gates = document.querySelectorAll('.katla-consent-gate[data-katla-requires]');

		Array.prototype.forEach.call(gates, function (gate) {
			var allowed = isAllowed(gate.getAttribute('data-katla-requires'));
			var invert = '1' === gate.getAttribute('data-katla-invert');

			gate.hidden = invert ? allowed : !allowed;
		});
	}

	function allowCategory(category) {
		if (!window.KatlaConsent) {
			return;
		}

		var categories = allowedCategories().slice();

		if (categories.indexOf(category) === -1) {
			categories.push(category);
		}

		window.KatlaConsent.acceptCategories(categories);
	}

	// -----------------------------------------------------------------
	// 4. Built-in banner (headless mode)
	// -----------------------------------------------------------------

	var banner = null;
	var modal = null;
	var lastFocus = null;

	function element(tag, className, text) {
		var node = document.createElement(tag);

		if (className) {
			node.className = className;
		}
		if (text != null) {
			node.textContent = text;
		}

		return node;
	}

	function button(label, className, action) {
		var node = element('button', className, label);
		node.type = 'button';
		node.addEventListener('click', action);

		return node;
	}

	function manageableCategories() {
		return (config.categories || []).filter(function (category) {
			return !category.required;
		});
	}

	function buildBanner() {
		var root = element('div', 'katla-wp katla-wp--banner');
		root.setAttribute('role', 'dialog');
		root.setAttribute('aria-live', 'polite');
		root.setAttribute('aria-label', i18n.title || 'Cookies');

		var inner = element('div', 'katla-wp__inner');
		inner.appendChild(element('h2', 'katla-wp__title', i18n.title));
		inner.appendChild(element('p', 'katla-wp__description', i18n.description));

		if (i18n.privacyPolicyUrl) {
			var link = element('a', 'katla-wp__policy', i18n.privacyPolicy);
			link.href = i18n.privacyPolicyUrl;
			inner.appendChild(link);
		}

		var actions = element('div', 'katla-wp__actions');

		actions.appendChild(button(i18n.customize, 'katla-wp__btn katla-wp__btn--ghost', openModal));

		// DMA: reject and accept carry identical visual weight.
		actions.appendChild(button(i18n.rejectAll, 'katla-wp__btn katla-wp__btn--equal', function () {
			window.KatlaConsent.rejectAll();
			hideBanner();
		}));
		actions.appendChild(button(i18n.acceptAll, 'katla-wp__btn katla-wp__btn--equal katla-wp__btn--accept', function () {
			window.KatlaConsent.acceptAll();
			hideBanner();
		}));

		inner.appendChild(actions);
		root.appendChild(inner);

		return root;
	}

	function buildCategoryRow(category) {
		var section = element('section', 'katla-cat');
		var head = element('div', 'katla-cat__head');

		var toggleBody = element('button', 'katla-cat__summary');
		toggleBody.type = 'button';
		toggleBody.setAttribute('aria-expanded', 'false');
		toggleBody.appendChild(element('span', 'katla-cat__label', category.label));
		toggleBody.appendChild(element('span', 'katla-cat__chevron', ''));

		head.appendChild(toggleBody);

		if (category.required) {
			head.appendChild(element('span', 'katla-cat__required', i18n.alwaysActive));
		} else {
			var label = element('label', 'katla-cat__switch');
			var input = document.createElement('input');
			input.type = 'checkbox';
			input.value = category.id;
			input.checked = isAllowed(category.id);
			input.setAttribute('data-katla-category-toggle', category.id);
			label.appendChild(input);
			label.appendChild(element('span', 'katla-cat__slider'));
			label.setAttribute('aria-label', category.label);
			head.appendChild(label);
		}

		section.appendChild(head);

		var body = element('div', 'katla-cat__body');
		body.hidden = true;
		body.appendChild(element('p', 'katla-cat__description', category.description));

		if (category.cookies && category.cookies.length) {
			var wrap = element('div', 'katla-cat__table-wrap');
			var table = element('table', 'katla-cat__table');
			var thead = element('thead');
			var headRow = element('tr');

			[i18n.cookieName, i18n.cookieDomain, i18n.cookieDuration, i18n.cookiePurpose].forEach(function (heading) {
				var th = element('th', null, heading);
				th.setAttribute('scope', 'col');
				headRow.appendChild(th);
			});

			thead.appendChild(headRow);
			table.appendChild(thead);

			var tbody = element('tbody');
			category.cookies.forEach(function (cookie) {
				var row = element('tr');
				row.appendChild(element('td', null, cookie.name));
				row.appendChild(element('td', null, cookie.domain));
				row.appendChild(element('td', null, cookie.expires || '—'));
				row.appendChild(element('td', null, cookie.description || ''));
				tbody.appendChild(row);
			});

			table.appendChild(tbody);
			wrap.appendChild(table);
			body.appendChild(wrap);
		}

		section.appendChild(body);

		toggleBody.addEventListener('click', function () {
			var expanded = 'true' === toggleBody.getAttribute('aria-expanded');
			toggleBody.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			body.hidden = expanded;
		});

		return section;
	}

	function buildModal() {
		var root = element('div', 'katla-wp-modal');
		root.setAttribute('role', 'dialog');
		root.setAttribute('aria-modal', 'true');
		root.setAttribute('aria-label', i18n.preferencesTitle || 'Cookie preferences');
		root.hidden = true;

		var backdrop = element('div', 'katla-wp-modal__backdrop');
		backdrop.addEventListener('click', closeModal);
		root.appendChild(backdrop);

		var panel = element('div', 'katla-wp-modal__panel');
		var header = element('div', 'katla-wp-modal__header');
		header.appendChild(element('h2', 'katla-wp-modal__title', i18n.preferencesTitle));

		var close = button('×', 'katla-wp-modal__close', closeModal);
		close.setAttribute('aria-label', i18n.close || 'Close');
		header.appendChild(close);
		panel.appendChild(header);

		var body = element('div', 'katla-wp-modal__body');
		(config.categories || []).forEach(function (category) {
			body.appendChild(buildCategoryRow(category));
		});

		if (i18n.privacyPolicyUrl) {
			var link = element('a', 'katla-wp-modal__policy', i18n.privacyPolicy);
			link.href = i18n.privacyPolicyUrl;
			body.appendChild(link);
		}

		panel.appendChild(body);

		var footer = element('div', 'katla-wp-modal__footer');
		footer.appendChild(button(i18n.rejectAll, 'katla-wp__btn katla-wp__btn--equal', function () {
			window.KatlaConsent.rejectAll();
			closeModal();
			hideBanner();
		}));
		footer.appendChild(button(i18n.save, 'katla-wp__btn katla-wp__btn--ghost', function () {
			var selected = ['functional'];

			Array.prototype.forEach.call(panel.querySelectorAll('[data-katla-category-toggle]'), function (input) {
				if (input.checked) {
					selected.push(input.value);
				}
			});

			window.KatlaConsent.acceptCategories(selected);
			closeModal();
			hideBanner();
		}));
		footer.appendChild(button(i18n.acceptAll, 'katla-wp__btn katla-wp__btn--equal katla-wp__btn--accept', function () {
			window.KatlaConsent.acceptAll();
			closeModal();
			hideBanner();
		}));

		panel.appendChild(footer);
		root.appendChild(panel);

		root.addEventListener('keydown', function (event) {
			if ('Escape' === event.key) {
				closeModal();
			} else if ('Tab' === event.key) {
				trapFocus(event, panel);
			}
		});

		return root;
	}

	function trapFocus(event, panel) {
		var focusable = panel.querySelectorAll('button, [href], input, select, textarea');

		if (!focusable.length) {
			return;
		}

		var first = focusable[0];
		var last = focusable[focusable.length - 1];

		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	function ensureModal() {
		if (!modal) {
			modal = buildModal();
			document.body.appendChild(modal);
		}

		return modal;
	}

	function openModal() {
		if ('builtin' !== config.banner) {
			// Hosted widget, or a custom banner: hand control over.
			openHostedSettings();
			return;
		}

		lastFocus = document.activeElement;

		var node = ensureModal();

		// Reflect the current choice each time the dialog opens.
		Array.prototype.forEach.call(node.querySelectorAll('[data-katla-category-toggle]'), function (input) {
			input.checked = isAllowed(input.value);
		});

		node.hidden = false;
		document.documentElement.classList.add('katla-wp-modal-open');

		var focusable = node.querySelector('button, [href], input');
		if (focusable) {
			focusable.focus();
		}
	}

	function closeModal() {
		if (!modal) {
			return;
		}

		modal.hidden = true;
		document.documentElement.classList.remove('katla-wp-modal-open');

		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	function hideBanner() {
		if (banner) {
			banner.hidden = true;
		}

		showSettingsButton();
	}

	var settingsButton = null;

	/**
	 * Offer a way back into the preferences. Skipped when the site already
	 * places a cookie settings link of its own.
	 */
	function showSettingsButton() {
		if (settingsButton || document.querySelector('[data-katla-open-settings]')) {
			return;
		}

		settingsButton = button(i18n.settings, 'katla-wp__settings-button', openModal);
		settingsButton.setAttribute('data-katla-open-settings', '1');
		document.body.appendChild(settingsButton);
	}

	function renderBanner() {
		if ('builtin' !== config.banner || banner) {
			return;
		}

		if (window.KatlaConsent && window.KatlaConsent.hasConsent()) {
			showSettingsButton();
			return;
		}

		banner = buildBanner();
		document.body.appendChild(banner);
	}

	// -----------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------

	function openHostedSettings() {
		var widget = window.KatlaWidget && (window.KatlaWidget.KatlaWidget || window.KatlaWidget);

		if (widget && 'function' === typeof widget.openCustomize) {
			widget.openCustomize();
			return;
		}

		// Custom banner: tell the site's own code to take over.
		window.dispatchEvent(new CustomEvent('katla:open-settings'));
	}

	wp.openSettings = openModal;
	wp.allowCategory = allowCategory;
	wp.isAllowed = isAllowed;
	wp.getAllowedCategories = allowedCategories;
	wp.activateBlocked = activateBlocked;

	// -----------------------------------------------------------------
	// Boot
	// -----------------------------------------------------------------

	function bindDelegates() {
		document.addEventListener('click', function (event) {
			var target = event.target.closest ? event.target.closest('[data-katla-open-settings],[data-katla-allow]') : null;

			if (!target) {
				return;
			}

			event.preventDefault();

			var category = target.getAttribute('data-katla-allow');

			if (category) {
				allowCategory(category);
			} else {
				openModal();
			}
		});
	}

	function ready(callback) {
		if ('loading' === document.readyState) {
			document.addEventListener('DOMContentLoaded', callback);
		} else {
			callback();
		}
	}

	function boot() {
		installAllowlist();

		if (!installConsentBridge()) {
			// The consent script is a hard dependency, but a caching plugin may
			// have deferred it. Wait for it rather than failing silently.
			var attempts = 0;
			var timer = window.setInterval(function () {
				attempts++;

				if (installConsentBridge() || attempts > 100) {
					window.clearInterval(timer);
					initGoogleConsentMode();
					ready(afterReady);
				}
			}, 100);

			return;
		}

		initGoogleConsentMode();
		ready(afterReady);
	}

	function afterReady() {
		bindDelegates();
		activateBlocked();
		updateGates();
		renderBanner();
	}

	boot();
})(window, document);
