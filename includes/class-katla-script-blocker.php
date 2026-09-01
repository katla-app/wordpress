<?php
/**
 * Server-side blocking of scripts and embeds until consent is given.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Katla's cookie guard stops non-consented cookies from being written, but a
 * tracking script that has already loaded can still fingerprint or beacon. This
 * class neutralises those scripts and third-party embeds server-side, and the
 * front-end runtime activates them the moment their category is allowed.
 */
class Katla_Script_Blocker {

	const SEEN_OPTION    = 'katla_seen_scripts';
	const SEEN_THROTTLE  = 'katla_seen_scripts_lock';
	const CONTENT_FILTER = 'katla_content';

	/**
	 * Handles registered for blocking at runtime via `katla_block_script()`.
	 *
	 * @var array<string,string>
	 */
	private static $runtime_handles = array();

	/**
	 * Handles seen on this request, for the admin picker.
	 *
	 * @var array<string,string>
	 */
	private $seen = array();

	/**
	 * Hook into WordPress.
	 */
	public function __construct() {
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 20, 3 );

		foreach ( array( 'the_content', 'widget_text_content', 'embed_oembed_html', 'video_embed_html', 'post_thumbnail_html' ) as $filter ) {
			add_filter( $filter, array( $this, 'filter_embeds' ), 999 );
		}

		add_action( 'shutdown', array( $this, 'persist_seen_handles' ) );
	}

	/**
	 * Register a script handle for consent gating from code.
	 *
	 * @param string $handle   Script handle.
	 * @param string $category Cookie category required to run it.
	 * @return void
	 */
	public static function block( string $handle, string $category ): void {
		if ( in_array( $category, Katla_Categories::ids(), true ) ) {
			self::$runtime_handles[ $handle ] = $category;
		}
	}

	/**
	 * The handle-to-category map, from settings plus runtime registrations.
	 *
	 * @return array<string,string>
	 */
	public static function handle_map(): array {
		$map = array_merge( (array) Katla_Settings::get( 'blocked_handles' ), self::$runtime_handles );

		/**
		 * Filters the script handles gated behind consent.
		 *
		 * @param array $map Map of script handle to cookie category.
		 */
		return (array) apply_filters( 'katla_blocked_handles', $map );
	}

	/**
	 * The URL-pattern-to-category map for scripts enqueued by other plugins.
	 *
	 * @return array<string,string>
	 */
	public static function pattern_map(): array {
		/**
		 * Filters the script URL patterns gated behind consent.
		 *
		 * @param array $map Map of URL substring to cookie category.
		 */
		return (array) apply_filters( 'katla_blocked_patterns', (array) Katla_Settings::get( 'blocked_patterns' ) );
	}

	/**
	 * Every category this site actually gates something on.
	 *
	 * @return array<int,string>
	 */
	public static function categories_in_use(): array {
		$categories = array();

		if ( Katla_Settings::get( 'block_scripts' ) ) {
			$categories = array_merge( array_values( self::handle_map() ), array_values( self::pattern_map() ) );
		}

		if ( Katla_Settings::get( 'content_blocker' ) ) {
			$categories[] = (string) Katla_Settings::get( 'content_blocker_category' );
		}

		return array_values( array_unique( array_filter( $categories ) ) );
	}

	/**
	 * The category a given script handle/src is gated on, if any.
	 *
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return string Category ID, or '' when the script is not gated.
	 */
	private function category_for( string $handle, string $src ): string {
		$map = self::handle_map();

		if ( isset( $map[ $handle ] ) ) {
			return (string) $map[ $handle ];
		}

		if ( '' !== $src ) {
			foreach ( self::pattern_map() as $pattern => $category ) {
				if ( '' !== $pattern && false !== stripos( $src, (string) $pattern ) ) {
					return (string) $category;
				}
			}
		}

		return '';
	}

	/**
	 * Neutralise a blocked script tag.
	 *
	 * Since WordPress 6.3 the tag handed to this filter is the whole bundle for
	 * the handle — translations, the `before` inline script, the `<script src>`
	 * and the `after` inline script — so every `<script>` element inside it is
	 * rewritten rather than replaced, keeping inline configuration intact for
	 * when consent arrives.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public function filter_script_tag( $tag, $handle, $src ) {
		$tag    = (string) $tag;
		$handle = (string) $handle;
		$src    = (string) $src;

		if ( is_admin() ) {
			return $tag;
		}

		$this->seen[ $handle ] = $src;

		if ( ! Katla_Settings::get( 'block_scripts' ) || ! Katla_Frontend::should_load() ) {
			return $tag;
		}

		// Never gate our own scripts.
		if ( in_array( $handle, array( Katla_Frontend::HANDLE_SCRIPT, Katla_Frontend::HANDLE_RUNTIME ), true ) ) {
			return $tag;
		}

		$category = $this->category_for( $handle, $src );

		if ( '' === $category ) {
			return $tag;
		}

		return self::neutralize_script_tags( $tag, $category, $handle );
	}

	/**
	 * Rewrite every `<script>` opening tag in a fragment so it cannot run.
	 *
	 * @param string $html     Fragment containing one or more script tags.
	 * @param string $category Category required to run them.
	 * @param string $handle   Script handle, for debugging on the front end.
	 * @return string
	 */
	public static function neutralize_script_tags( string $html, string $category, string $handle = '' ): string {
		return (string) preg_replace_callback(
			'#<script\b([^>]*)>#i',
			static function ( array $matches ) use ( $category, $handle ): string {
				$attributes = $matches[1];

				if ( false !== stripos( $attributes, 'data-katla-' ) ) {
					return $matches[0]; // Already neutralised.
				}

				$extra = ' type="text/plain" data-katla-category="' . esc_attr( $category ) . '"';

				if ( '' !== $handle ) {
					$extra .= ' data-katla-handle="' . esc_attr( $handle ) . '"';
				}

				// Drop the existing type so the browser will not execute the tag.
				$attributes = (string) preg_replace( '/\stype\s*=\s*(["\']).*?\1/i', '', $attributes );

				// Remember async/defer so the script behaves the same once activated.
				foreach ( array( 'async', 'defer' ) as $flag ) {
					$pattern = '/\s' . $flag . '(\s*=\s*(["\']).*?\2)?(?=\s|$)/i';

					if ( preg_match( $pattern, $attributes ) ) {
						$attributes = (string) preg_replace( $pattern, '', $attributes );
						$extra     .= ' data-katla-' . $flag . '="1"';
					}
				}

				// Move src out of the way; the runtime restores it on consent.
				if ( preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $attributes, $src_match ) ) {
					$attributes = str_replace( $src_match[0], ' data-katla-src=' . $src_match[1] . $src_match[2] . $src_match[1], $attributes );
				} else {
					$extra .= ' data-katla-inline="1"';
				}

				return '<script' . rtrim( $attributes ) . $extra . '>';
			},
			$html
		);
	}

	/**
	 * Replace third-party iframes in content with a consent placeholder.
	 *
	 * @param string $html Content HTML.
	 * @return string
	 */
	public function filter_embeds( $html ) {
		$html = (string) $html;

		if ( is_admin() || ! Katla_Settings::get( 'content_blocker' ) || ! Katla_Frontend::should_load() ) {
			return $html;
		}

		if ( false === stripos( $html, '<iframe' ) ) {
			return $html;
		}

		$hosts = array_filter( (array) Katla_Settings::get( 'content_blocker_hosts' ) );

		if ( ! $hosts ) {
			return $html;
		}

		$category = (string) Katla_Settings::get( 'content_blocker_category' );

		return (string) preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe>|<iframe\b[^>]*/?>#is',
			function ( array $matches ) use ( $hosts, $category ): string {
				return self::maybe_block_iframe( $matches[0], $hosts, $category );
			},
			$html
		);
	}

	/**
	 * Wrap a single iframe in a consent placeholder when its host is gated.
	 *
	 * @param string            $iframe   The iframe HTML.
	 * @param array<int,string> $hosts    Host fragments to block.
	 * @param string            $category Category required to load it.
	 * @return string
	 */
	public static function maybe_block_iframe( string $iframe, array $hosts, string $category ): string {
		if ( false !== stripos( $iframe, 'data-katla-src' ) ) {
			return $iframe;
		}

		if ( ! preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $iframe, $src_match ) ) {
			return $iframe;
		}

		$src   = html_entity_decode( $src_match[2], ENT_QUOTES, 'UTF-8' );
		$match = false;

		foreach ( $hosts as $host ) {
			$host = trim( (string) $host );
			if ( '' !== $host && false !== stripos( $src, $host ) ) {
				$match = true;
				break;
			}
		}

		if ( ! $match ) {
			return $iframe;
		}

		$blocked = str_replace( $src_match[0], ' data-katla-src="' . esc_url( $src ) . '"', $iframe );
		$blocked = preg_replace( '/<iframe\b/i', '<iframe hidden data-katla-embed="1"', $blocked, 1 );

		return self::placeholder( (string) $blocked, $category );
	}

	/**
	 * Placeholder markup shown in place of blocked content.
	 *
	 * @param string $blocked_html The neutralised markup, rendered once allowed.
	 * @param string $category     Category required to view it.
	 * @return string
	 */
	public static function placeholder( string $blocked_html, string $category ): string {
		$strings = Katla_Frontend::translations();
		$label   = Katla_Categories::label( $category );

		$markup = sprintf(
			'<div class="katla-blocked" data-katla-blocked="%1$s">
	<div class="katla-blocked__notice">
		<p class="katla-blocked__title">%2$s</p>
		<p class="katla-blocked__message">%3$s</p>
		<button type="button" class="katla-blocked__button" data-katla-allow="%1$s">%4$s</button>
	</div>
	%5$s
</div>',
			esc_attr( $category ),
			esc_html( $strings['blockedTitle'] ),
			esc_html( sprintf( $strings['blockedMessage'], $label ) ),
			esc_html( $strings['blockedButton'] ),
			$blocked_html
		);

		/**
		 * Filters the placeholder markup shown in place of blocked content.
		 *
		 * @param string $markup       Placeholder HTML.
		 * @param string $category     Required category.
		 * @param string $blocked_html The neutralised markup.
		 */
		return (string) apply_filters( 'katla_blocked_placeholder', $markup, $category, $blocked_html );
	}

	/**
	 * Remember which script handles this site prints, for the settings screen.
	 *
	 * @return void
	 */
	public function persist_seen_handles(): void {
		if ( is_admin() || ! $this->seen || wp_doing_cron() ) {
			return;
		}

		if ( get_transient( self::SEEN_THROTTLE ) ) {
			return;
		}

		$stored = get_option( self::SEEN_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_slice( array_merge( $stored, $this->seen ), 0, 250, true );

		if ( $merged !== $stored ) {
			update_option( self::SEEN_OPTION, $merged, false );
		}

		set_transient( self::SEEN_THROTTLE, 1, 15 * MINUTE_IN_SECONDS );
	}
}
