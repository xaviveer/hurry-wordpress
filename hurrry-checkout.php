<?php
/**
 * Plugin Name:       Hurrry Checkout
 * Plugin URI:        https://hurrry.com/
 * Description:       Replaces the WooCommerce checkout with the Hurrry hosted checkout. Builds the exact same payload as the Shopify hurry.js integration.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Hurrry
 * License:           GPL-2.0-or-later
 * Text Domain:       hurrry-checkout
 *
 * WC requires at least: 6.0
 * WC tested up to:      9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HURRRY_VERSION', '1.1.0' );
define( 'HURRRY_PLUGIN_FILE', __FILE__ );
define( 'HURRRY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HURRRY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HURRRY_OPTION_KEY', 'hurrry_settings' );

require_once HURRRY_PLUGIN_DIR . 'includes/class-hurrry-settings.php';
require_once HURRRY_PLUGIN_DIR . 'includes/class-hurrry-cart-rest.php';
require_once HURRRY_PLUGIN_DIR . 'includes/class-hurrry-frontend.php';

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', HURRRY_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', HURRRY_PLUGIN_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'Hurrry Checkout requires WooCommerce to be installed and active.', 'hurrry-checkout' );
					echo '</p></div>';
				}
			);
			return;
		}

		Hurrry_Settings::instance();
		Hurrry_Cart_Rest::instance();
		Hurrry_Frontend::instance();
	}
);

/**
 * Settings link on the plugins list.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=hurrry-checkout' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'hurrry-checkout' ) . '</a>' );
		return $links;
	}
);
