<?php
/**
 * Product matching against ResellingKori.
 *
 *   Option 1 - exact SKU match (most reliable)
 *   Option 2 - suggestions found by searching ResellingKori with keywords
 *              taken from the product name (never auto-confirmed)
 *   Option 3 - manual: paste a ResellingKori product ID or SKU (handled in admin)
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Matcher {

	/** Below this name similarity an exact SKU match is flagged for review during bulk linking. */
	const SKU_MIN_SCORE = 0.15;

	/** Words that carry no identifying value in a product title. */
	private static $stop = array(
		'a', 'an', 'the', 'and', 'or', 'for', 'with', 'of', 'in', 'on', 'to', 'by',
		'new', 'pcs', 'pc', 'piece', 'pieces', 'set', 'free', 'best', 'offer',
		'original', 'official', 'genuine', 'authentic', 'bd', 'bangladesh', 'sale',
		'price', 'combo', 'buy', 'hot', 'top', 'high', 'quality', 'premium', 'latest',
	);

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}

	/**
	 * Split a title into lower-case tokens. Hyphens and dots inside a token are kept
	 * so model codes such as "SH-309" or "v5.3" survive. Works with Bangla (combining
	 * marks are kept inside words).
	 *
	 * @param string $text      Product title.
	 * @param bool   $drop_stop Remove stop words.
	 * @return string[]
	 */
	public static function tokens( $text, $drop_stop = true ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = self::lower( $text );
		$raw  = preg_split( '/[^\p{L}\p{N}\p{M}\-\.]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$out  = array();
		$seen = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $tok ) {
			$tok = trim( $tok, '-.' );
			if ( self::len( $tok ) < 2 ) {
				continue;
			}
			if ( $drop_stop && in_array( $tok, self::$stop, true ) ) {
				continue;
			}
			if ( isset( $seen[ $tok ] ) ) {
				continue;
			}
			$seen[ $tok ] = true;
			$out[]        = (string) $tok;
		}
		return $out;
	}

	/**
	 * A "model-like" token mixes letters and digits (y526, 30w, 5000mah, sh-309).
	 * These identify a product far better than generic words.
	 *
	 * @param string $tok Token.
	 * @return bool
	 */
	public static function is_model( $tok ) {
		$tok = (string) $tok;
		return 1 === preg_match( '/\p{L}/u', $tok ) && 1 === preg_match( '/\p{N}/u', $tok );
	}

	/** Comparison key: drop hyphens/dots and a trailing plural "s". */
	private static function norm( $tok ) {
		$tok = str_replace( array( '-', '.' ), '', (string) $tok );
		if ( 1 === preg_match( '/^[a-z]{4,}s$/', $tok ) && 'ss' !== substr( $tok, -2 ) ) {
			$tok = substr( $tok, 0, -1 );
		}
		return $tok;
	}

	/**
	 * @param string $name Title.
	 * @return array normalised token => weight (model-like tokens weigh 3, others 1).
	 */
	private static function weights( $name ) {
		$w = array();
		foreach ( self::tokens( $name ) as $tok ) {
			$key = self::norm( $tok );
			if ( '' === $key ) {
				continue;
			}
			$w[ $key ] = self::is_model( $tok ) ? 3.0 : 1.0;
		}
		return $w;
	}

	/**
	 * Keywords used to search ResellingKori. Keeps model-like tokens first, then the
	 * earliest remaining words, up to $max.
	 *
	 * @param string $name Product title.
	 * @param int    $max  Maximum keywords.
	 * @return string[]
	 */
	public static function keywords( $name, $max = 6 ) {
		$tokens = self::tokens( $name );
		if ( count( $tokens ) <= $max ) {
			return $tokens;
		}
		$keep = array();
		foreach ( $tokens as $i => $tok ) {
			if ( self::is_model( $tok ) ) {
				$keep[ $i ] = $tok;
			}
		}
		foreach ( $tokens as $i => $tok ) {
			if ( count( $keep ) >= $max ) {
				break;
			}
			if ( ! isset( $keep[ $i ] ) ) {
				$keep[ $i ] = $tok;
			}
		}
		ksort( $keep );
		return array_values( array_slice( $keep, 0, $max ) );
	}

	/**
	 * Compare the store product title ($a_name) with a ResellingKori title ($b_name).
	 *
	 * score = 0.65 * coverage of the store title + 0.35 * weighted Jaccard.
	 * Guards: a different model code caps the score at 0.30; one-word titles cap at 0.50.
	 *
	 * @param string $a_name Store product title.
	 * @param string $b_name ResellingKori product title.
	 * @return array { score, matched[], missing[], extra[] }
	 */
	public static function compare( $a_name, $b_name ) {
		$res = array(
			'score'   => 0.0,
			'matched' => array(),
			'missing' => array(),
			'extra'   => array(),
		);
		$a   = self::weights( $a_name );
		$b   = self::weights( $b_name );
		if ( ! $a || ! $b ) {
			return $res;
		}

		$inter = 0.0;
		foreach ( $a as $key => $w ) {
			if ( isset( $b[ $key ] ) ) {
				$inter            += $w;
				$res['matched'][] = (string) $key;
			} else {
				$res['missing'][] = (string) $key;
			}
		}
		foreach ( $b as $key => $w ) {
			if ( ! isset( $a[ $key ] ) ) {
				$res['extra'][] = (string) $key;
			}
		}

		$wa       = array_sum( $a );
		$wb       = array_sum( $b );
		$union    = $wa + $wb - $inter;
		$coverage = $wa > 0 ? $inter / $wa : 0.0;
		$jaccard  = $union > 0 ? $inter / $union : 0.0;
		$score    = 0.65 * $coverage + 0.35 * $jaccard;

		// Model-code guard: if the store title has model codes and none appear on the RK side, it is a different product.
		$models = array();
		foreach ( $a as $key => $w ) {
			if ( $w >= 3.0 ) {
				$models[] = $key;
			}
		}
		if ( $models ) {
			$hit = false;
			foreach ( $models as $key ) {
				if ( isset( $b[ $key ] ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				$score = min( $score, 0.3 );
			}
		}
		if ( count( $a ) < 2 ) {
			$score = min( $score, 0.5 );
		}

		$res['score'] = round( $score, 3 );
		return $res;
	}

	public static function confidence( $score ) {
		if ( $score >= 0.6 ) {
			return 'high';
		}
		if ( $score >= 0.35 ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Option 2: search ResellingKori with product-name keywords and rank the results.
	 *
	 * @param string $name     Store product title (parent title for variations).
	 * @param string $keywords Optional keywords typed by the admin; auto-extracted when blank.
	 * @return array|WP_Error { keywords: string, items: array }
	 */
	public static function suggest( $name, $keywords = '' ) {
		$keywords = trim( (string) preg_replace( '/\s+/', ' ', (string) $keywords ) );
		if ( '' === $keywords ) {
			$keywords = implode( ' ', self::keywords( $name ) );
		}
		if ( '' === $keywords ) {
			return array(
				'keywords' => '',
				'items'    => array(),
			);
		}

		$res = RKC_API_Client::search_products( $keywords, 20 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$items = ( isset( $res['items'] ) && is_array( $res['items'] ) ) ? $res['items'] : array();
		foreach ( $items as $i => $item ) {
			$cmp                      = self::compare( $name, isset( $item['name'] ) ? $item['name'] : '' );
			$items[ $i ]['score']      = $cmp['score'];
			$items[ $i ]['confidence'] = self::confidence( $cmp['score'] );
			$items[ $i ]['diff']       = array(
				'missing' => $cmp['missing'],
				'extra'   => array_slice( $cmp['extra'], 0, 6 ),
			);
		}
		usort(
			$items,
			function ( $x, $y ) {
				return $y['score'] <=> $x['score'];
			}
		);

		return array(
			'keywords' => $keywords,
			'items'    => array_slice( $items, 0, 10 ),
		);
	}

	/**
	 * Option 1: exact SKU lookup for one product.
	 *
	 * @param WC_Product $product Store product or variation.
	 * @return array|WP_Error { state: no_sku|none|exact, item?, review? }
	 */
	public static function sku_option( WC_Product $product ) {
		$sku = trim( (string) $product->get_sku( 'edit' ) );
		if ( '' === $sku ) {
			return array( 'state' => 'no_sku' );
		}
		if ( false !== strpos( $sku, ',' ) ) {
			return array(
				'state' => 'none',
				'sku'   => $sku,
			);
		}

		$res = RKC_API_Client::products_by_sku( array( $sku ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$item = ( isset( $res['matches'][ $sku ] ) && is_array( $res['matches'][ $sku ] ) ) ? $res['matches'][ $sku ] : null;
		if ( ! $item ) {
			return array(
				'state' => 'none',
				'sku'   => $sku,
			);
		}

		$cmp                = self::compare( RKC_Mapping::base_name( $product ), isset( $item['name'] ) ? $item['name'] : '' );
		$item['score']      = $cmp['score'];
		$item['confidence'] = self::confidence( $cmp['score'] );
		$item['diff']       = array(
			'missing' => $cmp['missing'],
			'extra'   => array_slice( $cmp['extra'], 0, 6 ),
		);

		return array(
			'state'  => 'exact',
			'item'   => $item,
			'review' => ( 'yes' === RKC_Settings::get( 'sku_safety_check' ) && $cmp['score'] < self::SKU_MIN_SCORE ),
		);
	}

	/**
	 * Bulk Option 1: link every product whose SKU exactly matches a ResellingKori SKU.
	 *
	 * @param int[] $woo_ids Store product / variation IDs.
	 * @return array|WP_Error { linked, review, not_found }
	 */
	public static function bulk_sku_link( array $woo_ids ) {
		$result = array(
			'linked'    => 0,
			'review'    => 0,
			'not_found' => 0,
		);

		$wanted = array();
		foreach ( $woo_ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$sku = trim( (string) $p->get_sku( 'edit' ) );
			if ( '' === $sku || false !== strpos( $sku, ',' ) ) {
				continue;
			}
			$wanted[ (int) $id ] = array( $p, $sku );
		}
		if ( ! $wanted ) {
			return $result;
		}

		$skus = array();
		foreach ( $wanted as $pair ) {
			$skus[] = $pair[1];
		}
		$res = RKC_API_Client::products_by_sku( $skus );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$matches = ( isset( $res['matches'] ) && is_array( $res['matches'] ) ) ? $res['matches'] : array();
		$safety  = ( 'yes' === RKC_Settings::get( 'sku_safety_check' ) );

		foreach ( $wanted as $id => $pair ) {
			$p   = $pair[0];
			$sku = $pair[1];
			if ( empty( $matches[ $sku ] ) || ! is_array( $matches[ $sku ] ) ) {
				++$result['not_found'];
				continue;
			}
			$item = $matches[ $sku ];
			$vid  = isset( $item['matched_variation_id'] ) ? (int) $item['matched_variation_id'] : 0;

			// SKU belongs to a variable parent: the exact variation is unknown, so a human must choose.
			if ( isset( $item['type'] ) && 'variable' === $item['type'] && ! $vid ) {
				++$result['review'];
				continue;
			}

			$cmp = self::compare( RKC_Mapping::base_name( $p ), isset( $item['name'] ) ? $item['name'] : '' );
			if ( $safety && $cmp['score'] < self::SKU_MIN_SCORE ) {
				++$result['review'];
				continue;
			}

			RKC_Mapping::save( $id, $item, $vid, 'sku' );
			++$result['linked'];
		}

		return $result;
	}
}
