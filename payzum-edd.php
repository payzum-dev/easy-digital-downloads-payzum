<?php
/**
 * Plugin Name: Payzum Crypto & Stablecoin Payments for Easy Digital Downloads
 * Plugin URI:  https://payzum.com
 * Description: Accept crypto and stablecoins (USDC/USDT, multi-chain) in EDD with Payzum. Buyers choose the coin on the Payzum checkout. Non-custodial — funds settle to your own wallet.
 * Version:     1.3.0
 * Author:      Payzum
 * Author URI:  https://payzum.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payzum-edd
 * Requires at least: 5.6
 * Requires PHP: 8.1
 * Requires Plugins: easy-digital-downloads
 *
 * Disclosure: contributed by Payzum. Opt-in gateway; does not change default behaviour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'PAYZUM_EDD_VERSION', '1.3.0' );
define( 'PAYZUM_EDD_PLUGIN_FILE', __FILE__ );
define( 'PAYZUM_EDD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Boot once EDD is loaded.
 *
 * Everything the gateway does lives behind this check — including the IPN listener, which used to
 * be registered unconditionally and returned a fatal 500 (rather than a 4xx) when EDD was inactive.
 * Payzum retries a delivery about six times either way — verified 2026-08-26, a 4xx does NOT
 * stop the retries the way the prose docs claim — so the listener was answering a PHP fatal
 * six times over. Booting behind the guard makes it a clean 404 instead.
 */
add_action( 'plugins_loaded', 'payzum_edd_init', 11 );

function payzum_edd_init() {
	if ( ! function_exists( 'EDD' ) ) {
		add_action( 'admin_notices', 'payzum_edd_missing_edd_notice' );
		return;
	}

	// The official payzum/payzum-php SDK, vendored. Namespaced (Payzum\*), so it coexists with
	// the WooCommerce plugin's copy: whichever autoloader registers first serves the classes.
	require_once PAYZUM_EDD_PLUGIN_DIR . 'vendor/autoload.php';
	require_once PAYZUM_EDD_PLUGIN_DIR . 'includes/class-payzum-edd-gateway.php';

	payzum_edd_maybe_upgrade();

	new Payzum_EDD_Gateway();

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'payzum_edd_settings_link' );
}

function payzum_edd_settings_link( $links ) {
	$url  = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=gateways&section=payzum' );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'payzum-edd' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}

function payzum_edd_missing_edd_notice() {
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'Payzum Crypto Payments requires Easy Digital Downloads to be installed and active.', 'payzum-edd' )
		. '</p></div>';
}

/**
 * One-time upgrade routine.
 *
 * 1.1.0 moved coin selection out of the plugin: the buyer now picks on the Payzum hosted checkout,
 * limited to the merchant's allowlist (Payzum dashboard -> Merchants -> Settings -> Accepted
 * tokens). The old single "Settlement currency" is therefore meaningless — and its default,
 * usdttrc20, carries a $100 network minimum, so an untouched install rejected every ordinary order
 * with AMOUNT_BELOW_MINIMUM. Drop it rather than leave a setting that can only do harm.
 *
 * The IPN signature header is fixed by the platform, so the field that let a merchant get it wrong
 * goes too.
 */
function payzum_edd_maybe_upgrade() {
	if ( get_option( 'payzum_edd_version' ) === PAYZUM_EDD_VERSION ) {
		return;
	}

	$settings = get_option( 'edd_settings' );
	if ( is_array( $settings ) ) {
		$obsolete = array(
			'payzum_pay_currency', // single settlement currency; the buyer now chooses
			'payzum_sig_header',   // the IPN header is fixed, never merchant-configurable
		);
		$changed = false;
		foreach ( $obsolete as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				unset( $settings[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'edd_settings', $settings );
		}
	}

	update_option( 'payzum_edd_version', PAYZUM_EDD_VERSION );
}
