<?php
/**
 * Server-side guard: the WooCommerce checkout page is never rendered while the
 * plugin is enabled. Whatever brought the shopper here (a button the script
 * did not catch, a theme that navigates from JavaScript, a bookmark, a cached
 * page served without the script, a browser too old for the script, a JS
 * optimiser that delayed it past the click) ends in the Hurrry checkout.
 *
 * Order of attempts on GET /checkout/:
 *
 *  1. A hosted URL the browser already preloaded for this exact cart (cookie
 *     hurrry_pre, verified against the externalId the server computes) or one
 *     this guard created minutes ago (WooCommerce session). Zero round trips.
 *  2. The endpoint, called from PHP with the shopper's IP and User-Agent
 *     forwarded. Works without any JavaScript at all.
 *  3. A minimal interstitial page whose ES5 script calls the endpoint from the
 *     browser (covers hosts that block outbound HTTP from PHP).
 *  4. An error page with "retry" and "back to cart". The WooCommerce checkout
 *     only renders here when the store owner explicitly enabled the fallback.
 *
 * Around that: payment gateways are removed from the storefront (so express
 * buttons and any surviving checkout cannot take money), the classic and
 * Store API checkout submissions are refused, and express payment buttons are
 * hidden.
 *
 * @package hurrry-checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hurrry_Gate {

	const LOG_OPTION      = 'hurrry_gate_log';
	const LOG_MAX         = 30;
	const SESSION_KEY     = 'hurrry_handoff';
	const SESSION_TTL     = 1800;
	const PRE_COOKIE      = 'hurrry_pre';
	const BYPASS_COOKIE   = 'hurrry_bypass';
	const SERVER_ATTEMPTS = 2;
	const SERVER_TIMEOUT  = 6; // seconds, per attempt; a healthy endpoint answers in well under one.

	private static $instance = null;

	/** @var bool|null Memoised result of bypass_active(). */
	private $bypass = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! $this->active() ) {
			return;
		}

		// Priority 1: WooCommerce AJAX handlers (wc-ajax=) exit at priority 0,
		// so they never reach the guard.
		add_action( 'template_redirect', array( $this, 'guard' ), 1 );
		add_action( 'init', array( $this, 'handle_bypass_param' ), 20 );

		// With the store fallback on, the WooCommerce checkout must stay able
		// to take a payment, so the gateways are only locked without it.
		if ( Hurrry_Settings::is_yes( 'lock_gateways' ) && ! Hurrry_Settings::is_yes( 'fallback_to_store' ) ) {
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'lock_gateways' ), 9999 );
		}

		if ( Hurrry_Settings::is_yes( 'hide_express' ) ) {
			add_action( 'wp_head', array( $this, 'express_css' ), 1 );
			add_filter( 'wc_stripe_show_payment_request_on_product_page', array( $this, 'filter_false' ), 9999 );
			add_filter( 'wc_stripe_show_payment_request_on_cart', array( $this, 'filter_false' ), 9999 );
			add_filter( 'wc_stripe_show_payment_request_on_checkout', array( $this, 'filter_false' ), 9999 );
			add_filter( 'wcpay_payment_request_is_product_supported', array( $this, 'filter_false' ), 9999 );
			add_filter( 'wcpay_payment_request_is_cart_supported', array( $this, 'filter_false' ), 9999 );
			add_filter( 'wcpay_express_checkout_is_product_supported', array( $this, 'filter_false' ), 9999 );
			add_filter( 'wcpay_express_checkout_is_cart_supported', array( $this, 'filter_false' ), 9999 );
		}

		if ( ! Hurrry_Settings::is_yes( 'fallback_to_store' ) ) {
			add_action( 'woocommerce_checkout_process', array( $this, 'refuse_classic_checkout' ), 1 );
			add_filter( 'rest_request_before_callbacks', array( $this, 'refuse_store_api_checkout' ), 1, 3 );
		}
	}

	/**
	 * Enabled, endpoint configured, guard switched on.
	 *
	 * @return bool
	 */
	private function active() {
		return Hurrry_Settings::is_yes( 'enabled' )
			&& '' !== (string) Hurrry_Settings::get( 'endpoint', '' )
			&& Hurrry_Settings::is_yes( 'server_gate' );
	}

	/**
	 * Store managers can look at the real WooCommerce checkout with
	 * /checkout/?hurrry_bypass=1 (and ?hurrry_bypass=0 to stop). The flag
	 * lives in a cookie so the AJAX calls the checkout page makes are covered
	 * too, and it is honoured only for users who can manage the store.
	 */
	public function handle_bypass_param() {
		if ( ! isset( $_GET['hurrry_bypass'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$on     = '1' === (string) wp_unslash( $_GET['hurrry_bypass'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		$secure = is_ssl();
		if ( $on ) {
			setcookie( self::BYPASS_COOKIE, '1', time() + HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, true );
			$_COOKIE[ self::BYPASS_COOKIE ] = '1';
		} else {
			setcookie( self::BYPASS_COOKIE, '', time() - YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, true );
			unset( $_COOKIE[ self::BYPASS_COOKIE ] );
		}
		$this->bypass = null;
	}

	/**
	 * @return bool
	 */
	public function bypass_active() {
		if ( null !== $this->bypass ) {
			return $this->bypass;
		}
		$this->bypass = isset( $_COOKIE[ self::BYPASS_COOKIE ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& '1' === (string) $_COOKIE[ self::BYPASS_COOKIE ] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& is_user_logged_in()
			&& current_user_can( 'manage_woocommerce' );
		return $this->bypass;
	}

	// ------------------------------------------------------------------
	// The guard itself
	// ------------------------------------------------------------------

	public function guard() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( is_preview() || is_customize_preview() ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			// Paying an existing order and the thank-you page are not the
			// checkout; they must keep working for orders created elsewhere.
			return;
		}
		if ( $this->bypass_active() ) {
			$this->record( 'bypass', 'manager cookie', 0, 0 );
			return;
		}
		if ( ! apply_filters( 'hurrry_gate_should_guard', true ) ) {
			return;
		}

		$started = microtime( true );
		$reason  = $this->arrival_reason();

		if ( function_exists( 'wc_load_cart' ) && ( ! function_exists( 'WC' ) || null === WC()->cart ) ) {
			wc_load_cart();
		}
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof WC_Cart ) {
			$this->record( 'no-cart', $reason, 0, $this->elapsed( $started ) );
			return;
		}

		$cart_payload = Hurrry_Cart_Rest::instance()->build_cart_payload( 'gate' );
		$count        = count( $cart_payload['items'] );

		if ( 0 === $count ) {
			// WooCommerce itself sends an empty checkout back to the cart.
			$this->record( 'empty-cart', $reason, 0, $this->elapsed( $started ) );
			if ( $this->is_current_request( $this->cart_url() ) ) {
				// Cart and checkout on the same page (one-page checkouts):
				// redirecting would loop, let WooCommerce render the empty cart.
				return;
			}
			$this->redirect( $this->cart_url() );
		}

		if ( ! $this->cart_total_positive( $cart ) ) {
			// Nothing to charge: a free order has no business in a payment
			// checkout, let WooCommerce take it.
			$this->record( 'free-cart', $reason, $count, $this->elapsed( $started ) );
			return;
		}

		$external_id = Hurrry_Payload::external_id( $cart_payload );
		$signals     = Hurrry_Payload::signals();

		// 1. A URL that already exists for this exact cart.
		$known = $this->known_url( $external_id );
		if ( $known ) {
			$this->record( 'reused:' . $known['from'], $reason, $count, $this->elapsed( $started ) );
			$this->redirect( Hurrry_Payload::decorate_url( $known['url'], $signals ) );
		}

		// 2. Create it from the server.
		$payload = Hurrry_Payload::build( $cart_payload, (string) Hurrry_Settings::get( 'endpoint', '' ) );
		$result  = $this->create_from_server( $payload );
		if ( ! empty( $result['url'] ) ) {
			$this->remember( $external_id, $result['url'] );
			$this->record( 'server', $reason, $count, $this->elapsed( $started ) );
			$this->redirect( Hurrry_Payload::decorate_url( $result['url'], $signals ) );
		}

		$this->log_error( sprintf( 'Server-side handoff failed for %s after %d attempt(s): %s', $external_id, self::SERVER_ATTEMPTS, $result['error'] ) );

		if ( Hurrry_Settings::is_yes( 'fallback_to_store' ) ) {
			$this->record( 'fallback-woo', $reason . ' / ' . $result['error'], $count, $this->elapsed( $started ) );
			return;
		}

		// 3. Let the browser try, on a page that is ours.
		$this->record( 'interstitial', $reason . ' / ' . $result['error'], $count, $this->elapsed( $started ) );
		$this->render_interstitial( $payload, $signals, $result['error'] );
	}

	/**
	 * @param WC_Cart $cart Cart.
	 * @return bool
	 */
	private function cart_total_positive( $cart ) {
		return (float) $cart->get_total( 'edit' ) > 0;
	}

	/**
	 * Why the shopper reached the server guard, for the diagnostics log.
	 *
	 * @return string
	 */
	private function arrival_reason() {
		$from = isset( $_GET['hurrry_from'] ) ? sanitize_key( wp_unslash( $_GET['hurrry_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$why  = isset( $_GET['hurrry_r'] ) ? sanitize_key( wp_unslash( $_GET['hurrry_r'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $from ) {
			return 'from ' . $from . ( '' !== $why ? ' (' . $why . ')' : '' );
		}
		$ref = wp_get_referer();
		return $ref ? 'direct, referer ' . substr( $ref, 0, 120 ) : 'direct, no referer';
	}

	/**
	 * @param string $external_id Id of the current cart.
	 * @return array|null {from, url}
	 */
	private function known_url( $external_id ) {
		// Written by hurrry.js right after a successful preload.
		if ( isset( $_COOKIE[ self::PRE_COOKIE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// PHP already URL-decodes cookie values while filling $_COOKIE.
			$raw = (string) wp_unslash( $_COOKIE[ self::PRE_COOKIE ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
			$sep = strpos( $raw, '|' );
			if ( false !== $sep ) {
				$id  = substr( $raw, 0, $sep );
				$url = substr( $raw, $sep + 1 );
				if ( $id === $external_id && $this->plausible_hosted_url( $url ) ) {
					return array(
						'from' => 'browser',
						'url'  => $url,
					);
				}
			}
		}

		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			$saved = WC()->session->get( self::SESSION_KEY );
			if ( is_array( $saved )
				&& isset( $saved['id'], $saved['url'], $saved['ts'] )
				&& $saved['id'] === $external_id
				&& time() - (int) $saved['ts'] < self::SESSION_TTL
				&& $this->plausible_hosted_url( $saved['url'] ) ) {
				return array(
					'from' => 'session',
					'url'  => $saved['url'],
				);
			}
		}

		return null;
	}

	/**
	 * @param string $external_id Cart id.
	 * @param string $url         Hosted URL.
	 */
	private function remember( $external_id, $url ) {
		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			WC()->session->set(
				self::SESSION_KEY,
				array(
					'id'  => $external_id,
					'url' => $url,
					'ts'  => time(),
				)
			);
		}
	}

	/**
	 * @param string $url Candidate.
	 * @return bool
	 */
	private function plausible_hosted_url( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 2000 || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		// Never bounce the shopper back into this very site: that is how a
		// bad cookie could turn the guard into a loop.
		$own = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $own && strcasecmp( $host, $own ) === 0 ) {
			return false;
		}
		return true;
	}

	/**
	 * POSTs the payload to the endpoint from PHP, forwarding what the backend
	 * reads from the request for Meta CAPI: the shopper's IP (first element of
	 * X-Forwarded-For) and User-Agent.
	 *
	 * @param array $payload Order payload.
	 * @return array {url: string|null, error: string}
	 */
	private function create_from_server( $payload ) {
		$endpoint = (string) Hurrry_Settings::get( 'endpoint', '' );
		if ( '' === $endpoint ) {
			return array(
				'url'   => null,
				'error' => 'no endpoint',
			);
		}

		$timeout = min( self::SERVER_TIMEOUT, max( 2, (int) ceil( (int) Hurrry_Settings::get( 'fetch_timeout_ms', 8000 ) / 1000 ) ) );
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : 'Hurrry-WordPress/' . HURRRY_VERSION;
		$ip      = $this->client_ip();

		$headers = array(
			'Content-Type'    => 'application/json',
			'Accept'          => 'application/json',
			'X-Hurrry-Origin' => 'wordpress-gate/' . HURRRY_VERSION,
		);
		if ( '' !== $ip ) {
			$headers['X-Forwarded-For'] = $ip;
			$headers['X-Real-IP']       = $ip;
		}
		$referer = Hurrry_Payload::source_url();
		if ( '' !== $referer ) {
			$headers['Referer'] = $referer;
		}

		$body  = wp_json_encode( $payload );
		$error = 'unknown';

		for ( $attempt = 1; $attempt <= self::SERVER_ATTEMPTS; $attempt++ ) {
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout'     => $timeout,
					'redirection' => 2,
					'headers'     => $headers,
					'user-agent'  => $ua,
					'body'        => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				$error = 'http: ' . $response->get_error_message();
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$url  = Hurrry_Payload::hosted_url_from( $json );

			if ( $code >= 200 && $code < 300 && $url ) {
				return array(
					'url'   => $url,
					'error' => '',
				);
			}

			$error = 'endpoint answered ' . $code . ( $url ? '' : ' without a redirect URL' );
			if ( $code >= 200 && $code < 300 ) {
				// A 200 with a null URL is the backend's "cannot route this
				// shop" answer: retrying will not change it.
				break;
			}
		}

		return array(
			'url'   => null,
			'error' => $error,
		);
	}

	/**
	 * Best guess of the shopper's IP behind the usual proxies.
	 *
	 * @return string
	 */
	private function client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw   = (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$first = trim( strtok( $raw, ',' ) );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		return '';
	}

	// ------------------------------------------------------------------
	// Interstitial
	// ------------------------------------------------------------------

	/**
	 * Minimal page, no theme: a spinner while an ES5 script posts the payload
	 * from the browser, then an error block with retry / back to cart.
	 *
	 * @param array  $payload Order payload.
	 * @param array  $signals Tracking signals.
	 * @param string $error   Why the server attempt failed (debug only).
	 */
	private function render_interstitial( $payload, $signals, $error ) {
		$this->no_cache_constants();
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$t        = self::texts();
		$retries  = min( 4, max( 1, (int) Hurrry_Settings::get( 'retries', 5 ) ) );
		$endpoint = (string) Hurrry_Settings::get( 'endpoint', '' );

		$decor = array();
		$lang  = Hurrry_Settings::get( 'default_lang', '' );
		if ( '' !== (string) $lang ) {
			$decor['defaultLang'] = (string) $lang;
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
				$decor[ $to ] = $signals[ $from ];
			}
		}

		$config = array(
			'endpoint'  => $endpoint,
			'payload'   => $payload,
			'attempts'  => $retries,
			'timeoutMs' => max( 3000, min( 15000, (int) Hurrry_Settings::get( 'fetch_timeout_ms', 8000 ) ) ),
			'decorate'  => $decor,
			'heartbeat' => $this->sibling_endpoint( $endpoint, '/api/payments/heartbeat' ),
			'retryUrl'  => $this->retry_url(),
			'backUrl'   => $this->cart_url(),
			'debug'     => Hurrry_Settings::is_yes( 'debug' ),
			'error'     => Hurrry_Settings::is_yes( 'debug' ) ? $error : '',
		);

		$site = get_bloginfo( 'name' );
		$lang_attr = get_bloginfo( 'language' );

		echo '<!DOCTYPE html><html lang="' . esc_attr( $lang_attr ) . '"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html( $t['title'] ) . ' · ' . esc_html( $site ) . '</title>';
		echo '<style>';
		echo 'html,body{margin:0;height:100%;background:#fff;color:#111;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased}';
		echo '.hy-wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:32px 20px;box-sizing:border-box;text-align:center}';
		echo '.hy-box{max-width:420px;width:100%}';
		echo '.hy-spin{width:44px;height:44px;margin:0 auto 20px;border:4px solid #e5e7eb;border-top-color:#111;border-radius:50%;animation:hy-rot .8s linear infinite}';
		echo '@keyframes hy-rot{to{transform:rotate(360deg)}}';
		echo 'h1{font-size:20px;margin:0 0 8px;font-weight:600}p{margin:0 0 8px;color:#4b5563}';
		echo '.hy-btn{display:inline-block;margin:14px 6px 0;padding:12px 22px;border-radius:8px;background:#111;color:#fff;text-decoration:none;font-weight:600}';
		echo '.hy-btn.hy-sec{background:#f3f4f6;color:#111}';
		echo '#hy-error{display:none}#hy-error.hy-on{display:block}#hy-wait.hy-off{display:none}';
		echo '.hy-debug{margin-top:18px;font:12px/1.4 ui-monospace,Menlo,Consolas,monospace;color:#9ca3af;word-break:break-all}';
		echo 'noscript #hy-wait{display:none}';
		echo '</style></head><body><div class="hy-wrap"><div class="hy-box">';
		echo '<div id="hy-wait"><div class="hy-spin"></div><h1>' . esc_html( $t['title'] ) . '</h1><p>' . esc_html( $t['body'] ) . '</p></div>';
		echo '<div id="hy-error"><h1>' . esc_html( $t['error_title'] ) . '</h1><p>' . esc_html( $t['error_body'] ) . '</p>';
		echo '<a class="hy-btn" href="' . esc_url( $config['retryUrl'] ) . '">' . esc_html( $t['retry'] ) . '</a>';
		echo '<a class="hy-btn hy-sec" href="' . esc_url( $config['backUrl'] ) . '">' . esc_html( $t['back'] ) . '</a>';
		if ( '' !== $config['error'] ) {
			echo '<div class="hy-debug">' . esc_html( $config['error'] ) . '</div>';
		}
		echo '</div>';
		echo '<noscript><style>#hy-error{display:block}</style></noscript>';
		echo '</div></div>';
		echo '<script data-cfasync="false" nowprocket data-no-optimize="1" data-no-defer="1" data-no-minify="1">window.HurrryGate=' . wp_json_encode( $config ) . ';</script>';
		echo '<script data-cfasync="false" nowprocket data-no-optimize="1" data-no-defer="1" data-no-minify="1">' . self::interstitial_script() . '</script>';
		echo '</body></html>';
		exit;
	}

	/**
	 * Plain ES5 on purpose: this is the path for browsers the main script
	 * cannot run on.
	 *
	 * @return string
	 */
	private static function interstitial_script() {
		return <<<'JS'
(function(){
var C=window.HurrryGate||{};var attempt=0;var done=false;
function el(id){return document.getElementById(id);}
function log(){if(C.debug&&window.console){try{console.log.apply(console,["[HURRRY gate]"].concat([].slice.call(arguments)));}catch(e){}}}
function fail(why){if(done)return;log("failed",why);var w=el("hy-wait"),e=el("hy-error");if(w)w.className="hy-off";if(e)e.className="hy-on";}
function withParams(url,params){try{var u=url;var qs=[];for(var k in params){if(params.hasOwnProperty(k)&&params[k]!==null&&params[k]!==""){qs.push(encodeURIComponent(k)+"="+encodeURIComponent(params[k]));}}if(!qs.length)return u;return u+(u.indexOf("?")!==-1?"&":"?")+qs.join("&");}catch(e){return url;}}
function heartbeat(orderId){if(!C.heartbeat||!orderId)return;try{var x=new XMLHttpRequest();x.open("POST",C.heartbeat,true);x.setRequestHeader("Content-Type","application/json");x.send(JSON.stringify({orderId:orderId,stage:"redirect"}));}catch(e){}}
function go(url){if(done)return;done=true;var target=withParams(url,C.decorate||{});log("redirect",target);try{window.location.assign(target);}catch(e){window.location.href=target;}setTimeout(function(){try{window.location.href=target;}catch(e){}},1500);}
function post(){attempt++;log("attempt",attempt);var x;try{x=new XMLHttpRequest();x.open("POST",C.endpoint,true);x.timeout=C.timeoutMs||8000;x.setRequestHeader("Content-Type","application/json");x.setRequestHeader("Accept","application/json");}catch(e){return retry("open: "+e);}
x.onreadystatechange=function(){if(x.readyState!==4)return;var j=null,url=null;try{j=JSON.parse(x.responseText||"{}");url=j.hostedUrl||j.redirectUrl||j.url||j.direct_link||j.checkoutUrl||null;}catch(e){}
if(x.status>=200&&x.status<300&&url&&/^https?:\/\//i.test(url)){heartbeat(j&&j.orderId);go(url);}else if(x.status>=200&&x.status<300){fail("no url in response");}else{retry("status "+x.status);}};
x.ontimeout=function(){retry("timeout");};x.onerror=function(){retry("network");};
try{x.send(JSON.stringify(C.payload||{}));}catch(e){retry("send: "+e);}}
function retry(why){if(done)return;log("retry",why);if(attempt<(C.attempts||3)){setTimeout(post,300*attempt);}else{fail(why);}}
if(!C.endpoint||!C.payload){fail("no config");}else{post();}
})();
JS;
	}

	/**
	 * Message texts, by store locale.
	 *
	 * @return array
	 */
	public static function texts() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$lang   = strtolower( substr( (string) $locale, 0, 2 ) );

		$all = array(
			'es' => array(
				'title'       => 'Un momento…',
				'body'        => 'Te estamos llevando al pago seguro.',
				'wait'        => 'Está tardando más de lo normal. Si no avanza, pulsa aquí.',
				'error_title' => 'No hemos podido abrir el pago',
				'error_body'  => 'Inténtalo de nuevo en unos segundos. Tu carrito sigue guardado.',
				'retry'       => 'Reintentar',
				'back'        => 'Volver al carrito',
			),
			'en' => array(
				'title'       => 'One moment…',
				'body'        => 'Taking you to the secure checkout.',
				'wait'        => 'This is taking longer than usual. Tap here if nothing happens.',
				'error_title' => 'We could not open the checkout',
				'error_body'  => 'Please try again in a few seconds. Your cart is saved.',
				'retry'       => 'Try again',
				'back'        => 'Back to cart',
			),
			'it' => array(
				'title'       => 'Un attimo…',
				'body'        => 'Ti stiamo portando al pagamento sicuro.',
				'wait'        => 'Sta richiedendo più tempo del solito. Tocca qui se non succede nulla.',
				'error_title' => 'Non siamo riusciti ad aprire il pagamento',
				'error_body'  => 'Riprova tra qualche secondo. Il tuo carrello è salvato.',
				'retry'       => 'Riprova',
				'back'        => 'Torna al carrello',
			),
			'fr' => array(
				'title'       => 'Un instant…',
				'body'        => 'Nous vous dirigeons vers le paiement sécurisé.',
				'wait'        => 'Cela prend plus de temps que prévu. Appuyez ici si rien ne se passe.',
				'error_title' => 'Impossible d’ouvrir le paiement',
				'error_body'  => 'Réessayez dans quelques secondes. Votre panier est conservé.',
				'retry'       => 'Réessayer',
				'back'        => 'Retour au panier',
			),
			'de' => array(
				'title'       => 'Einen Moment…',
				'body'        => 'Wir leiten dich zur sicheren Zahlung weiter.',
				'wait'        => 'Das dauert länger als gewöhnlich. Tippe hier, wenn nichts passiert.',
				'error_title' => 'Die Zahlung konnte nicht geöffnet werden',
				'error_body'  => 'Bitte versuche es in ein paar Sekunden erneut. Dein Warenkorb bleibt erhalten.',
				'retry'       => 'Erneut versuchen',
				'back'        => 'Zurück zum Warenkorb',
			),
			'pt' => array(
				'title'       => 'Um momento…',
				'body'        => 'Estamos a levar-te para o pagamento seguro.',
				'wait'        => 'Está a demorar mais do que o normal. Toca aqui se nada acontecer.',
				'error_title' => 'Não foi possível abrir o pagamento',
				'error_body'  => 'Tenta novamente dentro de alguns segundos. O teu carrinho está guardado.',
				'retry'       => 'Tentar novamente',
				'back'        => 'Voltar ao carrinho',
			),
		);

		$texts = isset( $all[ $lang ] ) ? $all[ $lang ] : $all['en'];

		return apply_filters( 'hurrry_gate_texts', $texts, $locale );
	}

	// ------------------------------------------------------------------
	// Lockdown of the WooCommerce payment paths
	// ------------------------------------------------------------------

	/**
	 * No gateway is available on the storefront: express buttons do not
	 * render and no checkout, should one survive, can take a payment. The
	 * admin, cron, CLI and the order-pay page keep theirs.
	 *
	 * @param array $gateways Available gateways.
	 * @return array
	 */
	public function lock_gateways( $gateways ) {
		if ( $this->storefront_locked() ) {
			return array();
		}
		return $gateways;
	}

	/**
	 * @return bool
	 */
	private function storefront_locked() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( $this->bypass_active() ) {
			return false;
		}
		if ( $this->is_order_pay_request() ) {
			return false;
		}
		// Admin-side AJAX (order editing, etc.) carries a logged-in manager.
		if ( wp_doing_ajax() && current_user_can( 'manage_woocommerce' ) && ! $this->is_frontend_ajax() ) {
			return false;
		}
		return (bool) apply_filters( 'hurrry_gate_lock_gateways', true );
	}

	/**
	 * @return bool
	 */
	private function is_frontend_ajax() {
		// WooCommerce front-end AJAX goes through ?wc-ajax=, admin AJAX through admin-ajax.php.
		return isset( $_GET['wc-ajax'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * @return bool
	 */
	private function is_order_pay_request() {
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return true;
		}
		if ( isset( $_GET['pay_for_order'] ) || isset( $_GET['order-pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		if ( isset( $_POST['woocommerce_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return false !== strpos( $uri, '/order-pay/' );
	}

	/**
	 * Classic checkout submission (wc-ajax=checkout or a plain POST): adds an
	 * error so WC_Checkout::process_checkout() never creates the order.
	 */
	public function refuse_classic_checkout() {
		if ( $this->bypass_active() || $this->is_order_pay_request() ) {
			return;
		}
		$t = self::texts();
		wc_add_notice( $t['error_title'] . ' — ' . $t['error_body'], 'error' );
	}

	/**
	 * Store API checkout (the Checkout block posts here): refused with a 403.
	 * The order-pay variant (/checkout/{order_id}) is left alone.
	 *
	 * @param mixed           $response Current response.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function refuse_store_api_checkout( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( 'GET' === $request->get_method() || $this->bypass_active() ) {
			return $response;
		}
		$route = $request->get_route();
		if ( preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#', $route ) ) {
			$t = self::texts();
			return new WP_Error( 'hurrry_checkout_locked', $t['error_title'], array( 'status' => 403 ) );
		}
		return $response;
	}

	/**
	 * Express payment buttons (Apple Pay, Google Pay, PayPal, Amazon, Klarna…)
	 * would complete a WooCommerce payment without visiting the checkout page.
	 * The gateway lock already keeps most from rendering; the CSS covers the
	 * ones that only check their own gateway settings.
	 */
	public function express_css() {
		if ( $this->bypass_active() || is_admin() ) {
			return;
		}
		$selectors = array(
			'#wc-stripe-payment-request-wrapper',
			'#wc-stripe-payment-request-button-separator',
			'#wc-stripe-express-checkout-element',
			'#wc-stripe-express-checkout-button-separator',
			'.wc-stripe-express-checkout-wrapper',
			'#wcpay-payment-request-wrapper',
			'#wcpay-payment-request-button-separator',
			'#wcpay-express-checkout-element',
			'#wcpay-express-checkout-button-separator',
			'.wc-block-components-express-payment',
			'.wc-block-components-express-payment-continue-rule',
			'.wc-block-components-express-payment--cart',
			// FunnelKit Cart smart buttons (Stripe GPay/Apple Pay, PayPal) in the side cart.
			'#fkcart_fkwcs_smart_button',
			'#fkcart_paypalpayments_smart_button',
			'#fkcart_pymntpl_paypal_smart_button',
			'[id^="fkcart_"][id$="_smart_button"]',
			'.fkcart-smart-buttons',
			'.fkcart-smart-buttons-wrap',
			'[id^="ppc-button"]',
			'.ppcp-button-container',
			'.ppc-button-wrapper',
			'.ppcp-messages',
			'.wcppec-cart-widget-button',
			'#woo_pp_ec_button_cart',
			'#woo_pp_ec_button_product',
			'#woo_pp_ec_button_mini_cart',
			'.wc-square-digital-wallet',
			'#wc-square-digital-wallet',
			'#wc-braintree-paypal-button-container',
			'#wc_braintree_paypal_container',
			'.wc-braintree-paypal-button',
			'.mollie-applepaydirect',
			'#mollie-applepayDirect-button',
			'.amazon-pay-button',
			'#pay_with_amazon',
			'.klarna-express-button',
			'#klarna-express-button',
			'.wc-klarna-express',
			'.gpay-button',
			'apple-pay-button',
			'.apple-pay-button',
			'.paypal-buttons',
			'.paypal-button-container',
			'.hurrry-hide',
		);
		$selectors = apply_filters( 'hurrry_express_selectors', $selectors );
		// Selectors carry quotes and brackets, so no HTML escaping: only the
		// characters that could close the style element are removed.
		$css = str_replace( array( '<', '>' ), '', implode( ',', array_map( 'strval', (array) $selectors ) ) );
		echo '<style id="hurrry-express-css">' . $css . '{display:none !important;visibility:hidden !important;pointer-events:none !important}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @return bool
	 */
	public function filter_false() {
		return false;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * @param string $url Target.
	 */
	private function redirect( $url ) {
		$this->no_cache_constants();
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		wp_redirect( $url, 302, 'Hurrry Checkout' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Page caches and optimisers all have their own "leave this response
	 * alone" constant.
	 */
	private function no_cache_constants() {
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY', 'DONOTCDN', 'DONOTROCKETOPTIMIZE', 'LSCACHE_NO_CACHE' ) as $c ) {
			if ( ! defined( $c ) ) {
				define( $c, true );
			}
		}
	}

	/**
	 * Is this URL the page being requested right now (same path, host aside)?
	 *
	 * @param string $url Candidate.
	 * @return bool
	 */
	private function is_current_request( $url ) {
		$target  = '/' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$current = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$current = '/' . trim( (string) wp_parse_url( $current, PHP_URL_PATH ), '/' );
		return strtolower( $target ) === strtolower( $current );
	}

	/**
	 * @return string
	 */
	private function cart_url() {
		$url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
		return $url ? $url : home_url( '/' );
	}

	/**
	 * The current checkout URL without the hurrry_* bookkeeping parameters.
	 *
	 * @return string
	 */
	private function retry_url() {
		$url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
		return remove_query_arg( array( 'hurrry_from', 'hurrry_r', 'hurrry_bypass' ), $url );
	}

	/**
	 * Other backend endpoints live next to /api/orders.
	 *
	 * @param string $endpoint Orders endpoint.
	 * @param string $path     Sibling path.
	 * @return string
	 */
	private function sibling_endpoint( $endpoint, $path ) {
		$parts = wp_parse_url( $endpoint );
		if ( empty( $parts['host'] ) || empty( $parts['path'] ) || ! preg_match( '#/api/orders/?$#', $parts['path'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		return $scheme . '://' . $parts['host'] . $port . $path;
	}

	/**
	 * @param float $started microtime(true) at the start.
	 * @return int Milliseconds.
	 */
	private function elapsed( $started ) {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}

	/**
	 * Ring buffer of what the guard did, shown in the settings screen. This
	 * is what tells "the checkout showed on my phone" apart: every arrival at
	 * the server guard is one line here, with the device and the outcome.
	 *
	 * @param string $result Outcome.
	 * @param string $reason How the shopper arrived.
	 * @param int    $items  Cart lines.
	 * @param int    $ms     Time spent.
	 */
	private function record( $result, $reason, $items, $ms ) {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 160 ) : '';

		$entry = array(
			't'      => time(),
			'result' => substr( $result, 0, 60 ),
			'reason' => substr( $reason, 0, 200 ),
			'items'  => (int) $items,
			'ms'     => (int) $ms,
			'ua'     => $ua,
			'user'   => is_user_logged_in() ? 'logged-in' : 'guest',
		);

		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::LOG_MAX );
		update_option( self::LOG_OPTION, $log, false );

		if ( Hurrry_Settings::is_yes( 'debug' ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( sprintf( 'gate %s | %s | items=%d | %dms | %s | %s', $result, $reason, $items, $ms, $entry['user'], $ua ), array( 'source' => 'hurrry-checkout' ) );
		}
	}

	/**
	 * @param string $message Error.
	 */
	private function log_error( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'hurrry-checkout' ) );
		}
	}

	/**
	 * @return array Newest first.
	 */
	public static function recent_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear_log() {
		delete_option( self::LOG_OPTION );
	}
}
