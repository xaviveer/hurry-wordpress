<?php
/**
 * Self-updater: the plugin is not on wordpress.org, so WordPress learns about
 * new versions from a manifest on the Hurrry CDN.
 *
 * The manifest is a JSON object keyed by plugin slug:
 *
 *   { "hurrry-checkout": {
 *       "version": "1.5.6",
 *       "package": "https://cdn.hurrypayments.com/wordpress/hurrry-checkout-1.5.6.zip",
 *       "sha256": "<hex digest of the zip>",
 *       "signature": "<base64 Ed25519 signature of 'slug|version|sha256'>",
 *       "requires": "5.8", "requires_php": "7.4", "tested": "6.6",
 *       "changelog": "<ul><li>…</li></ul>"
 *   } }
 *
 * A release is only ever offered when its signature checks out against the
 * public key below, and the zip is only installed when its digest matches the
 * signed one. Someone who can write to the CDN but does not hold the private
 * key cannot ship code to the stores, and cannot roll them back either:
 * WordPress never installs a version that is not newer than the current one.
 *
 * @package Hurrry_Checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hurrry_Updater {

	const SLUG         = 'hurrry-checkout';
	const MANIFEST_URL = 'https://cdn.hurrypayments.com/wordpress/update.json';
	const PACKAGE_BASE = 'https://cdn.hurrypayments.com/wordpress/';

	/** Ed25519 public key (base64 of the 32 raw bytes). Its private half signs the releases. */
	const PUBLIC_KEY = 'RCVdyVb4j8SXt1YO1ZTvw50kEqx/u2LOLDBzhrJhdmY=';

	const CACHE_KEY   = 'hurrry_update_manifest';
	const CACHE_TTL   = 300;
	const SEEDED_FLAG = 'hurrry_auto_update_seeded';

	/** @var Hurrry_Updater|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// The hook name carries the host of the "Update URI" plugin header.
		add_filter( 'update_plugins_cdn.hurrypayments.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_verified' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_action( 'init', array( $this, 'seed_auto_update' ) );
	}

	private function plugin_file() {
		return plugin_basename( HURRRY_PLUGIN_FILE );
	}

	/**
	 * Tells WordPress which version is current. WordPress itself decides
	 * whether that is newer than the installed one.
	 *
	 * @param array|false $update      Update data so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file relative to the plugins folder.
	 * @return array|false
	 */
	public function check( $update, $plugin_data = array(), $plugin_file = '' ) {
		if ( $plugin_file !== $this->plugin_file() ) {
			return $update;
		}

		$release = $this->release( false );
		if ( null === $release ) {
			return $update;
		}

		return array(
			'id'           => self::MANIFEST_URL,
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => 'https://hurrry.com/',
			'package'      => $release['package'],
			'tested'       => $release['tested'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
		);
	}

	/**
	 * Downloads the release ourselves so the zip can be checked against the
	 * signed digest before WordPress unpacks it.
	 *
	 * @param bool|WP_Error|string $reply      Whether another filter already handled the download.
	 * @param string               $package    Package URL.
	 * @param WP_Upgrader|null     $upgrader   Upgrader instance.
	 * @param array                $hook_extra Extra arguments.
	 * @return bool|WP_Error|string Path of the verified zip, or an error.
	 */
	public function download_verified( $reply, $package = '', $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::PACKAGE_BASE . self::SLUG . '-' ) ) {
			return $reply;
		}

		$release = $this->release( true );
		if ( null === $release || $release['package'] !== $package ) {
			return new WP_Error( 'hurrry_update_unsigned', __( 'Hurrry Checkout: the update could not be verified and was not installed.', 'hurrry-checkout' ) );
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$digest = hash_file( 'sha256', $file );
		if ( ! is_string( $digest ) || ! hash_equals( $release['sha256'], $digest ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'hurrry_update_tampered', __( 'Hurrry Checkout: the downloaded update does not match its signature and was not installed.', 'hurrry-checkout' ) );
		}

		return $file;
	}

	/**
	 * Fills the "View details" window of the plugins screen.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action = '', $args = null ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->release( false );
		if ( null === $release ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = 'Hurrry Checkout';
		$info->slug          = self::SLUG;
		$info->version       = $release['version'];
		$info->author        = 'Hurrry';
		$info->homepage      = 'https://hurrry.com/';
		$info->requires      = $release['requires'];
		$info->requires_php  = $release['requires_php'];
		$info->tested        = $release['tested'];
		$info->download_link = $release['package'];
		$info->sections      = array(
			'changelog' => '' !== $release['changelog'] ? wp_kses_post( $release['changelog'] ) : esc_html__( 'See the plugin readme for the list of changes.', 'hurrry-checkout' ),
		);

		return $info;
	}

	/**
	 * Turns WordPress' own automatic updates on for this plugin, once. After
	 * that the choice belongs to the store: "Disable auto-updates" on the
	 * plugins screen sticks, because this never runs again.
	 */
	public function seed_auto_update() {
		if ( get_site_option( self::SEEDED_FLAG ) ) {
			return;
		}

		$file = $this->plugin_file();
		$auto = get_site_option( 'auto_update_plugins', array() );
		if ( ! is_array( $auto ) ) {
			$auto = array();
		}
		if ( ! in_array( $file, $auto, true ) ) {
			$auto[] = $file;
			update_site_option( 'auto_update_plugins', array_values( $auto ) );
		}
		update_site_option( self::SEEDED_FLAG, 1 );
	}

	/**
	 * The current release from the manifest, or null when there is none that
	 * can be trusted (network error, malformed entry, bad signature).
	 *
	 * @param bool $fresh Skip the short cache (used right before installing).
	 * @return array|null
	 */
	private function release( $fresh ) {
		$entry = $fresh ? false : get_site_transient( self::CACHE_KEY );

		if ( ! is_array( $entry ) ) {
			$entry = $this->fetch();
			// Failures are cached too, so a CDN outage is not retried on every admin page.
			set_site_transient( self::CACHE_KEY, null === $entry ? array() : $entry, self::CACHE_TTL );
		}

		return $this->validate( $entry );
	}

	/**
	 * @return array|null Raw manifest entry for this plugin.
	 */
	private function fetch() {
		$response = wp_remote_get(
			self::MANIFEST_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > 65536 ) {
			return null;
		}

		$manifest = json_decode( $body, true );
		if ( ! is_array( $manifest ) || ! isset( $manifest[ self::SLUG ] ) || ! is_array( $manifest[ self::SLUG ] ) ) {
			return null;
		}

		return $manifest[ self::SLUG ];
	}

	/**
	 * @param mixed $entry Raw manifest entry.
	 * @return array|null Normalised release, only when its signature is valid.
	 */
	private function validate( $entry ) {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$version   = isset( $entry['version'] ) && is_string( $entry['version'] ) ? $entry['version'] : '';
		$package   = isset( $entry['package'] ) && is_string( $entry['package'] ) ? $entry['package'] : '';
		$sha256    = isset( $entry['sha256'] ) && is_string( $entry['sha256'] ) ? strtolower( $entry['sha256'] ) : '';
		$signature = isset( $entry['signature'] ) && is_string( $entry['signature'] ) ? $entry['signature'] : '';

		if ( ! preg_match( '/^[0-9]+(\.[0-9]+){1,3}$/', $version ) || ! preg_match( '/^[0-9a-f]{64}$/', $sha256 ) ) {
			return null;
		}

		// The zip of a version has one possible address: nothing outside our CDN folder is ever downloaded.
		if ( self::PACKAGE_BASE . self::SLUG . '-' . $version . '.zip' !== $package ) {
			return null;
		}

		if ( ! $this->signature_ok( self::SLUG . '|' . $version . '|' . $sha256, $signature ) ) {
			return null;
		}

		return array(
			'version'      => $version,
			'package'      => $package,
			'sha256'       => $sha256,
			'requires'     => $this->version_field( $entry, 'requires' ),
			'requires_php' => $this->version_field( $entry, 'requires_php' ),
			'tested'       => $this->version_field( $entry, 'tested' ),
			'changelog'    => isset( $entry['changelog'] ) && is_string( $entry['changelog'] ) ? $entry['changelog'] : '',
		);
	}

	private function version_field( array $entry, $key ) {
		if ( isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) && preg_match( '/^[0-9]+(\.[0-9]+){0,3}$/', $entry[ $key ] ) ) {
			return $entry[ $key ];
		}
		return '';
	}

	private function signature_ok( $message, $signature ) {
		// PHP 7.2+ ships sodium; WordPress bundles a pure-PHP fallback since 5.2.
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}

		$sig = base64_decode( $signature, true );
		$key = base64_decode( self::PUBLIC_KEY, true );
		if ( ! is_string( $sig ) || 64 !== strlen( $sig ) || ! is_string( $key ) || 32 !== strlen( $key ) ) {
			return false;
		}

		try {
			return true === sodium_crypto_sign_verify_detached( $sig, $message, $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
