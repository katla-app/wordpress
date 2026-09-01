<?php
/**
 * Admin settings screen.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Katla settings screen and its maintenance actions.
 */
class Katla_Admin {

	const PAGE   = 'katla-consent';
	const NOTICE = 'katla_admin_notice';

	/**
	 * Field keys rendered on the current screen.
	 *
	 * @var array<int,string>
	 */
	private $rendered = array();

	/**
	 * Hook into WordPress.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'print_notices' ) );
		add_filter( 'plugin_action_links_' . KATLA_PLUGIN_BASENAME, array( $this, 'action_links' ) );

		add_action( 'admin_post_katla_flush_cache', array( $this, 'handle_flush_cache' ) );
		add_action( 'admin_post_katla_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_katla_create_policy_page', array( $this, 'handle_create_policy_page' ) );
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Katla Cookie Consent', 'katla-consent' ),
			__( 'Katla Consent', 'katla-consent' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'katla_consent',
			Katla_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Katla_Settings', 'sanitize' ),
				'default'           => Katla_Settings::defaults(),
			)
		);
	}

	/**
	 * Add a Settings link on the plugins screen.
	 *
	 * @param array<int,string> $links Action links.
	 * @return array<int,string>
	 */
	public function action_links( $links ) {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'katla-consent' )
		);

		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Load admin assets on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'katla-admin', KATLA_PLUGIN_URL . 'assets/css/katla-admin.css', array(), KATLA_VERSION );
		wp_enqueue_script( 'katla-admin', KATLA_PLUGIN_URL . 'assets/js/katla-admin.js', array(), KATLA_VERSION, true );
		wp_add_inline_script(
			'katla-admin',
			'window.katlaAdmin = ' . wp_json_encode(
				array(
					'categories' => array_map(
						static function ( string $id ): array {
							return array(
								'value' => $id,
								'label' => Katla_Categories::label( $id ),
							);
						},
						Katla_Categories::ids()
					),
					'i18n'       => array(
						'remove' => __( 'Remove', 'katla-consent' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Show any queued admin notice.
	 *
	 * @return void
	 */
	public function print_notices(): void {
		$notice = get_transient( self::NOTICE );

		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}

		delete_transient( self::NOTICE );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( (string) ( $notice['type'] ?? 'info' ) ),
			wp_kses_post( (string) ( $notice['message'] ?? '' ) )
		);
	}

	/**
	 * Queue an admin notice for the next page load.
	 *
	 * @param string $message Notice text.
	 * @param string $type    `success`, `error`, `warning` or `info`.
	 * @return void
	 */
	private static function notice( string $message, string $type = 'success' ): void {
		set_transient(
			self::NOTICE,
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
	}

	/**
	 * The tabs available on the settings screen.
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		$tabs = array(
			'general'  => __( 'General', 'katla-consent' ),
			'blocking' => __( 'Blocking', 'katla-consent' ),
			'policies' => __( 'Policies', 'katla-consent' ),
		);

		if ( Katla_Plugin::has_woocommerce() ) {
			$tabs['woocommerce'] = __( 'WooCommerce', 'katla-consent' );
		}

		$tabs['tools'] = __( 'Tools', 'katla-consent' );

		return $tabs;
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';

		$this->rendered = array();

		echo '<div class="wrap katla-admin">';
		echo '<h1>' . esc_html__( 'Katla Cookie Consent', 'katla-consent' ) . '</h1>';

		$this->render_status_banner();

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=' . $slug ) ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( 'tools' === $tab ) {
			$this->render_tools_tab();
			echo '</div>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( 'katla_consent' );

		echo '<table class="form-table" role="presentation"><tbody>';

		switch ( $tab ) {
			case 'blocking':
				$this->render_blocking_tab();
				break;
			case 'policies':
				$this->render_policies_tab();
				break;
			case 'woocommerce':
				$this->render_woocommerce_tab();
				break;
			default:
				$this->render_general_tab();
		}

		echo '</tbody></table>';

		printf(
			'<input type="hidden" name="%s[_fields]" value="%s" />',
			esc_attr( Katla_Settings::OPTION ),
			esc_attr( implode( ',', array_unique( $this->rendered ) ) )
		);

		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * A short summary of whether the plugin is live.
	 *
	 * @return void
	 */
	private function render_status_banner(): void {
		if ( ! Katla_Settings::is_configured() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Add your Katla Site ID below to start collecting consent. You can find it in the Widget section of your site settings at katla.app.', 'katla-consent' )
			);

			return;
		}

		if ( ! Katla_Settings::get( 'enabled' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Katla is configured but currently switched off, so no consent banner is shown to visitors.', 'katla-consent' )
			);

			return;
		}

		$mode = 'headless' === Katla_Settings::get( 'mode' )
			? __( 'headless (cookie guard only)', 'katla-consent' )
			: __( 'hosted widget', 'katla-consent' );

		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: the active mode. */
					__( 'Katla is active in %s mode.', 'katla-consent' ),
					$mode
				)
			)
		);
	}

	// -------------------------------------------------------------------
	// Tabs.
	// -------------------------------------------------------------------

	/**
	 * General tab fields.
	 *
	 * @return void
	 */
	private function render_general_tab(): void {
		$this->text_field(
			'site_id',
			__( 'Site ID', 'katla-consent' ),
			__( 'The UUID of your site in Katla. Find it under Widget in your site settings.', 'katla-consent' ),
			array( 'placeholder' => '00000000-0000-0000-0000-000000000000' )
		);

		$this->text_field(
			'verification_token',
			__( 'Verification token', 'katla-consent' ),
			__( 'Optional. Prints the katla-verification meta tag so you can verify domain ownership without editing your theme.', 'katla-consent' )
		);

		$this->checkbox_field(
			'enabled',
			__( 'Enable on the front end', 'katla-consent' ),
			__( 'Load the consent script for visitors.', 'katla-consent' )
		);

		$this->radio_field(
			'mode',
			__( 'Mode', 'katla-consent' ),
			array(
				'widget'   => __( 'Hosted widget — Katla renders the banner, styled from your dashboard.', 'katla-consent' ),
				'headless' => __( 'Advanced / headless — cookie guard and consent API only, no hosted UI.', 'katla-consent' ),
			)
		);

		$this->radio_field(
			'headless_banner',
			__( 'Headless banner', 'katla-consent' ),
			array(
				'builtin' => __( "Use this plugin's banner — restyle it with CSS custom properties and the katla_translations filter.", 'katla-consent' ),
				'custom'  => __( 'Bring your own banner — the plugin only loads the guard and fires katla:consent events.', 'katla-consent' ),
			),
			__( 'Only applies in headless mode.', 'katla-consent' )
		);

		$this->radio_field(
			'placement',
			__( 'Script placement', 'katla-consent' ),
			array(
				'head'   => __( 'Head — recommended. The cookie guard must run before other scripts can set cookies.', 'katla-consent' ),
				'footer' => __( 'Footer — only for debugging or when a caching plugin requires it.', 'katla-consent' ),
			)
		);

		$this->radio_field(
			'locale_mode',
			__( 'Language', 'katla-consent' ),
			array(
				'site'    => __( 'Follow the WordPress locale', 'katla-consent' ),
				'custom'  => __( 'Use a fixed locale', 'katla-consent' ),
				'browser' => __( "Let Katla detect the visitor's browser language", 'katla-consent' ),
			)
		);

		$this->select_field(
			'locale',
			__( 'Fixed locale', 'katla-consent' ),
			array_combine( Katla_Categories::SUPPORTED_LOCALES, Katla_Categories::SUPPORTED_LOCALES ),
			__( 'Used when Language is set to a fixed locale.', 'katla-consent' )
		);

		$this->select_field(
			'theme',
			__( 'Theme override', 'katla-consent' ),
			array(
				''      => __( 'Use the setting from my Katla dashboard', 'katla-consent' ),
				'light' => __( 'Light', 'katla-consent' ),
				'dark'  => __( 'Dark', 'katla-consent' ),
			)
		);

		$this->checkbox_field(
			'consent_events',
			__( 'Dispatch consent events', 'katla-consent' ),
			__( 'Fire a window katla:consent CustomEvent whenever consent changes, matching the Katla SDK contract.', 'katla-consent' )
		);

		$this->checkbox_field(
			'google_consent_mode',
			__( 'Google Consent Mode v2 bridge', 'katla-consent' ),
			__( 'Push gtag consent default and update calls from this plugin. Leave off if you already enabled Google Consent Mode in your Katla dashboard — the hosted widget does it for you.', 'katla-consent' )
		);

		$this->checkbox_field(
			'debug',
			__( 'Debug logging', 'katla-consent' ),
			__( 'Log guard and consent activity to the browser console.', 'katla-consent' )
		);

		$this->roles_field();
	}

	/**
	 * Blocking tab fields.
	 *
	 * @return void
	 */
	private function render_blocking_tab(): void {
		$this->checkbox_field(
			'protect_essential',
			__( 'Protect essential cookies', 'katla-consent' ),
			__( 'Let strictly necessary WordPress (and WooCommerce) cookies through the cookie guard even before consent, so logins, carts and sessions keep working.', 'katla-consent' )
		);

		$this->textarea_field(
			'extra_allowlist',
			__( 'Additional allowed cookies', 'katla-consent' ),
			__( 'One cookie name per line. A trailing asterisk matches by prefix, for example my_plugin_*.', 'katla-consent' )
		);

		$this->checkbox_field(
			'block_scripts',
			__( 'Block scripts until consent', 'katla-consent' ),
			__( 'Neutralise the scripts below server-side and run them only once their category is allowed.', 'katla-consent' )
		);

		$this->map_field(
			'blocked_handles',
			__( 'Blocked script handles', 'katla-consent' ),
			__( 'Match enqueued scripts by their WordPress handle.', 'katla-consent' ),
			$this->seen_handles()
		);

		$this->map_field(
			'blocked_patterns',
			__( 'Blocked script URLs', 'katla-consent' ),
			__( 'Match any script whose URL contains this text, for example googletagmanager.com. Useful for scripts other plugins print directly.', 'katla-consent' ),
			array( 'googletagmanager.com', 'google-analytics.com', 'connect.facebook.net', 'static.hotjar.com', 'cdn.matomo.cloud', 'snap.licdn.com', 'analytics.tiktok.com' )
		);

		$this->checkbox_field(
			'content_blocker',
			__( 'Block third-party embeds', 'katla-consent' ),
			__( 'Replace iframes from the domains below with a consent placeholder until the visitor allows the chosen category.', 'katla-consent' )
		);

		$this->select_field(
			'content_blocker_category',
			__( 'Embed category', 'katla-consent' ),
			$this->category_options()
		);

		$this->textarea_field(
			'content_blocker_hosts',
			__( 'Blocked embed domains', 'katla-consent' ),
			__( 'One domain fragment per line.', 'katla-consent' )
		);
	}

	/**
	 * Policies tab fields.
	 *
	 * @return void
	 */
	private function render_policies_tab(): void {
		$this->radio_field(
			'policy_render',
			__( 'Rendering', 'katla-consent' ),
			array(
				'server' => __( 'On the server — the policy is part of the page HTML, so search engines can index it. Recommended.', 'katla-consent' ),
				'client' => __( "In the browser — uses Katla's hosted policy script.", 'katla-consent' ),
			)
		);

		$this->number_field(
			'cache_ttl',
			__( 'Cache lifetime', 'katla-consent' ),
			__( 'How long policy and cookie data fetched from Katla is cached, in seconds. Set to 0 to disable caching.', 'katla-consent' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Blocks and shortcodes', 'katla-consent' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( 'Add these anywhere in your content, or use the matching blocks in the Katla category of the block inserter.', 'katla-consent' ) . '</p>';
		echo '<table class="widefat striped katla-shortcodes"><tbody>';

		$examples = array(
			'[katla_policy]'                            => __( 'Full cookie and privacy policy.', 'katla-consent' ),
			'[katla_policy format="cookie"]'            => __( 'Cookie policy only.', 'katla-consent' ),
			'[katla_policy format="table"]'             => __( 'Cookie tables only.', 'katla-consent' ),
			'[katla_cookie_table category="analytics"]' => __( 'Cookies in one category.', 'katla-consent' ),
			'[katla_cookie_settings]'                   => __( 'Button that reopens the preferences dialog.', 'katla-consent' ),
			'[katla_block category="marketing"]…[/katla_block]' => __( 'Hold back the enclosed scripts or iframes until that category is allowed.', 'katla-consent' ),
			'[katla_consent category="analytics"]…[/katla_consent]' => __( 'Show the enclosed content only while that category is allowed.', 'katla-consent' ),
		);

		foreach ( $examples as $code => $description ) {
			printf(
				'<tr><td><code>%s</code></td><td>%s</td></tr>',
				esc_html( $code ),
				esc_html( $description )
			);
		}

		echo '</tbody></table></td></tr>';
	}

	/**
	 * WooCommerce tab fields.
	 *
	 * @return void
	 */
	private function render_woocommerce_tab(): void {
		$this->checkbox_field(
			'woo_protect_cookies',
			__( 'Protect store cookies and scripts', 'katla-consent' ),
			__( 'Allow WooCommerce cart, session and checkout cookies through the guard, and never gate store-critical scripts behind consent.', 'katla-consent' )
		);

		$protected = array_map(
			static function ( string $cookie ): string {
				return '<code>' . esc_html( $cookie ) . '</code>';
			},
			Katla_WooCommerce::essential_cookies()
		);

		echo '<tr><th scope="row">' . esc_html__( 'Protected cookies', 'katla-consent' ) . '</th><td>'
			. implode( ', ', $protected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item escaped above.
		echo '<p class="description">' . esc_html__( 'Order attribution cookies (sbjs_*) are deliberately left out — they are marketing cookies and stay behind consent. Gate the wc-order-attribution script on the Blocking tab to stop them being set at all.', 'katla-consent' ) . '</p>';
		echo '</td></tr>';

		$this->checkbox_field(
			'woo_privacy_text',
			__( 'Checkout privacy notice', 'katla-consent' ),
			__( 'Append a cookie settings link to the privacy text shown at checkout and registration.', 'katla-consent' )
		);

		$this->checkbox_field(
			'woo_account_link',
			__( 'My Account link', 'katla-consent' ),
			__( 'Show a cookie settings link on the My Account dashboard.', 'katla-consent' )
		);
	}

	/**
	 * Tools tab.
	 *
	 * @return void
	 */
	private function render_tools_tab(): void {
		echo '<table class="form-table" role="presentation"><tbody>';

		// Connection.
		echo '<tr><th scope="row">' . esc_html__( 'Connection', 'katla-consent' ) . '</th><td>';
		if ( Katla_Settings::is_configured() ) {
			printf(
				'<p><code>%s</code></p>',
				esc_html( Katla_Client::script_url() )
			);
			$this->action_button( 'katla_test_connection', __( 'Test connection', 'katla-consent' ) );
		} else {
			echo '<p>' . esc_html__( 'Add a Site ID on the General tab first.', 'katla-consent' ) . '</p>';
		}
		echo '</td></tr>';

		// Scanned cookies.
		echo '<tr><th scope="row">' . esc_html__( 'Scanned cookies', 'katla-consent' ) . '</th><td>';
		$this->render_cookie_summary();
		$this->action_button( 'katla_flush_cache', __( 'Clear cached Katla data', 'katla-consent' ) );
		echo '</td></tr>';

		// Cookie policy page.
		echo '<tr><th scope="row">' . esc_html__( 'Cookie policy page', 'katla-consent' ) . '</th><td>';
		$url = Katla_Privacy::cookie_policy_url();
		if ( $url ) {
			printf(
				'<p><a href="%1$s" target="_blank" rel="noopener">%1$s</a></p>',
				esc_url( $url )
			);
		} else {
			echo '<p>' . esc_html__( 'Create a page containing the Katla policy block.', 'katla-consent' ) . '</p>';
		}
		$this->action_button( 'katla_create_policy_page', __( 'Create cookie policy page', 'katla-consent' ) );
		echo '</td></tr>';

		echo '</tbody></table>';

		// Uninstall preference lives in its own small form.
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( 'katla_consent' );
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->checkbox_field(
			'delete_data_on_uninstall',
			__( 'Delete settings on uninstall', 'katla-consent' ),
			__( 'Remove all Katla options and cached data when the plugin is deleted.', 'katla-consent' )
		);
		echo '</tbody></table>';
		printf(
			'<input type="hidden" name="%s[_fields]" value="%s" />',
			esc_attr( Katla_Settings::OPTION ),
			esc_attr( implode( ',', array_unique( $this->rendered ) ) )
		);
		submit_button();
		echo '</form>';
	}

	/**
	 * Summarise the cookies Katla has scanned.
	 *
	 * @return void
	 */
	private function render_cookie_summary(): void {
		if ( ! Katla_Settings::is_configured() ) {
			echo '<p>' . esc_html__( 'Add a Site ID on the General tab first.', 'katla-consent' ) . '</p>';

			return;
		}

		$data = Katla_Client::get_cookies( Katla_Categories::resolve_locale() );

		if ( is_wp_error( $data ) ) {
			printf( '<p class="katla-error">%s</p>', esc_html( $data->get_error_message() ) );

			return;
		}

		$total = 0;
		$rows  = '';

		foreach ( Katla_Categories::ids() as $category ) {
			$count = isset( $data['cookies'][ $category ] ) ? count( (array) $data['cookies'][ $category ] ) : 0;
			if ( ! $count ) {
				continue;
			}
			$total += $count;
			$rows  .= sprintf(
				'<tr><td>%s</td><td>%d</td></tr>',
				esc_html( Katla_Categories::label( $category ) ),
				(int) $count
			);
		}

		if ( ! $total ) {
			echo '<p>' . esc_html__( 'No cookies have been classified for this site yet. Run a scan in Katla.', 'katla-consent' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped katla-summary"><tbody>' . $rows . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
	}

	// -------------------------------------------------------------------
	// Field helpers.
	// -------------------------------------------------------------------

	/**
	 * The `name` attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function name( string $key ): string {
		$this->rendered[] = $key;

		return Katla_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Open a form-table row.
	 *
	 * @param string $key   Setting key, used for the label's `for`.
	 * @param string $label Field label.
	 * @return void
	 */
	private function open_row( string $key, string $label ): void {
		printf(
			'<tr class="katla-row katla-row--%1$s"><th scope="row"><label for="katla-%1$s">%2$s</label></th><td>',
			esc_attr( $key ),
			esc_html( $label )
		);
	}

	/**
	 * Print a field description.
	 *
	 * @param string $description Description text.
	 * @return void
	 */
	private function description( string $description ): void {
		if ( '' !== $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	/**
	 * A single-line text field.
	 *
	 * @param string               $key         Setting key.
	 * @param string               $label       Field label.
	 * @param string               $description Help text.
	 * @param array<string,string> $attrs       Extra input attributes.
	 * @return void
	 */
	private function text_field( string $key, string $label, string $description = '', array $attrs = array() ): void {
		$this->open_row( $key, $label );

		$extra = '';
		foreach ( $attrs as $attr => $value ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $value ) );
		}

		printf(
			'<input type="text" class="regular-text" id="katla-%1$s" name="%2$s" value="%3$s"%4$s />',
			esc_attr( $key ),
			esc_attr( $this->name( $key ) ),
			esc_attr( (string) Katla_Settings::get( $key ) ),
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per attribute above.
		);

		$this->description( $description );
		echo '</td></tr>';
	}

	/**
	 * A number field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function number_field( string $key, string $label, string $description = '' ): void {
		$this->open_row( $key, $label );

		printf(
			'<input type="number" min="0" step="60" class="small-text" id="katla-%1$s" name="%2$s" value="%3$d" />',
			esc_attr( $key ),
			esc_attr( $this->name( $key ) ),
			(int) Katla_Settings::get( $key )
		);

		$this->description( $description );
		echo '</td></tr>';
	}

	/**
	 * A checkbox, with a hidden companion so unchecking is recorded.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function checkbox_field( string $key, string $label, string $description = '' ): void {
		$this->open_row( $key, $label );

		$name = $this->name( $key );

		printf(
			'<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" id="katla-%2$s" name="%1$s" value="1" %3$s /> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $key ),
			checked( (bool) Katla_Settings::get( $key ), true, false ),
			esc_html( $description )
		);

		echo '</td></tr>';
	}

	/**
	 * A radio group.
	 *
	 * @param string               $key         Setting key.
	 * @param string               $label       Field label.
	 * @param array<string,string> $options     Value to label map.
	 * @param string               $description Help text.
	 * @return void
	 */
	private function radio_field( string $key, string $label, array $options, string $description = '' ): void {
		$this->open_row( $key, $label );

		$name    = $this->name( $key );
		$current = (string) Katla_Settings::get( $key );

		echo '<fieldset>';
		foreach ( $options as $value => $option_label ) {
			printf(
				'<label class="katla-radio"><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $name ),
				esc_attr( (string) $value ),
				checked( $current, (string) $value, false ),
				esc_html( $option_label )
			);
		}
		echo '</fieldset>';

		$this->description( $description );
		echo '</td></tr>';
	}

	/**
	 * A select field.
	 *
	 * @param string               $key         Setting key.
	 * @param string               $label       Field label.
	 * @param array<string,string> $options     Value to label map.
	 * @param string               $description Help text.
	 * @return void
	 */
	private function select_field( string $key, string $label, array $options, string $description = '' ): void {
		$this->open_row( $key, $label );

		$current = (string) Katla_Settings::get( $key );

		printf( '<select id="katla-%s" name="%s">', esc_attr( $key ), esc_attr( $this->name( $key ) ) );
		foreach ( $options as $value => $option_label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( $option_label )
			);
		}
		echo '</select>';

		$this->description( $description );
		echo '</td></tr>';
	}

	/**
	 * A textarea storing one value per line.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Field label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function textarea_field( string $key, string $label, string $description = '' ): void {
		$this->open_row( $key, $label );

		printf(
			'<textarea id="katla-%1$s" name="%2$s" rows="6" class="large-text code">%3$s</textarea>',
			esc_attr( $key ),
			esc_attr( $this->name( $key ) ),
			esc_textarea( implode( "\n", (array) Katla_Settings::get( $key ) ) )
		);

		$this->description( $description );
		echo '</td></tr>';
	}

	/**
	 * A repeater mapping arbitrary keys onto cookie categories.
	 *
	 * @param string            $key         Setting key.
	 * @param string            $label       Field label.
	 * @param string            $description Help text.
	 * @param array<int,string> $suggestions Autocomplete suggestions.
	 * @return void
	 */
	private function map_field( string $key, string $label, string $description = '', array $suggestions = array() ): void {
		$this->open_row( $key, $label );

		$name       = $this->name( $key );
		$rows       = (array) Katla_Settings::get( $key );
		$categories = $this->category_options();
		$list_id    = 'katla-list-' . $key;

		printf( '<div class="katla-map" data-katla-map="%s" data-katla-list="%s">', esc_attr( $name ), esc_attr( $list_id ) );

		$index = 0;
		foreach ( $rows as $row_key => $category ) {
			$this->map_row( $name, $index, (string) $row_key, (string) $category, $categories, $list_id );
			++$index;
		}

		echo '<div class="katla-map__rows-end"></div>';
		printf(
			'<p><button type="button" class="button katla-map__add">%s</button></p>',
			esc_html__( 'Add rule', 'katla-consent' )
		);
		echo '</div>';

		if ( $suggestions ) {
			printf( '<datalist id="%s">', esc_attr( $list_id ) );
			foreach ( array_unique( $suggestions ) as $suggestion ) {
				printf( '<option value="%s"></option>', esc_attr( (string) $suggestion ) );
			}
			echo '</datalist>';
		}

		$this->description( $description );
		echo '</td></tr>';
	}

	/**
	 * One row of a map repeater.
	 *
	 * @param string               $name       Base field name.
	 * @param int                  $index      Row index.
	 * @param string               $row_key    Row key.
	 * @param string               $category   Selected category.
	 * @param array<string,string> $categories Category options.
	 * @param string               $list_id    Datalist ID.
	 * @return void
	 */
	private function map_row( string $name, int $index, string $row_key, string $category, array $categories, string $list_id ): void {
		echo '<div class="katla-map__row">';

		printf(
			'<input type="text" class="regular-text" list="%1$s" name="%2$s[%3$d][key]" value="%4$s" />',
			esc_attr( $list_id ),
			esc_attr( $name ),
			(int) $index,
			esc_attr( $row_key )
		);

		printf( '<select name="%s[%d][category]">', esc_attr( $name ), (int) $index );
		foreach ( $categories as $value => $option_label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $category, (string) $value, false ),
				esc_html( $option_label )
			);
		}
		echo '</select>';

		printf(
			'<button type="button" class="button-link katla-map__remove" aria-label="%1$s">%1$s</button>',
			esc_attr__( 'Remove', 'katla-consent' )
		);

		echo '</div>';
	}

	/**
	 * A checkbox list of the site's roles.
	 *
	 * @return void
	 */
	private function roles_field(): void {
		$this->open_row( 'disable_for_roles', __( 'Hide from roles', 'katla-consent' ) );

		$name     = $this->name( 'disable_for_roles' );
		$selected = (array) Katla_Settings::get( 'disable_for_roles' );
		$roles    = wp_roles()->get_names();

		echo '<fieldset>';
		printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( $name ) );
		foreach ( $roles as $role => $role_label ) {
			printf(
				'<label class="katla-radio"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $name ),
				esc_attr( (string) $role ),
				checked( in_array( (string) $role, $selected, true ), true, false ),
				esc_html( translate_user_role( $role_label ) )
			);
		}
		echo '</fieldset>';

		$this->description( __( 'Skip the consent script for logged-in users with these roles. Handy while developing — but remember they will then be tracked without a consent record.', 'katla-consent' ) );
		echo '</td></tr>';
	}

	/**
	 * Category select options.
	 *
	 * @return array<string,string>
	 */
	private function category_options(): array {
		$options = array();

		foreach ( Katla_Categories::ids() as $id ) {
			$options[ $id ] = Katla_Categories::label( $id );
		}

		return $options;
	}

	/**
	 * Script handles seen on the front end, for autocomplete.
	 *
	 * @return array<int,string>
	 */
	private function seen_handles(): array {
		$seen = get_option( Katla_Script_Blocker::SEEN_OPTION, array() );

		if ( ! is_array( $seen ) ) {
			return array();
		}

		$handles = array_keys( $seen );
		sort( $handles );

		return $handles;
	}

	/**
	 * A nonce-protected action button posting to admin-post.php.
	 *
	 * @param string $action Action name.
	 * @param string $label  Button label.
	 * @return void
	 */
	private function action_button( string $action, string $label ): void {
		printf(
			'<form method="post" action="%1$s" class="katla-action">%2$s<input type="hidden" name="action" value="%3$s" /><button type="submit" class="button">%4$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( $action, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated by core.
			esc_attr( $action ),
			esc_html( $label )
		);
	}

	// -------------------------------------------------------------------
	// Actions.
	// -------------------------------------------------------------------

	/**
	 * Guard an admin-post action.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'katla-consent' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the Tools tab.
	 *
	 * @return void
	 */
	private function redirect_back(): void {
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=tools' ) );
		exit;
	}

	/**
	 * Clear cached Katla responses.
	 *
	 * @return void
	 */
	public function handle_flush_cache(): void {
		$this->authorize( 'katla_flush_cache' );

		$deleted = Katla_Client::flush_cache();

		self::notice(
			sprintf(
				/* translators: %d: number of cache entries. */
				_n( 'Cleared %d cached response from Katla.', 'Cleared %d cached responses from Katla.', $deleted, 'katla-consent' ),
				$deleted
			)
		);

		$this->redirect_back();
	}

	/**
	 * Fetch the manifest to confirm the Site ID works.
	 *
	 * @return void
	 */
	public function handle_test_connection(): void {
		$this->authorize( 'katla_test_connection' );

		$manifest = Katla_Client::get_json( '/manifest.json', array(), 'manifest', true );

		if ( is_wp_error( $manifest ) ) {
			self::notice(
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not reach Katla: %s', 'katla-consent' ),
					esc_html( $manifest->get_error_message() )
				),
				'error'
			);
		} else {
			self::notice(
				sprintf(
					/* translators: %s: verified domain. */
					__( 'Connected to Katla. This Site ID belongs to %s.', 'katla-consent' ),
					'<strong>' . esc_html( (string) ( $manifest['site'] ?? '' ) ) . '</strong>'
				)
			);
		}

		$this->redirect_back();
	}

	/**
	 * Create the cookie policy page.
	 *
	 * @return void
	 */
	public function handle_create_policy_page(): void {
		$this->authorize( 'katla_create_policy_page' );

		$page_id = Katla_Privacy::create_cookie_policy_page();

		if ( is_wp_error( $page_id ) ) {
			self::notice( esc_html( $page_id->get_error_message() ), 'error' );
		} else {
			self::notice(
				sprintf(
					/* translators: %s: link to the created page. */
					__( 'Cookie policy page ready: %s', 'katla-consent' ),
					'<a href="' . esc_url( (string) get_permalink( $page_id ) ) . '">' . esc_html( (string) get_the_title( $page_id ) ) . '</a>'
				)
			);
		}

		$this->redirect_back();
	}
}
