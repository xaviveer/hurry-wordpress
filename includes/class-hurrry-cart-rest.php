<?php
/**
 * Exposes the WooCommerce cart with the same shape Shopify serves on /cart.js,
 * so the front-end payload builder is a literal port of hurry.js.
 *
 * The cart is served over two transports:
 *
 *  - WooCommerce AJAX ( /?wc-ajax=hurrry_get_cart ), the primary one. It is a
 *    normal front-end request, so WooCommerce itself boots the session and the
 *    cart exactly as it does for add-to-cart, sends its own nocache headers and
 *    sets DONOTCACHEPAGE. No REST authentication rules are involved.
 *  - The REST route ( /wp-json/hurrry/v1/cart ), kept as a fallback and for
 *    debugging.
 *
 * The REST route alone was not enough: the REST API drops the auth cookie when
 * a request carries no X-WP-Nonce, WooCommerce does not boot its session there,
 * and page/CDN caches happily serve /wp-json/ responses stripped of the
 * shopper's cookies. Any of those yields an empty cart.
 *
 * @package hurrry-checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hurrry_Cart_Rest {

	const NAMESPACE_V1 = 'hurrry/v1';
	const ROUTE_CART   = '/cart';
	const AJAX_ACTION  = 'hurrry_get_cart';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// WooCommerce AJAX: /?wc-ajax=hurrry_get_cart
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_get_cart' ) );

		// admin-ajax.php fallback, for setups where ?wc-ajax= is blocked.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_get_cart' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_get_cart' ) );
	}

	/**
	 * WooCommerce AJAX URL of the cart route: the JS equivalent of /cart.js.
	 *
	 * @return string
	 */
	public static function ajax_url() {
		if ( class_exists( 'WC_AJAX' ) ) {
			return WC_AJAX::get_endpoint( self::AJAX_ACTION );
		}
		return add_query_arg( 'wc-ajax', self::AJAX_ACTION, home_url( '/' ) );
	}

	/**
	 * admin-ajax.php URL of the same handler.
	 *
	 * @return string
	 */
	public static function admin_ajax_url() {
		return add_query_arg( 'action', self::AJAX_ACTION, admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Absolute URL of the REST route.
	 *
	 * @return string
	 */
	public static function cart_url() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE_CART );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE_CART,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cart' ),
				// The route only ever returns the caller's own session cart.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * WooCommerce AJAX handler. WooCommerce has already booted the session and
	 * the cart for this request, so there is nothing to restore by hand.
	 */
	public function ajax_get_cart() {
		if ( function_exists( 'wc_nocache_headers' ) ) {
			wc_nocache_headers();
		} else {
			nocache_headers();
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$this->boot_cart();

		wp_send_json( $this->build_cart_payload( 'wc-ajax' ) );
	}

	/**
	 * Restores the logged-in user for a REST request.
	 *
	 * The REST API deliberately ignores the WordPress auth cookie when the
	 * request carries no X-WP-Nonce header: rest_cookie_check_errors() calls
	 * wp_set_current_user( 0 ), so the request looks like a guest. That breaks
	 * the cart badly, because WC_Session_Handler::is_session_cookie_valid()
	 * then sees a numeric (logged-in) customer id on a request it believes is
	 * anonymous, declares the session cookie invalid and calls
	 * destroy_session() -- which deletes the shopper's cart from the database
	 * and hands back a brand new empty session. Hence an empty cart response,
	 * and a cart that keeps emptying itself while the plugin polls it.
	 *
	 * Reading the auth cookie by hand puts the real user back before the
	 * session is touched. This route is read-only and only ever returns the
	 * caller's own cart, so honouring the cookie without a nonce exposes
	 * nothing that a cross-site request could read anyway.
	 */
	private function restore_current_user() {
		if ( is_user_logged_in() ) {
			return;
		}

		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * WooCommerce does not boot its session on REST requests, so do it by hand.
	 *
	 * @return bool
	 */
	private function boot_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		// Must run before the session handler reads the cookie.
		$this->restore_current_user();

		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( null === WC()->session ) {
			$handler      = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
			WC()->session = new $handler();
			WC()->session->init();
		}
		if ( null === WC()->customer ) {
			WC()->customer = new WC_Customer( get_current_user_id(), true );
		}
		if ( null === WC()->cart ) {
			WC()->cart = new WC_Cart();
		}

		if ( null !== WC()->cart ) {
			// The cart object can exist while its contents were never read from
			// the session, which is indistinguishable from an empty cart.
			WC()->cart->get_cart();
		}

		return null !== WC()->cart;
	}

	/**
	 * @return WP_REST_Response
	 */
	public function get_cart() {
		$this->boot_cart();

		$rest = new WP_REST_Response( $this->build_cart_payload( 'rest' ), 200 );
		$rest->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $rest;
	}

	/**
	 * The cart in Shopify /cart.js shape.
	 *
	 * @param string $source Transport that produced the payload.
	 * @return array
	 */
	public function build_cart_payload( $source = '' ) {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart instanceof WC_Cart ) {
			return $this->with_diagnostics(
				array(
					'token'       => '',
					'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
					'item_count'  => 0,
					'items'       => array(),
					'total_price' => 0,
				),
				$source
			);
		}

		$items = array();
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$item = $this->map_item( $cart_item_key, $cart_item );
			if ( $item ) {
				$items[] = $item;
			}
		}

		$response = array(
			'token'       => $this->cart_token(),
			'currency'    => get_woocommerce_currency(),
			'item_count'  => (int) $cart->get_cart_contents_count(),
			'items'       => $items,
			'total_price' => self::to_minor_units( $cart->get_total( 'edit' ) ),
		);

		$response = apply_filters( 'hurrry_cart_response', $response, $cart );

		return $this->with_diagnostics( $response, $source );
	}

	/**
	 * Adds a _diag block while debug logging is on, so an empty cart can be
	 * told apart from a cart the request never got to see.
	 *
	 * @param array  $response Cart response.
	 * @param string $source   Transport name.
	 * @return array
	 */
	private function with_diagnostics( $response, $source ) {
		if ( ! class_exists( 'Hurrry_Settings' ) || ! Hurrry_Settings::is_yes( 'debug' ) ) {
			return $response;
		}

		$session_cookie = '';
		foreach ( array_keys( $_COOKIE ) as $name ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 0 === strpos( $name, 'wp_woocommerce_session_' ) ) {
				$session_cookie = $name;
				break;
			}
		}

		$response['_diag'] = array(
			'source'             => $source,
			'logged_in'          => is_user_logged_in(),
			'user_id'            => get_current_user_id(),
			'has_session_cookie' => '' !== $session_cookie,
			'session_customer'   => ( function_exists( 'WC' ) && null !== WC()->session ) ? (string) WC()->session->get_customer_id() : '',
			'cart_loaded'        => function_exists( 'WC' ) && WC()->cart instanceof WC_Cart,
		);

		return $response;
	}

	/**
	 * Maps one WooCommerce cart line onto the Shopify line-item shape used by
	 * buildPayload(): id, quantity, line_price, product_title, variant_title,
	 * sku and image.
	 *
	 * @param string $cart_item_key Cart line key.
	 * @param array  $cart_item     Cart line data.
	 * @return array|null
	 */
	private function map_item( $cart_item_key, $cart_item ) {
		/** @var WC_Product|false $product */
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$quantity   = max( 1, (int) $cart_item['quantity'] );
		$product_id = (int) ( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : $product->get_id() );
		$variant_id = (int) ( isset( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : 0 );

		// Match what the shopper sees in the cart totals: taxes are folded in
		// only when the store displays prices including tax.
		$line_total = (float) ( isset( $cart_item['line_total'] ) ? $cart_item['line_total'] : 0 );
		if ( WC()->cart->display_prices_including_tax() ) {
			$line_total += (float) ( isset( $cart_item['line_tax'] ) ? $cart_item['line_tax'] : 0 );
		}

		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;

		$item = array(
			'id'             => $variant_id > 0 ? $variant_id : $product_id,
			'key'            => $cart_item_key,
			'product_id'     => $product_id,
			'variant_id'     => $variant_id,
			'quantity'       => $quantity,
			'line_price'     => self::to_minor_units( $line_total ),
			'product_title'  => $parent instanceof WC_Product ? $parent->get_name() : $product->get_name(),
			'variant_title'  => $this->variant_title( $product ),
			'sku'            => (string) $product->get_sku(),
			'image'          => $this->image_url( $product, $parent ),
			'featured_image' => array( 'url' => $this->image_url( $product, $parent ) ),
			'url'            => get_permalink( $product_id ),
		);

		return apply_filters( 'hurrry_cart_item', $item, $cart_item, $product );
	}

	/**
	 * @param WC_Product $product Cart line product.
	 * @return string
	 */
	private function variant_title( $product ) {
		if ( ! $product->is_type( 'variation' ) ) {
			return '';
		}

		$parts = array();
		foreach ( $product->get_variation_attributes() as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return implode( ' / ', $parts );
	}

	/**
	 * @param WC_Product       $product Cart line product.
	 * @param WC_Product|false $parent  Parent product for variations.
	 * @return string
	 */
	private function image_url( $product, $parent ) {
		$image_id = (int) $product->get_image_id();
		if ( ! $image_id && $parent instanceof WC_Product ) {
			$image_id = (int) $parent->get_image_id();
		}
		if ( ! $image_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

		return $url ? $url : '';
	}

	/**
	 * Session identifier used as the stable cart token. It survives reloads and
	 * is per browser, which is exactly what cartExternalId() needs to avoid
	 * creating a new order on every preload.
	 *
	 * @return string
	 */
	private function cart_token() {
		if ( function_exists( 'WC' ) && null !== WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
			$token = (string) WC()->session->get_customer_id();
			if ( '' !== $token ) {
				return substr( md5( $token ), 0, 32 );
			}
		}
		return '';
	}

	/**
	 * Amounts travel in minor units (cents), like Shopify line_price.
	 *
	 * @param float|string $amount Amount in major units.
	 * @return int
	 */
	public static function to_minor_units( $amount ) {
		$multiplier = (int) apply_filters( 'hurrry_amount_multiplier', 100 );
		return (int) round( ( (float) $amount ) * $multiplier );
	}
}
