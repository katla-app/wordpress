# Katla Cookie Consent for WordPress

GDPR, CCPA/CPRA and DMA cookie compliance for WordPress and WooCommerce, backed by
[Katla](https://katla.app). The plugin loads Katla's consent script, blocks non-consented
scripts and embeds server-side, and renders the cookie and privacy policies Katla generates.

Docs for the underlying platform: <https://docs.katla.app>

## Requirements

| | |
|---|---|
| WordPress | 6.3+ |
| PHP | 7.4+ |
| WooCommerce | 7.0+ (optional) |
| Katla | A verified site with at least one completed scan |

## Install

Copy the plugin into `wp-content/plugins/katla-consent`, activate it, then set your Site ID:

```bash
wp plugin activate katla-consent
wp katla site-id 00000000-0000-0000-0000-000000000000
wp katla verify
```

Or paste it into **Settings → Katla Consent**.

## Modes

### Hosted widget (default)

Loads `https://dist.katla.app/{siteId}.js`. Katla renders the banner and the preferences
dialog, styled from your Katla dashboard.

### Advanced / headless

Loads the same script with `?headless=true` — the cookie guard and `window.KatlaConsent`
only, no UI. Then either:

* **Built-in banner** — this plugin renders a banner and preferences dialog from plain DOM,
  translated through WordPress and styled with CSS custom properties.
* **Bring your own** — the plugin renders nothing. Build your own UI against
  `window.KatlaConsent` and the `katla:consent` event.

## Blocks and shortcodes

| Shortcode | Block | What it renders |
|---|---|---|
| `[katla_policy]` | Katla Policy | Full cookie + privacy policy |
| `[katla_policy format="cookie"]` | Katla Policy | Cookie policy only |
| `[katla_policy format="table"]` | Katla Policy | Cookie tables only |
| `[katla_cookie_table category="analytics"]` | Katla Cookie Table | Scanned cookies, grouped by category |
| `[katla_cookie_settings]` | Katla Cookie Settings | Button/link that reopens the preferences |
| `[katla_block category="marketing"]…[/katla_block]` | — | Holds back the enclosed scripts and iframes |
| `[katla_consent category="analytics"]…[/katla_consent]` | — | Shows the enclosed content only while allowed |

`[katla_policy]` renders on the server by default, so the policy is part of the page HTML
and indexable. `render="client"` switches to Katla's hosted `policy.js` instead.

## Blocking scripts

Katla's cookie guard stops cookies from being *written*. It does not stop a tracker from
loading. Map script handles (or URL fragments) to categories on the **Blocking** tab, or
from code:

```php
add_action( 'wp_enqueue_scripts', function () {
	katla_block_script( 'google-analytics', 'analytics' );
	katla_block_script( 'facebook-pixel', 'marketing' );
}, 20 );
```

A blocked handle is rewritten to `<script type="text/plain" data-katla-src="…"
data-katla-category="analytics">`. Inline scripts attached to the same handle
(`wp_add_inline_script`) are neutralised alongside it and replayed **in source order** once
the category is allowed, so tag configuration is never lost.

## JavaScript API

`window.KatlaConsent` is Katla's own API — see the
[JavaScript API docs](https://docs.katla.app/javascript-api). This plugin adds:

```js
window.katlaWP.openSettings();            // Open the preferences dialog
window.katlaWP.isAllowed('analytics');    // boolean
window.katlaWP.getAllowedCategories();    // string[]
window.katlaWP.allowCategory('marketing');// Add one category to the current consent
window.katlaWP.activateBlocked();         // Re-scan the DOM for blocked nodes
window.katlaWP.config;                    // The server-rendered configuration
```

And it dispatches the SDK-compatible event on every change:

```js
window.addEventListener('katla:consent', (event) => {
	// { functional: true, analytics: true, marketing: false, …, type: 'partial' }
	console.log(event.detail);
});
```

The plugin takes ownership of `KatlaConsent.onConsentChange` as a subscriber list, so
assigning to it (as `@katla.app/sdk`'s `ConsentBridge` does) *adds* a listener instead of
replacing the plugin's.

In headless "bring your own" mode, anything with `data-katla-open-settings` fires a
`katla:open-settings` event for your own UI to handle.

## Markup contract

Anything the plugin holds back is marked up so you can style or extend it:

```html
<!-- A blocked script -->
<script type="text/plain" data-katla-src="…" data-katla-category="analytics"></script>

<!-- A blocked embed -->
<div class="katla-blocked" data-katla-blocked="marketing">
  <div class="katla-blocked__notice">…<button data-katla-allow="marketing">…</button></div>
  <iframe hidden data-katla-embed="1" data-katla-src="…"></iframe>
</div>

<!-- Content shown only while a category is allowed -->
<div class="katla-consent-gate" data-katla-requires="analytics" hidden>…</div>
```

## Hooks

### Filters

| Filter | Purpose |
|---|---|
| `katla_settings` | The full settings array |
| `katla_setting_{$key}` | One setting |
| `katla_should_load` | Whether the consent script loads on this request |
| `katla_locale` | The locale sent to Katla |
| `katla_runtime_config` | The config object handed to the front-end runtime |
| `katla_cookie_allowlist` | Cookie names that bypass the guard (trailing `*` = prefix) |
| `katla_categories` | Category labels and descriptions |
| `katla_banner_categories` | Categories rendered in the built-in banner |
| `katla_translations` | Banner and placeholder strings |
| `katla_banner_css_vars` | CSS custom properties for the built-in banner |
| `katla_blocked_handles` | Script handles gated behind consent |
| `katla_blocked_patterns` | Script URL fragments gated behind consent |
| `katla_blocked_placeholder` | Markup shown in place of blocked content |
| `katla_policy_html` | Server-rendered policy HTML |
| `katla_dist_url` | Any generated dist.katla.app URL |
| `katla_http_timeout` | HTTP timeout for Katla requests |

### Actions

| Action | Purpose |
|---|---|
| `katla_cache_flushed` | After the response cache is cleared |

### PHP helpers

```php
katla_is_active();                     // bool
katla_block_script( $handle, $cat );   // Gate an enqueued script
katla_get_cookies( $locale );          // Scanned cookies by category
katla_get_policy_html( $format );      // Generated policy as HTML
katla_settings_link( $label, $tag );   // Markup for a preferences link
katla_blocked_placeholder( $html, $c );// Wrap markup in a consent placeholder
```

### Restyling the built-in banner

```php
add_filter( 'katla_banner_css_vars', function () {
	return array(
		'katla-primary' => '#111111',
		'katla-radius'  => '0px',
		'katla-font'    => 'var(--wp--preset--font-family--body)',
	);
} );
```

## WooCommerce

* Declares compatibility with **HPOS** (`custom_order_tables`) and **cart/checkout blocks**.
* Cart, session and checkout cookies bypass the cookie guard — `woocommerce_cart_hash`,
  `woocommerce_items_in_cart`, `wp_woocommerce_session_*`, `wc_cart_hash_*`,
  `wc_fragments_*`, `store_notice*` and friends.
* Store-critical script handles (`wc-cart-fragments`, `wc-checkout`, `wc-blocks-checkout`, …)
  are stripped from the blocked list, so a stray rule can never break the store.
* Optionally appends a cookie settings link to the checkout/registration privacy notice and
  the My Account dashboard.

Order attribution cookies (`sbjs_*`) are **not** allowlisted — they are marketing cookies.
To stop them being set at all, gate the `wc-order-attribution` handle on the Blocking tab.

## WP-CLI

```bash
wp katla status                       # Configuration and connection summary
wp katla site-id <uuid>               # Get or set the Site ID
wp katla verify                       # Confirm the Site ID resolves
wp katla cookies --category=marketing # List scanned cookies
wp katla policy --format=cookie --as=html
wp katla flush                        # Clear cached Katla responses
```

## Caching

Cookie and policy responses are cached in transients (12 hours by default, configurable on
the **Policies** tab, `0` to disable). The cache is cleared when settings are saved, from
the Tools tab, via `wp katla flush`, and on deactivation.

## Notes on compliance

* **Load order matters.** The cookie guard must run before any script that sets cookies.
  Keep the placement on *Head* and exclude `dist.katla.app` from JS deferral or combination
  in optimisation plugins.
* **DMA equal prominence.** In the built-in banner, "Reject all" and "Accept all" use
  identical size, padding and weight so neither is the nudged choice.
* **Hide from roles** is a development convenience. Users in those roles are not shown a
  banner and no consent record is created for them — do not leave it on for a role real
  visitors have.
