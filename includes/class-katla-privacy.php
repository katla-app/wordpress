<?php
/**
 * WordPress privacy tooling integration.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds Katla guidance to the WordPress privacy policy guide and can create a
 * ready-made cookie policy page.
 */
class Katla_Privacy {

	const PAGE_OPTION = 'katla_cookie_policy_page';

	/**
	 * Hook into WordPress.
	 */
	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register suggested privacy policy text.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">'
			. esc_html__( 'This site uses Katla to collect and store cookie consent. Katla records the consent choice, the categories allowed, a consent ID, the timestamp and the browser user agent.', 'katla-consent' )
			. '</p>'
			. '<p><strong>' . esc_html__( 'Cookies and consent', 'katla-consent' ) . '</strong></p>'
			. '<p>' . esc_html__( 'When you first visit this site you are asked which categories of cookies you allow. Your choice is stored in a cookie named _katla_consent for 365 days, and a record of the choice is stored by our consent provider, Katla, so we can demonstrate compliance. You can change or withdraw your choice at any time through the cookie settings link.', 'katla-consent' ) . '</p>'
			. '<p>' . esc_html__( 'A full, automatically maintained list of the cookies this site sets — including their purpose, provider and duration — is available on our cookie policy page.', 'katla-consent' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Katla Cookie Consent', 'katla-consent' ), $content );
	}

	/**
	 * Create (or reuse) a published cookie policy page containing the policy.
	 *
	 * @return int|WP_Error Page ID on success.
	 */
	public static function create_cookie_policy_page() {
		$existing = (int) get_option( self::PAGE_OPTION, 0 );

		if ( $existing && 'publish' === get_post_status( $existing ) ) {
			return $existing;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Cookie Policy', 'katla-consent' ),
				'post_name'    => 'cookie-policy',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:katla/policy {"format":"cookie"} /-->',
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		update_option( self::PAGE_OPTION, (int) $page_id, false );

		return (int) $page_id;
	}

	/**
	 * URL of the generated cookie policy page, if one exists.
	 *
	 * @return string
	 */
	public static function cookie_policy_url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );

		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		return (string) get_permalink( $page_id );
	}
}
