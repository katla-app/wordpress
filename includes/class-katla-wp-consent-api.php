<?php
/**
 * WP Consent API integration.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports the visitor's Katla choice to the WP Consent API, so plugins that read it
 * (Site Kit among them) follow that choice without the site adding any code.
 *
 * The choice itself is written client-side: the runtime calls wp_set_consent() for every
 * category on load and on every change. This side declares the plugin, states the consent
 * type, and keeps the API's own cookies out of the cookie guard's reach.
 *
 * @link https://wordpress.org/plugins/wp-consent-api/
 */
class Katla_WP_Consent_API {

	/**
	 * Hook into WordPress.
	 */
	public function __construct() {
		add_filter( 'wp_consent_api_registered_' . KATLA_PLUGIN_BASENAME, '__return_true' );
		add_filter( 'wp_get_consent_type', array( $this, 'consent_type' ) );
	}

	/**
	 * Whether the WP Consent API plugin is active on this site.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'wp_set_consent' ) && class_exists( 'WP_Consent_API' );
	}

	/**
	 * The consent type, while Katla is the one collecting consent.
	 *
	 * Opt-in. Katla decides the regulation per visitor in the browser, so the server cannot
	 * know it; the runtime switches a CCPA visitor to opt-out once the consent script has
	 * answered. Until then opt-in is the stricter reading, and never the less compliant one.
	 *
	 * @param string $type Consent type set so far.
	 * @return string
	 */
	public function consent_type( $type ) {
		if ( ! Katla_Frontend::should_load() ) {
			return $type;
		}

		return 'optin';
	}

	/**
	 * The API's own cookies, as allowlist patterns.
	 *
	 * They record the choice this plugin reports. The cookie guard does not know them, so it
	 * files them as unknown and refuses them to anyone who has not accepted everything:
	 * exactly the visitors whose refusal most needs recording.
	 *
	 * @return array<int,string>
	 */
	public static function cookie_patterns(): array {
		if ( ! self::is_active() ) {
			return array();
		}

		$prefix = 'wp_consent';

		if ( isset( WP_Consent_API::$config ) && is_object( WP_Consent_API::$config ) && method_exists( WP_Consent_API::$config, 'consent_cookie_prefix' ) ) {
			$prefix = (string) WP_Consent_API::$config->consent_cookie_prefix();
		}

		return array( $prefix . '_*' );
	}
}
