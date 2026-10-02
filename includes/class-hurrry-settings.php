<?php
/**
 * Admin settings for the Hurrry checkout.
 *
 * @package hurrry-checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hurrry_Settings {

	private static $instance = null;

	/**
	 * Defaults mirror DEFAULT_CFG in hurry.js so the JS behaves the same
	 * whether or not the store owner ever opened the settings screen.
	 */
	const DEFAULTS = array(
		'enabled'             => 'yes',
		'endpoint'            => '',
		'default_lang'        => '',
		'google_ads_id'       => '',
		'notes'               => 'WooCommerce Cart Inline',
		'fallback_to_store'   => 'no',
		'iframe'              => 'no',
		'debug'               => 'no',
		'preload_debounce_ms' => 200,
		'fetch_timeout_ms'    => 8000,
		'retries'             => 5,
		'extra_selectors'     => '',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * All settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		$stored = get_option( HURRRY_OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::DEFAULTS, $stored );
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value when the setting is missing or empty.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::get_all();
		if ( array_key_exists( $key, $all ) && '' !== $all[ $key ] ) {
			return $all[ $key ];
		}
		return $fallback;
	}

	public static function is_yes( $key ) {
		return 'yes' === self::get( $key, 'no' );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Hurrry Checkout', 'hurrry-checkout' ),
			__( 'Hurrry Checkout', 'hurrry-checkout' ),
			'manage_woocommerce',
			'hurrry-checkout',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'hurrry_settings_group',
			HURRRY_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::DEFAULTS,
			)
		);
	}

	/**
	 * @param array $input Raw posted settings.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::DEFAULTS;

		$out['enabled']           = isset( $input['enabled'] ) ? 'yes' : 'no';
		$out['fallback_to_store'] = isset( $input['fallback_to_store'] ) ? 'yes' : 'no';
		$out['iframe']            = isset( $input['iframe'] ) ? 'yes' : 'no';
		$out['debug']             = isset( $input['debug'] ) ? 'yes' : 'no';

		$out['endpoint']      = isset( $input['endpoint'] ) ? esc_url_raw( trim( $input['endpoint'] ) ) : '';
		$out['default_lang']  = isset( $input['default_lang'] ) ? sanitize_text_field( $input['default_lang'] ) : '';
		$out['google_ads_id'] = isset( $input['google_ads_id'] ) ? sanitize_text_field( $input['google_ads_id'] ) : '';
		$out['notes']         = isset( $input['notes'] ) ? sanitize_text_field( $input['notes'] ) : self::DEFAULTS['notes'];

		$out['preload_debounce_ms'] = isset( $input['preload_debounce_ms'] ) ? max( 0, absint( $input['preload_debounce_ms'] ) ) : self::DEFAULTS['preload_debounce_ms'];
		$out['fetch_timeout_ms']    = isset( $input['fetch_timeout_ms'] ) ? max( 1000, absint( $input['fetch_timeout_ms'] ) ) : self::DEFAULTS['fetch_timeout_ms'];
		$out['retries']             = isset( $input['retries'] ) ? min( 20, absint( $input['retries'] ) ) : self::DEFAULTS['retries'];

		$out['extra_selectors'] = isset( $input['extra_selectors'] ) ? sanitize_textarea_field( $input['extra_selectors'] ) : '';

		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s   = self::get_all();
		$key = HURRRY_OPTION_KEY;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Hurrry Checkout', 'hurrry-checkout' ); ?></h1>
			<p><?php esc_html_e( 'Intercepts the Proceed to checkout buttons and sends the cart to the Hurrry hosted checkout.', 'hurrry-checkout' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'hurrry_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled', 'hurrry-checkout' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[enabled]" value="yes" <?php checked( $s['enabled'], 'yes' ); ?> />
								<?php esc_html_e( 'Redirect the checkout through Hurrry', 'hurrry-checkout' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hurrry-endpoint"><?php esc_html_e( 'Endpoint (required)', 'hurrry-checkout' ); ?></label></th>
						<td>
							<input type="url" id="hurrry-endpoint" class="regular-text code" name="<?php echo esc_attr( $key ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://checkout.example.com/api/orders" />
							<p class="description"><?php esc_html_e( 'URL that receives the cart payload and returns the hosted checkout URL.', 'hurrry-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hurrry-lang"><?php esc_html_e( 'Default language', 'hurrry-checkout' ); ?></label></th>
						<td>
							<input type="text" id="hurrry-lang" class="small-text" name="<?php echo esc_attr( $key ); ?>[default_lang]" value="<?php echo esc_attr( $s['default_lang'] ); ?>" placeholder="es" />
							<p class="description"><?php esc_html_e( 'Appended to the returned URL as defaultLang. Leave empty to let the checkout decide.', 'hurrry-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hurrry-ads"><?php esc_html_e( 'Google Ads ID', 'hurrry-checkout' ); ?></label></th>
						<td>
							<input type="text" id="hurrry-ads" class="regular-text" name="<?php echo esc_attr( $key ); ?>[google_ads_id]" value="<?php echo esc_attr( $s['google_ads_id'] ); ?>" placeholder="AW-123456789" />
							<p class="description"><?php esc_html_e( 'Enables the cross-domain linker towards the checkout host.', 'hurrry-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hurrry-notes"><?php esc_html_e( 'Order notes', 'hurrry-checkout' ); ?></label></th>
						<td>
							<input type="text" id="hurrry-notes" class="regular-text" name="<?php echo esc_attr( $key ); ?>[notes]" value="<?php echo esc_attr( $s['notes'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Sent verbatim as the notes field of the payload.', 'hurrry-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Behaviour', 'hurrry-checkout' ); ?></th>
						<td>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[fallback_to_store]" value="yes" <?php checked( $s['fallback_to_store'], 'yes' ); ?> />
								<?php esc_html_e( 'Fall back to the WooCommerce checkout when no URL is preloaded', 'hurrry-checkout' ); ?>
							</label>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[iframe]" value="yes" <?php checked( $s['iframe'], 'yes' ); ?> />
								<?php esc_html_e( 'Open the checkout inside a full-screen iframe instead of redirecting', 'hurrry-checkout' ); ?>
							</label>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[debug]" value="yes" <?php checked( $s['debug'], 'yes' ); ?> />
								<?php esc_html_e( 'Verbose console logging', 'hurrry-checkout' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Network', 'hurrry-checkout' ); ?></th>
						<td>
							<label style="display:block">
								<?php esc_html_e( 'Preload debounce (ms)', 'hurrry-checkout' ); ?>
								<input type="number" min="0" step="50" class="small-text" name="<?php echo esc_attr( $key ); ?>[preload_debounce_ms]" value="<?php echo esc_attr( $s['preload_debounce_ms'] ); ?>" />
							</label>
							<label style="display:block">
								<?php esc_html_e( 'Fetch timeout (ms)', 'hurrry-checkout' ); ?>
								<input type="number" min="1000" step="500" class="small-text" name="<?php echo esc_attr( $key ); ?>[fetch_timeout_ms]" value="<?php echo esc_attr( $s['fetch_timeout_ms'] ); ?>" />
							</label>
							<label style="display:block">
								<?php esc_html_e( 'Retries', 'hurrry-checkout' ); ?>
								<input type="number" min="0" max="20" class="small-text" name="<?php echo esc_attr( $key ); ?>[retries]" value="<?php echo esc_attr( $s['retries'] ); ?>" />
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hurrry-selectors"><?php esc_html_e( 'Extra checkout selectors', 'hurrry-checkout' ); ?></label></th>
						<td>
							<textarea id="hurrry-selectors" class="large-text code" rows="4" name="<?php echo esc_attr( $key ); ?>[extra_selectors]" placeholder=".my-theme__checkout-btn"><?php echo esc_textarea( $s['extra_selectors'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One CSS selector per line, added to the built-in WooCommerce selectors.', 'hurrry-checkout' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
