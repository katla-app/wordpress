=== Katla Cookie Consent ===
Contributors: katla
Tags: cookie consent, gdpr, ccpa, cookie banner, woocommerce
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

GDPR, CCPA/CPRA and DMA cookie compliance for WordPress and WooCommerce, powered by Katla. Consent banner, script blocking and auto-generated policies.

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
* **WP-CLI.** `wp katla status`, `verify`, `cookies`, `policy`, `flush`.

= Requirements =

A Katla account with a verified domain and at least one completed scan. Get your Site ID from the Widget section of your site settings at katla.app.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/katla-consent` and activate it.
2. Go to **Settings → Katla Consent** and paste your Site ID.
3. Optionally add your domain verification token — the plugin prints the meta tag for you.
4. On the **Blocking** tab, map the scripts you want held back to their cookie categories.
5. Add the **Katla Policy** block to a page for your cookie policy, or use the **Create cookie policy page** button on the Tools tab.

== Frequently Asked Questions ==

= Does this work without a Katla account? =

No. The plugin is a WordPress front-end for Katla — the cookie data, consent records and policies all come from your Katla site.

= Will the cookie guard break my WooCommerce cart? =

No. Cart, session and checkout cookies are allowlisted and reach the browser untouched even before consent, because they are strictly necessary and exempt from consent under GDPR. WooCommerce order attribution cookies (`sbjs_*`) are deliberately *not* allowlisted — those are marketing cookies and stay behind consent.

= My analytics still loads before consent =

Katla's cookie guard blocks cookie writes, not script loading. Add the script's handle (or a fragment of its URL) on the **Blocking** tab so the plugin holds the script itself back until its category is allowed.

= Where should the script load? =

In the head, which is the default. The cookie guard has to run before anything else can set a cookie. If a caching or optimisation plugin defers or moves the Katla script, exclude it from that optimisation.

= Can I build my own banner? =

Yes. Choose **Advanced / headless** mode and set the banner to **Bring your own**. The plugin loads the guard, exposes `window.KatlaConsent`, and fires a `katla:consent` event on every change with the same shape the Katla SDK uses.

== Screenshots ==

1. The settings screen.
2. The built-in headless banner.
3. The cookie preferences dialog.
4. A generated cookie policy page.

== Changelog ==

= 1.0.0 =
* Initial release.
