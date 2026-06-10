<?php
/**
 * Markdown -> WordPress content conversion.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Converts StealthGPT Markdown output into editor-ready content.
 */
class StealthGPT_Markdown {

	/**
	 * Convert Markdown into sanitized block-editor content.
	 *
	 * Returns HTML which the block editor treats as a Classic block in v1.
	 * Output is always sanitized with wp_kses_post.
	 *
	 * @param string $markdown Markdown source.
	 * @return string Sanitized HTML.
	 */
	public static function to_blocks( $markdown ) {
		$markdown = (string) $markdown;
		if ( '' === trim( $markdown ) ) {
			return '';
		}

		$html = self::convert( $markdown );

		return wp_kses_post( $html );
	}

	/**
	 * Convert Markdown to HTML, preferring CommonMark when available.
	 *
	 * @param string $markdown Markdown source.
	 * @return string HTML.
	 */
	private static function convert( $markdown ) {
		if ( class_exists( GithubFlavoredMarkdownConverter::class ) ) {
			try {
				$converter = new GithubFlavoredMarkdownConverter(
					array(
						'html_input'         => 'strip',
						'allow_unsafe_links' => false,
					)
				);
				return (string) $converter->convert( $markdown );
			} catch ( \Throwable $e ) {
				unset( $e ); // Fall through to the minimal converter below.
			}
		}

		return self::fallback_convert( $markdown );
	}

	/**
	 * Minimal Markdown fallback used only if CommonMark is unavailable.
	 *
	 * Handles headings, bold/italic, links, and paragraphs. Anything else
	 * is treated as plain text and escaped.
	 *
	 * @param string $markdown Markdown source.
	 * @return string HTML.
	 */
	private static function fallback_convert( $markdown ) {
		$blocks = preg_split( '/\n{2,}/', str_replace( array( "\r\n", "\r" ), "\n", trim( $markdown ) ) );
		$out    = array();

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $block, $m ) ) {
				$level = strlen( $m[1] );
				$out[] = sprintf( '<h%1$d>%2$s</h%1$d>', $level, self::inline( $m[2] ) );
				continue;
			}

			$out[] = '<p>' . self::inline( $block ) . '</p>';
		}

		return implode( "\n\n", $out );
	}

	/**
	 * Apply minimal inline Markdown formatting to escaped text.
	 *
	 * @param string $text Raw text.
	 * @return string HTML.
	 */
	private static function inline( $text ) {
		$text = esc_html( trim( $text ) );
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text );
		$text = preg_replace_callback(
			'/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '">' . $m[1] . '</a>';
			},
			$text
		);
		return $text;
	}
}
