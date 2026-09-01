<?php
/**
 * Public helper API for themes and other plugins.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gate an enqueued script behind a cookie category.
 *
 * Call from `wp_enqueue_scripts` (or any hook before the script is printed):
 *
 *     katla_block_script( 'my-analytics', 'analytics' );
 *
 * @param string $handle   Script handle.
 * @param string $category Cookie category: functional, analytics, marketing,
 *                         personalization, security or unknown.
 * @return void
 */
function katla_block_script( string $handle, string $category = 'analytics' ): void {
	Katla_Script_Blocker::block( $handle, $category );
}

/**
 * Render the consent-gated placeholder around arbitrary markup.
 *
 * @param string $html     Markup to hold back. Scripts and iframes inside it
 *                         must already be neutralised, or use
 *                         `[katla_block]` which does that for you.
 * @param string $category Required cookie category.
 * @return string
 */
function katla_blocked_placeholder( string $html, string $category = 'marketing' ): string {
	return Katla_Script_Blocker::placeholder( $html, $category );
}

/**
 * The cookies Katla has classified for this site, grouped by category.
 *
 * @param string $locale Optional locale override.
 * @return array<string,array<int,array<string,mixed>>> Empty on failure.
 */
function katla_get_cookies( string $locale = '' ): array {
	$data = Katla_Client::get_cookies( '' !== $locale ? $locale : Katla_Categories::resolve_locale() );

	if ( is_wp_error( $data ) || ! isset( $data['cookies'] ) || ! is_array( $data['cookies'] ) ) {
		return array();
	}

	return $data['cookies'];
}

/**
 * The generated policy as HTML.
 *
 * @param string $format `full`, `cookie` or `table`.
 * @param string $locale Optional locale override.
 * @return string Empty on failure.
 */
function katla_get_policy_html( string $format = 'full', string $locale = '' ): string {
	$data = Katla_Client::get_policy( $format, '' !== $locale ? $locale : Katla_Categories::resolve_locale() );

	if ( is_wp_error( $data ) || empty( $data['policy']['markdown'] ) ) {
		return '';
	}

	return Katla_Markdown::to_html( (string) $data['policy']['markdown'] );
}

/**
 * Whether the plugin is configured and loading on the front end.
 *
 * @return bool
 */
function katla_is_active(): bool {
	return Katla_Settings::is_configured() && (bool) Katla_Settings::get( 'enabled' );
}

/**
 * Markup for a link that reopens the cookie preferences.
 *
 * @param string $label Link text. Defaults to the translated "Cookie settings".
 * @param string $tag   `button` or `a`.
 * @return string
 */
function katla_settings_link( string $label = '', string $tag = 'button' ): string {
	return Katla_Shortcodes::settings_link(
		array(
			'text' => $label,
			'tag'  => $tag,
		)
	);
}
