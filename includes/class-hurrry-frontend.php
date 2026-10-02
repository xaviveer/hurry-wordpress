<?php
/**
 * Loads and configures the front-end script.
 *
 * @package hurrry-checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hurrry_Frontend {

	private static $instance = null;

	/**
	 * WooCommerce equivalents of the Shopify selectors shipped in hurry.js:
	 * classic cart, block cart and the usual mini-cart markup.
	 */
	const DEFAULT_SELECTORS = array(
		'.checkout-button',
		'.wc-proceed-to-checkout a',
		'.wc-block-cart__submit-button',
		'.wc-block-mini-cart__footer-checkout',
		'.woocommerce-mini-cart__buttons a.checkout',
		'a.button.checkout',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
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

		// Nothing to intercept once the shopper is already on the native
		// checkout, and intercepting there would fight the fallback.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return;
		}

		wp_register_script(
			'hurrry-checkout',
			HURRRY_PLUGIN_URL . 'assets/js/hurrry.js',
			array(),
			HURRRY_VERSION,
			true
		);

		wp_add_inline_script(
			'hurrry-checkout',
			'window.HurrryConfig = ' . wp_json_encode( $this->build_config( $endpoint ) ) . ';',
			'before'
		);

		wp_enqueue_script( 'hurrry-checkout' );
	}

	/**
	 * @param string $endpoint Configured endpoint.
	 * @return array
	 */
	private function build_config( $endpoint ) {
		$config = array(
			'endpoint'          => $endpoint,
			'defaultLang'       => Hurrry_Settings::get( 'default_lang', null ),
			'googleAdsId'       => Hurrry_Settings::get( 'google_ads_id', null ),
			'notes'             => (string) Hurrry_Settings::get( 'notes', 'WooCommerce Cart Inline' ),
			'fallbackToStore'   => Hurrry_Settings::is_yes( 'fallback_to_store' ),
			'iframe'            => Hurrry_Settings::is_yes( 'iframe' ),
			'debug'             => Hurrry_Settings::is_yes( 'debug' ),
			'preloadDebounceMs' => (int) Hurrry_Settings::get( 'preload_debounce_ms', 200 ),
			'fetchTimeoutMs'    => (int) Hurrry_Settings::get( 'fetch_timeout_ms', 8000 ),
			'retries'           => (int) Hurrry_Settings::get( 'retries', 5 ),
			'selectors'         => $this->selectors(),
			// Tried in order. WooCommerce AJAX first: it is a plain front-end
			// request, so the session and the cart are the shopper's real ones.
			// The REST route is only a fallback, since the REST API drops the
			// auth cookie without a nonce and caches like to strip cookies off
			// /wp-json/ responses.
			'cartUrls'          => array(
				Hurrry_Cart_Rest::ajax_url(),
				Hurrry_Cart_Rest::cart_url(),
				Hurrry_Cart_Rest::admin_ajax_url(),
			),
			'cartUrl'           => Hurrry_Cart_Rest::ajax_url(),
			'restNonce'         => wp_create_nonce( 'wp_rest' ),
			'cartBootstrap'     => $this->inline_cart(),
			'checkoutUrl'       => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ),
			'cartPageUrl'       => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
			'shopUrl'           => wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		return apply_filters( 'hurrry_frontend_config', $config );
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
