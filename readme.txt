=== Katla Cookie Consent ===
Contributors: katlaapp
Tags: cookie consent, gdpr, ccpa, cookie banner, woocommerce
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookie consent for WordPress and WooCommerce, powered by Katla: GDPR, CCPA and DMA banner, Google Consent Mode v2, script blocking and policies.

== Description ==

[Katla](https://katla.app) scans your site, classifies every cookie it finds, and collects visitor consent. This plugin connects your WordPress site to Katla and adds the parts a WordPress site actually needs: server-side script blocking, embed blocking, blocks and shortcodes for the generated policies, and a WooCommerce integration that keeps carts and sessions working under a cookie guard.

= Two ways to run it =

**Hosted widget.** Paste in your Site ID and Katla renders the banner, styled from your dashboard. Nothing else to configure.

**Advanced / headless.** Katla loads the cookie guard and consent API only, with no UI. Either use this plugin's own banner — plain HTML and CSS custom properties you can restyle and translate through WordPress — or turn it off entirely and build your own against `window.KatlaConsent` and the `katla:consent` event.

= What the plugin adds =

* **Script blocking.** Hold back any enqueued script by handle, or any script whose URL matches a pattern, until its cookie category is allowed. Inline configuration attached to the handle is preserved and replayed in order, so a tag manager still gets its settings when consent arrives.
* **Embed blocking.** YouTube, Vimeo, Maps and other third-party iframes are replaced with a consent placeholder and loaded on request.
* **Policies.** Blocks and shortcodes for the cookie and privacy policies Katla generates. Rendered on the server by default so search engines can index them, and cached in a transient.
* **Essential cookie protection.** WordPress and WooCommerce cookies that are strictly necessary — logins, carts, sessions — bypass the cookie guard so nothing breaks before consent.
* **WooCommerce.** HPOS and cart/checkout blocks compatible. Store-critical scripts can never be gated by accident, and a cookie settings link can be added to the checkout privacy notice and My Account.
* **Google Consent Mode v2.** Signal consent to Google tags from the plugin, or let the hosted widget do it.
* **WP Consent API.** With the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) plugin active, every choice is reported to it, so plugins that read it, such as Site Kit by Google, follow the visitor's consent with no extra setup.
* **WP-CLI.** `wp katla status`, `verify`, `cookies`, `policy`, `flush`.

= Requirements =

A Katla account with a verified domain and at least one completed scan. Get your Site ID from the Widget section of your site settings at katla.app.

== Installation ==

1. Install the plugin from **Plugins > Add New**, or upload it to `/wp-content/plugins/katla-consent`, and activate it.
2. Go to **Settings > Katla Consent**. The **Setup** tab walks you through the rest: connecting your Katla site, turning on Google Consent Mode and Google's banner text, and checking the banner works. Each step checks itself.
3. On the **Blocking** tab, map the scripts you want held back to their cookie categories.
4. Add the **Katla Policy** block to a page for your cookie policy, or use the **Create cookie policy page** button on the Tools tab.

== External services ==

This plugin connects your site to [Katla](https://katla.app), a cookie consent service. It needs Katla to work: the banner, the cookie list and the policies all come from your Katla site.

* **Consent script, from cdn.katla.app.** On every page, the visitor's browser loads your site's consent script from `https://cdn.katla.app/{your Site ID}.js`. It shows the banner, blocks cookies until the visitor chooses, and sends Google Consent Mode signals when that is on. Like any file a browser downloads, the request carries the visitor's IP address and browser details.
* **Consent records, to consent.katla.app.** When a visitor makes or changes a choice, the script sends it to `https://consent.katla.app` as proof of consent: the choice and the categories allowed, the regulation that applied, the browser's user agent, an anonymised IP address and the country.
* **Cookie and policy data, from cdn.katla.app.** Your server fetches your site's cookie list, cookie and privacy policies and a manifest from `https://cdn.katla.app/{your Site ID}`: when a page or the settings screen needs them and the cached copy has expired, and when you connect or check again on the Setup tab or test the connection on the Tools tab. Each request sends the Site ID, a language, and the plugin version and your site's address in the user agent. The answers are cached on your server.

No other service is contacted. The domain names offered on the Blocking tab, such as googletagmanager.com, are suggestions for matching scripts that other plugins add to your pages; this plugin never connects to them.

Katla's [terms of service](https://katla.app/terms) and [privacy policy](https://katla.app/privacy-policy).

== Frequently Asked Questions ==

= Does this work without a Katla account? =

No. The plugin is a WordPress front-end for Katla — the cookie data, consent records and policies all come from your Katla site.

= Will the cookie guard break my WooCommerce cart? =

No. Cart, session and checkout cookies are allowlisted and reach the browser untouched even before consent, because they are strictly necessary and exempt from consent under GDPR. WooCommerce order attribution cookies (`sbjs_*`) are deliberately *not* allowlisted — those are marketing cookies and stay behind consent.

= My analytics still loads before consent =

Katla's cookie guard blocks cookie writes, not script loading. Add the script's handle (or a fragment of its URL) on the **Blocking** tab so the plugin holds the script itself back until its category is allowed.

= Where should the script load? =

First in the head, which is where the plugin always prints it. The cookie guard has to run before anything else can set a cookie, and the Google Consent Mode default before any Google tag reads consent. If a caching or optimisation plugin defers, delays or combines JavaScript, exclude cdn.katla.app from it.

= Can I build my own banner? =

Yes. Choose **Advanced / headless** mode and set the banner to **Bring your own**. The plugin loads the guard, exposes `window.KatlaConsent`, and fires a `katla:consent` event on every change with the same shape the Katla SDK uses.

== Screenshots ==

1. The settings screen.
2. The built-in headless banner.
3. The cookie preferences dialog.
4. A generated cookie policy page.

== Changelog ==

= 1.2.1 =
* The Katla script, policies and cookie data now load from cdn.katla.app, Katla's current host, instead of the retired dist.katla.app. Sites that saved the old address switch over by themselves.

= 1.2.0 =
* Guided setup: a Setup tab connects the site, checks that Google Consent Mode and Google's banner text are on in Katla, and suggests the WP Consent API where Site Kit by Google is active.
* The Katla script is now printed first in the head, ahead of Google tags that other plugins or the theme print early, so the Consent Mode default always arrives first.
* The footer placement option is gone. A site that used it now loads the script in the head.
* Tested with WordPress 7.1.

= 1.1.0 =
* WP Consent API support: consent is reported to the API on load and on every change, with the consent type set per visitor (opt-in, or opt-out under CCPA).
* Google Consent Mode bridge: no longer declares a second default when Katla already has, sends Katla's CMP Partner Program developer ID, and denies analytics after a CCPA opt-out.
* Google Consent Mode default is now denied for every visitor, with a returning visitor's stored choice sent as an update straight after it, as Google requires.
* Consent signals are now sent before held-back scripts are released.
* The essential cookie allowlist now works in hosted widget mode.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.1 =
Katla now loads from cdn.katla.app. If a caching or optimisation plugin excludes dist.katla.app from deferring, delaying or combining JavaScript, exclude cdn.katla.app instead.
