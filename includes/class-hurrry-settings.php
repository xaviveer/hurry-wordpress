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

	const TEST_TRANSIENT = 'hurrry_endpoint_test';

	private static $instance = null;

	/**
	 * Defaults mirror DEFAULT_CFG in hurrry.js so the JS behaves the same
	 * whether or not the store owner ever opened the settings screen.
	 */
	const DEFAULTS = array(
		'enabled'                 => 'yes',
		'endpoint'                => '',
		'default_lang'            => '',
		'google_ads_id'           => '',
		'meta_pixel_id'           => '',
		'notes'                   => 'WooCommerce Cart Inline',
		// Protections. All on by default: the plugin exists so the WooCommerce
		// checkout is never seen.
		'server_gate'             => 'yes',
		'lock_gateways'           => 'yes',
		'hide_express'            => 'yes',
		'overlay'                 => 'yes',
		'track_initiate_checkout' => 'no',
		'fallback_to_store'       => 'no',
		'iframe'                  => 'no',
		'debug'                   => 'no',
		'preload_debounce_ms'     => 200,
		'fetch_timeout_ms'        => 8000,
		'retries'                 => 5,
		'extra_selectors'         => '',
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
		add_action( 'admin_post_hurrry_clear_log', array( $this, 'action_clear_log' ) );
		add_action( 'admin_post_hurrry_purge_caches', array( $this, 'action_purge_caches' ) );
		add_action( 'admin_post_hurrry_test_endpoint', array( $this, 'action_test_endpoint' ) );

		// Any settings change invalidates every cached page that embedded the
		// old configuration (or no script at all).
		add_action( 'update_option_' . HURRRY_OPTION_KEY, array( __CLASS__, 'purge_caches' ), 10, 0 );
		add_action( 'add_option_' . HURRRY_OPTION_KEY, array( __CLASS__, 'purge_caches' ), 10, 0 );
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

		foreach ( array( 'enabled', 'server_gate', 'lock_gateways', 'hide_express', 'overlay', 'track_initiate_checkout', 'fallback_to_store', 'iframe', 'debug' ) as $flag ) {
			$out[ $flag ] = isset( $input[ $flag ] ) ? 'yes' : 'no';
		}

		$out['endpoint']      = isset( $input['endpoint'] ) ? esc_url_raw( trim( $input['endpoint'] ) ) : '';
		$out['default_lang']  = isset( $input['default_lang'] ) ? sanitize_text_field( $input['default_lang'] ) : '';
		$out['google_ads_id'] = isset( $input['google_ads_id'] ) ? sanitize_text_field( $input['google_ads_id'] ) : '';
		$out['meta_pixel_id'] = isset( $input['meta_pixel_id'] ) ? preg_replace( '/\D+/', '', (string) $input['meta_pixel_id'] ) : '';
		$out['notes']         = isset( $input['notes'] ) ? sanitize_text_field( $input['notes'] ) : self::DEFAULTS['notes'];

		$out['preload_debounce_ms'] = isset( $input['preload_debounce_ms'] ) ? max( 0, absint( $input['preload_debounce_ms'] ) ) : self::DEFAULTS['preload_debounce_ms'];
		$out['fetch_timeout_ms']    = isset( $input['fetch_timeout_ms'] ) ? max( 1000, absint( $input['fetch_timeout_ms'] ) ) : self::DEFAULTS['fetch_timeout_ms'];
		$out['retries']             = isset( $input['retries'] ) ? min( 20, absint( $input['retries'] ) ) : self::DEFAULTS['retries'];

		$out['extra_selectors'] = isset( $input['extra_selectors'] ) ? sanitize_textarea_field( $input['extra_selectors'] ) : '';

		return $out;
	}

	// ------------------------------------------------------------------
	// Actions
	// ------------------------------------------------------------------

	public function action_clear_log() {
		$this->check_action( 'hurrry_clear_log' );
		Hurrry_Gate::clear_log();
		$this->back( 'log-cleared' );
	}

	public function action_purge_caches() {
		$this->check_action( 'hurrry_purge_caches' );
		$purged = self::purge_caches();
		$this->back( 'purged', (string) $purged );
	}

	/**
	 * Can this server reach the endpoint host at all? Hosts that block
	 * outbound HTTP make the server-side handoff fall through to the
	 * interstitial; this tells the owner so before a shopper does.
	 */
	public function action_test_endpoint() {
		$this->check_action( 'hurrry_test_endpoint' );

		$endpoint = (string) self::get( 'endpoint', '' );
		$result   = array(
			't'  => time(),
			'ok' => false,
		);

		if ( '' === $endpoint ) {
			$result['msg'] = 'No endpoint configured.';
		} else {
			$started  = microtime( true );
			$response = wp_remote_request(
				$endpoint,
				array(
					'method'      => 'OPTIONS',
					'timeout'     => 8,
					'redirection' => 2,
					'headers'     => array( 'Accept' => 'application/json' ),
				)
			);
			$ms = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( is_wp_error( $response ) ) {
				$result['msg'] = 'Unreachable from this server: ' . $response->get_error_message() . ' (' . $ms . ' ms)';
			} else {
				$code          = (int) wp_remote_retrieve_response_code( $response );
				$result['ok']  = $code > 0 && $code < 500;
				$result['msg'] = sprintf( 'Reachable, HTTP %d in %d ms.', $code, $ms );
			}
		}

		set_transient( self::TEST_TRANSIENT, $result, DAY_IN_SECONDS );
		$this->back( 'tested' );
	}

	/**
	 * @param string $action Nonce action.
	 */
	private function check_action( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'hurrry-checkout' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * @param string $notice Notice key.
	 * @param string $extra  Extra value.
	 */
	private function back( $notice, $extra = '' ) {
		$url = add_query_arg(
			array(
				'page'          => 'hurrry-checkout',
				'hurrry_notice' => $notice,
				'hurrry_extra'  => $extra,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Flushes the page caches we know how to talk to. A cached page without
	 * the script, or with yesterday's configuration, is one of the ways a
	 * shopper on "the other device" ends up on the WooCommerce checkout.
	 *
	 * @return int Number of caches that were asked to purge.
	 */
	public static function purge_caches() {
		$n = 0;

		$calls = array(
			array( 'function', 'rocket_clean_domain' ),                       // WP Rocket.
			array( 'function', 'w3tc_flush_all' ),                            // W3 Total Cache.
			array( 'function', 'wp_cache_clear_cache' ),                      // WP Super Cache.
			array( 'action', 'litespeed_purge_all' ),                         // LiteSpeed Cache.
			array( 'function', 'sg_cachepress_purge_cache' ),                 // SiteGround Optimizer.
			array( 'function', 'wpfc_clear_all_cache' ),                      // WP Fastest Cache.
			array( 'action', 'cache_enabler_clear_complete_cache' ),          // Cache Enabler.
			array( 'action', 'breeze_clear_all_cache' ),                      // Breeze.
			array( 'action', 'wpo_cache_flush' ),                             // WP-Optimize.
			array( 'action', 'wphb_clear_page_cache' ),                       // Hummingbird.
			array( 'action', 'kinsta_cache_purge_all' ),                      // Kinsta.
			array( 'static', array( '\FlyingPress\Purge', 'purge_everything' ) ),
			array( 'static', array( 'Swift_Performance_Cache', 'clear_all_cache' ) ),
			array( 'static', array( 'WpeCommon', 'purge_varnish_cache' ) ),   // WP Engine.
			array( 'static', array( 'WpeCommon', 'purge_memcached' ) ),
			array( 'static', array( 'comet_cache', 'clear' ) ),
			array( 'function', 'nginx_helper_purge_all' ),                    // Nginx Helper (older).
			array( 'action', 'rt_nginx_helper_purge_all' ),                   // Nginx Helper.
			array( 'function', 'pantheon_wp_clear_edge_all' ),                // Pantheon.
		);

		foreach ( $calls as $call ) {
			try {
				if ( 'function' === $call[0] && function_exists( $call[1] ) ) {
					if ( 'wpfc_clear_all_cache' === $call[1] ) {
						call_user_func( $call[1], true );
					} else {
						call_user_func( $call[1] );
					}
					$n++;
				} elseif ( 'action' === $call[0] && has_action( $call[1] ) ) {
					do_action( $call[1] );
					$n++;
				} elseif ( 'static' === $call[0] && class_exists( $call[1][0] ) && method_exists( $call[1][0], $call[1][1] ) ) {
					call_user_func( $call[1] );
					$n++;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// A cache plugin throwing must never break saving settings.
			}
		}

		// Autoptimize keeps its own aggregated bundles.
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			try {
				autoptimizeCache::clearall();
				$n++;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		wp_cache_flush();

		return $n;
	}

	// ------------------------------------------------------------------
	// Screen
	// ------------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s   = self::get_all();
		$key = HURRRY_OPTION_KEY;

		$notice = isset( $_GET['hurrry_notice'] ) ? sanitize_key( wp_unslash( $_GET['hurrry_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$extra  = isset( $_GET['hurrry_extra'] ) ? sanitize_text_field( wp_unslash( $_GET['hurrry_extra'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$test   = get_transient( self::TEST_TRANSIENT );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Hurrry Checkout', 'hurrry-checkout' ); ?></h1>
			<p><?php esc_html_e( 'Sends every checkout to the Hurrry hosted checkout. The WooCommerce checkout page is never shown while the protections below are on.', 'hurrry-checkout' ); ?></p>

			<?php if ( 'log-cleared' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Diagnostics log cleared.', 'hurrry-checkout' ); ?></p></div>
			<?php elseif ( 'purged' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>
				<?php
				/* translators: %s: number of cache plugins purged */
				echo esc_html( sprintf( __( 'Caches purged (%s cache plugins found).', 'hurrry-checkout' ), $extra ) );
				?>
				</p></div>
			<?php elseif ( 'tested' === $notice && is_array( $test ) ) : ?>
				<div class="notice <?php echo ! empty( $test['ok'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $test['msg'] ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:100%;margin-top:12px">
				<h2 class="title"><?php esc_html_e( 'Status', 'hurrry-checkout' ); ?></h2>
				<table class="widefat striped" style="max-width:900px">
					<tbody>
						<tr><td style="width:220px"><?php esc_html_e( 'Plugin version', 'hurrry-checkout' ); ?></td><td><code><?php echo esc_html( HURRRY_VERSION ); ?></code></td></tr>
						<tr><td><?php esc_html_e( 'Store URL sent as shopUrl', 'hurrry-checkout' ); ?></td><td><code><?php echo esc_html( Hurrry_Payload::shop_url() ); ?></code> <span class="description"><?php esc_html_e( 'The store must be registered in Hurrry with exactly this value.', 'hurrry-checkout' ); ?></span></td></tr>
						<tr><td><?php esc_html_e( 'Endpoint', 'hurrry-checkout' ); ?></td><td>
							<?php if ( '' !== (string) $s['endpoint'] ) : ?>
								<code><?php echo esc_html( $s['endpoint'] ); ?></code>
								<?php if ( is_array( $test ) ) : ?>
									<br><span class="description"><?php echo esc_html( $test['msg'] ); ?> (<?php echo esc_html( human_time_diff( (int) $test['t'] ) ); ?> ago)</span>
								<?php endif; ?>
							<?php else : ?>
								<strong style="color:#b32d2e"><?php esc_html_e( 'Not configured: the plugin is inactive until an endpoint is set.', 'hurrry-checkout' ); ?></strong>
							<?php endif; ?>
						</td></tr>
						<tr><td><?php esc_html_e( 'Server-side guard', 'hurrry-checkout' ); ?></td><td><?php echo 'yes' === $s['server_gate'] && 'yes' === $s['enabled'] ? '<span style="color:#00a32a">&#9679; ' . esc_html__( 'Active', 'hurrry-checkout' ) . '</span>' : '<span style="color:#b32d2e">&#9679; ' . esc_html__( 'Off', 'hurrry-checkout' ) . '</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'Preview the WooCommerce checkout', 'hurrry-checkout' ); ?></td><td><span class="description"><?php esc_html_e( 'Store managers can open', 'hurrry-checkout' ); ?> <code><?php echo esc_html( add_query_arg( 'hurrry_bypass', '1', function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ) ) ); ?></code> <?php esc_html_e( 'to see the native checkout for one hour; the same URL with hurrry_bypass=0 ends it. Shoppers are never affected.', 'hurrry-checkout' ); ?></span></td></tr>
					</tbody>
				</table>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hurrry_test_endpoint' ), 'hurrry_test_endpoint' ) ); ?>"><?php esc_html_e( 'Test endpoint from this server', 'hurrry-checkout' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hurrry_purge_caches' ), 'hurrry_purge_caches' ) ); ?>"><?php esc_html_e( 'Purge page caches', 'hurrry-checkout' ); ?></a>
				</p>
			</div>

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
							<input type="url" id="hurrry-endpoint" class="regular-text code" name="<?php echo esc_attr( $key ); ?>[endpoint]" value="<?php echo esc_attr( $s['endpoint'] ); ?>" placeholder="https://app.hurrypayments.com/api/orders" />
							<p class="description"><?php esc_html_e( 'URL that receives the cart payload and returns the hosted checkout URL.', 'hurrry-checkout' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Protections', 'hurrry-checkout' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[server_gate]" value="yes" <?php checked( $s['server_gate'], 'yes' ); ?> />
								<strong><?php esc_html_e( 'Server-side guard', 'hurrry-checkout' ); ?></strong>
								<span class="description"><?php esc_html_e( '— any request to the checkout page is answered with a redirect to the Hurrry checkout, created on the server if the browser did not preload it. Works without JavaScript, with old browsers, with cached pages and with buttons the script never saw.', 'hurrry-checkout' ); ?></span>
							</label>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[lock_gateways]" value="yes" <?php checked( $s['lock_gateways'], 'yes' ); ?> />
								<strong><?php esc_html_e( 'Disable WooCommerce payment gateways on the storefront', 'hurrry-checkout' ); ?></strong>
								<span class="description"><?php esc_html_e( '— no gateway is offered to shoppers, so neither express buttons nor a surviving checkout can take a payment. The admin and the “pay for order” page keep theirs. Ignored while the store fallback below is on.', 'hurrry-checkout' ); ?></span>
							</label>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[hide_express]" value="yes" <?php checked( $s['hide_express'], 'yes' ); ?> />
								<strong><?php esc_html_e( 'Hide express payment buttons', 'hurrry-checkout' ); ?></strong>
								<span class="description"><?php esc_html_e( '— Apple Pay, Google Pay, PayPal, Amazon Pay, Klarna… on product, cart and mini-cart.', 'hurrry-checkout' ); ?></span>
							</label>
							<label style="display:block;margin-bottom:6px">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[overlay]" value="yes" <?php checked( $s['overlay'], 'yes' ); ?> />
								<strong><?php esc_html_e( 'Show a “taking you to the secure checkout” overlay on click', 'hurrry-checkout' ); ?></strong>
								<span class="description"><?php esc_html_e( '— immediate feedback, blocks double taps.', 'hurrry-checkout' ); ?></span>
							</label>
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
						<th scope="row"><label for="hurrry-meta"><?php esc_html_e( 'Meta Pixel ID', 'hurrry-checkout' ); ?></label></th>
						<td>
							<input type="text" id="hurrry-meta" class="regular-text" name="<?php echo esc_attr( $key ); ?>[meta_pixel_id]" value="<?php echo esc_attr( $s['meta_pixel_id'] ); ?>" placeholder="123456789012345" />
							<label style="display:block;margin-top:6px">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[track_initiate_checkout]" value="yes" <?php checked( $s['track_initiate_checkout'], 'yes' ); ?> />
								<?php esc_html_e( 'Fire InitiateCheckout when the shopper is sent to Hurrry', 'hurrry-checkout' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Optional. If the theme already loads the pixel, leave the ID empty: click ids and the visitor id are matched into the existing pixel anyway.', 'hurrry-checkout' ); ?></p>
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
								<?php esc_html_e( 'If Hurrry cannot be reached after every retry, show the WooCommerce checkout instead of an error page', 'hurrry-checkout' ); ?>
								<span class="description"><?php esc_html_e( '(turns the gateway lock off)', 'hurrry-checkout' ); ?></span>
							</label>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[iframe]" value="yes" <?php checked( $s['iframe'], 'yes' ); ?> />
								<?php esc_html_e( 'Open the checkout inside a full-screen iframe instead of redirecting', 'hurrry-checkout' ); ?>
								<span class="description"><?php esc_html_e( '(not recommended: Safari and Firefox block third-party storage inside iframes)', 'hurrry-checkout' ); ?></span>
							</label>
							<label style="display:block">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>[debug]" value="yes" <?php checked( $s['debug'], 'yes' ); ?> />
								<?php esc_html_e( 'Verbose logging (browser console and WooCommerce > Status > Logs, source hurrry-checkout)', 'hurrry-checkout' ); ?>
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
							<p class="description"><?php esc_html_e( 'One CSS selector per line, added to the built-in WooCommerce selectors. Links to the checkout URL are caught automatically; this is for buttons that navigate from JavaScript. Any element with data-hurrry-checkout is caught too.', 'hurrry-checkout' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php $this->render_diagnostics(); ?>
		</div>
		<?php
	}

	/**
	 * What the server guard did lately. A shopper that "saw the WooCommerce
	 * checkout" either appears here (and the line says what happened) or
	 * never reached the server, which is its own answer.
	 */
	private function render_diagnostics() {
		$log = Hurrry_Gate::recent_log();
		?>
		<h2><?php esc_html_e( 'Server guard diagnostics', 'hurrry-checkout' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Every arrival at the WooCommerce checkout URL that the server guard handled. "reused:browser" means the script had preloaded the URL and the server only redirected; "server" means the server created the order itself; "interstitial" means the endpoint failed from the server and the browser was asked to try.', 'hurrry-checkout' ); ?>
		</p>
		<?php if ( empty( $log ) ) : ?>
			<p><em><?php esc_html_e( 'Nothing yet. Shoppers whose browser handled the redirect never reach the server guard.', 'hurrry-checkout' ); ?></em></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1200px">
				<thead>
					<tr>
						<th style="width:120px"><?php esc_html_e( 'When', 'hurrry-checkout' ); ?></th>
						<th style="width:130px"><?php esc_html_e( 'Result', 'hurrry-checkout' ); ?></th>
						<th><?php esc_html_e( 'How it arrived', 'hurrry-checkout' ); ?></th>
						<th style="width:50px"><?php esc_html_e( 'Items', 'hurrry-checkout' ); ?></th>
						<th style="width:60px"><?php esc_html_e( 'ms', 'hurrry-checkout' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'User', 'hurrry-checkout' ); ?></th>
						<th><?php esc_html_e( 'Device', 'hurrry-checkout' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td title="<?php echo esc_attr( wp_date( 'Y-m-d H:i:s', (int) $row['t'] ) ); ?>"><?php echo esc_html( human_time_diff( (int) $row['t'] ) ); ?> ago</td>
						<td><code><?php echo esc_html( $row['result'] ); ?></code></td>
						<td><?php echo esc_html( $row['reason'] ); ?></td>
						<td><?php echo (int) $row['items']; ?></td>
						<td><?php echo (int) $row['ms']; ?></td>
						<td><?php echo esc_html( $row['user'] ); ?></td>
						<td style="font-size:11px;color:#50575e"><?php echo esc_html( $row['ua'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hurrry_clear_log' ), 'hurrry_clear_log' ) ); ?>"><?php esc_html_e( 'Clear log', 'hurrry-checkout' ); ?></a></p>
		<?php endif; ?>
		<?php
	}
}
