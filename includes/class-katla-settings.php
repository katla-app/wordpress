<?php
/**
 * Settings storage, defaults and sanitization.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin option, and defines its schema.
 */
class Katla_Settings {

	const OPTION = 'katla_consent_settings';

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Connection.
			'site_id'                  => '',
			'base_url'                 => 'https://dist.katla.app',
			'verification_token'       => '',

			// Loading.
			'enabled'                  => true,
			'mode'                     => 'widget', // widget | headless.
			'headless_banner'          => 'builtin', // builtin | custom.
			'placement'                => 'head', // head | footer.
			'locale_mode'              => 'site', // site | custom | browser.
			'locale'                   => '',
			'theme'                    => '', // '' = use dashboard setting | light | dark.
			'debug'                    => false,
			'disable_for_roles'        => array(),

			// Behaviour.
			'protect_essential'        => true,
			'extra_allowlist'          => array(),
			'consent_events'           => true,
			'google_consent_mode'      => false,

			// Script and embed blocking.
			'block_scripts'            => true,
			'blocked_handles'          => array(),
			'blocked_patterns'         => array(),
			'content_blocker'          => true,
			'content_blocker_category' => 'marketing',
			'content_blocker_hosts'    => array(
				'youtube.com',
				'youtube-nocookie.com',
				'vimeo.com',
				'google.com/maps',
				'facebook.com',
				'instagram.com',
				'x.com',
				'twitter.com',
				'spotify.com',
				'soundcloud.com',
			),

			// Policies.
			'policy_render'            => 'server', // server | client.
			'cache_ttl'                => 12 * HOUR_IN_SECONDS,

			// WooCommerce.
			'woo_protect_cookies'      => true,
			'woo_privacy_text'         => true,
			'woo_account_link'         => false,

			// Housekeeping.
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			/**
			 * Filters the full Katla settings array.
			 *
			 * @param array $settings Merged settings.
			 */
			self::$cache = apply_filters( 'katla_settings', array_merge( self::defaults(), $stored ) );
		}

		return self::$cache;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		$value = array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;

		/**
		 * Filters a single Katla setting.
		 *
		 * @param mixed  $value Setting value.
		 * @param string $key   Setting key.
		 */
		return apply_filters( "katla_setting_{$key}", $value, $key );
	}

	/**
	 * Persist a partial set of settings.
	 *
	 * @param array<string,mixed> $values Values to merge in.
	 * @return bool
	 */
	public static function update( array $values ): bool {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$result = update_option( self::OPTION, self::sanitize( array_merge( $stored, $values ) ) );

		self::flush_cache();

		return $result;
	}

	/**
	 * Clear the runtime settings cache.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * Whether the plugin has everything it needs to run on the front end.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::get( 'site_id' ) && self::is_uuid( self::get( 'site_id' ) );
	}

	/**
	 * Validate a Katla site ID.
	 *
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_uuid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Sanitize the whole settings array. Used as the `register_setting` callback.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$existing = get_option( self::OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$out = array_merge( $defaults, $existing );

		if ( ! is_array( $input ) ) {
			return $out;
		}

		// Only fields actually rendered by the submitted screen are updated, so
		// saving one tab never silently resets the checkboxes on another.
		$fields = isset( $input['_fields'] )
			? array_filter( array_map( 'sanitize_key', explode( ',', (string) $input['_fields'] ) ) )
			: array_keys( $defaults );

		$submitted = static function ( string $key ) use ( $fields ): bool {
			return in_array( $key, $fields, true );
		};

		// --- Connection ---------------------------------------------------.
		if ( $submitted( 'site_id' ) ) {
			$site_id = strtolower( trim( sanitize_text_field( (string) ( $input['site_id'] ?? '' ) ) ) );
			if ( '' === $site_id || self::is_uuid( $site_id ) ) {
				$out['site_id'] = $site_id;
			} else {
				add_settings_error(
					self::OPTION,
					'katla_site_id',
					__( 'The Site ID must be a valid UUID. Copy it from the Widget section of your site settings in Katla.', 'katla-consent' ),
					'error'
				);
			}
		}

		if ( $submitted( 'base_url' ) ) {
			$base            = untrailingslashit( trim( (string) ( $input['base_url'] ?? '' ) ) );
			$base            = '' === $base ? $defaults['base_url'] : esc_url_raw( $base );
			$out['base_url'] = $base ? $base : $defaults['base_url'];
		}

		if ( $submitted( 'verification_token' ) ) {
			$out['verification_token'] = sanitize_text_field( (string) ( $input['verification_token'] ?? '' ) );
		}

		if ( $submitted( 'locale' ) ) {
			$out['locale'] = sanitize_text_field( (string) ( $input['locale'] ?? '' ) );
		}

		if ( $submitted( 'cache_ttl' ) ) {
			$ttl              = absint( $input['cache_ttl'] ?? 0 );
			$out['cache_ttl'] = $ttl > 0 ? max( 60, $ttl ) : 0;
		}

		// --- Enumerated values --------------------------------------------.
		$enums = array(
			'mode'                     => array( 'widget', 'headless' ),
			'headless_banner'          => array( 'builtin', 'custom' ),
			'placement'                => array( 'head', 'footer' ),
			'locale_mode'              => array( 'site', 'custom', 'browser' ),
			'theme'                    => array( '', 'light', 'dark' ),
			'policy_render'            => array( 'server', 'client' ),
			'content_blocker_category' => Katla_Categories::ids(),
		);

		foreach ( $enums as $key => $allowed ) {
			if ( $submitted( $key ) ) {
				$out[ $key ] = self::one_of( $input[ $key ] ?? '', $allowed, (string) $defaults[ $key ] );
			}
		}

		// --- Booleans -----------------------------------------------------.
		$booleans = array(
			'enabled',
			'debug',
			'protect_essential',
			'consent_events',
			'google_consent_mode',
			'block_scripts',
			'content_blocker',
			'woo_protect_cookies',
			'woo_privacy_text',
			'woo_account_link',
			'delete_data_on_uninstall',
		);

		foreach ( $booleans as $key ) {
			if ( $submitted( $key ) ) {
				$value       = $input[ $key ] ?? false;
				$out[ $key ] = ! empty( $value ) && '0' !== (string) $value;
			}
		}

		// --- Lists --------------------------------------------------------.
		if ( $submitted( 'disable_for_roles' ) ) {
			$out['disable_for_roles'] = self::sanitize_lines( $input['disable_for_roles'] ?? array(), 'sanitize_key' );
		}

		if ( $submitted( 'extra_allowlist' ) ) {
			$out['extra_allowlist'] = self::sanitize_lines( $input['extra_allowlist'] ?? array(), 'sanitize_text_field' );
		}

		if ( $submitted( 'content_blocker_hosts' ) ) {
			$out['content_blocker_hosts'] = self::sanitize_lines( $input['content_blocker_hosts'] ?? array(), 'sanitize_text_field' );
		}

		// --- Maps ---------------------------------------------------------.
		if ( $submitted( 'blocked_handles' ) ) {
			$out['blocked_handles'] = self::sanitize_map( $input['blocked_handles'] ?? array(), 'sanitize_key' );
		}

		if ( $submitted( 'blocked_patterns' ) ) {
			$out['blocked_patterns'] = self::sanitize_map( $input['blocked_patterns'] ?? array(), 'sanitize_text_field' );
		}

		Katla_Client::flush_cache();
		self::flush_cache();

		return $out;
	}

	/**
	 * Constrain a value to a list of allowed values.
	 *
	 * @param mixed         $value    Raw value.
	 * @param array<string> $allowed  Allowed values.
	 * @param string        $fallback Value used when `$value` is not allowed.
	 * @return string
	 */
	private static function one_of( $value, array $allowed, string $fallback ): string {
		$value = is_scalar( $value ) ? (string) $value : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Turn a textarea (or array) into a clean list of unique, non-empty values.
	 *
	 * @param mixed    $value    Raw value: newline separated string or array.
	 * @param callable $sanitize Per-item sanitizer.
	 * @return array<int,string>
	 */
	public static function sanitize_lines( $value, callable $sanitize ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$item = trim( call_user_func( $sanitize, (string) $item ) );
			if ( '' !== $item ) {
				$items[] = $item;
			}
		}

		return array_values( array_unique( $items ) );
	}

	/**
	 * Sanitize a `value => category` map, dropping unknown categories.
	 *
	 * @param mixed    $value        Raw map, or a list of `{key,category}` rows.
	 * @param callable $key_sanitize Sanitizer for the map key.
	 * @return array<string,string>
	 */
	private static function sanitize_map( $value, callable $key_sanitize ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$categories = Katla_Categories::ids();
		$out        = array();

		foreach ( $value as $key => $category ) {
			// Repeater rows arrive as array( 'key' => ..., 'category' => ... ).
			if ( is_array( $category ) ) {
				$key      = $category['key'] ?? '';
				$category = $category['category'] ?? '';
			}

			$key      = trim( call_user_func( $key_sanitize, (string) $key ) );
			$category = sanitize_key( (string) $category );

			if ( '' === $key || ! in_array( $category, $categories, true ) ) {
				continue;
			}

			$out[ $key ] = $category;
		}

		return $out;
	}
}
