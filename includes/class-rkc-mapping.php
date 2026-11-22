<?php
/**
 * Store product -> ResellingKori product mapping.
 *
 * A "unit" is a simple product or a single variation. Each unit keeps its mapping in its own post meta (products are
 * not affected by HPOS; mappings survive deactivation and are kept on uninstall unless the owner chooses otherwise).
 *
 * A mapping can carry a REVIEW flag: it was created by a rule (attribute match between variations) rather than by a
 * person, so orders will not use it until somebody confirms it. SKU matches that pass the name safety check and
 * everything a person clicks are confirmed from the start. Keyword suggestions are never saved without a click.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Mapping {

	// Keys used since v0.2.0 (kept for upgrades).
	const META_PRODUCT   = '_rkc_rk_product_id';
	const META_VARIATION = '_rkc_rk_variation_id';
	const META_SKU       = '_rkc_rk_sku';
	const META_NAME      = '_rkc_rk_name';
	const META_URL       = '_rkc_rk_url';
	const META_SOURCE    = '_rkc_map_source';
	const META_TIME      = '_rkc_mapped_at';
	// New in v1.0.0.
	const META_IMAGE   = '_rkc_rk_image';
	const META_BASE    = '_rkc_rk_base';
	const META_AVAIL   = '_rkc_rk_avail'; // in | out | gone
	const META_CHECKED = '_rkc_rk_checked';
	const META_REVIEW  = '_rkc_map_review';
	const META_BY      = '_rkc_mapped_by';

	private static function all_keys() {
		return array( self::META_PRODUCT, self::META_VARIATION, self::META_SKU, self::META_NAME, self::META_URL, self::META_SOURCE, self::META_TIME, self::META_IMAGE, self::META_BASE, self::META_AVAIL, self::META_CHECKED, self::META_REVIEW, self::META_BY );
	}

	/**
	 * @param int $woo_id Product or variation ID.
	 * @return array|null
	 */
	public static function get( $woo_id ) {
		$woo_id = (int) $woo_id;
		if ( ! $woo_id ) {
			return null;
		}
		$pid = (int) get_post_meta( $woo_id, self::META_PRODUCT, true );
		if ( ! $pid ) {
			return null;
		}
		return array(
			'rk_product_id'   => $pid,
			'rk_variation_id' => (int) get_post_meta( $woo_id, self::META_VARIATION, true ),
			'sku'             => (string) get_post_meta( $woo_id, self::META_SKU, true ),
			'name'            => (string) get_post_meta( $woo_id, self::META_NAME, true ),
			'url'             => (string) get_post_meta( $woo_id, self::META_URL, true ),
			'source'          => (string) get_post_meta( $woo_id, self::META_SOURCE, true ),
			'mapped_at'       => (int) get_post_meta( $woo_id, self::META_TIME, true ),
			'image'           => (string) get_post_meta( $woo_id, self::META_IMAGE, true ),
			'base'            => (string) get_post_meta( $woo_id, self::META_BASE, true ),
			'avail'           => (string) get_post_meta( $woo_id, self::META_AVAIL, true ),
			'checked_at'      => (int) get_post_meta( $woo_id, self::META_CHECKED, true ),
			'review'          => (bool) get_post_meta( $woo_id, self::META_REVIEW, true ),
			'mapped_by'       => (int) get_post_meta( $woo_id, self::META_BY, true ),
		);
	}

	/** The product (or its variation) node of a ResellingKori item. */
	public static function node( array $item, $variation_id ) {
		$variation_id = (int) $variation_id;
		if ( $variation_id && ! empty( $item['variations'] ) && is_array( $item['variations'] ) ) {
			foreach ( $item['variations'] as $v ) {
				if ( isset( $v['id'] ) && (int) $v['id'] === $variation_id ) {
					return $v;
				}
			}
			return null;
		}
		return $variation_id ? null : $item;
	}

	/**
	 * Persist a mapping from an API item (see /rk/v1/products/{id}).
	 *
	 * @param int    $woo_id       Product or variation ID.
	 * @param array  $item         ResellingKori product data.
	 * @param int    $variation_id ResellingKori variation ID (0 for simple products).
	 * @param string $source       sku | keyword | manual | attributes.
	 * @param bool   $review       Needs a person's confirmation before orders may use it.
	 */
	public static function save( $woo_id, array $item, $variation_id, $source, $review = false ) {
		$variation_id = absint( $variation_id );
		$name         = isset( $item['name'] ) ? (string) $item['name'] : '';
		$sku          = isset( $item['sku'] ) ? (string) $item['sku'] : '';
		$node         = self::node( $item, $variation_id );

		if ( $variation_id && is_array( $node ) ) {
			if ( ! empty( $node['label'] ) ) {
				$name .= ' — ' . $node['label'];
			}
			if ( ! empty( $node['sku'] ) ) {
				$sku = (string) $node['sku'];
			}
		}

		update_post_meta( $woo_id, self::META_PRODUCT, absint( $item['id'] ) );
		update_post_meta( $woo_id, self::META_VARIATION, $variation_id );
		update_post_meta( $woo_id, self::META_SKU, sanitize_text_field( $sku ) );
		update_post_meta( $woo_id, self::META_NAME, sanitize_text_field( $name ) );
		update_post_meta( $woo_id, self::META_URL, esc_url_raw( isset( $item['permalink'] ) ? (string) $item['permalink'] : '' ) );
		update_post_meta( $woo_id, self::META_IMAGE, esc_url_raw( isset( $item['image'] ) ? (string) $item['image'] : '' ) );
		update_post_meta( $woo_id, self::META_SOURCE, sanitize_key( $source ) );
		update_post_meta( $woo_id, self::META_TIME, time() );
		update_post_meta( $woo_id, self::META_BY, function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		if ( $review ) {
			update_post_meta( $woo_id, self::META_REVIEW, 1 );
		} else {
			delete_post_meta( $woo_id, self::META_REVIEW );
		}
		self::store_availability( $woo_id, is_array( $node ) ? $node : null );

		do_action( 'rkc_product_mapped', $woo_id, $item, $variation_id, $source );
	}

	/** @param array|null $node Item or variation node; null = the mapped variation no longer exists. */
	private static function store_availability( $woo_id, $node ) {
		if ( null === $node ) {
			update_post_meta( $woo_id, self::META_AVAIL, 'gone' );
		} else {
			update_post_meta( $woo_id, self::META_AVAIL, ! isset( $node['purchasable'] ) || $node['purchasable'] ? 'in' : 'out' );
			update_post_meta( $woo_id, self::META_BASE, isset( $node['base_price'] ) ? sanitize_text_field( (string) $node['base_price'] ) : '' );
		}
		update_post_meta( $woo_id, self::META_CHECKED, time() );
	}

	public static function clear( $woo_id ) {
		foreach ( self::all_keys() as $key ) {
			delete_post_meta( $woo_id, $key );
		}
		do_action( 'rkc_product_unmapped', $woo_id );
	}

	/** A person confirms a rule-made mapping. */
	public static function confirm( $woo_id ) {
		if ( ! self::get( $woo_id ) ) {
			return false;
		}
		delete_post_meta( $woo_id, self::META_REVIEW );
		update_post_meta( $woo_id, self::META_BY, (int) get_current_user_id() );
		return true;
	}

	/**
	 * Validate against ResellingKori, then save. The person chose this match, so it is confirmed.
	 *
	 * @param int    $woo_id          Product or variation ID.
	 * @param int    $rk_product_id   ResellingKori product ID.
	 * @param int    $rk_variation_id ResellingKori variation ID (required for variable products).
	 * @param string $source          sku | keyword | manual.
	 * @return array|WP_Error { siblings: int }
	 */
	public static function link( $woo_id, $rk_product_id, $rk_variation_id, $source ) {
		$woo = wc_get_product( $woo_id );
		if ( ! $woo ) {
			return new WP_Error( 'rkc_no_product', __( 'Product not found.', 'resellingkori-connector' ) );
		}
		if ( $woo->is_type( 'variable' ) || $woo->is_type( 'grouped' ) || $woo->is_type( 'external' ) ) {
			return new WP_Error( 'rkc_not_a_unit', __( 'Link the individual variations of a variable product, not the parent.', 'resellingkori-connector' ) );
		}

		$res  = RKC_API_Client::get_product( $rk_product_id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$item = ( isset( $res['item'] ) && is_array( $res['item'] ) ) ? $res['item'] : null;
		if ( ! $item || empty( $item['id'] ) ) {
			return new WP_Error( 'rkc_bad_response', __( 'ResellingKori returned an unexpected response for that product.', 'resellingkori-connector' ) );
		}

		$rk_variation_id = absint( $rk_variation_id );
		$variation_ids   = array();
		if ( ! empty( $item['variations'] ) && is_array( $item['variations'] ) ) {
			foreach ( $item['variations'] as $v ) {
				$variation_ids[] = (int) $v['id'];
			}
		}

		if ( isset( $item['type'] ) && 'variable' === $item['type'] ) {
			if ( ! $rk_variation_id || ! in_array( $rk_variation_id, $variation_ids, true ) ) {
				return new WP_Error( 'rkc_variation_required', __( 'That ResellingKori product has variations. Select the exact variation.', 'resellingkori-connector' ) );
			}
		} else {
			$rk_variation_id = 0;
		}

		self::save( $woo_id, $item, $rk_variation_id, $source, false );

		return array( 'siblings' => $rk_variation_id ? self::auto_map_siblings( $woo, $item ) : 0 );
	}

	/**
	 * After one variation is linked, propose the same for the other unmapped variations of the same store product
	 * whose attribute values match EXACTLY ONE ResellingKori variation. These stay flagged "review": they are not
	 * used for orders until a person confirms them.
	 *
	 * @param WC_Product $woo  The variation that was just linked.
	 * @param array      $item ResellingKori product data (with variations).
	 * @return int Number of siblings proposed.
	 */
	public static function auto_map_siblings( WC_Product $woo, array $item ) {
		if ( ! $woo->is_type( 'variation' ) || empty( $item['variations'] ) ) {
			return 0;
		}
		$parent = wc_get_product( $woo->get_parent_id() );
		if ( ! $parent ) {
			return 0;
		}

		$index = array();
		foreach ( $item['variations'] as $v ) {
			$key = self::rk_attr_key( isset( $v['attributes'] ) ? (array) $v['attributes'] : array() );
			if ( '' !== $key ) {
				$index[ $key ][] = (int) $v['id'];
			}
		}

		$count = 0;
		foreach ( $parent->get_children() as $sibling_id ) {
			if ( (int) $sibling_id === (int) $woo->get_id() || self::get( $sibling_id ) ) {
				continue;
			}
			$sibling = wc_get_product( $sibling_id );
			if ( ! $sibling ) {
				continue;
			}
			$key = self::woo_attr_key( $sibling );
			if ( '' === $key || empty( $index[ $key ] ) || 1 !== count( $index[ $key ] ) ) {
				continue;
			}
			self::save( $sibling_id, $item, $index[ $key ][0], 'attributes', true );
			++$count;
		}
		return $count;
	}

	/** Sorted, slugified attribute values of a store variation ('' if any attribute is "Any"). */
	private static function woo_attr_key( WC_Product $variation ) {
		$vals = array();
		foreach ( (array) $variation->get_attributes() as $value ) {
			if ( '' === (string) $value ) {
				return '';
			}
			$vals[] = sanitize_title( (string) $value );
		}
		sort( $vals );
		return implode( '|', $vals );
	}

	private static function rk_attr_key( array $attributes ) {
		$vals = array();
		foreach ( $attributes as $value ) {
			if ( '' === (string) $value ) {
				return '';
			}
			$vals[] = sanitize_title( (string) $value );
		}
		sort( $vals );
		return implode( '|', $vals );
	}

	/* --------------------------------------------------------- Descriptions */

	/** Title used for matching: the parent title for variations. */
	public static function base_name( WC_Product $p ) {
		if ( $p->is_type( 'variation' ) ) {
			$parent = wc_get_product( $p->get_parent_id() );
			if ( $parent ) {
				return wp_strip_all_tags( $parent->get_name() );
			}
		}
		return wp_strip_all_tags( $p->get_name() );
	}

	/** Human label: "Parent — Black, XL". */
	public static function label( WC_Product $p ) {
		$name = self::base_name( $p );
		if ( $p->is_type( 'variation' ) ) {
			$attrs = wp_strip_all_tags( wc_get_formatted_variation( $p, true, false ) );
			if ( '' !== $attrs ) {
				$name .= ' — ' . $attrs;
			}
		}
		return $name;
	}

	/**
	 * Everything the mapping screen shows about one store unit.
	 *
	 * @param WC_Product $p Product or variation.
	 * @return array
	 */
	public static function describe( WC_Product $p ) {
		$img_id = $p->get_image_id();
		if ( ! $img_id && $p->is_type( 'variation' ) ) {
			$parent = wc_get_product( $p->get_parent_id() );
			$img_id = $parent ? $parent->get_image_id() : 0;
		}
		return array(
			'id'        => (int) $p->get_id(),
			'parent_id' => (int) $p->get_parent_id(),
			'name'      => self::base_name( $p ),
			'label'     => $p->is_type( 'variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $p, true, false ) ) : '',
			'sku'       => (string) $p->get_sku( 'edit' ),
			'price'     => (string) $p->get_price(),
			'image'     => $img_id ? (string) wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
			'map'       => self::get( $p->get_id() ),
		);
	}

	/* --------------------------------------------------------------- Listing */

	/**
	 * WP_Query args for the mapping table: simple products and variations only (variable parents, grouped and
	 * external products cannot be fulfilled directly).
	 *
	 * @param string $filter   all | mapped | unmapped | review | unavailable.
	 * @param string $search   Search text (name or exact SKU).
	 * @param int    $per_page Rows per page.
	 * @param int    $paged    Page number.
	 * @return array
	 */
	public static function query_args( $filter, $search, $per_page, $paged ) {
		$args = array(
			'post_type'      => array( 'product', 'product_variation' ),
			'post_status'    => array( 'publish', 'private' ),
			'fields'         => 'ids',
			'posts_per_page' => (int) $per_page,
			'paged'          => max( 1, (int) $paged ),
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => false,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => array( 'variable', 'grouped', 'external' ),
					'operator' => 'NOT IN',
				),
			),
		);

		$meta = null;
		switch ( $filter ) {
			case 'mapped':
				$meta = array( array( 'key' => self::META_PRODUCT, 'compare' => 'EXISTS' ) );
				break;
			case 'unmapped':
				$meta = array( array( 'key' => self::META_PRODUCT, 'compare' => 'NOT EXISTS' ) );
				break;
			case 'review':
				$meta = array( array( 'key' => self::META_REVIEW, 'value' => '1' ) );
				break;
			case 'unavailable':
				$meta = array( array( 'key' => self::META_AVAIL, 'value' => array( 'out', 'gone' ), 'compare' => 'IN' ) );
				break;
		}
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( '' !== $search ) {
			$sku_id = wc_get_product_id_by_sku( $search );
			if ( $sku_id ) {
				$hit = wc_get_product( $sku_id );
				if ( $hit && $hit->is_type( 'variable' ) ) {
					$args['post_type']   = 'product_variation';
					$args['post_parent'] = $sku_id;
				} else {
					$args['post__in'] = array( $sku_id );
				}
			} else {
				$args['s'] = $search;
			}
		}

		return $args;
	}

	/** @return array { all, mapped, unmapped, review, unavailable } */
	public static function counts() {
		$out = array();
		foreach ( array( 'all', 'mapped', 'unmapped', 'review', 'unavailable' ) as $filter ) {
			$q              = new WP_Query( self::query_args( $filter, '', 1, 1 ) );
			$out[ $filter ] = (int) $q->found_posts;
		}
		return $out;
	}

	/**
	 * Unmapped units that have their own SKU (used by the bulk SKU matcher).
	 *
	 * @param int $limit  Batch size.
	 * @param int $offset Units to skip (those already reviewed / not found).
	 * @return int[]
	 */
	public static function unmapped_with_sku( $limit, $offset ) {
		$args                  = self::query_args( 'all', '', $limit, 1 );
		$args['paged']         = 1;
		$args['offset']        = (int) $offset;
		$args['orderby']       = 'ID';
		$args['order']         = 'ASC';
		$args['no_found_rows'] = true;
		$args['meta_query']    = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			array(
				'key'     => self::META_PRODUCT,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_sku',
				'value'   => '',
				'compare' => '!=',
			),
		);
		$q = new WP_Query( $args );
		return array_map( 'intval', $q->posts );
	}

	/** Mapped units, oldest check first (for the availability refresh). */
	public static function mapped_ids( $limit, $offset ) {
		$args                  = self::query_args( 'mapped', '', $limit, 1 );
		$args['paged']         = 1;
		$args['offset']        = (int) $offset;
		$args['orderby']       = 'ID';
		$args['order']         = 'ASC';
		$args['no_found_rows'] = true;
		$q                     = new WP_Query( $args );
		return array_map( 'intval', $q->posts );
	}

	/* ---------------------------------------------------------- Availability */

	/**
	 * Re-read price and availability of mapped units from ResellingKori.
	 *
	 * @param int[] $woo_ids Store units.
	 * @return array|WP_Error { checked, unavailable }
	 */
	public static function refresh_availability( array $woo_ids ) {
		$by_sku  = array();
		$singles = array();
		foreach ( $woo_ids as $id ) {
			$m = self::get( $id );
			if ( ! $m ) {
				continue;
			}
			if ( '' !== $m['sku'] && false === strpos( $m['sku'], ',' ) ) {
				$by_sku[ $m['sku'] ][] = (int) $id;
			} else {
				$singles[ (int) $id ] = $m;
			}
		}
		$checked     = 0;
		$unavailable = 0;

		foreach ( array_chunk( array_keys( $by_sku ), 50 ) as $chunk ) {
			$res = RKC_API_Client::products_by_sku( $chunk );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$matches = ( isset( $res['matches'] ) && is_array( $res['matches'] ) ) ? $res['matches'] : array();
			foreach ( $chunk as $sku ) {
				foreach ( $by_sku[ $sku ] as $id ) {
					$m    = self::get( $id );
					$item = isset( $matches[ $sku ] ) && is_array( $matches[ $sku ] ) ? $matches[ $sku ] : null;
					// The SKU now belongs to a different product: the mapping is stale.
					$same = $item && (int) $item['id'] === (int) $m['rk_product_id'];
					self::store_availability( $id, $same ? self::node( $item, $m['rk_variation_id'] ) : null );
					++$checked;
					if ( 'in' !== get_post_meta( $id, self::META_AVAIL, true ) ) {
						++$unavailable;
					}
				}
			}
		}
		foreach ( $singles as $id => $m ) { // Callers keep batches small.
			$res = RKC_API_Client::get_product( $m['rk_product_id'] );
			if ( is_wp_error( $res ) ) {
				if ( 'rkapi_product_not_found' === $res->get_error_code() ) {
					self::store_availability( $id, null );
					++$checked;
					++$unavailable;
					continue;
				}
				return $res;
			}
			$item = ( isset( $res['item'] ) && is_array( $res['item'] ) ) ? $res['item'] : array();
			self::store_availability( $id, $item ? self::node( $item, $m['rk_variation_id'] ) : null );
			++$checked;
			if ( 'in' !== get_post_meta( $id, self::META_AVAIL, true ) ) {
				++$unavailable;
			}
		}
		return array( 'checked' => $checked, 'unavailable' => $unavailable );
	}
}
