<?php
/**
 * Gutenberg blocks.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the editor blocks that wrap the plugin's shortcodes.
 */
class Katla_Blocks {

	const EDITOR_HANDLE = 'katla-blocks-editor';

	/**
	 * Hook into WordPress.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'block_categories_all', array( $this, 'register_category' ), 10, 1 );
	}

	/**
	 * Register the editor script and the blocks themselves.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			self::EDITOR_HANDLE,
			KATLA_PLUGIN_URL . 'assets/js/katla-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			KATLA_VERSION,
			true
		);

		wp_add_inline_script(
			self::EDITOR_HANDLE,
			'window.katlaBlocks = ' . wp_json_encode(
				array(
					'categories'  => array_map(
						static function ( string $id ): array {
							return array(
								'value' => $id,
								'label' => Katla_Categories::label( $id ),
							);
						},
						Katla_Categories::ids()
					),
					'locales'     => Katla_Categories::SUPPORTED_LOCALES,
					'configured'  => Katla_Settings::is_configured(),
					'settingsUrl' => admin_url( 'options-general.php?page=katla-consent' ),
				)
			) . ';',
			'before'
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::EDITOR_HANDLE, 'katla-consent', KATLA_PLUGIN_DIR . 'languages' );
		}

		wp_register_style( 'katla-blocks-editor-style', KATLA_PLUGIN_URL . 'assets/css/katla-editor.css', array(), KATLA_VERSION );

		register_block_type(
			KATLA_PLUGIN_DIR . 'blocks/policy',
			array( 'render_callback' => array( __CLASS__, 'render_policy' ) )
		);
		register_block_type(
			KATLA_PLUGIN_DIR . 'blocks/cookie-table',
			array( 'render_callback' => array( __CLASS__, 'render_cookie_table' ) )
		);
		register_block_type(
			KATLA_PLUGIN_DIR . 'blocks/settings-button',
			array( 'render_callback' => array( __CLASS__, 'render_settings_button' ) )
		);
	}

	/**
	 * Add a Katla block category to the inserter.
	 *
	 * @param array<int,array<string,mixed>> $categories Block categories.
	 * @return array<int,array<string,mixed>>
	 */
	public function register_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}

		array_unshift(
			$categories,
			array(
				'slug'  => 'katla',
				'title' => __( 'Katla', 'katla-consent' ),
				'icon'  => null,
			)
		);

		return $categories;
	}

	/**
	 * Render the policy block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_policy( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return Katla_Shortcodes::policy(
			array(
				'format' => (string) ( $attributes['format'] ?? 'full' ),
				'locale' => (string) ( $attributes['locale'] ?? '' ),
				'render' => (string) ( $attributes['render'] ?? Katla_Settings::get( 'policy_render' ) ),
			)
		);
	}

	/**
	 * Render the cookie table block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_cookie_table( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return Katla_Shortcodes::cookie_table(
			array(
				'locale'   => (string) ( $attributes['locale'] ?? '' ),
				'category' => (string) ( $attributes['category'] ?? '' ),
			)
		);
	}

	/**
	 * Render the cookie settings button block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_settings_button( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return Katla_Shortcodes::settings_link(
			array(
				'text' => (string) ( $attributes['label'] ?? '' ),
				'tag'  => 'link' === ( $attributes['style'] ?? 'button' ) ? 'a' : 'button',
			)
		);
	}
}
