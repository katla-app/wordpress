<?php
/**
 * WooCommerce integration.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps stores working under a cookie guard and surfaces consent controls in
 * WooCommerce's own templates.
 */
class Katla_WooCommerce {

	/**
	 * Hook into WooCommerce.
	 */
	public function __construct() {
		// Registered at load time: WooCommerce fires this on `plugins_loaded`
		// at priority -1, before any later hook of ours could run.
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );

		add_action( 'plugins_loaded', array( $this, 'init_integration' ), 20 );
	}

	/**
	 * Wire up the store-facing hooks once we know WooCommerce is present.
	 *
	 * @return void
	 */
	public function init_integration(): void {
		if ( ! Katla_Plugin::has_woocommerce() ) {
			return;
		}

		add_filter( 'katla_blocked_handles', array( __CLASS__, 'protect_store_scripts' ), 5 );

		if ( Katla_Settings::get( 'woo_privacy_text' ) ) {
			add_filter( 'woocommerce_get_privacy_policy_text', array( __CLASS__, 'privacy_policy_text' ), 10, 2 );
		}

		if ( Katla_Settings::get( 'woo_account_link' ) ) {
			add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'account_settings_link' ), 20 );
		}
	}

	/**
	 * Declare compatibility with HPOS and the cart/checkout blocks.
	 *
	 * @return void
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KATLA_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', KATLA_PLUGIN_FILE, true );
	}

	/**
	 * Cookies WooCommerce needs for the cart, session and checkout to work.
	 *
	 * These are strictly necessary under GDPR Article 6(1)(b) and are exempt
	 * from consent, so they bypass the cookie guard. Order-attribution cookies
	 * (`sbjs_*`) are deliberately not included — those are marketing cookies and
	 * should stay behind consent.
	 *
	 * @return array<int,string>
	 */
	public static function essential_cookies(): array {
		return array(
			'woocommerce_cart_hash',
			'woocommerce_items_in_cart',
			'woocommerce_recently_viewed',
			'wp_woocommerce_session_*',
			'wc_cart_hash_*',
			'wc_fragments_*',
			'store_notice*',
			'woocommerce_snooze_suggestions_*',
			'wp-wpml_current_language',
		);
	}

	/**
	 * WooCommerce scripts that must never be gated behind consent.
	 *
	 * @return array<int,string>
	 */
	public static function protected_handles(): array {
		return array(
			'wc-cart-fragments',
			'woocommerce',
			'wc-add-to-cart',
			'wc-cart',
			'wc-checkout',
			'wc-country-select',
			'wc-address-i18n',
			'wc-blocks-checkout',
			'wc-blocks-registry',
			'wc-cart-checkout-base',
			'wc-settings',
		);
	}

	/**
	 * Strip store-critical handles out of the blocked list.
	 *
	 * @param array<string,string> $map Map of handle to category.
	 * @return array<string,string>
	 */
	public static function protect_store_scripts( $map ) {
		if ( ! is_array( $map ) || ! Katla_Settings::get( 'woo_protect_cookies' ) ) {
			return $map;
		}

		foreach ( self::protected_handles() as $handle ) {
			unset( $map[ $handle ] );
		}

		return $map;
	}

	/**
	 * Append a cookie-settings link to WooCommerce's privacy notices.
	 *
	 * @param string $text Privacy policy text.
	 * @param string $type Context: `checkout` or `registration`.
	 * @return string
	 */
	public static function privacy_policy_text( $text, $type ) {
		unset( $type );

		$link = sprintf(
			' <a href="#" class="katla-settings-link" data-katla-open-settings="1">%s</a>',
			esc_html( Katla_Frontend::translations()['settings'] )
		);

		return $text . $link;
	}

	/**
	 * Print a cookie-settings link on the My Account dashboard.
	 *
	 * @return void
	 */
	public static function account_settings_link(): void {
		echo '<p class="katla-account-settings">';
		echo do_shortcode( '[katla_cookie_settings tag="a"]' );
		echo '</p>';
	}
}
