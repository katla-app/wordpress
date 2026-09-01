<?php
/**
 * Minimal Markdown renderer for Katla policy content.
 *
 * @package Katla
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts the Markdown produced by the Katla policy endpoints into safe HTML.
 *
 * Deliberately supports only the subset Katla emits — headings, tables, lists,
 * emphasis and links — so no third-party parser is needed and the output stays
 * predictable and escapable.
 */
class Katla_Markdown {

	/**
	 * Render Markdown as HTML.
	 *
	 * @param string $markdown Markdown source.
	 * @return string
	 */
	public static function to_html( string $markdown ): string {
		$lines    = preg_split( '/\r\n|\r|\n/', $markdown );
		$html     = array();
		$in_table = false;
		$in_list  = false;
		$row      = 0;

		foreach ( (array) $lines as $line ) {
			$line = rtrim( (string) $line );

			// Table rows.
			if ( 0 === strpos( $line, '|' ) ) {
				if ( preg_match( '/^\|[\s\-:|]+\|?$/', $line ) ) {
					continue; // Separator row.
				}

				$cells = self::table_cells( $line );

				if ( ! $in_table ) {
					$in_table = true;
					$row      = 0;
					$html[]   = '<div class="katla-policy__table-wrap"><table class="katla-policy__table">';
					$html[]   = '<thead><tr>';
					foreach ( $cells as $cell ) {
						$html[] = '<th scope="col">' . self::inline( $cell ) . '</th>';
					}
					$html[] = '</tr></thead><tbody>';
					continue;
				}

				++$row;
				$html[] = '<tr>';
				foreach ( $cells as $cell ) {
					$html[] = '<td>' . self::inline( $cell ) . '</td>';
				}
				$html[] = '</tr>';
				continue;
			}

			if ( $in_table ) {
				$in_table = false;
				$html[]   = '</tbody></table></div>';
			}

			// Unordered list items.
			if ( preg_match( '/^[-*]\s+(.*)$/', $line, $matches ) ) {
				if ( ! $in_list ) {
					$in_list = true;
					$html[]  = '<ul class="katla-policy__list">';
				}
				$html[] = '<li>' . self::inline( $matches[1] ) . '</li>';
				continue;
			}

			if ( $in_list ) {
				$in_list = false;
				$html[]  = '</ul>';
			}

			// Headings.
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $matches ) ) {
				$level  = strlen( $matches[1] );
				$text   = self::inline( $matches[2] );
				$html[] = sprintf( '<h%1$d class="katla-policy__heading katla-policy__heading--%1$d">%2$s</h%1$d>', $level, $text );
				continue;
			}

			if ( '' === trim( $line ) ) {
				continue;
			}

			$html[] = '<p>' . self::inline( $line ) . '</p>';
		}

		if ( $in_table ) {
			$html[] = '</tbody></table></div>';
		}
		if ( $in_list ) {
			$html[] = '</ul>';
		}

		return implode( "\n", $html );
	}

	/**
	 * Split a Markdown table row into cells.
	 *
	 * @param string $line Table row, including the leading pipe.
	 * @return array<int,string>
	 */
	private static function table_cells( string $line ): array {
		$line  = trim( $line, "| \t" );
		$cells = explode( '|', $line );

		return array_map( 'trim', $cells );
	}

	/**
	 * Render inline Markdown (escaping first, then adding known-safe tags).
	 *
	 * @param string $text Inline Markdown.
	 * @return string
	 */
	private static function inline( string $text ): string {
		$text = esc_html( $text );

		// Links: [label](https://example.com).
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( array $matches ): string {
				$url = esc_url( html_entity_decode( $matches[2], ENT_QUOTES, 'UTF-8' ) );
				if ( '' === $url ) {
					return $matches[1];
				}

				return '<a href="' . $url . '" rel="noopener">' . $matches[1] . '</a>';
			},
			$text
		);

		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', (string) $text );
		$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', (string) $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', (string) $text );

		return (string) $text;
	}
}
