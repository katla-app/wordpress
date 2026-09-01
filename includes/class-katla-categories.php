<?php
/**
 * Cookie categories and locale helpers.
 *
 * Mirrors the category model used by the Katla widget so the built-in headless
 * banner can be rendered — and translated — entirely from WordPress.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Category metadata and locale mapping.
 */
class Katla_Categories {

	/**
	 * Locales the Katla widget and policy endpoints ship translations for.
	 */
	const SUPPORTED_LOCALES = array(
		'en-GB',
		'en-US',
		'de-DE',
		'fr-FR',
		'es-ES',
		'it-IT',
		'nl-NL',
		'pt-PT',
		'sv-SE',
		'nb-NO',
		'da-DK',
		'pl-PL',
		'fi-FI',
	);

	/**
	 * Category IDs in display order, matching the widget's CATEGORY_ORDER.
	 *
	 * @return array<int,string>
	 */
	public static function ids(): array {
		return array( 'functional', 'analytics', 'marketing', 'personalization', 'security', 'unknown' );
	}

	/**
	 * Categories a visitor may toggle. `functional` is always on.
	 *
	 * @return array<int,string>
	 */
	public static function manageable(): array {
		return array( 'analytics', 'marketing', 'personalization', 'security', 'unknown' );
	}

	/**
	 * Whether a category is always allowed.
	 *
	 * @param string $category Category ID.
	 * @return bool
	 */
	public static function is_required( string $category ): bool {
		return 'functional' === $category;
	}

	/**
	 * Translated label and description for every category.
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function all(): array {
		$categories = array(
			'functional'      => array(
				'label'       => __( 'Functional', 'katla-consent' ),
				'description' => __( 'These cookies are necessary for the website to function properly.', 'katla-consent' ),
			),
			'analytics'       => array(
				'label'       => __( 'Analytics', 'katla-consent' ),
				'description' => __( 'These cookies help us understand how visitors interact with the website.', 'katla-consent' ),
			),
			'marketing'       => array(
				'label'       => __( 'Marketing', 'katla-consent' ),
				'description' => __( 'These cookies are used to deliver relevant advertisements.', 'katla-consent' ),
			),
			'personalization' => array(
				'label'       => __( 'Personalization', 'katla-consent' ),
				'description' => __( 'These cookies allow the website to remember choices you make.', 'katla-consent' ),
			),
			'security'        => array(
				'label'       => __( 'Security', 'katla-consent' ),
				'description' => __( 'These cookies help keep the website secure.', 'katla-consent' ),
			),
			'unknown'         => array(
				'label'       => __( 'Other', 'katla-consent' ),
				'description' => __( 'These cookies have not been categorized yet.', 'katla-consent' ),
			),
		);

		/**
		 * Filters the cookie category labels and descriptions.
		 *
		 * @param array $categories Category metadata keyed by category ID.
		 */
		return apply_filters( 'katla_categories', $categories );
	}

	/**
	 * Label for a single category.
	 *
	 * @param string $category Category ID.
	 * @return string
	 */
	public static function label( string $category ): string {
		$all = self::all();

		return isset( $all[ $category ]['label'] ) ? (string) $all[ $category ]['label'] : ucfirst( $category );
	}

	/**
	 * Description for a single category.
	 *
	 * @param string $category Category ID.
	 * @return string
	 */
	public static function description( string $category ): string {
		$all = self::all();

		return isset( $all[ $category ]['description'] ) ? (string) $all[ $category ]['description'] : '';
	}

	/**
	 * Resolve the locale to send to Katla, based on the plugin settings.
	 *
	 * Returns an empty string when the widget should auto-detect from the
	 * visitor's browser.
	 *
	 * @return string
	 */
	public static function resolve_locale(): string {
		$mode = Katla_Settings::get( 'locale_mode' );

		if ( 'browser' === $mode ) {
			$locale = '';
		} elseif ( 'custom' === $mode ) {
			$locale = self::normalize_locale( (string) Katla_Settings::get( 'locale' ) );
		} else {
			$locale = self::normalize_locale( determine_locale() );
		}

		/**
		 * Filters the locale passed to the Katla widget and policy endpoints.
		 *
		 * @param string $locale BCP-47 style locale, or '' for browser detection.
		 */
		return (string) apply_filters( 'katla_locale', $locale );
	}

	/**
	 * Map a WordPress locale (`sv_SE`) onto a Katla locale (`sv-SE`).
	 *
	 * Falls back to the closest supported language, then to an empty string so
	 * the widget can auto-detect.
	 *
	 * @param string $locale WordPress or BCP-47 locale.
	 * @return string
	 */
	public static function normalize_locale( string $locale ): string {
		$locale = trim( str_replace( '_', '-', $locale ) );

		if ( '' === $locale ) {
			return '';
		}

		foreach ( self::SUPPORTED_LOCALES as $supported ) {
			if ( 0 === strcasecmp( $supported, $locale ) ) {
				return $supported;
			}
		}

		$language = strtolower( explode( '-', $locale )[0] );

		foreach ( self::SUPPORTED_LOCALES as $supported ) {
			if ( strtolower( explode( '-', $supported )[0] ) === $language ) {
				return $supported;
			}
		}

		return '';
	}
}
