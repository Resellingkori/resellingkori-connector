<?php
/**
 * Store product -> ResellingKori product mapping.
 *
 * A "unit" is a simple product or a single variation. Each unit stores its mapping
 * in its own post meta, so it works with HPOS (products are not affected by HPOS)
 * and survives plugin deactivation.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Mapping {

	const META_PRODUCT   = '_rkc_rk_product_id';
	const META_VARIATION = '_rkc_rk_variation_id';
	const META_SKU       = '_rkc_rk_sku';
	const META_NAME      = '_rkc_rk_name';
	const META_URL       = '_rkc_rk_url';
	const META_SOURCE    = '_rkc_map_source';
	const META_TIME      = '_rkc_mapped_at';

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
		);
	}

	/**
	 * Persist a mapping from an API item (see /rk/v1/products/{id}).
	 *
	 * @param int    $woo_id       Product or variation ID.
	 * @param array  $item         ResellingKori product data.
	 * @param int    $variation_id ResellingKori variation ID (0 for simple products).
	 * @param string $source       sku | keyword | manual | attributes.
	 */
	public static function save( $woo_id, array $item, $variation_id, $source ) {
		$variation_id = absint( $variation_id );
		$name         = isset( $item['name'] ) ? (string) $item['name'] : '';
		$sku          = isset( $item['sku'] ) ? (string) $item['sku'] : '';

		if ( $variation_id && ! empty( $item['variations'] ) && is_array( $item['variations'] ) ) {
			foreach ( $item['variations'] as $v ) {
				if ( isset( $v['id'] ) && (int) $v['id'] === $variation_id ) {
					if ( ! empty( $v['label'] ) ) {
						$name .= ' — ' . $v['label'];
					}
					if ( ! empty( $v['sku'] ) ) {
						$sku = (string) $v['sku'];
					}
					break;
				}
			}
		}

		update_post_meta( $woo_id, self::META_PRODUCT, absint( $item['id'] ) );
		update_post_meta( $woo_id, self::META_VARIATION, $variation_id );
		update_post_meta( $woo_id, self::META_SKU, sanitize_text_field( $sku ) );
		update_post_meta( $woo_id, self::META_NAME, sanitize_text_field( $name ) );
		update_post_meta( $woo_id, self::META_URL, esc_url_raw( isset( $item['permalink'] ) ? (string) $item['permalink'] : '' ) );
		update_post_meta( $woo_id, self::META_SOURCE, sanitize_key( $source ) );
		update_post_meta( $woo_id, self::META_TIME, time() );

		do_action( 'rkc_product_mapped', $woo_id, $item, $variation_id, $source );
	}

	public static function clear( $woo_id ) {
		foreach ( array( self::META_PRODUCT, self::META_VARIATION, self::META_SKU, self::META_NAME, self::META_URL, self::META_SOURCE, self::META_TIME ) as $key ) {
			delete_post_meta( $woo_id, $key );
		}
		do_action( 'rkc_product_unmapped', $woo_id );
	}

	/**
	 * Validate against ResellingKori, then save.
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

		self::save( $woo_id, $item, $rk_variation_id, $source );

		return array( 'siblings' => $rk_variation_id ? self::auto_map_siblings( $woo, $item ) : 0 );
	}

	/**
	 * After one variation is linked, link the other unmapped variations of the same
	 * store product whose attribute values match exactly one ResellingKori variation.
	 *
	 * @param WC_Product $woo  The variation that was just linked.
	 * @param array      $item ResellingKori product data (with variations).
	 * @return int Number of siblings linked.
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
			self::save( $sibling_id, $item, $index[ $key ][0], 'attributes' );
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

	/** Human label for list tables: "Parent — Black, XL". */
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
	 * Data for the mapping modal header.
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
			'id'    => (int) $p->get_id(),
			'name'  => self::base_name( $p ),
			'label' => $p->is_type( 'variation' ) ? wp_strip_all_tags( wc_get_formatted_variation( $p, true, false ) ) : '',
			'sku'   => (string) $p->get_sku( 'edit' ),
			'image' => $img_id ? (string) wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
		);
	}

	/**
	 * WP_Query args for the mapping table: simple products and variations only
	 * (variable parents, grouped and external products cannot be fulfilled directly).
	 *
	 * @param string $filter   all | mapped | unmapped.
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

		if ( 'mapped' === $filter ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_PRODUCT,
					'compare' => 'EXISTS',
				),
			);
		} elseif ( 'unmapped' === $filter ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_PRODUCT,
					'compare' => 'NOT EXISTS',
				),
			);
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

	/** @return array { all, mapped, unmapped } */
	public static function counts() {
		$out = array();
		foreach ( array( 'all', 'mapped', 'unmapped' ) as $filter ) {
			$q            = new WP_Query( self::query_args( $filter, '', 1, 1 ) );
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
		$args               = self::query_args( 'all', '', $limit, 1 );
		$args['paged']      = 1;
		$args['offset']     = (int) $offset;
		$args['orderby']    = 'ID';
		$args['order']      = 'ASC';
		$args['no_found_rows'] = true;
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
}
