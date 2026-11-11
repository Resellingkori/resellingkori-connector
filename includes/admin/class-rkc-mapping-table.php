<?php
/**
 * Product Mapping list table. One row per simple product or per variation.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class RKC_Mapping_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'product',
				'plural'   => 'products',
				'ajax'     => false,
			)
		);
	}

	/** @return string all | mapped | unmapped */
	public function current_filter() {
		$f = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $f, array( 'all', 'mapped', 'unmapped' ), true ) ? $f : 'all';
	}

	public function get_columns() {
		return array(
			'rkc_thumb' => '',
			'rkc_name'  => __( 'Your product', 'resellingkori-connector' ),
			'rkc_rk'    => __( 'ResellingKori product', 'resellingkori-connector' ),
			'rkc_act'   => __( 'Mapping', 'resellingkori-connector' ),
		);
	}

	/** This is a custom admin page, so tell WP which column holds the row title. */
	protected function get_primary_column_name() {
		return 'rkc_name';
	}

	protected function get_views() {
		$current = $this->current_filter();
		$counts  = RKC_Mapping::counts();
		$labels  = array(
			'all'      => __( 'All', 'resellingkori-connector' ),
			'mapped'   => __( 'Mapped', 'resellingkori-connector' ),
			'unmapped' => __( 'Not mapped', 'resellingkori-connector' ),
		);
		$views   = array();
		foreach ( $labels as $key => $label ) {
			$views[ $key ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( RKC_Admin::page_url( 'products', array( 'filter' => $key ) ) ),
				$current === $key ? 'current' : '',
				esc_html( $label ),
				(int) $counts[ $key ]
			);
		}
		return $views;
	}

	public function prepare_items() {
		$per_page = 20;
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q        = new WP_Query( RKC_Mapping::query_args( $this->current_filter(), $search, $per_page, $this->get_pagenum() ) );

		$this->items = array_map( 'intval', $q->posts );
		$this->set_pagination_args(
			array(
				'total_items' => (int) $q->found_posts,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	public function no_items() {
		esc_html_e( 'No products found.', 'resellingkori-connector' );
	}

	/** Pagination only: avoids the bulk-action nonce that a GET search form does not need. */
	protected function display_tablenav( $which ) {
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		$this->pagination( $which );
		echo '<br class="clear" /></div>';
	}

	/* -------------------------------------------------------------- Columns */

	public function column_rkc_thumb( $id ) {
		$p = wc_get_product( $id );
		return $p ? $p->get_image( array( 40, 40 ) ) : '';
	}

	public function column_rkc_name( $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return esc_html( '#' . (int) $id );
		}

		$edit = get_edit_post_link( $p->is_type( 'variation' ) ? $p->get_parent_id() : $id );
		$name = esc_html( RKC_Mapping::label( $p ) );
		$out  = $edit ? '<a href="' . esc_url( $edit ) . '"><strong>' . $name . '</strong></a>' : '<strong>' . $name . '</strong>';

		$meta = array( '#' . (int) $id );
		$sku  = (string) $p->get_sku( 'edit' );
		if ( '' !== $sku ) {
			array_unshift( $meta, 'SKU ' . $sku );
		}
		return $out . '<div class="rkc-muted">' . esc_html( implode( ' · ', $meta ) ) . '</div>';
	}

	public function column_rkc_rk( $id ) {
		$m = RKC_Mapping::get( $id );
		if ( ! $m ) {
			return '<span class="rkc-badge rkc-low">' . esc_html__( 'Not mapped', 'resellingkori-connector' ) . '</span>';
		}

		$label = '' !== $m['name'] ? $m['name'] : '#' . $m['rk_product_id'];
		$out   = '<span class="rkc-ok">&check;</span> ';
		$out  .= '' !== $m['url']
			? '<a href="' . esc_url( $m['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>'
			: esc_html( $label );

		$sources = array(
			'sku'        => __( 'matched by SKU', 'resellingkori-connector' ),
			'keyword'    => __( 'confirmed from name suggestions', 'resellingkori-connector' ),
			'manual'     => __( 'entered manually', 'resellingkori-connector' ),
			'attributes' => __( 'matched by attributes', 'resellingkori-connector' ),
		);
		$bits    = array( 'RK #' . $m['rk_product_id'] );
		if ( $m['rk_variation_id'] ) {
			$bits[] = 'var #' . $m['rk_variation_id'];
		}
		if ( '' !== $m['sku'] ) {
			$bits[] = 'SKU ' . $m['sku'];
		}
		if ( isset( $sources[ $m['source'] ] ) ) {
			$bits[] = $sources[ $m['source'] ];
		}
		return $out . '<div class="rkc-muted">' . esc_html( implode( ' · ', $bits ) ) . '</div>';
	}

	public function column_rkc_act( $id ) {
		$mapped = (bool) RKC_Mapping::get( $id );
		$out    = '<button type="button" class="button rkc-find" data-product-id="' . esc_attr( $id ) . '">'
			. esc_html( $mapped ? __( 'Change', 'resellingkori-connector' ) : __( 'Find match', 'resellingkori-connector' ) )
			. '</button>';
		if ( $mapped ) {
			$out .= ' <button type="button" class="button-link button-link-delete rkc-unlink" data-product-id="' . esc_attr( $id ) . '">' . esc_html__( 'Unmap', 'resellingkori-connector' ) . '</button>';
		}
		return $out;
	}
}
