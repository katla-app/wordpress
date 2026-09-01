<?php
/**
 * Shortcodes for policies, cookie tables, settings links and gated content.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin's shortcodes.
 */
class Katla_Shortcodes {

	/**
	 * Incrementing counter used to give each policy embed a unique target.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Hook into WordPress.
	 */
	public function __construct() {
		add_shortcode( 'katla_policy', array( __CLASS__, 'policy' ) );
		add_shortcode( 'katla_cookie_table', array( __CLASS__, 'cookie_table' ) );
		add_shortcode( 'katla_cookie_settings', array( __CLASS__, 'settings_link' ) );
		add_shortcode( 'katla_block', array( __CLASS__, 'block' ) );
		add_shortcode( 'katla_consent', array( __CLASS__, 'gated_content' ) );
	}

	/**
	 * `[katla_policy]` — the generated cookie and privacy policy.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function policy( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'format' => 'full',
				'locale' => '',
				'render' => (string) Katla_Settings::get( 'policy_render' ),
				'class'  => '',
			),
			(array) $atts,
			'katla_policy'
		);

		$format = in_array( $atts['format'], array( 'full', 'cookie', 'table' ), true ) ? $atts['format'] : 'full';
		$locale = '' !== $atts['locale']
			? Katla_Categories::normalize_locale( $atts['locale'] )
			: Katla_Categories::resolve_locale();

		if ( ! Katla_Settings::is_configured() ) {
			return self::admin_notice( __( 'Katla: add your Site ID in Settings → Katla Consent to render the policy.', 'katla-consent' ) );
		}

		wp_enqueue_style( Katla_Frontend::HANDLE_POLICY );

		$classes = trim( 'katla-policy katla-policy--' . $format . ' ' . sanitize_html_class( $atts['class'] ) );

		if ( 'client' === $atts['render'] ) {
			return self::policy_client( $format, $locale, $classes );
		}

		return self::policy_server( $format, $locale, $classes );
	}

	/**
	 * Render the policy on the server so it is indexable and cached.
	 *
	 * @param string $format  Policy format.
	 * @param string $locale  Locale.
	 * @param string $classes Wrapper classes.
	 * @return string
	 */
	private static function policy_server( string $format, string $locale, string $classes ): string {
		$data = Katla_Client::get_policy( $format, $locale ? $locale : 'en-GB' );

		if ( is_wp_error( $data ) ) {
			return self::admin_notice(
				sprintf(
					/* translators: %s: error message. */
					__( 'Katla could not load the policy: %s', 'katla-consent' ),
					$data->get_error_message()
				)
			);
		}

		$markdown = isset( $data['policy']['markdown'] ) ? (string) $data['policy']['markdown'] : '';

		if ( '' === $markdown ) {
			return self::admin_notice( __( 'Katla returned an empty policy. Run a scan and configure your privacy policy details in Katla.', 'katla-consent' ) );
		}

		$html = Katla_Markdown::to_html( $markdown );

		/**
		 * Filters the server-rendered policy HTML.
		 *
		 * @param string $html   Rendered HTML.
		 * @param string $format Policy format.
		 * @param string $locale Locale.
		 * @param array  $data   Raw policy payload.
		 */
		$html = (string) apply_filters( 'katla_policy_html', $html, $format, $locale, $data );

		return sprintf(
			'<div class="%1$s" data-katla-policy="%2$s" data-katla-locale="%3$s">%4$s</div>',
			esc_attr( $classes ),
			esc_attr( $format ),
			esc_attr( isset( $data['policy']['locale'] ) ? (string) $data['policy']['locale'] : $locale ),
			$html
		);
	}

	/**
	 * Render the policy in the browser with the hosted policy script.
	 *
	 * @param string $format  Policy format.
	 * @param string $locale  Locale.
	 * @param string $classes Wrapper classes.
	 * @return string
	 */
	private static function policy_client( string $format, string $locale, string $classes ): string {
		++self::$instance;

		$id     = 'katla-policy-' . self::$instance;
		$handle = 'katla-policy-js-' . self::$instance;

		wp_enqueue_script(
			$handle,
			Katla_Client::policy_script_url( $format, $locale, '#' . $id ),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- versioned by the CDN.
			true
		);

		return sprintf(
			'<div id="%1$s" class="%2$s" data-katla-policy="%3$s"></div>',
			esc_attr( $id ),
			esc_attr( $classes ),
			esc_attr( $format )
		);
	}

	/**
	 * `[katla_cookie_table]` — the scanned cookies, grouped by category.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function cookie_table( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'locale'   => '',
				'category' => '',
				'class'    => '',
			),
			(array) $atts,
			'katla_cookie_table'
		);

		if ( ! Katla_Settings::is_configured() ) {
			return self::admin_notice( __( 'Katla: add your Site ID in Settings → Katla Consent to render the cookie table.', 'katla-consent' ) );
		}

		$locale = '' !== $atts['locale'] ? Katla_Categories::normalize_locale( $atts['locale'] ) : Katla_Categories::resolve_locale();
		$data   = Katla_Client::get_cookies( $locale );

		if ( is_wp_error( $data ) ) {
			return self::admin_notice(
				sprintf(
					/* translators: %s: error message. */
					__( 'Katla could not load your cookies: %s', 'katla-consent' ),
					$data->get_error_message()
				)
			);
		}

		$by_category = isset( $data['cookies'] ) && is_array( $data['cookies'] ) ? $data['cookies'] : array();
		$only        = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $atts['category'] ) ) ) );

		wp_enqueue_style( Katla_Frontend::HANDLE_POLICY );

		$strings = Katla_Frontend::translations();
		$out     = '<div class="' . esc_attr( trim( 'katla-policy katla-policy--table ' . sanitize_html_class( $atts['class'] ) ) ) . '">';
		$rows    = 0;

		foreach ( Katla_Categories::ids() as $category ) {
			if ( $only && ! in_array( $category, $only, true ) ) {
				continue;
			}

			$cookies = isset( $by_category[ $category ] ) && is_array( $by_category[ $category ] ) ? $by_category[ $category ] : array();

			if ( ! $cookies ) {
				continue;
			}

			++$rows;

			$out .= '<h3 class="katla-policy__heading katla-policy__heading--3">' . esc_html( Katla_Categories::label( $category ) ) . '</h3>';
			$out .= '<p>' . esc_html( Katla_Categories::description( $category ) ) . '</p>';
			$out .= '<div class="katla-policy__table-wrap"><table class="katla-policy__table"><thead><tr>';
			$out .= '<th scope="col">' . esc_html( $strings['cookieName'] ) . '</th>';
			$out .= '<th scope="col">' . esc_html( $strings['cookieDomain'] ) . '</th>';
			$out .= '<th scope="col">' . esc_html( $strings['cookieDuration'] ) . '</th>';
			$out .= '<th scope="col">' . esc_html( $strings['cookiePurpose'] ) . '</th>';
			$out .= '</tr></thead><tbody>';

			foreach ( $cookies as $cookie ) {
				if ( ! is_array( $cookie ) ) {
					continue;
				}
				$out .= '<tr>';
				$out .= '<td><code>' . esc_html( (string) ( $cookie['name'] ?? '' ) ) . '</code></td>';
				$out .= '<td>' . esc_html( (string) ( $cookie['domain'] ?? '' ) ) . '</td>';
				$out .= '<td>' . esc_html( (string) ( $cookie['expires'] ?? '—' ) ) . '</td>';
				$out .= '<td>' . esc_html( (string) ( $cookie['description'] ?? '' ) ) . '</td>';
				$out .= '</tr>';
			}

			$out .= '</tbody></table></div>';
		}

		$out .= '</div>';

		if ( 0 === $rows ) {
			return self::admin_notice( __( 'Katla has not classified any cookies for this site yet. Run a scan in Katla first.', 'katla-consent' ) );
		}

		return $out;
	}

	/**
	 * `[katla_cookie_settings]` — a link or button that reopens the preferences.
	 *
	 * @param array<string,string>|string $atts    Shortcode attributes.
	 * @param string|null                 $content Enclosed content, used as the label.
	 * @return string
	 */
	public static function settings_link( $atts = array(), $content = null ): string {
		$atts = shortcode_atts(
			array(
				'text'  => '',
				'class' => '',
				'tag'   => 'button',
			),
			(array) $atts,
			'katla_cookie_settings'
		);

		$strings = Katla_Frontend::translations();
		$label   = $content ? wp_strip_all_tags( $content ) : $atts['text'];
		$label   = '' !== $label ? $label : $strings['settings'];
		$classes = trim( 'katla-settings-link ' . implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $atts['class'] ) ) ) );

		if ( 'a' === $atts['tag'] ) {
			return sprintf(
				'<a href="#" class="%1$s" data-katla-open-settings="1">%2$s</a>',
				esc_attr( $classes ),
				esc_html( $label )
			);
		}

		return sprintf(
			'<button type="button" class="%1$s" data-katla-open-settings="1">%2$s</button>',
			esc_attr( $classes ),
			esc_html( $label )
		);
	}

	/**
	 * `[katla_block category="marketing"]…[/katla_block]` — neutralise the
	 * enclosed markup until the category is allowed.
	 *
	 * @param array<string,string>|string $atts    Shortcode attributes.
	 * @param string|null                 $content Enclosed content.
	 * @return string
	 */
	public static function block( $atts = array(), $content = null ): string {
		$atts = shortcode_atts(
			array(
				'category'    => (string) Katla_Settings::get( 'content_blocker_category' ),
				'placeholder' => 'yes',
			),
			(array) $atts,
			'katla_block'
		);

		$content = (string) $content;

		if ( '' === trim( $content ) ) {
			return '';
		}

		$category = in_array( $atts['category'], Katla_Categories::ids(), true ) ? $atts['category'] : 'marketing';
		$inner    = do_shortcode( $content );

		if ( ! Katla_Frontend::should_load() ) {
			return $inner;
		}

		// Neutralise scripts and iframes inside the block.
		$inner = Katla_Script_Blocker::neutralize_script_tags( $inner, $category );
		$inner = preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe>|<iframe\b[^>]*/?>#is',
			static function ( array $matches ) use ( $category ): string {
				$iframe = preg_replace( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', ' data-katla-src="$2"', $matches[0], 1 );

				return (string) preg_replace( '/<iframe\b/i', '<iframe hidden data-katla-embed="1"', (string) $iframe, 1 );
			},
			(string) $inner
		);

		if ( 'no' === $atts['placeholder'] ) {
			return sprintf( '<div class="katla-blocked katla-blocked--bare" data-katla-blocked="%s">%s</div>', esc_attr( $category ), $inner );
		}

		return Katla_Script_Blocker::placeholder( (string) $inner, $category );
	}

	/**
	 * `[katla_consent category="analytics"]…[/katla_consent]` — show the
	 * enclosed content only while that category is allowed.
	 *
	 * @param array<string,string>|string $atts    Shortcode attributes.
	 * @param string|null                 $content Enclosed content.
	 * @return string
	 */
	public static function gated_content( $atts = array(), $content = null ): string {
		$atts = shortcode_atts(
			array(
				'category' => 'analytics',
				'invert'   => 'no',
			),
			(array) $atts,
			'katla_consent'
		);

		$category = in_array( $atts['category'], Katla_Categories::ids(), true ) ? $atts['category'] : 'analytics';

		return sprintf(
			'<div class="katla-consent-gate" data-katla-requires="%1$s" data-katla-invert="%2$s" hidden>%3$s</div>',
			esc_attr( $category ),
			'yes' === $atts['invert'] ? '1' : '0',
			do_shortcode( (string) $content )
		);
	}

	/**
	 * A message rendered only for users who can fix the underlying problem.
	 *
	 * @param string $message Notice text.
	 * @return string
	 */
	private static function admin_notice( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<div class="katla-admin-notice notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
