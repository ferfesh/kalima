<?php
/**
 * HTML / plain-text tokenizer.
 *
 * The whole point of this class is that the AI model never sees markup. We cut
 * a string into "units" (the pieces a human actually reads), hand only those to
 * the model, and stitch the answers back into a template we kept byte-for-byte.
 *
 * That means tags, attributes, entities, shortcodes, URLs and inline
 * placeholders are structurally incapable of being damaged by a translation.
 *
 * @package KALIMA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Splits markup into translatable units and rebuilds it afterwards.
 */
class KALIMA_Tokenizer {

	/** Attributes whose quoted values are worth translating. */
	const ATTRS = 'title|alt|aria-label|placeholder|data-title|data-tooltip|aria-description';

	/** Elements whose contents are code, not prose. */
	const OPAQUE = array( 'script', 'style', 'noscript', 'textarea', 'code', 'pre' );

	/**
	 * Fragments inside a text node that must reach the model untouched.
	 *
	 * Group 1 is an HTML entity (`&amp;`, `&#8217;`), group 2 a WordPress
	 * shortcode (`[gallery id="12"]`). Both are structural, not prose.
	 */
	const PROTECTED = '/(&(?:[a-zA-Z][a-zA-Z0-9]{1,31}|#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6});|\[\/?[a-zA-Z][a-zA-Z0-9_-]*(?:[^\]]*)?\])/';

	/**
	 * Split a string into a template plus the units to translate.
	 *
	 * @param string $html Raw HTML or plain text.
	 * @return array{template:string,units:array<int,array{key:string,text:string,kind:string}>}
	 */
	public static function tokenize( $html ) {
		$html = (string) $html;

		$units  = array();
		$prefix = self::safe_prefix( $html );

		// 1. Attribute values inside tags.
		$html = self::tokenize_attributes( $html, $prefix, $units );

		// 2. Text nodes outside tags.
		$html = self::tokenize_text( $html, $prefix, $units );

		return array(
			'template' => $html,
			'units'    => $units,
		);
	}

	/**
	 * Put translated units back into the template.
	 *
	 * @param string $template   Template produced by tokenize().
	 * @param array  $units      Original units (used for keys).
	 * @param array  $translated Translated strings, same order as $units.
	 * @return string
	 */
	public static function restore( $template, array $units, array $translated ) {
		if ( count( $units ) !== count( $translated ) ) {
			return $template;
		}

		$map = array();

		foreach ( $units as $i => $unit ) {
			$value = $translated[ $i ];
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				$value = $unit['text'];
			}

			/*
			 * Leading/trailing whitespace was deliberately left in the template
			 * around the marker, so the replacement is the bare translated text.
			 * Adding it here as well would double every space.
			 */
			$map[ $unit['key'] ] = $value;
		}

		/*
		 * strtr() with an array replaces in a single left-to-right pass and never
		 * rescans its own output, so a translation that happened to contain a
		 * marker cannot cascade.
		 */
		return strtr( (string) $template, $map );
	}

	/**
	 * Plain-text helper: tokenize, keeping only the unit list.
	 *
	 * @param string $text Text.
	 * @return array{template:string,units:array}
	 */
	public static function tokenize_plain( $text ) {
		return self::tokenize( $text );
	}

	/**
	 * Reassemble a list of translated plain-text strings.
	 *
	 * Used for fields that are never HTML (titles, slugs, menu labels).
	 *
	 * @param array $units      Units.
	 * @param array $translated Translations.
	 * @return string Concatenated result.
	 */
	public static function join_units( array $units, array $translated ) {
		$out = '';

		foreach ( $units as $i => $unit ) {
			$value = isset( $translated[ $i ] ) ? $translated[ $i ] : $unit['text'];
			$out  .= ( $unit['lead'] ?? '' ) . $value . ( $unit['trail'] ?? '' );
		}

		return $out;
	}

	/**
	 * Pick a marker prefix that cannot collide with the content.
	 *
	 * @param string $html Content being tokenized.
	 * @return string
	 */
	private static function safe_prefix( $html ) {
		$prefix = '@@KALIMA_';
		$guard  = 0;

		while ( false !== stripos( $html, $prefix ) && $guard < 25 ) {
			$prefix = '_' . $prefix;
			$guard++;
		}

		return $prefix;
	}

	/**
	 * Replace translatable attribute values with markers.
	 *
	 * @param string $html   Markup.
	 * @param string $prefix Marker prefix.
	 * @param array  $units  Collected units, by reference.
	 * @return string
	 */
	private static function tokenize_attributes( $html, $prefix, array &$units ) {
		$pattern = '/\s(' . self::ATTRS . ')\s*=\s*("([^"]*)"|\'([^\']*)\')/i';

		return preg_replace_callback(
			$pattern,
			function ( $m ) use ( $prefix, &$units ) {
				$attr   = $m[1];
				$quote  = substr( $m[2], 0, 1 );
				$value  = substr( $m[2], 1, -1 );

				if ( ! self::is_translatable( $value ) ) {
					return $m[0];
				}

				$key = $prefix . count( $units ) . '@@';

				$units[] = array(
					'key'  => $key,
					'text' => $value,
					'kind' => 'attr:' . $attr,
				);

				return ' ' . $attr . '=' . $quote . $key . $quote;
			},
			$html
		);
	}

	/**
	 * Replace translatable text nodes with markers.
	 *
	 * Contents of <script>, <style>, <noscript>, <textarea>, <code> and <pre>
	 * are skipped by tracking open tags as we walk the string.
	 *
	 * @param string $html   Markup.
	 * @param string $prefix Marker prefix.
	 * @param array  $units  Collected units, by reference.
	 * @return string
	 */
	private static function tokenize_text( $html, $prefix, array &$units ) {
		$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return $html;
		}

		$inside = null;
		$out    = '';

		foreach ( $parts as $i => $part ) {
			// Odd indexes are tags.
			if ( $i % 2 === 1 ) {
				$tag = strtolower( $part );

				if ( $inside ) {
					if ( preg_match( '#</\s*' . $inside . '\s*>#i', $tag ) ) {
						$inside = null;
					}
				} elseif ( preg_match( '#<(' . implode( '|', self::OPAQUE ) . ')[\s>/]#i', $tag, $tm ) ) {
					// Only enter the opaque state on a real opening tag.
					if ( ! preg_match( '#^</#', $tag ) ) {
						$inside = strtolower( $tm[1] );
					}
				}

				$out .= $part;
				continue;
			}

			if ( $inside || '' === $part ) {
				$out .= $part;
				continue;
			}

			$pieces = preg_split( self::PROTECTED, $part, -1, PREG_SPLIT_DELIM_CAPTURE );

			if ( false === $pieces ) {
				$out .= $part;
				continue;
			}

			foreach ( $pieces as $j => $piece ) {
				// Even indexes are text, odd are protected entities / shortcodes.
				if ( $j % 2 === 1 || '' === $piece ) {
					$out .= $piece;
					continue;
				}

				$bare  = $piece;
				$lead  = '';
				$trail = '';

				// Keep surrounding whitespace outside the unit so the model
				// never has to reproduce it exactly.
				if ( preg_match( '/^[\s\x{00A0}]+/u', $bare, $m ) ) {
					$lead = $m[0];
					$bare = substr( $bare, strlen( $lead ) );
				}

				if ( preg_match( '/[\s\x{00A0}]+$/u', $bare, $m ) ) {
					$trail = $m[0];
					$bare  = substr( $bare, 0, strlen( $bare ) - strlen( $trail ) );
				}

				if ( '' === $bare || ! self::is_translatable( $bare ) ) {
					$out .= $piece;
					continue;
				}

				$key = $prefix . count( $units ) . '@@';

				$units[] = array(
					'key'   => $key,
					'text'  => $bare,
					'lead'  => $lead,
					'trail' => $trail,
					'kind'  => 'text',
				);

				$out .= $lead . $key . $trail;
			}
		}

		return $out;
	}

	/**
	 * Does this fragment actually contain prose worth translating?
	 *
	 * Rejects pure whitespace, punctuation, digits and bare placeholders.
	 *
	 * @param string $value Candidate string.
	 * @return bool
	 */
	public static function is_translatable( $value ) {
		$value = (string) $value;

		// Must contain at least one Unicode letter.
		if ( 1 !== preg_match( '/\p{L}/u', $value ) ) {
			return false;
		}

		// Nothing to gain from single characters.
		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( trim( $value ) ) < 2 ) {
				return false;
			}
		} elseif ( strlen( trim( $value ) ) < 2 ) {
			return false;
		}

		return true;
	}

	/**
	 * Cheap check: does this string look like markup at all?
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function looks_like_html( $value ) {
		return 1 === preg_match( '/<[a-zA-Z!\/?][^>]*>/', (string) $value );
	}
}
