<?php
/**
 * Loads and configures the front-end script, and keeps JavaScript optimisers
 * and caches from delaying, combining or mangling it.
 *
 * @package hurrry-checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hurrry_Frontend {

	const HANDLE = 'hurrry-checkout';

	private static $instance = null;

	/**
	 * WooCommerce equivalents of the Shopify selectors shipped in hurry.js:
	 * classic cart, block cart, the usual mini-cart markup and the most common
	 * side-cart plugins. Links to the checkout URL are caught regardless of
	 * their class, so this list only matters for buttons that navigate from
	 * JavaScript.
	 */
	const DEFAULT_SELECTORS = array(
		'.checkout-button',
		'.wc-proceed-to-checkout a',
		'.wc-block-cart__submit-button',
		'.wc-block-mini-cart__footer-checkout',
		'.woocommerce-mini-cart__buttons a.checkout',
		'a.button.checkout',
		'a.checkout',
		'button[name="checkout"]',
		'input[name="checkout"]',
		'.xoo-wsc-ft-btn-checkout',
		'.fkcart-checkout--btn',
		'.elementor-button--checkout',
		'.cart-checkout-button',
		'[data-hurrry-checkout]',
	);

	/**
	 * Optimiser filters that take a list of patterns to leave alone. Every one
	 * of them gets "hurrry" so the script is neither delayed until the first
	 * interaction (which is after the click we need to catch), nor deferred
	 * behind the theme, nor merged into a bundle that fails as a whole.
	 */
	const EXCLUSION_FILTERS = array(
		// WP Rocket.
		'rocket_exclude_js',
		'rocket_exclude_defer_js',
		'rocket_delay_js_exclusions',
		'rocket_exclude_inline_js',
		'rocket_minify_excluded_external_js',
		// Autoptimize (comma separated string).
		'autoptimize_filter_js_exclude',
		'autoptimize_filter_js_dontaggregate',
		// LiteSpeed Cache.
		'litespeed_optimize_js_excludes',
		'litespeed_optm_js_defer_exc',
		'litespeed_optm_gm_js_exc',
		'litespeed_optm_js_delay_exc',
		// Perfmatters.
		'perfmatters_delay_js_exclusions',
		'perfmatters_defer_js_exclusions',
		'perfmatters_minify_js_exclusions',
		// SiteGround Optimizer.
		'sgo_js_minify_exclude',
		'sgo_javascript_combine_exclude',
		'sgo_js_async_exclude',
		// FlyingPress.
		'flying_press_exclude_from_delay',
		'flying_press_exclude_from_defer',
		'flying_press_exclude_from_minify',
		// WP-Optimize.
		'wp_optimize_minify_default_exclusions',
		// Breeze.
		'breeze_filter_js_exclude',
		// Hummingbird.
		'wphb_dont_combine_handles',
		'wphb_dont_minify_handles',
		'wphb_dont_defer_handles',
		// W3 Total Cache.
		'w3tc_minify_js_do_tag_minification',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 5 );
		add_filter( 'script_loader_tag', array( $this, 'script_tag' ), 10, 3 );
		add_filter( 'wp_inline_script_attributes', array( $this, 'inline_script_attributes' ), 10, 2 );

		foreach ( self::EXCLUSION_FILTERS as $filter ) {
			add_filter( $filter, array( $this, 'exclude_from_optimisers' ), 9999 );
		}
	}

	public function enqueue() {
		if ( is_admin() ) {
			return;
		}
		if ( ! Hurrry_Settings::is_yes( 'enabled' ) ) {
			return;
		}

		$endpoint = (string) Hurrry_Settings::get( 'endpoint', '' );
		if ( '' === $endpoint ) {
			return;
		}

		// The checkout page only renders when the server guard let it through
		// (store fallback, free cart, manager bypass): nothing to intercept
		// there, and intercepting would fight the page the shopper is on.
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) ) {
			return;
		}

		wp_register_script(
			self::HANDLE,
			HURRRY_PLUGIN_URL . 'assets/js/hurrry.js',
			array(),
			HURRRY_VERSION,
			true
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.HurrryConfig = ' . wp_json_encode( $this->build_config( $endpoint ) ) . ';',
			'before'
		);

		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Attributes that the common optimisers and Cloudflare Rocket Loader
	 * honour as "do not touch".
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 * @param string $src    Source.
	 * @return string
	 */
	public function script_tag( $tag, $handle, $src ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		return str_replace( '<script ', '<script ' . self::protect_attributes() . ' ', $tag );
	}

	/**
	 * Same attributes for the inline configuration (WordPress 5.7+).
	 *
	 * @param array  $attributes Attributes.
	 * @param string $javascript Script content.
	 * @return array
	 */
	public function inline_script_attributes( $attributes, $javascript ) {
		if ( false === strpos( (string) $javascript, 'window.HurrryConfig' ) ) {
			return $attributes;
		}
		$attributes['data-cfasync']     = 'false';
		$attributes['nowprocket']       = '';
		$attributes['data-no-optimize'] = '1';
		$attributes['data-no-defer']    = '1';
		$attributes['data-no-minify']   = '1';
		$attributes['data-noptimize']   = '1';
		$attributes['data-wpfc-render'] = 'false';
		return $attributes;
	}

	/**
	 * @return string
	 */
	private static function protect_attributes() {
		return 'data-cfasync="false" nowprocket data-no-optimize="1" data-no-defer="1" data-no-minify="1" data-noptimize="1" data-wpfc-render="false" data-pagespeed-no-defer';
	}

	/**
	 * Adds our handle/filename to whatever shape the optimiser's exclusion
	 * list takes (array of patterns, comma separated string, or a boolean per
	 * tag as in W3 Total Cache).
	 *
	 * @param mixed $value Current exclusions.
	 * @return mixed
	 */
	public function exclude_from_optimisers( $value ) {
		$filter = current_filter();

		if ( 'w3tc_minify_js_do_tag_minification' === $filter ) {
			// ( bool $do, string $script_tag, string $file ) — only the tag is of interest.
			$args = func_get_args();
			$tag  = isset( $args[1] ) ? (string) $args[1] : '';
			return false !== strpos( $tag, 'hurrry' ) ? false : $value;
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value ) ? 'hurrry' : $value . ',hurrry';
		}
		if ( is_array( $value ) ) {
			foreach ( array( 'hurrry', 'hurrry.js', 'HurrryConfig', self::HANDLE ) as $pattern ) {
				if ( ! in_array( $pattern, $value, true ) ) {
					$value[] = $pattern;
				}
			}
			return $value;
		}
		return $value;
	}

	/**
	 * @param string $endpoint Configured endpoint.
	 * @return array
	 */
	private function build_config( $endpoint ) {
		$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
		$cart_url     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );

		$config = array(
			'version'               => HURRRY_VERSION,
			'endpoint'              => $endpoint,
			'defaultLang'           => Hurrry_Settings::get( 'default_lang', null ),
			'googleAdsId'           => Hurrry_Settings::get( 'google_ads_id', null ),
			'metaPixelId'           => Hurrry_Settings::get( 'meta_pixel_id', null ),
			'trackInitiateCheckout' => Hurrry_Settings::is_yes( 'track_initiate_checkout' ),
			'notes'                 => (string) Hurrry_Settings::get( 'notes', 'WooCommerce Cart Inline' ),
			'fallbackToStore'       => Hurrry_Settings::is_yes( 'fallback_to_store' ),
			'serverGate'            => Hurrry_Settings::is_yes( 'server_gate' ),
			'overlay'               => Hurrry_Settings::is_yes( 'overlay' ),
			'iframe'                => Hurrry_Settings::is_yes( 'iframe' ),
			'debug'                 => Hurrry_Settings::is_yes( 'debug' ),
			'installPing'           => true,
			'collectPii'            => true,
			'pixelSettleMs'         => 1200,
			'preloadDebounceMs'     => (int) Hurrry_Settings::get( 'preload_debounce_ms', 200 ),
			'fetchTimeoutMs'        => (int) Hurrry_Settings::get( 'fetch_timeout_ms', 8000 ),
			'retries'               => (int) Hurrry_Settings::get( 'retries', 5 ),
			'clickWaitMs'           => 7000,
			'selectors'             => $this->selectors(),
			// Tried in order. WooCommerce AJAX first: it is a plain front-end
			// request, so the session and the cart are the shopper's real ones.
			// The REST route is only a fallback, since the REST API drops the
			// auth cookie without a nonce and caches like to strip cookies off
			// /wp-json/ responses.
			'cartUrls'              => array(
				Hurrry_Cart_Rest::ajax_url(),
				Hurrry_Cart_Rest::cart_url(),
				Hurrry_Cart_Rest::admin_ajax_url(),
			),
			'cartUrl'               => Hurrry_Cart_Rest::ajax_url(),
			'restNonce'             => wp_create_nonce( 'wp_rest' ),
			'cartBootstrap'         => $this->inline_cart(),
			'checkoutUrl'           => $checkout_url,
			'checkoutPaths'         => $this->checkout_paths( $checkout_url ),
			'checkoutPageId'        => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0,
			'cartPageUrl'           => $cart_url,
			'shopUrl'               => Hurrry_Payload::shop_url(),
			'customer'              => $this->customer(),
			'i18n'                  => Hurrry_Gate::texts(),
		);

		return apply_filters( 'hurrry_frontend_config', $config );
	}

	/**
	 * Every path that renders the checkout page: the configured permalink,
	 * its translations under WPML / Polylang, and the plain /checkout slug.
	 * The script treats a link to any of them as a checkout link.
	 *
	 * @param string $checkout_url Current-language checkout URL.
	 * @return array
	 */
	private function checkout_paths( $checkout_url ) {
		$paths = array( '/checkout' );

		$add = function ( $url ) use ( &$paths ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			$path = '/' . trim( (string) $path, '/' );
			if ( '/' !== $path && ! in_array( $path, $paths, true ) ) {
				$paths[] = $path;
			}
		};

		$add( $checkout_url );

		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		if ( $page_id > 0 ) {
			// WPML.
			$languages = apply_filters( 'wpml_active_languages', null );
			if ( is_array( $languages ) ) {
				foreach ( array_keys( $languages ) as $code ) {
					$translated = apply_filters( 'wpml_object_id', $page_id, 'page', false, $code );
					if ( $translated ) {
						$add( get_permalink( (int) $translated ) );
					}
				}
			}
			// Polylang.
			if ( function_exists( 'pll_get_post_translations' ) ) {
				foreach ( (array) pll_get_post_translations( $page_id ) as $translated ) {
					if ( $translated ) {
						$add( get_permalink( (int) $translated ) );
					}
				}
			}
		}

		return array_values( array_unique( apply_filters( 'hurrry_checkout_paths', $paths ) ) );
	}

	/**
	 * Email and phone of a logged-in customer, for Meta Advanced Matching.
	 * Pages with a logged-in user are not served from page caches, so this
	 * never ends up in HTML handed to somebody else.
	 *
	 * @return array|null
	 */
	private function customer() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$user = wp_get_current_user();
		$out  = array();
		if ( $user && $user->user_email ) {
			$out['email'] = $user->user_email;
		}
		$phone = get_user_meta( get_current_user_id(), 'billing_phone', true );
		if ( $phone ) {
			$out['phone'] = (string) $phone;
		}
		return $out ? $out : null;
	}

	/**
	 * Server-rendered cart snapshot, so the first preload needs no request at
	 * all and cannot be defeated by whatever sits between the browser and PHP.
	 *
	 * Only emitted on pages that caches are guaranteed to skip: printing a
	 * shopper's cart into a page that a cache could hand to somebody else would
	 * turn a stale snapshot into a wrong order. WooCommerce defines
	 * DONOTCACHEPAGE on cart, checkout and account pages, which is exactly the
	 * set of pages where this is safe.
	 *
	 * @return array|null
	 */
	private function inline_cart() {
		$cacheable = ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
		if ( $cacheable && ! ( function_exists( 'is_cart' ) && is_cart() ) ) {
			return null;
		}

		if ( function_exists( 'wc_load_cart' ) && null === WC()->cart ) {
			wc_load_cart();
		}
		if ( null === WC()->cart ) {
			return null;
		}

		return Hurrry_Cart_Rest::instance()->build_cart_payload( 'inline' );
	}

	/**
	 * @return array
	 */
	private function selectors() {
		$selectors = self::DEFAULT_SELECTORS;

		$extra = (string) Hurrry_Settings::get( 'extra_selectors', '' );
		foreach ( preg_split( '/\r\n|\r|\n/', $extra ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$selectors[] = $line;
			}
		}

		return array_values( array_unique( apply_filters( 'hurrry_checkout_selectors', $selectors ) ) );
	}
}
