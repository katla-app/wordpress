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

		$signals = '';
		foreach ( array( 'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization' ) as $signal ) {
			$signals .= sprintf( '<tr><td><code>%1$s</code></td><td data-wpc-gcm="%1$s">-</td></tr>', esc_html( $signal ) );
		}

		return '<table class="wpc-status">'
			. '<thead><tr><th>WP Consent API category</th><th>In the browser, live</th><th>On the server, at page load</th></tr></thead>'
			. '<tbody>' . $rows . '</tbody>'
			. '<tfoot><tr><td>Consent type</td><td id="wpc-type">-</td><td>' . esc_html( wp_get_consent_type() ) . '</td></tr></tfoot>'
			. '</table>'
			. '<table class="wpc-gcm">'
			. '<thead><tr><th>Google Consent Mode signal</th><th>Last set on this page</th></tr></thead>'
			. '<tbody>' . $signals . '</tbody>'
			. '</table>'
			. '<script>(function () {'
			// Consent commands carry no event, so the Consent Mode state is folded from the
			// dataLayer on a timer rather than on an event.
			. 'function consentMode() {'
			. 'var state = {};'
			. '(window.dataLayer || []).forEach(function (entry) {'
			. 'if (entry && entry[0] === "consent" && (entry[1] === "default" || entry[1] === "update") && entry[2]) {'
			. 'for (var key in entry[2]) { if (key !== "region" && key !== "wait_for_update") state[key] = entry[2][key]; }'
			. '}'
			. '});'
			. 'return state;'
			. '}'
			. 'function render() {'
			. 'var state = consentMode();'
			. 'Array.prototype.forEach.call(document.querySelectorAll("[data-wpc-gcm]"), function (cell) {'
			. 'cell.textContent = state[cell.getAttribute("data-wpc-gcm")] || "-";'
			. '});'
			. 'if (typeof window.wp_has_consent !== "function") return;'
			. 'document.getElementById("wpc-type").textContent = window.wp_consent_type || window.wp_fallback_consent_type || "-";'
			. 'Array.prototype.forEach.call(document.querySelectorAll("[data-wpc-browser]"), function (cell) {'
			. 'cell.textContent = window.wp_has_consent(cell.getAttribute("data-wpc-browser")) ? "allow" : "deny";'
			. '});'
			. '}'
			. 'document.addEventListener("wp_listen_for_consent_change", render);'
			. 'document.addEventListener("wp_consent_type_defined", render);'
			. 'document.addEventListener("DOMContentLoaded", render);'
			. 'window.setInterval(render, 500);'
			. '})();</script>';
	}
);
