<?php
/**
 * Plugin Name: WP Consent API status (Katla demo)
 * Description: Demo only. The [wp_consent_status] shortcode shows what the WP Consent API reports, live.
 *
 * Installed as a must-use plugin by the Playground blueprint next to this file. Not part of
 * Katla Cookie Consent.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

add_shortcode(
	'wp_consent_status',
	static function () {
		if ( ! function_exists( 'wp_has_consent' ) ) {
			return '<p>' . esc_html__( 'The WP Consent API plugin is not active.', 'katla-consent' ) . '</p>';
		}

		$rows = '';
		foreach ( array( 'functional', 'preferences', 'statistics', 'statistics-anonymous', 'marketing' ) as $category ) {
			$rows .= sprintf(
				'<tr><td><code>%1$s</code></td><td data-wpc-browser="%1$s">-</td><td>%2$s</td></tr>',
				esc_html( $category ),
				wp_has_consent( $category ) ? 'allow' : 'deny'
			);
		}

		return '<table class="wpc-status">'
			. '<thead><tr><th>Category</th><th>In the browser, live</th><th>On the server, at page load</th></tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '<tfoot><tr><td>Consent type</td><td id="wpc-type">-</td><td>' . esc_html( wp_get_consent_type() ) . '</td></tr></tfoot>'
			. '</table>'
			. '<script>(function () {'
			. 'function render() {'
			. 'if (typeof window.wp_has_consent !== "function") return;'
			. 'document.getElementById("wpc-type").textContent = window.wp_consent_type || window.wp_fallback_consent_type || "-";'
			. 'Array.prototype.forEach.call(document.querySelectorAll("[data-wpc-browser]"), function (cell) {'
			. 'cell.textContent = window.wp_has_consent(cell.getAttribute("data-wpc-browser")) ? "allow" : "deny";'
			. '});'
			. '}'
			. 'document.addEventListener("wp_listen_for_consent_change", render);'
			. 'document.addEventListener("wp_consent_type_defined", render);'
			. 'document.addEventListener("DOMContentLoaded", render);'
			. '})();</script>';
	}
);
