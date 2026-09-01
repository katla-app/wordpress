<?php
/**
 * Plugin Name:       Katla Cookie Consent
 * Plugin URI:        https://katla.app
 * Description:       GDPR, CCPA/CPRA and DMA cookie compliance for WordPress and WooCommerce. Loads the hosted Katla consent widget, supports fully headless/custom banners, blocks non-consented scripts and embeds, and renders auto-generated cookie &amp; privacy policies.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Katla
 * Author URI:        https://katla.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       katla-consent
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.6
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

define( 'KATLA_VERSION', '1.0.0' );
define( 'KATLA_PLUGIN_FILE', __FILE__ );
define( 'KATLA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KATLA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KATLA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once KATLA_PLUGIN_DIR . 'includes/functions.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-settings.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-client.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-markdown.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-categories.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-frontend.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-script-blocker.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-shortcodes.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-blocks.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-privacy.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-woocommerce.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-admin.php';
require_once KATLA_PLUGIN_DIR . 'includes/class-katla-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once KATLA_PLUGIN_DIR . 'includes/class-katla-cli.php';
}

/**
 * Main plugin instance.
 *
 * @return Katla_Plugin
 */
function katla(): Katla_Plugin {
	return Katla_Plugin::instance();
}

katla();

register_activation_hook( __FILE__, array( 'Katla_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Katla_Plugin', 'deactivate' ) );
