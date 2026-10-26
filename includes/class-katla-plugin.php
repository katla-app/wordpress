<?php
/**
 * Plugin bootstrap.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components together.
 */
class Katla_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Katla_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instantiated components, keyed by short name.
	 *
	 * @var array<string,object>
	 */
	private $components = array();

	/**
	 * Get (and on first call, build) the plugin instance.
	 *
	 * @return Katla_Plugin
	 */
	public static function instance(): Katla_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build the plugin.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->components['frontend']    = new Katla_Frontend();
		$this->components['blocker']     = new Katla_Script_Blocker();
		$this->components['shortcodes']  = new Katla_Shortcodes();
		$this->components['blocks']      = new Katla_Blocks();
		$this->components['privacy']     = new Katla_Privacy();
		$this->components['woocommerce'] = new Katla_WooCommerce();
		$this->components['consent_api'] = new Katla_WP_Consent_API();

		if ( is_admin() ) {
			$this->components['admin'] = new Katla_Admin();
		}
	}

	/**
	 * A component instance, for themes and add-ons.
	 *
	 * @param string $name Component name.
	 * @return object|null
	 */
	public function get( string $name ) {
		return $this->components[ $name ] ?? null;
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'katla-consent', false, dirname( KATLA_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function has_woocommerce(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Store defaults on activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( false === get_option( Katla_Settings::OPTION, false ) ) {
			add_option( Katla_Settings::OPTION, Katla_Settings::defaults() );
		}
	}

	/**
	 * Drop cached remote data on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Katla_Client::flush_cache();
		delete_transient( Katla_Script_Blocker::SEEN_THROTTLE );
	}
}
