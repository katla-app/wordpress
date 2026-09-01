<?php
/**
 * HTTP client for the Katla distribution endpoints.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches cookie, policy and manifest data from dist.katla.app and caches it in
 * transients so front-end rendering never blocks on a remote request twice.
 */
class Katla_Client {

	const TRANSIENT_PREFIX = 'katla_';
	const INDEX_OPTION     = 'katla_cache_index';

	/**
	 * Per-request memoisation.
	 *
	 * @var array<string,mixed>
	 */
	private static $memo = array();

	/**
	 * Base URL of the distribution service, without a trailing slash.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		$base = untrailingslashit( (string) Katla_Settings::get( 'base_url' ) );

		return $base ? $base : 'https://dist.katla.app';
	}

	/**
	 * Build a URL for the configured site.
	 *
	 * @param string               $path  Path relative to the site, e.g. `.js` or `/policy.json`.
	 * @param array<string,scalar> $args  Query arguments. Empty values are dropped.
	 * @return string
	 */
	public static function url( string $path, array $args = array() ): string {
		$site_id = (string) Katla_Settings::get( 'site_id' );
		$url     = self::base_url() . '/' . rawurlencode( $site_id ) . $path;

		$args = array_filter(
			$args,
			static function ( $value ) {
				return '' !== $value && null !== $value && false !== $value;
			}
		);

		if ( $args ) {
			// add_query_arg does not encode values, so do it here — the policy
			// target selector ("#katla-policy-1") would otherwise be read as a
			// URL fragment and truncate the query string.
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url );
		}

		/**
		 * Filters a generated Katla distribution URL.
		 *
		 * @param string $url  Final URL.
		 * @param string $path Requested path.
		 * @param array  $args Query arguments.
		 */
		return apply_filters( 'katla_dist_url', $url, $path, $args );
	}

	/**
	 * URL of the consent widget script.
	 *
	 * @return string
	 */
	public static function script_url(): string {
		$args = array(
			'locale' => Katla_Categories::resolve_locale(),
			'theme'  => (string) Katla_Settings::get( 'theme' ),
		);

		if ( 'headless' === Katla_Settings::get( 'mode' ) ) {
			$args['headless'] = 'true';
		}

		if ( Katla_Settings::get( 'debug' ) ) {
			$args['debug'] = 'true';
		}

		return self::url( '.js', $args );
	}

	/**
	 * URL of the hosted policy script.
	 *
	 * @param string $format Policy format: `full`, `cookie` or `table`.
	 * @param string $locale Locale, or '' for browser detection.
	 * @param string $target CSS selector the script renders into.
	 * @return string
	 */
	public static function policy_script_url( string $format, string $locale, string $target ): string {
		return self::url(
			'/policy.js',
			array(
				'format' => $format,
				'locale' => $locale,
				'target' => $target,
			)
		);
	}

	/**
	 * Cookies for the site, grouped by category.
	 *
	 * @param string $locale Locale, or '' for the endpoint default.
	 * @return array<string,mixed>|WP_Error Array with `site`, `cookies` and `jsUrl` keys.
	 */
	public static function get_cookies( string $locale = '' ) {
		return self::get_json( '.json', array( 'locale' => $locale ), 'cookies_' . $locale );
	}

	/**
	 * Generated policy content.
	 *
	 * @param string $format Policy format: `full`, `cookie` or `table`.
	 * @param string $locale Locale, or '' for the endpoint default.
	 * @return array<string,mixed>|WP_Error Array with `site` and `policy` keys.
	 */
	public static function get_policy( string $format = 'full', string $locale = '' ) {
		return self::get_json(
			'/policy.json',
			array(
				'format' => $format,
				'locale' => $locale,
			),
			'policy_' . $format . '_' . $locale
		);
	}

	/**
	 * Manifest of the resources available for the site.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_manifest() {
		return self::get_json( '/manifest.json', array(), 'manifest' );
	}

	/**
	 * Fetch and cache a JSON endpoint.
	 *
	 * @param string               $path      Path relative to the site.
	 * @param array<string,scalar> $args      Query arguments.
	 * @param string               $cache_key Stable cache key suffix.
	 * @param bool                 $force     Bypass the cache.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_json( string $path, array $args, string $cache_key, bool $force = false ) {
		if ( ! Katla_Settings::is_configured() ) {
			return new WP_Error( 'katla_not_configured', __( 'No Katla Site ID has been configured.', 'katla-consent' ) );
		}

		$site_id   = (string) Katla_Settings::get( 'site_id' );
		$transient = self::TRANSIENT_PREFIX . md5( $site_id . '|' . self::base_url() . '|' . $cache_key );

		if ( ! $force ) {
			if ( isset( self::$memo[ $transient ] ) ) {
				return self::$memo[ $transient ];
			}

			$cached = get_transient( $transient );
			if ( false !== $cached ) {
				self::$memo[ $transient ] = self::unpack( $cached );

				return self::$memo[ $transient ];
			}
		}

		$response = wp_remote_get(
			self::url( $path, $args ),
			array(
				/**
				 * Filters the HTTP timeout for Katla requests.
				 *
				 * @param int $timeout Timeout in seconds.
				 */
				'timeout'    => (int) apply_filters( 'katla_http_timeout', 8 ),
				'user-agent' => 'Katla-WordPress/' . KATLA_VERSION . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::remember_failure( $transient, $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			$decoded = json_decode( $body, true );
			$message = is_array( $decoded ) && isset( $decoded['error'] )
				? (string) $decoded['error']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Katla returned HTTP %d.', 'katla-consent' ), $code );

			return self::remember_failure( $transient, new WP_Error( 'katla_http_error', $message, array( 'status' => $code ) ) );
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return self::remember_failure( $transient, new WP_Error( 'katla_invalid_json', __( 'Katla returned a response that could not be read.', 'katla-consent' ) ) );
		}

		$ttl = (int) Katla_Settings::get( 'cache_ttl' );
		if ( $ttl > 0 ) {
			set_transient( $transient, $data, $ttl );
			self::remember_key( $transient );
		}

		self::$memo[ $transient ] = $data;

		return $data;
	}

	/**
	 * Cache a failure briefly, so an unreachable CDN cannot make every page
	 * request wait for a fresh timeout.
	 *
	 * @param string   $transient Transient name.
	 * @param WP_Error $error     The failure to remember.
	 * @return WP_Error The same error, for convenient returning.
	 */
	private static function remember_failure( string $transient, WP_Error $error ): WP_Error {
		/**
		 * Filters how long a failed Katla request is remembered, in seconds.
		 *
		 * @param int $ttl Seconds. Set to 0 to retry on every request.
		 */
		$ttl = (int) apply_filters( 'katla_failure_cache_ttl', 5 * MINUTE_IN_SECONDS );

		if ( $ttl > 0 ) {
			set_transient(
				$transient,
				array(
					'__katla_error'   => $error->get_error_code(),
					'__katla_message' => $error->get_error_message(),
				),
				$ttl
			);
			self::remember_key( $transient );
		}

		self::$memo[ $transient ] = $error;

		return $error;
	}

	/**
	 * Turn a cached value back into either data or the failure it recorded.
	 *
	 * @param mixed $cached Cached transient value.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function unpack( $cached ) {
		if ( is_array( $cached ) && isset( $cached['__katla_error'] ) ) {
			return new WP_Error( (string) $cached['__katla_error'], (string) ( $cached['__katla_message'] ?? '' ) );
		}

		return $cached;
	}

	/**
	 * Track a transient key so the cache can be flushed wholesale.
	 *
	 * @param string $transient Transient name.
	 * @return void
	 */
	private static function remember_key( string $transient ): void {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		if ( ! in_array( $transient, $index, true ) ) {
			$index[] = $transient;
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * Delete every cached Katla response.
	 *
	 * @return int Number of cache entries removed.
	 */
	public static function flush_cache(): int {
		self::$memo = array();

		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$deleted = 0;
		foreach ( $index as $transient ) {
			if ( delete_transient( (string) $transient ) ) {
				++$deleted;
			}
		}

		delete_option( self::INDEX_OPTION );

		/**
		 * Fires after the Katla response cache has been flushed.
		 *
		 * @param int $deleted Number of entries removed.
		 */
		do_action( 'katla_cache_flushed', $deleted );

		return $deleted;
	}
}
