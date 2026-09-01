<?php
/**
 * Front-end loading of the Katla consent script and runtime.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and prints everything the visitor-facing side needs.
 */
class Katla_Frontend {

	const HANDLE_SCRIPT  = 'katla-consent';
	const HANDLE_RUNTIME = 'katla-runtime';
	const HANDLE_STYLE   = 'katla';
	const HANDLE_BANNER  = 'katla-banner';
	const HANDLE_POLICY  = 'katla-policy';

	/**
	 * Hook into WordPress.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'print_verification_meta' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 2 );
	}

	/**
	 * Memoised result of should_load() for this request.
	 *
	 * @var bool|null
	 */
	private static $should_load = null;

	/**
	 * Whether the consent script should load for this request.
	 *
	 * Answered once per request: it is consulted for every script tag and every
	 * block of post content.
	 *
	 * @return bool
	 */
	public static function should_load(): bool {
		if ( null !== self::$should_load ) {
			return self::$should_load;
		}

		$load = true;

		if ( ! Katla_Settings::get( 'enabled' ) || ! Katla_Settings::is_configured() ) {
			$load = false;
		} elseif ( is_admin() || wp_doing_ajax() ) {
			$load = false;
		} elseif ( did_action( 'wp' ) && ( is_feed() || is_embed() ) ) {
			$load = false;
		} elseif ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			$load = false;
		} elseif ( self::is_disabled_for_current_user() ) {
			$load = false;
		}

		/**
		 * Filters whether the Katla consent script loads on this request.
		 *
		 * @param bool $load Whether to load.
		 */
		$load = (bool) apply_filters( 'katla_should_load', $load );

		// Only remember the answer once the query is available, so an early
		// call cannot freeze a "yes" that is_feed() would later refuse.
		if ( did_action( 'wp' ) ) {
			self::$should_load = $load;
		}

		return $load;
	}

	/**
	 * Whether the current user's role opts them out of the banner.
	 *
	 * @return bool
	 */
	private static function is_disabled_for_current_user(): bool {
		$roles = (array) Katla_Settings::get( 'disable_for_roles' );

		if ( ! $roles || ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * Print the domain-verification meta tag.
	 *
	 * @return void
	 */
	public function print_verification_meta(): void {
		$token = (string) Katla_Settings::get( 'verification_token' );

		if ( '' === $token ) {
			return;
		}

		printf( '<meta name="katla-verification" content="%s" />' . "\n", esc_attr( $token ) );
	}

	/**
	 * Register scripts and styles so themes can depend on or dequeue them.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		$in_footer = 'footer' === Katla_Settings::get( 'placement' );

		wp_register_script( self::HANDLE_SCRIPT, Katla_Client::script_url(), array(), null, $in_footer ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- versioned by the CDN.
		wp_register_script( self::HANDLE_RUNTIME, KATLA_PLUGIN_URL . 'assets/js/katla-runtime.js', array( self::HANDLE_SCRIPT ), KATLA_VERSION, $in_footer );
		wp_register_style( self::HANDLE_STYLE, KATLA_PLUGIN_URL . 'assets/css/katla.css', array(), KATLA_VERSION );
		wp_register_style( self::HANDLE_BANNER, KATLA_PLUGIN_URL . 'assets/css/katla-banner.css', array( self::HANDLE_STYLE ), KATLA_VERSION );
		wp_register_style( self::HANDLE_POLICY, KATLA_PLUGIN_URL . 'assets/css/katla-policy.css', array(), KATLA_VERSION );
	}

	/**
	 * Enqueue the consent script and runtime.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! self::should_load() ) {
			return;
		}

		wp_enqueue_script( self::HANDLE_SCRIPT );
		wp_enqueue_script( self::HANDLE_RUNTIME );
		wp_enqueue_style( self::HANDLE_STYLE );

		wp_add_inline_script(
			self::HANDLE_RUNTIME,
			'window.katlaWP = window.katlaWP || {}; window.katlaWP.config = ' . wp_json_encode( self::config() ) . ';',
			'before'
		);

		if ( self::uses_builtin_banner() ) {
			wp_enqueue_style( self::HANDLE_BANNER );
		}

		wp_add_inline_style( self::HANDLE_STYLE, self::inline_banner_css() );
	}

	/**
	 * Whether the plugin renders its own banner UI (headless + built-in).
	 *
	 * @return bool
	 */
	public static function uses_builtin_banner(): bool {
		return 'headless' === Katla_Settings::get( 'mode' )
			&& 'builtin' === Katla_Settings::get( 'headless_banner' );
	}

	/**
	 * Configuration handed to the front-end runtime.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		$mode = (string) Katla_Settings::get( 'mode' );

		$config = array(
			'siteId'            => (string) Katla_Settings::get( 'site_id' ),
			'baseUrl'           => Katla_Client::base_url(),
			'mode'              => $mode,
			'banner'            => 'headless' === $mode ? (string) Katla_Settings::get( 'headless_banner' ) : 'widget',
			'locale'            => Katla_Categories::resolve_locale(),
			'debug'             => (bool) Katla_Settings::get( 'debug' ),
			'events'            => (bool) Katla_Settings::get( 'consent_events' ),
			'googleConsentMode' => (bool) Katla_Settings::get( 'google_consent_mode' ),
			'protectEssential'  => (bool) Katla_Settings::get( 'protect_essential' ),
			'allowlist'         => self::cookie_allowlist(),
			'blockScripts'      => (bool) Katla_Settings::get( 'block_scripts' ),
			'contentBlocker'    => array(
				'enabled'  => (bool) Katla_Settings::get( 'content_blocker' ),
				'category' => (string) Katla_Settings::get( 'content_blocker_category' ),
			),
			'categories'        => self::banner_categories(),
			'i18n'              => self::translations(),
		);

		/**
		 * Filters the configuration object passed to the Katla front-end runtime.
		 *
		 * @param array $config Runtime configuration.
		 */
		return (array) apply_filters( 'katla_runtime_config', $config );
	}

	/**
	 * Cookie names that must never be blocked by the cookie guard.
	 *
	 * Values ending in `*` are treated as prefixes by the runtime.
	 *
	 * @return array<int,string>
	 */
	public static function cookie_allowlist(): array {
		if ( ! Katla_Settings::get( 'protect_essential' ) ) {
			return array();
		}

		$allowlist = array(
			// WordPress core.
			'wordpress_*',
			'wordpress_logged_in_*',
			'wordpress_test_cookie',
			'wordpress_sec_*',
			'wp-settings-*',
			'wp-postpass_*',
			'wp_lang',
			'comment_author_*',
			'comment_author_email_*',
			'comment_author_url_*',
			'wp-resetpass-*',
			'devicePixelRatio',
		);

		if ( Katla_Plugin::has_woocommerce() && Katla_Settings::get( 'woo_protect_cookies' ) ) {
			$allowlist = array_merge( $allowlist, Katla_WooCommerce::essential_cookies() );
		}

		$allowlist = array_merge( $allowlist, (array) Katla_Settings::get( 'extra_allowlist' ) );

		/**
		 * Filters the cookie names that bypass the Katla cookie guard.
		 *
		 * Use this for strictly necessary cookies your site sets from
		 * JavaScript. A trailing `*` matches by prefix.
		 *
		 * @param array $allowlist Cookie names or prefixes.
		 */
		$allowlist = (array) apply_filters( 'katla_cookie_allowlist', $allowlist );

		return array_values( array_unique( array_filter( array_map( 'strval', $allowlist ) ) ) );
	}

	/**
	 * Categories to show in the built-in banner.
	 *
	 * Combines the categories Katla has scanned cookies for with the categories
	 * this site actually gates scripts and embeds on, so a visitor is never
	 * asked about a category that does nothing — or blocked from allowing one.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function banner_categories(): array {
		if ( ! self::uses_builtin_banner() ) {
			return array();
		}

		$cookies_by_category = array();
		$data                = Katla_Client::get_cookies( Katla_Categories::resolve_locale() );

		if ( ! is_wp_error( $data ) && isset( $data['cookies'] ) && is_array( $data['cookies'] ) ) {
			$cookies_by_category = $data['cookies'];
		}

		$in_use = array( 'functional' );

		foreach ( $cookies_by_category as $category => $cookies ) {
			if ( is_array( $cookies ) && $cookies ) {
				$in_use[] = (string) $category;
			}
		}

		$in_use = array_merge( $in_use, Katla_Script_Blocker::categories_in_use() );

		$categories = array();

		foreach ( Katla_Categories::ids() as $id ) {
			if ( ! in_array( $id, $in_use, true ) ) {
				continue;
			}

			$cookies = array();
			foreach ( (array) ( $cookies_by_category[ $id ] ?? array() ) as $cookie ) {
				if ( ! is_array( $cookie ) ) {
					continue;
				}
				$cookies[] = array(
					'name'           => (string) ( $cookie['name'] ?? '' ),
					'domain'         => (string) ( $cookie['domain'] ?? '' ),
					'platform'       => (string) ( $cookie['platform'] ?? '' ),
					'description'    => (string) ( $cookie['description'] ?? '' ),
					'dataController' => (string) ( $cookie['dataController'] ?? '' ),
					'expires'        => (string) ( $cookie['expires'] ?? '' ),
				);
			}

			$categories[] = array(
				'id'          => $id,
				'label'       => Katla_Categories::label( $id ),
				'description' => Katla_Categories::description( $id ),
				'required'    => Katla_Categories::is_required( $id ),
				'cookies'     => $cookies,
			);
		}

		/**
		 * Filters the categories rendered in the built-in consent banner.
		 *
		 * @param array $categories Category definitions.
		 */
		return (array) apply_filters( 'katla_banner_categories', $categories );
	}

	/**
	 * Strings used by the built-in banner.
	 *
	 * @return array<string,string>
	 */
	public static function translations(): array {
		$privacy_url = get_privacy_policy_url();

		$strings = array(
			'title'            => __( 'We value your privacy', 'katla-consent' ),
			'description'      => __( 'We use cookies to improve your experience, analyse traffic and personalise content. You can choose which categories to allow.', 'katla-consent' ),
			'acceptAll'        => __( 'Accept all', 'katla-consent' ),
			'rejectAll'        => __( 'Reject all', 'katla-consent' ),
			'customize'        => __( 'Customise', 'katla-consent' ),
			'save'             => __( 'Save preferences', 'katla-consent' ),
			'close'            => __( 'Close', 'katla-consent' ),
			'settings'         => __( 'Cookie settings', 'katla-consent' ),
			'preferencesTitle' => __( 'Cookie preferences', 'katla-consent' ),
			'alwaysActive'     => __( 'Always active', 'katla-consent' ),
			'showCookies'      => __( 'Show cookies', 'katla-consent' ),
			'hideCookies'      => __( 'Hide cookies', 'katla-consent' ),
			'cookieName'       => __( 'Cookie', 'katla-consent' ),
			'cookieDomain'     => __( 'Domain', 'katla-consent' ),
			'cookieDuration'   => __( 'Duration', 'katla-consent' ),
			'cookiePurpose'    => __( 'Purpose', 'katla-consent' ),
			'privacyPolicy'    => __( 'Privacy policy', 'katla-consent' ),
			'privacyPolicyUrl' => $privacy_url ? $privacy_url : '',
			'blockedTitle'     => __( 'This content is blocked', 'katla-consent' ),
			/* translators: %s: cookie category name, e.g. Marketing. */
			'blockedMessage'   => __( 'Allow %s cookies to view this content.', 'katla-consent' ),
			'blockedButton'    => __( 'Allow and show content', 'katla-consent' ),
		);

		/**
		 * Filters the strings used by the built-in banner and content blocker.
		 *
		 * @param array $strings Translated strings.
		 */
		return (array) apply_filters( 'katla_translations', $strings );
	}

	/**
	 * Theme-facing CSS custom properties for the built-in banner.
	 *
	 * @return string
	 */
	private static function inline_banner_css(): string {
		/**
		 * Filters the CSS custom properties applied to the built-in banner.
		 *
		 * @param array $vars Map of custom property name to value.
		 */
		$vars = (array) apply_filters( 'katla_banner_css_vars', array() );

		if ( ! $vars ) {
			return '';
		}

		$declarations = '';
		foreach ( $vars as $name => $value ) {
			$name          = '--' . ltrim( preg_replace( '/[^a-z0-9\-]/i', '', (string) $name ), '-' );
			$declarations .= sprintf( '%s:%s;', $name, esc_attr( (string) $value ) );
		}

		return ':root{' . $declarations . '}';
	}
}
