<?php
/**
 * Settings storage and the Settings tab.
 *
 * @package ResellingKori_Connector
 */

defined( 'ABSPATH' ) || exit;

class RKC_Settings {

	const OPTION = 'rkc_settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_setting(
			'rkc_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function defaults() {
		return array(
			'api_base'         => 'https://resellingkori.com',
			'key_id'           => '',
			'secret'           => '',
			'agent_email'      => '',
			'send_statuses'    => array( 'processing', 'on-hold' ),
			'after_send'       => '',
			'cod_gateways'     => 'cod',
			'sku_safety_check' => 'yes',
			'status_map'       => array(
				'pending'    => '',
				'processing' => '',
				'shipped'    => '',
				'delivered'  => 'completed',
				'returned'   => '',
				'cancelled'  => '',
			),
			'debug'            => 'no',
		);
	}

	/**
	 * Get all settings or a single one.
	 *
	 * @param string|null $key Setting key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$defaults           = self::defaults();
		$opts               = wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
		$opts['status_map'] = wp_parse_args( (array) $opts['status_map'], $defaults['status_map'] );
		if ( ! is_array( $opts['send_statuses'] ) ) {
			$opts['send_statuses'] = array();
		}
		if ( null === $key ) {
			return $opts;
		}
		return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
	}

	/**
	 * WooCommerce order statuses keyed by slug without the wc- prefix.
	 *
	 * @return array
	 */
	public static function statuses() {
		$out = array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$out[ preg_replace( '/^wc-/', '', $slug ) ] = $label;
		}
		return $out;
	}

	/**
	 * Normalised statuses used by the ResellingKori API.
	 *
	 * @return array
	 */
	public static function rk_statuses() {
		return array(
			'pending'    => __( 'Pending', 'resellingkori-connector' ),
			'processing' => __( 'Processing', 'resellingkori-connector' ),
			'shipped'    => __( 'Shipped', 'resellingkori-connector' ),
			'delivered'  => __( 'Delivered', 'resellingkori-connector' ),
			'returned'   => __( 'Returned', 'resellingkori-connector' ),
			'cancelled'  => __( 'Cancelled', 'resellingkori-connector' ),
		);
	}

	public static function sanitize( $input ) {
		$old   = self::get();
		$input = is_array( $input ) ? $input : array();
		$out   = $old;
		$valid = array_keys( self::statuses() );

		if ( isset( $input['api_base'] ) ) {
			$base            = esc_url_raw( trim( (string) $input['api_base'] ) );
			$out['api_base'] = $base ? untrailingslashit( $base ) : $old['api_base'];
		}
		if ( isset( $input['key_id'] ) ) {
			$out['key_id'] = sanitize_text_field( $input['key_id'] );
		}
		// A blank secret field means "keep the stored secret".
		if ( isset( $input['secret'] ) && '' !== trim( (string) $input['secret'] ) ) {
			$out['secret'] = sanitize_text_field( $input['secret'] );
		}
		if ( isset( $input['agent_email'] ) ) {
			$out['agent_email'] = sanitize_email( (string) $input['agent_email'] );
		}
		if ( isset( $input['send_statuses'] ) ) {
			$out['send_statuses'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $input['send_statuses'] ), $valid ) );
		}
		if ( isset( $input['after_send'] ) ) {
			$s                 = sanitize_key( $input['after_send'] );
			$out['after_send'] = in_array( $s, $valid, true ) ? $s : '';
		}
		if ( isset( $input['cod_gateways'] ) ) {
			$ids                 = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', (string) $input['cod_gateways'] ) ) );
			$out['cod_gateways'] = implode( ',', $ids );
		}
		if ( isset( $input['sku_safety_check'] ) ) {
			$out['sku_safety_check'] = 'yes' === $input['sku_safety_check'] ? 'yes' : 'no';
		}
		if ( isset( $input['debug'] ) ) {
			$out['debug'] = 'yes' === $input['debug'] ? 'yes' : 'no';
		}
		if ( isset( $input['status_map'] ) && is_array( $input['status_map'] ) ) {
			foreach ( array_keys( self::rk_statuses() ) as $rk ) {
				$v                        = isset( $input['status_map'][ $rk ] ) ? sanitize_key( $input['status_map'][ $rk ] ) : '';
				$out['status_map'][ $rk ] = in_array( $v, $valid, true ) ? $v : '';
			}
		}
		return $out;
	}

	/**
	 * Settings tab markup.
	 */
	public static function render() {
		$o          = self::get();
		$statuses   = self::statuses();
		$has_secret = '' !== $o['secret'];
		$name       = self::OPTION;
		?>
		<form method="post" action="options.php" class="rkc-settings-form">
			<?php settings_fields( 'rkc_settings_group' ); ?>

			<h2><?php esc_html_e( 'Connection', 'resellingkori-connector' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rkc_api_base"><?php esc_html_e( 'ResellingKori URL', 'resellingkori-connector' ); ?></label></th>
					<td>
						<input type="url" class="regular-text" id="rkc_api_base" name="<?php echo esc_attr( $name ); ?>[api_base]" value="<?php echo esc_attr( $o['api_base'] ); ?>" />
						<p class="description"><?php esc_html_e( 'The site that fulfils your orders. Normally https://resellingkori.com', 'resellingkori-connector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rkc_key_id"><?php esc_html_e( 'API key', 'resellingkori-connector' ); ?></label></th>
					<td><input type="text" class="regular-text" id="rkc_key_id" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[key_id]" value="<?php echo esc_attr( $o['key_id'] ); ?>" placeholder="rkk_xxxxxxxxxxxxxxxx" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="rkc_secret"><?php esc_html_e( 'API secret', 'resellingkori-connector' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="rkc_secret" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[secret]" value="" placeholder="<?php echo $has_secret ? esc_attr( '••••••••••••••••' ) : 'rks_...'; ?>" />
						<p class="description">
							<?php echo $has_secret ? esc_html__( 'A secret is saved. Leave blank to keep it.', 'resellingkori-connector' ) : esc_html__( 'Paste the secret ResellingKori gave you.', 'resellingkori-connector' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rkc_agent_email"><?php esc_html_e( 'Sales agent email (Gmail)', 'resellingkori-connector' ); ?></label></th>
					<td>
						<input type="email" class="regular-text" id="rkc_agent_email" name="<?php echo esc_attr( $name ); ?>[agent_email]" value="<?php echo esc_attr( $o['agent_email'] ); ?>" placeholder="agent@gmail.com" />
						<p class="description"><?php esc_html_e( 'Your ResellingKori sales-agent email. Orders from this store are assigned to the agent linked to your API key; this field is a safety check: if it does not match, ResellingKori refuses the order instead of crediting the wrong agent. Leave empty to skip the check.', 'resellingkori-connector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test', 'resellingkori-connector' ); ?></th>
					<td>
						<button type="button" class="button" id="rkc-test"><?php esc_html_e( 'Test connection', 'resellingkori-connector' ); ?></button>
						<span id="rkc-test-result" class="rkc-test-result"></span>
						<p class="description"><?php esc_html_e( 'Save your changes first, then test.', 'resellingkori-connector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status webhook URL', 'resellingkori-connector' ); ?></th>
					<td>
						<code><?php echo esc_html( rest_url( 'rkc/v1/webhook' ) ); ?></code>
						<p class="description"><?php esc_html_e( 'ResellingKori pushes status and tracking updates here automatically. It must be reachable from the internet. A 15-minute polling fallback also runs.', 'resellingkori-connector' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Sending orders', 'resellingkori-connector' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Allow sending when order is', 'resellingkori-connector' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[send_statuses][]" value="" />
						<?php foreach ( $statuses as $slug => $label ) : ?>
							<label class="rkc-check">
								<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[send_statuses][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $o['send_statuses'], true ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rkc_after_send"><?php esc_html_e( 'After a successful send', 'resellingkori-connector' ); ?></label></th>
					<td>
						<select id="rkc_after_send" name="<?php echo esc_attr( $name ); ?>[after_send]">
							<option value=""><?php esc_html_e( '— Leave order status unchanged —', 'resellingkori-connector' ); ?></option>
							<?php foreach ( $statuses as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $o['after_send'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rkc_cod"><?php esc_html_e( 'Cash-on-delivery gateway IDs', 'resellingkori-connector' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="rkc_cod" name="<?php echo esc_attr( $name ); ?>[cod_gateways]" value="<?php echo esc_attr( $o['cod_gateways'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Comma separated. Orders paid with these gateways are sent with the full order total as the amount to collect. Everything else is sent as prepaid (collect 0).', 'resellingkori-connector' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Product matching', 'resellingkori-connector' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'SKU safety check', 'resellingkori-connector' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[sku_safety_check]" value="no" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sku_safety_check]" value="yes" <?php checked( 'yes', $o['sku_safety_check'] ); ?> />
							<?php esc_html_e( 'Do not bulk auto-link an exact SKU match when the product names look unrelated (they are listed for manual review instead).', 'resellingkori-connector' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Status sync', 'resellingkori-connector' ); ?></h2>
			<p class="description"><?php esc_html_e( 'When ResellingKori reports a new status, set your WooCommerce order to:', 'resellingkori-connector' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( self::rk_statuses() as $rk => $rk_label ) : ?>
					<tr>
						<th scope="row"><label for="rkc_map_<?php echo esc_attr( $rk ); ?>"><?php echo esc_html( $rk_label ); ?></label></th>
						<td>
							<select id="rkc_map_<?php echo esc_attr( $rk ); ?>" name="<?php echo esc_attr( $name ); ?>[status_map][<?php echo esc_attr( $rk ); ?>]">
								<option value=""><?php esc_html_e( '— Do not change (add an order note only) —', 'resellingkori-connector' ); ?></option>
								<?php foreach ( $statuses as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $o['status_map'][ $rk ], $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Advanced', 'resellingkori-connector' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Debug logging', 'resellingkori-connector' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[debug]" value="no" />
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[debug]" value="yes" <?php checked( 'yes', $o['debug'] ); ?> />
							<?php esc_html_e( 'Log API activity to WooCommerce > Status > Logs (source: resellingkori-connector).', 'resellingkori-connector' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}
}
