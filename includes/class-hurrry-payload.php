<?php
/**
 * Builds the order payload on the server, byte-for-byte compatible with the
 * one assets/js/hurrry.js posts from the browser.
 *
 * The two builders must agree on externalId: the backend deduplicates orders
 * by it, so a shopper whose browser preloaded a checkout URL and then went
 * through the server-side guard must land on the same order, not a new one.
 * Hence hash36() is a literal port of the djb2 hash used by the script.
 *
 * @package hurrry-checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hurrry_Payload {

	const FB_RE  = '/^fb\.\d\.\d{10,}\..+/';
	const VID_RE = '/^[a-z0-9][a-z0-9._-]{3,63}$/';

	/**
	 * Same djb2 hash as hash36() in hurrry.js: 32-bit unsigned, base 36.
	 *
	 * @param string $str Input.
	 * @return string
	 */
	public static function hash36( $str ) {
		$h   = 5381;
		$len = strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$h = ( ( $h * 33 ) + ord( $str[ $i ] ) ) % 4294967296;
		}
		return base_convert( (string) $h, 10, 36 );
	}

	/**
	 * Stable id of the cart: CART-<token>-<hash of contents>.
	 *
	 * @param array $cart Cart in /cart.js shape (see Hurrry_Cart_Rest).
	 * @return string
	 */
	public static function external_id( $cart ) {
		$parts = array();
		foreach ( (array) ( isset( $cart['items'] ) ? $cart['items'] : array() ) as $li ) {
			$parts[] = (int) $li['id'] . ':' . (int) $li['quantity'] . ':' . (int) $li['line_price'];
		}
		$contents = implode( '|', $parts );
		$currency = isset( $cart['currency'] ) ? (string) $cart['currency'] : '';

		$token = isset( $cart['token'] ) ? substr( (string) $cart['token'], 0, 32 ) : '';
		if ( '' === $token ) {
			// The browser falls back to a per-tab random token here; the server
			// has no tab, so a fresh random one is the honest equivalent.
			$token = substr( md5( wp_generate_password( 12, false ) . microtime( true ) ), 0, 10 );
		}

		return 'CART-' . $token . '-' . self::hash36( $contents . '|' . $currency );
	}

	/**
	 * Order payload in the exact shape buildPayload() produces in the browser.
	 *
	 * @param array  $cart     Cart in /cart.js shape.
	 * @param string $endpoint Configured endpoint (unused, kept for filters).
	 * @return array
	 */
	public static function build( $cart, $endpoint = '' ) {
		$items    = array();
		$currency = isset( $cart['currency'] ) && '' !== $cart['currency'] ? (string) $cart['currency'] : 'USD';

		foreach ( (array) ( isset( $cart['items'] ) ? $cart['items'] : array() ) as $li ) {
			$qty        = (int) $li['quantity'];
			$qty        = $qty > 0 ? $qty : 1;
			$line_price = (int) $li['line_price'];
			$sku        = isset( $li['sku'] ) ? (string) $li['sku'] : '';
			$product_id = '' !== $sku ? $sku : (string) ( ! empty( $li['product_id'] ) ? $li['product_id'] : $li['id'] );
			$name       = (string) $li['product_title'];
			if ( ! empty( $li['variant_title'] ) ) {
				$name .= ' - ' . $li['variant_title'];
			}
			$image = '';
			if ( ! empty( $li['image'] ) ) {
				$image = (string) $li['image'];
			} elseif ( ! empty( $li['featured_image']['url'] ) ) {
				$image = (string) $li['featured_image']['url'];
			}

			$items[] = array(
				'productId'  => $product_id,
				'sku'        => $sku,
				'name'       => $name,
				'imageUrl'   => $image,
				'quantity'   => $qty,
				'unitAmount' => (int) round( $line_price / $qty ),
				'taxAmount'  => 0,
			);
		}

		$payload = array(
			'externalId'   => self::external_id( $cart ),
			'currency'     => $currency,
			'showCurrency' => $currency,
			'items'        => $items,
			'shopUrl'      => self::shop_url(),
			'sourceUrl'    => self::source_url(),
			'notes'        => (string) Hurrry_Settings::get( 'notes', 'WooCommerce Cart Inline' ),
		);

		$payload = array_merge( $payload, self::signals() );

		return apply_filters( 'hurrry_order_payload', $payload, $cart, $endpoint );
	}

	/**
	 * What the integration identifies the store with. Must match shops.url in
	 * the CRM exactly, so this is the single place that computes it.
	 *
	 * @return string
	 */
	public static function shop_url() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? (string) $host : '';
	}

	/**
	 * Page the shopper came from, same-site only, capped like pageUrl() in JS.
	 *
	 * @return string
	 */
	public static function source_url() {
		$ref = wp_get_referer();
		if ( $ref ) {
			return substr( strtok( $ref, '#' ), 0, 512 );
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return substr( home_url( strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '#' ) ), 0, 512 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return '';
	}

	/**
	 * Tracking signals the way SIG.payload() collects them in the browser:
	 * cookies written by the pixels (or by hurrry.js itself), click ids on
	 * the current URL, and a built _fbc when only the fbclid is known.
	 *
	 * @return array Keys among fbc, fbp, ttc, ttp, gclid, epik, visitorId.
	 */
	public static function signals() {
		$out   = array();
		$query = self::query();

		$cookie_fbc = self::cookie( '_fbc' );
		$fbclid     = isset( $query['fbclid'] ) ? $query['fbclid'] : ( isset( $query['hy_fbclid'] ) ? $query['hy_fbclid'] : '' );

		if ( preg_match( self::FB_RE, $cookie_fbc ) ) {
			$out['fbc'] = $cookie_fbc;
		} elseif ( '' !== $fbclid ) {
			$out['fbc'] = 'fb.' . self::subdomain_index() . '.' . (int) round( microtime( true ) * 1000 ) . '.' . $fbclid;
		} elseif ( isset( $query['hy_fbc'] ) && preg_match( self::FB_RE, $query['hy_fbc'] ) ) {
			$out['fbc'] = $query['hy_fbc'];
		}

		$cookie_fbp = self::cookie( '_fbp' );
		if ( preg_match( self::FB_RE, $cookie_fbp ) ) {
			$out['fbp'] = $cookie_fbp;
		} elseif ( isset( $query['hy_fbp'] ) && preg_match( self::FB_RE, $query['hy_fbp'] ) ) {
			$out['fbp'] = $query['hy_fbp'];
		}

		$ttc = self::first( array( isset( $query['ttclid'] ) ? $query['ttclid'] : '', self::cookie( 'ttclid' ), isset( $query['hy_ttc'] ) ? $query['hy_ttc'] : '' ) );
		if ( $ttc ) {
			$out['ttc'] = $ttc;
		}
		$ttp = self::first( array( self::cookie( '_ttp' ), isset( $query['hy_ttp'] ) ? $query['hy_ttp'] : '' ) );
		if ( $ttp ) {
			$out['ttp'] = $ttp;
		}

		$gcl_aw = self::cookie( '_gcl_aw' );
		if ( '' !== $gcl_aw && strpos( $gcl_aw, '.' ) > 0 ) {
			$gcl_parts = explode( '.', $gcl_aw );
			$gcl_aw    = implode( '.', array_slice( $gcl_parts, 2 ) );
		}
		$gclid = self::first(
			array(
				isset( $query['gclid'] ) ? $query['gclid'] : '',
				isset( $query['gbraid'] ) ? $query['gbraid'] : '',
				isset( $query['wbraid'] ) ? $query['wbraid'] : '',
				$gcl_aw,
				isset( $query['hy_gclid'] ) ? $query['hy_gclid'] : '',
			)
		);
		if ( $gclid ) {
			$out['gclid'] = $gclid;
		}

		$epik = self::first( array( self::cookie( '_epik' ), isset( $query['epik'] ) ? $query['epik'] : '', isset( $query['hy_epik'] ) ? $query['hy_epik'] : '' ) );
		if ( $epik ) {
			$out['epik'] = $epik;
		}

		$vid = strtolower( trim( self::first( array( isset( $query['hy_vid'] ) ? $query['hy_vid'] : '', self::cookie( 'hy_vid' ) ) ) ) );
		if ( '' !== $vid && preg_match( self::VID_RE, $vid ) ) {
			$out['visitorId'] = $vid;
		}

		return $out;
	}

	/**
	 * Appends defaultLang and the hy_* handoff parameters to a hosted checkout
	 * URL, like withQuery() + SIG.decorate() do in the browser.
	 *
	 * @param string $url     Hosted checkout URL.
	 * @param array  $signals Output of signals().
	 * @return string
	 */
	public static function decorate_url( $url, $signals = null ) {
		if ( null === $signals ) {
			$signals = self::signals();
		}
		$args = array();

		$lang = Hurrry_Settings::get( 'default_lang', '' );
		if ( '' !== (string) $lang ) {
			$args['defaultLang'] = (string) $lang;
		}

		$map = array(
			'fbc'       => 'hy_fbc',
			'fbp'       => 'hy_fbp',
			'ttc'       => 'hy_ttc',
			'ttp'       => 'hy_ttp',
			'gclid'     => 'hy_gclid',
			'epik'      => 'hy_epik',
			'visitorId' => 'hy_vid',
		);
		foreach ( $map as $from => $to ) {
			if ( ! empty( $signals[ $from ] ) ) {
				$args[ $to ] = $signals[ $from ];
			}
		}

		$query = self::query();
		$fbclid = isset( $query['fbclid'] ) ? $query['fbclid'] : ( isset( $query['hy_fbclid'] ) ? $query['hy_fbclid'] : '' );
		if ( '' !== $fbclid ) {
			$args['hy_fbclid'] = $fbclid;
		}

		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url;
	}

	/**
	 * The hosted checkout URL inside an endpoint response, whichever key the
	 * backend used. Null when the response is a failure (the backend answers
	 * HTTP 200 with a null redirectUrl when it cannot route the order).
	 *
	 * @param mixed $json Decoded response body.
	 * @return string|null
	 */
	public static function hosted_url_from( $json ) {
		if ( ! is_array( $json ) ) {
			return null;
		}
		foreach ( array( 'hostedUrl', 'redirectUrl', 'url', 'direct_link', 'checkoutUrl' ) as $key ) {
			if ( ! empty( $json[ $key ] ) && is_string( $json[ $key ] ) && preg_match( '#^https?://#i', $json[ $key ] ) ) {
				return $json[ $key ];
			}
		}
		return null;
	}

	/**
	 * Meta's subdomainIndex for the store host: 1 for shop.com, 2 for
	 * www.shop.com. The browser works it out from where a cookie sticks; the
	 * server only has the host.
	 *
	 * @return int
	 */
	private static function subdomain_index() {
		$host  = self::shop_url();
		$parts = array_values( array_filter( explode( '.', $host ) ) );
		$n     = count( $parts ) - 1;
		return $n > 0 ? $n : 1;
	}

	/**
	 * @param string $name Cookie name.
	 * @return string
	 */
	private static function cookie( $name ) {
		if ( ! isset( $_COOKIE[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$value = wp_unslash( $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$value = is_string( $value ) ? trim( $value ) : '';
		return strlen( $value ) > 512 ? '' : $value;
	}

	/**
	 * Query parameters of the current request, trimmed and bounded.
	 *
	 * @return array
	 */
	private static function query() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		foreach ( (array) $_GET as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $v ) ) {
				$v = trim( wp_unslash( $v ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( '' !== $v && strlen( $v ) <= 512 ) {
					$cache[ (string) $k ] = $v;
				}
			}
		}
		return $cache;
	}

	/**
	 * @param array $candidates Values in priority order.
	 * @return string First non-empty candidate, or ''.
	 */
	private static function first( $candidates ) {
		foreach ( $candidates as $c ) {
			$c = is_string( $c ) ? trim( $c ) : '';
			if ( '' !== $c ) {
				return $c;
			}
		}
		return '';
	}
}
