<?php
/**
 * WP-CLI commands.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage Katla cookie consent from the command line.
 */
class Katla_CLI {

	/**
	 * Show the current configuration and connection status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp katla status
	 *
	 * @return void
	 */
	public function status(): void {
		$rows = array(
			array(
				'setting' => 'site_id',
				'value'   => (string) Katla_Settings::get( 'site_id' ),
			),
			array(
				'setting' => 'enabled',
				'value'   => Katla_Settings::get( 'enabled' ) ? 'yes' : 'no',
			),
			array(
				'setting' => 'mode',
				'value'   => (string) Katla_Settings::get( 'mode' ),
			),
			array(
				'setting' => 'banner',
				'value'   => 'headless' === Katla_Settings::get( 'mode' ) ? (string) Katla_Settings::get( 'headless_banner' ) : 'hosted widget',
			),
			array(
				'setting' => 'locale',
				'value'   => Katla_Categories::resolve_locale() ? Katla_Categories::resolve_locale() : 'browser-detected',
			),
			array(
				'setting' => 'script',
				'value'   => Katla_Settings::is_configured() ? Katla_Client::script_url() : '—',
			),
			array(
				'setting' => 'blocked scripts',
				'value'   => (string) count( Katla_Script_Blocker::handle_map() ) . ' handles, ' . count( Katla_Script_Blocker::pattern_map() ) . ' URL patterns',
			),
			array(
				'setting' => 'woocommerce',
				'value'   => Katla_Plugin::has_woocommerce() ? 'active' : 'not installed',
			),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'setting', 'value' ) );

		if ( ! Katla_Settings::is_configured() ) {
			WP_CLI::warning( 'No Site ID configured. Run: wp katla site-id <uuid>' );
		}
	}

	/**
	 * Get or set the Katla Site ID.
	 *
	 * ## OPTIONS
	 *
	 * [<uuid>]
	 * : The site UUID from your Katla dashboard. Omit to print the current value.
	 *
	 * ## EXAMPLES
	 *
	 *     wp katla site-id
	 *     wp katla site-id 3f6c1b2a-0000-4a3b-8c1d-9e2f0a1b2c3d
	 *
	 * @subcommand site-id
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return void
	 */
	public function site_id( array $args ): void {
		if ( ! $args ) {
			WP_CLI::line( (string) Katla_Settings::get( 'site_id' ) );

			return;
		}

		if ( ! Katla_Settings::is_uuid( $args[0] ) ) {
			WP_CLI::error( 'That is not a valid UUID.' );
		}

		Katla_Settings::update( array( 'site_id' => strtolower( $args[0] ) ) );
		Katla_Client::flush_cache();

		WP_CLI::success( 'Site ID updated.' );
	}

	/**
	 * Verify that the configured Site ID resolves.
	 *
	 * ## EXAMPLES
	 *
	 *     wp katla verify
	 *
	 * @return void
	 */
	public function verify(): void {
		$manifest = Katla_Client::get_json( '/manifest.json', array(), 'manifest', true );

		if ( is_wp_error( $manifest ) ) {
			WP_CLI::error( $manifest->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Connected. Site ID belongs to %s.', (string) ( $manifest['site'] ?? '?' ) ) );

		foreach ( (array) ( $manifest['resources'] ?? array() ) as $name => $url ) {
			if ( is_string( $url ) ) {
				WP_CLI::line( sprintf( '  %-8s %s', $name, $url ) );
			}
		}
	}

	/**
	 * List the cookies Katla has classified for this site.
	 *
	 * ## OPTIONS
	 *
	 * [--category=<category>]
	 * : Only show one category.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp katla cookies
	 *     wp katla cookies --category=marketing --format=csv
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function cookies( array $args, array $assoc_args ): void {
		unset( $args );

		$data = Katla_Client::get_cookies( Katla_Categories::resolve_locale() );

		if ( is_wp_error( $data ) ) {
			WP_CLI::error( $data->get_error_message() );
		}

		$only = isset( $assoc_args['category'] ) ? sanitize_key( $assoc_args['category'] ) : '';
		$rows = array();

		foreach ( (array) ( $data['cookies'] ?? array() ) as $category => $cookies ) {
			if ( $only && $only !== $category ) {
				continue;
			}

			foreach ( (array) $cookies as $cookie ) {
				$rows[] = array(
					'category' => (string) $category,
					'name'     => (string) ( $cookie['name'] ?? '' ),
					'domain'   => (string) ( $cookie['domain'] ?? '' ),
					'platform' => (string) ( $cookie['platform'] ?? '' ),
					'expires'  => (string) ( $cookie['expires'] ?? '' ),
				);
			}
		}

		if ( ! $rows ) {
			WP_CLI::warning( 'No cookies found. Run a scan in Katla first.' );

			return;
		}

		WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'category', 'name', 'domain', 'platform', 'expires' )
		);
	}

	/**
	 * Print the generated policy.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Which policy to render.
	 * ---
	 * default: full
	 * options:
	 *   - full
	 *   - cookie
	 *   - table
	 * ---
	 *
	 * [--as=<as>]
	 * : Output as markdown or html.
	 * ---
	 * default: markdown
	 * options:
	 *   - markdown
	 *   - html
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp katla policy --format=cookie
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function policy( array $args, array $assoc_args ): void {
		unset( $args );

		$format = (string) ( $assoc_args['format'] ?? 'full' );
		$locale = Katla_Categories::resolve_locale();
		$data   = Katla_Client::get_policy( $format, $locale ? $locale : 'en-GB' );

		if ( is_wp_error( $data ) ) {
			WP_CLI::error( $data->get_error_message() );
		}

		$markdown = (string) ( $data['policy']['markdown'] ?? '' );

		WP_CLI::line( 'html' === ( $assoc_args['as'] ?? 'markdown' ) ? Katla_Markdown::to_html( $markdown ) : $markdown );
	}

	/**
	 * Delete cached Katla responses.
	 *
	 * ## EXAMPLES
	 *
	 *     wp katla flush
	 *
	 * @return void
	 */
	public function flush(): void {
		$deleted = Katla_Client::flush_cache();

		WP_CLI::success( sprintf( 'Cleared %d cached response(s).', $deleted ) );
	}
}

WP_CLI::add_command( 'katla', 'Katla_CLI' );
