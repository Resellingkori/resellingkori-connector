<?php
/**
 * Import a ResellingKori product into this shop (asked for from the agent dashboard), already linked.
 *
 * What the shop trusts and what it checks itself:
 *   - the instruction only names the ResellingKori product, the agent's selling prices and the stock-monitor choice;
 *   - the product itself (name, variations, SKUs, base prices, pictures, categories) is read from ResellingKori's API by
 *     this shop, exactly as for a manual link. Only the long description may come with the instruction, because the API
 *     answer for a single product may not carry it; it is filtered with wp_kses_post either way;
 *   - every price is checked against ResellingKori's live base price: a price below it is refused (the shop would sell
 *     at a loss and ResellingKori would refuse the order anyway);
 *   - nothing is imported twice: a product already linked to the same ResellingKori product, or a SKU already used in
 *     this shop, stops the import with a message that says what to do instead.
 *
 * The product is created as a draft, linked, and published only at the very end. A crash in between leaves a draft that
 * is marked "_rkc_import_pending"; the next import of the same product removes it first. Pictures are downloaded
 * afterwards in the background (RKC_Import::cron_media), so an import never waits for slow image downloads.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Import {

	const META_PENDING = '_rkc_import_pending';
	const META_FROM    = '_rkc_imported_from';
	const META_AT      = '_rkc_imported_at';
	const MEDIA_OPTION = 'rkc_import_media';

	const MAX_VARIATIONS = 100;
	const MAX_IMAGES     = 8;          // Main picture + gallery, per product.
	const MAX_IMAGE_SIZE = 8388608;    // 8 MB per picture.
	const MAX_MEDIA_JOBS = 2000;       // Waiting picture downloads (all products).

	/**
	 * @param array $args { rk_product_id, prices: { "<variation id or 0>": "1250.00" }, monitor: bool, description?: string }
	 * @return array|WP_Error { woo_id, variations, monitored, images }
	 */
	public static function run( array $args ) {
		$pid = isset( $args['rk_product_id'] ) && is_numeric( $args['rk_product_id'] ) ? (int) $args['rk_product_id'] : 0;
		if ( $pid < 1 ) {
			return new WP_Error( 'bad_command', __( 'This shop could not read the instruction.', 'resellingkori-connector' ) );
		}
		$prices_in = ( isset( $args['prices'] ) && is_array( $args['prices'] ) ) ? array_slice( $args['prices'], 0, self::MAX_VARIATIONS + 1, true ) : array();
		$monitor   = ! array_key_exists( 'monitor', $args ) || ! empty( $args['monitor'] );

		// 1. Not twice.
		$existing = self::linked_units( $pid );
		if ( $existing ) {
			return new WP_Error( 'already_in_shop', __( 'This ResellingKori product is already in your shop (linked). Nothing was imported.', 'resellingkori-connector' ), array( 'woo_id' => (int) $existing[0] ) );
		}

		// 2. The product as ResellingKori's API describes it to this shop.
		$res = RKC_API_Client::get_product_full( $pid );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$item = ( isset( $res['item'] ) && is_array( $res['item'] ) ) ? $res['item'] : null;
		if ( ! $item || empty( $item['id'] ) || (int) $item['id'] !== $pid || ! isset( $item['type'] ) || ! in_array( $item['type'], array( 'simple', 'variable' ), true ) ) {
			return new WP_Error( 'rk_product_missing', __( 'ResellingKori did not return this product (it may have been removed). Nothing was imported.', 'resellingkori-connector' ) );
		}
		$variable = 'variable' === $item['type'];

		// 3. The agent's prices, against the live base prices.
		$units = array(); // rk variation id (0 = simple) => { node, minor }
		$low   = array();
		if ( $variable ) {
			foreach ( (array) ( isset( $item['variations'] ) ? $item['variations'] : array() ) as $v ) {
				if ( ! is_array( $v ) || empty( $v['id'] ) || ! array_key_exists( (string) (int) $v['id'], $prices_in ) ) {
					continue;
				}
				$u = self::priced( $v, $prices_in[ (string) (int) $v['id'] ], $item['name'] . ( ! empty( $v['label'] ) ? ' — ' . $v['label'] : '' ), $low );
				if ( $u ) {
					$units[ (int) $v['id'] ] = $u;
				}
			}
		} elseif ( array_key_exists( '0', $prices_in ) ) {
			$u = self::priced( $item, $prices_in['0'], $item['name'], $low );
			if ( $u ) {
				$units[0] = $u;
			}
		}
		if ( $low ) {
			return new WP_Error( 'price_too_low', __( 'A selling price is lower than what you pay ResellingKori, so nothing was imported. Raise the price and try again.', 'resellingkori-connector' ), array( 'errors' => array_slice( $low, 0, 5 ) ) );
		}
		if ( ! $units ) {
			return new WP_Error( 'no_price', __( 'No selling price was given for this product (or for any of its variations), so nothing was imported.', 'resellingkori-connector' ) );
		}
		if ( count( $units ) > self::MAX_VARIATIONS ) {
			return new WP_Error( 'too_many', __( 'This product has too many variations to import from the dashboard.', 'resellingkori-connector' ) );
		}

		// 4. SKUs must be free in this shop (an existing product with the SKU should be linked, not duplicated).
		$skus = array();
		foreach ( $units as $vid => $u ) {
			$sku = self::sku_of( $item, $u['node'], $vid );
			if ( '' !== $sku ) {
				$hit = wc_get_product_id_by_sku( $sku );
				if ( $hit ) {
					/* translators: %s: SKU */
					return new WP_Error( 'sku_in_use', sprintf( __( 'Your shop already has a product with the SKU %s. Link that product to ResellingKori instead of importing it again.', 'resellingkori-connector' ), $sku ), array( 'woo_id' => (int) $hit ) );
				}
				$skus[ $vid ] = $sku;
			}
		}
		if ( $variable && '' !== (string) $item['sku'] && wc_get_product_id_by_sku( (string) $item['sku'] ) ) {
			/* translators: %s: SKU */
			return new WP_Error( 'sku_in_use', sprintf( __( 'Your shop already has a product with the SKU %s. Link that product to ResellingKori instead of importing it again.', 'resellingkori-connector' ), (string) $item['sku'] ), array( 'woo_id' => (int) wc_get_product_id_by_sku( (string) $item['sku'] ) ) );
		}

		// 5. Build it.
		self::remove_leftovers( $pid );
		try {
			$woo_id = $variable ? self::create_variable( $item, $units, $skus, $args, $monitor ) : self::create_simple( $item, $units[0], isset( $skus[0] ) ? $skus[0] : '', $args, $monitor );
		} catch ( Throwable $e ) {
			RKC_Logger::error( 'Import from the agent dashboard failed: ' . RKC_Redact::text( $e->getMessage() ), array( 'rk_product' => $pid ) );
			self::remove_leftovers( $pid );
			return new WP_Error( 'import_failed', __( 'This shop could not create the product. Nothing was kept. See the shop\'s log for details.', 'resellingkori-connector' ) );
		}
		if ( is_wp_error( $woo_id ) ) {
			self::remove_leftovers( $pid );
			return $woo_id;
		}
		$images = self::queue_media( $woo_id, $item, $units );
		RKC_Logger::info( 'Product imported from the agent dashboard.', array( 'product' => $woo_id, 'rk_product' => $pid, 'units' => count( $units ) ) );
		return array(
			'woo_id'     => (int) $woo_id,
			'variations' => $variable ? count( $units ) : 0,
			'monitored'  => $monitor ? count( $units ) : 0,
			'images'     => $images,
		);
	}

	/**
	 * @param array  $node  Item or variation node.
	 * @param mixed  $price The agent's price.
	 * @param string $name  For messages.
	 * @param array  $low   Collects "price too low" messages.
	 * @return array|null { node, minor }
	 */
	private static function priced( array $node, $price, $name, array &$low ) {
		$minor = is_scalar( $price ) ? RKC_Money::parse( (string) $price ) : null;
		if ( null === $minor || $minor < 1 ) {
			return null;
		}
		$base = isset( $node['base_price'] ) && '' !== (string) $node['base_price'] ? RKC_Money::parse( (string) $node['base_price'] ) : null;
		if ( null === $base ) {
			/* translators: %s: product name */
			$low[] = sprintf( __( '%s has no ResellingKori price at the moment and cannot be sold.', 'resellingkori-connector' ), wp_strip_all_tags( (string) $name ) );
			return null;
		}
		if ( $minor < $base ) {
			/* translators: 1: product name, 2: price, 3: ResellingKori price */
			$low[] = sprintf( __( '%1$s: %2$s is below the ResellingKori price %3$s.', 'resellingkori-connector' ), wp_strip_all_tags( (string) $name ), RKC_Money::bdt( $minor ), RKC_Money::bdt( $base ) );
			return null;
		}
		return array( 'node' => $node, 'minor' => $minor );
	}

	private static function sku_of( array $item, array $node, $vid ) {
		$sku = $vid ? ( isset( $node['sku'] ) ? (string) $node['sku'] : '' ) : ( isset( $item['sku'] ) ? (string) $item['sku'] : '' );
		return substr( sanitize_text_field( $sku ), 0, 100 );
	}

	/** @return int[] Store units linked to this ResellingKori product. */
	public static function linked_units( $rk_product_id ) {
		$q = new WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => RKC_Mapping::META_PRODUCT, 'value' => (string) (int) $rk_product_id ),
				),
			)
		);
		$out = array();
		foreach ( (array) $q->posts as $id ) {
			$owner = (int) $id;
			$p     = get_post( $owner );
			if ( $p && 'product_variation' === $p->post_type ) {
				$owner = (int) $p->post_parent;
			}
			if ( ! get_post_meta( $owner, self::META_PENDING, true ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/** Drafts left behind by an import that stopped half way. */
	private static function remove_leftovers( $rk_product_id ) {
		$q = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 10,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array( 'key' => self::META_FROM, 'value' => (string) (int) $rk_product_id ),
					array( 'key' => self::META_PENDING, 'compare' => 'EXISTS' ),
				),
			)
		);
		foreach ( (array) $q->posts as $id ) {
			$p = wc_get_product( (int) $id );
			if ( $p ) {
				foreach ( $p->get_children() as $child ) {
					wp_delete_post( (int) $child, true );
				}
				$p->delete( true );
			}
		}
	}

	/* ============================================================== Create */

	private static function common( WC_Product $p, array $item, array $args ) {
		$p->set_name( wp_strip_all_tags( (string) $item['name'] ) );
		$p->set_status( 'draft' );
		$p->set_catalog_visibility( 'visible' );
		$desc = isset( $item['description'] ) && is_string( $item['description'] ) && '' !== $item['description'] ? $item['description'] : ( isset( $args['description'] ) && is_string( $args['description'] ) ? $args['description'] : '' );
		$p->set_description( wp_kses_post( substr( $desc, 0, 60000 ) ) );
		$p->set_short_description( wp_kses_post( substr( isset( $item['short_description'] ) ? (string) $item['short_description'] : '', 0, 8000 ) ) );
		if ( isset( $item['weight'] ) && is_numeric( $item['weight'] ) ) {
			$p->set_weight( wc_format_decimal( $item['weight'] ) );
		}
		if ( ! empty( $item['dimensions'] ) && is_array( $item['dimensions'] ) ) {
			foreach ( array( 'length', 'width', 'height' ) as $k ) {
				if ( isset( $item['dimensions'][ $k ] ) && is_numeric( $item['dimensions'][ $k ] ) ) {
					$p->{'set_' . $k}( wc_format_decimal( $item['dimensions'][ $k ] ) );
				}
			}
		}
		$cats = self::categories( isset( $item['categories'] ) && is_array( $item['categories'] ) ? $item['categories'] : array() );
		if ( $cats ) {
			$p->set_category_ids( $cats );
		}
		$p->update_meta_data( self::META_PENDING, 1 );
		$p->update_meta_data( self::META_FROM, (int) $item['id'] );
		$p->update_meta_data( self::META_AT, time() );
	}

	/** Shop categories with the same slug (or name) as ResellingKori's; missing ones are created. */
	private static function categories( array $rk_cats ) {
		$ids = array();
		foreach ( array_slice( $rk_cats, 0, 10 ) as $c ) {
			if ( ! is_array( $c ) || empty( $c['name'] ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) $c['name'] );
			$slug = sanitize_title( isset( $c['slug'] ) && '' !== (string) $c['slug'] ? (string) $c['slug'] : $name );
			if ( 'uncategorized' === $slug || '' === $name ) {
				continue;
			}
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( ! $term ) {
				$term = get_term_by( 'name', $name, 'product_cat' );
			}
			if ( $term ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$new = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug ) );
			if ( ! is_wp_error( $new ) && isset( $new['term_id'] ) ) {
				$ids[] = (int) $new['term_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function stock_from( WC_Product $p, array $node ) {
		$p->set_manage_stock( false );
		$p->set_stock_status( ! isset( $node['purchasable'] ) || $node['purchasable'] ? 'instock' : 'outofstock' );
	}

	/** Link, monitor, then publish (the last step: until then the product is a hidden draft). */
	private static function finish( WC_Product $parent, array $links, array $item, $monitor ) {
		foreach ( $links as $woo_id => $vid ) {
			if ( $monitor ) {
				update_post_meta( (int) $woo_id, RKC_Stock::META_MONITOR, 'yes' );
			}
			RKC_Mapping::save( (int) $woo_id, $item, (int) $vid, 'import', false );
		}
		$parent = wc_get_product( $parent->get_id() );
		$parent->delete_meta_data( self::META_PENDING );
		$parent->set_status( 'publish' );
		$parent->save();
		return (int) $parent->get_id();
	}

	private static function create_simple( array $item, array $unit, $sku, array $args, $monitor ) {
		$p = new WC_Product_Simple();
		self::common( $p, $item, $args );
		if ( '' !== $sku ) {
			$p->set_sku( $sku );
		}
		$p->set_regular_price( RKC_Money::format( $unit['minor'] ) );
		self::stock_from( $p, $unit['node'] );
		$id = $p->save();
		if ( ! $id ) {
			return new WP_Error( 'import_failed', __( 'This shop could not create the product. Nothing was kept.', 'resellingkori-connector' ) );
		}
		return self::finish( $p, array( $id => 0 ), $item, $monitor );
	}

	/** "attribute_pa_color" / "pa_color" / "Color" -> "Color". */
	private static function attr_name( $key ) {
		$k = preg_replace( '/^(attribute_)?(pa_)?/', '', (string) $key );
		$k = trim( str_replace( array( '-', '_' ), ' ', (string) $k ) );
		return '' === $k ? __( 'Option', 'resellingkori-connector' ) : ucwords( $k );
	}

	/** "navy-blue" (a taxonomy slug) -> "Navy Blue"; real text is kept as it is. */
	private static function attr_value( $value ) {
		$v = sanitize_text_field( (string) $value );
		if ( preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $v ) && false !== strpos( $v, '-' ) ) {
			$v = ucwords( str_replace( '-', ' ', $v ) );
		}
		return substr( $v, 0, 200 );
	}

	private static function create_variable( array $item, array $units, array $skus, array $args, $monitor ) {
		// Attributes of the chosen variations ("Any" = empty value is allowed and kept).
		$attrs = array(); // display name => [ values ]
		$vmap  = array(); // rk vid => [ slug(name) => value ]
		foreach ( $units as $vid => $u ) {
			$vals  = array();
			$raw   = (array) ( isset( $u['node']['attributes'] ) ? $u['node']['attributes'] : array() );
			// The label ("Red, XL") carries the shop-facing text of taxonomy values ("red", "xl") in the same order.
			$parts = ! empty( $u['node']['label'] ) ? array_map( 'trim', explode( ',', (string) $u['node']['label'] ) ) : array();
			$pos   = 0;
			foreach ( $raw as $k => $v ) {
				$name = self::attr_name( $k );
				$val  = self::attr_value( $v );
				if ( count( $parts ) === count( $raw ) && '' !== (string) $v && sanitize_title( $parts[ $pos ] ) === sanitize_title( (string) $v ) ) {
					$val = substr( sanitize_text_field( $parts[ $pos ] ), 0, 200 );
				}
				++$pos;
				if ( ! isset( $attrs[ $name ] ) ) {
					$attrs[ $name ] = array();
				}
				if ( '' !== $val && ! in_array( $val, $attrs[ $name ], true ) ) {
					$attrs[ $name ][] = $val;
				}
				$vals[ sanitize_title( $name ) ] = $val;
			}
			if ( ! $vals ) {
				// A variation without attributes cannot be told apart in a shop: name it by its label.
				$name = __( 'Option', 'resellingkori-connector' );
				$val  = self::attr_value( ! empty( $u['node']['label'] ) ? $u['node']['label'] : '#' . $vid );
				$attrs[ $name ][] = $val;
				$vals[ sanitize_title( $name ) ] = $val;
			}
			$vmap[ $vid ] = $vals;
		}

		$p = new WC_Product_Variable();
		self::common( $p, $item, $args );
		if ( '' !== (string) $item['sku'] ) {
			$p->set_sku( substr( sanitize_text_field( (string) $item['sku'] ), 0, 100 ) );
		}
		$objs = array();
		$pos  = 0;
		foreach ( $attrs as $name => $values ) {
			$a = new WC_Product_Attribute();
			$a->set_id( 0 );
			$a->set_name( $name );
			$a->set_options( $values ? $values : array( '-' ) );
			$a->set_position( $pos++ );
			$a->set_visible( true );
			$a->set_variation( true );
			$objs[] = $a;
		}
		$p->set_attributes( $objs );
		$parent_id = $p->save();
		if ( ! $parent_id ) {
			return new WP_Error( 'import_failed', __( 'This shop could not create the product. Nothing was kept.', 'resellingkori-connector' ) );
		}

		$links = array();
		$menu  = 0;
		foreach ( $units as $vid => $u ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $parent_id );
			$v->set_attributes( $vmap[ $vid ] );
			$v->set_regular_price( RKC_Money::format( $u['minor'] ) );
			$v->set_menu_order( $menu++ );
			if ( isset( $skus[ $vid ] ) ) {
				$v->set_sku( $skus[ $vid ] );
			}
			if ( isset( $u['node']['weight'] ) && is_numeric( $u['node']['weight'] ) ) {
				$v->set_weight( wc_format_decimal( $u['node']['weight'] ) );
			}
			self::stock_from( $v, $u['node'] );
			$v->set_status( 'publish' );
			$vid_woo = $v->save();
			if ( ! $vid_woo ) {
				return new WP_Error( 'import_failed', __( 'This shop could not create a variation. Nothing was kept.', 'resellingkori-connector' ) );
			}
			$links[ $vid_woo ] = $vid;
		}
		if ( class_exists( 'WC_Product_Variable' ) ) {
			WC_Product_Variable::sync( $parent_id );
		}
		return self::finish( $p, $links, $item, $monitor );
	}

	/* =============================================================== Media */

	private static function web_url( $u ) {
		$u = is_string( $u ) ? trim( $u ) : '';
		return ( '' !== $u && preg_match( '#^https?://#i', $u ) && strlen( $u ) <= 1000 ) ? esc_url_raw( $u, array( 'http', 'https' ) ) : '';
	}

	/** @return int Pictures queued. */
	private static function queue_media( $woo_id, array $item, array $units ) {
		$jobs = array();
		$main = self::web_url( isset( $item['image'] ) ? $item['image'] : '' );
		$seen = array();
		if ( '' !== $main ) {
			$jobs[]        = array( 'p' => (int) $woo_id, 'u' => $main, 'r' => 'main', 't' => (int) $woo_id );
			$seen[ $main ] = true;
		}
		foreach ( (array) ( isset( $item['images'] ) ? $item['images'] : array() ) as $u ) {
			$u = self::web_url( $u );
			if ( '' === $u || isset( $seen[ $u ] ) || count( $seen ) >= self::MAX_IMAGES ) {
				continue;
			}
			$jobs[]     = array( 'p' => (int) $woo_id, 'u' => $u, 'r' => '' === $main && ! $jobs ? 'main' : 'gallery', 't' => (int) $woo_id );
			$seen[ $u ] = true;
		}
		$parent = wc_get_product( $woo_id );
		if ( $parent && $parent->is_type( 'variable' ) ) {
			foreach ( $parent->get_children() as $child ) {
				$map = RKC_Mapping::get( $child );
				$vid = $map ? (int) $map['rk_variation_id'] : 0;
				if ( $vid && isset( $units[ $vid ]['node']['image'] ) ) {
					$u = self::web_url( $units[ $vid ]['node']['image'] );
					if ( '' !== $u && $u !== $main ) {
						$jobs[] = array( 'p' => (int) $woo_id, 'u' => $u, 'r' => 'var', 't' => (int) $child );
					}
				}
			}
		}
		if ( ! $jobs ) {
			return 0;
		}
		$q = (array) get_option( self::MEDIA_OPTION, array() );
		if ( count( $q ) + count( $jobs ) > self::MAX_MEDIA_JOBS ) {
			RKC_Logger::warning( 'Too many pictures are waiting to be downloaded; the pictures of an imported product were skipped.', array( 'product' => $woo_id ) );
			return 0;
		}
		update_option( self::MEDIA_OPTION, array_merge( $q, $jobs ), false );
		if ( ! wp_next_scheduled( 'rkc_import_media' ) ) {
			wp_schedule_single_event( time() + 5, 'rkc_import_media' );
		}
		return count( $jobs );
	}

	/** Background job: download the waiting pictures, about 20 seconds per run. */
	public static function cron_media() {
		if ( get_transient( 'rkc_media_lock' ) ) {
			return;
		}
		set_transient( 'rkc_media_lock', 1, 2 * MINUTE_IN_SECONDS );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$deadline = time() + 20;
		$done     = array(); // url|product => attachment id (the same picture is downloaded once per product)
		try {
			while ( time() < $deadline ) {
				$q = (array) get_option( self::MEDIA_OPTION, array() );
				if ( ! $q ) {
					break;
				}
				$job = array_shift( $q );
				update_option( self::MEDIA_OPTION, $q, false );
				if ( ! is_array( $job ) || empty( $job['u'] ) || empty( $job['p'] ) ) {
					continue;
				}
				$key = $job['u'] . '|' . (int) $job['p'];
				$att = isset( $done[ $key ] ) ? $done[ $key ] : self::sideload( (string) $job['u'], (int) $job['p'] );
				if ( ! $att ) {
					continue;
				}
				$done[ $key ] = $att;
				self::attach( $job, (int) $att );
			}
		} finally {
			delete_transient( 'rkc_media_lock' );
		}
		if ( get_option( self::MEDIA_OPTION, array() ) && ! wp_next_scheduled( 'rkc_import_media' ) ) {
			wp_schedule_single_event( time() + 10, 'rkc_import_media' );
		}
	}

	private static function attach( array $job, $att ) {
		if ( 'gallery' === $job['r'] ) {
			$p = wc_get_product( (int) $job['p'] );
			if ( $p ) {
				$g   = $p->get_gallery_image_ids();
				$g[] = $att;
				$p->set_gallery_image_ids( array_values( array_unique( array_map( 'intval', $g ) ) ) );
				$p->save();
			}
			return;
		}
		$p = wc_get_product( (int) $job['t'] );
		if ( $p && ! $p->get_image_id() ) {
			$p->set_image_id( $att );
			$p->save();
		}
	}

	/** Download one picture into the media library. Only real images, at most 8 MB, never from a local address. */
	private static function sideload( $url, $post_id ) {
		if ( ! wc_get_product( $post_id ) ) {
			return 0;
		}
		$tmp = wp_tempnam( 'rkc-img' );
		if ( ! $tmp ) {
			return 0;
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => self::MAX_IMAGE_SIZE,
			)
		);
		$type = is_wp_error( $res ) ? '' : strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		$exts = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
		$mime = trim( (string) strtok( $type, ';' ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! isset( $exts[ $mime ] ) || ! filesize( $tmp ) || filesize( $tmp ) >= self::MAX_IMAGE_SIZE ) {
			wp_delete_file( $tmp );
			RKC_Logger::warning( 'A picture of an imported product could not be downloaded.', array( 'product' => $post_id ) );
			return 0;
		}
		$base = sanitize_file_name( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) );
		$file = array(
			'name'     => ( '' !== $base ? substr( $base, 0, 80 ) : 'product' ) . '.' . $exts[ $mime ],
			'tmp_name' => $tmp,
		);
		$att = media_handle_sideload( $file, $post_id );
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $tmp );
			RKC_Logger::warning( 'A picture of an imported product was not accepted: ' . $att->get_error_code(), array( 'product' => $post_id ) );
			return 0;
		}
		return (int) $att;
	}
}
