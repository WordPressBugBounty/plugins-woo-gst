<?php
/**
* Plugin Name: GST Invoice for WooCommerce
* Plugin URI: https://gstforecom.com/
* Description: Generate GST-compliant invoices and automated tax slabs (CGST, SGST, IGST, UTGST) for WooCommerce stores in India.
* Version: 2.1
* Requires Plugins: woocommerce
* Author: Stark Digital
* Author URI: https://starkdigital.net
* License: GPLv2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: woo-gst
* Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Bootstrap plugin safely so activation cannot white-screen the admin.
 */
if ( ! function_exists( 'woo_gst_bootstrap_plugin' ) ) {
function woo_gst_bootstrap_plugin() {
	require_once __DIR__ . '/inc/functions.php';
	require_once __DIR__ . '/inc/wc-gst-privacy.php';

	if ( ! function_exists( 'fn_is_woocommerce_active' ) || ! fn_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'fn_gst_admin_notice__error' );
		return;
	}

	if ( ! defined( 'gst_RELATIVE_PATH' ) ) {
		define( 'gst_RELATIVE_PATH', plugin_dir_url( __FILE__ ) );
	}
	if ( ! defined( 'gst_ABS_PATH' ) ) {
		define( 'gst_ABS_PATH', plugin_dir_path( __FILE__ ) );
	}
	if ( ! defined( 'gst_PLUGIN_PATH' ) ) {
		define( 'gst_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
	}
	if ( ! defined( 'gst_BASENAME' ) ) {
		define( 'gst_BASENAME', plugin_basename( __FILE__ ) );
	}
	if ( ! defined( 'GST_PRO_LINK' ) ) {
		define( 'GST_PRO_LINK', 'https://gstforecom.com/?utm_source=wordpress&utm_medium=plugin_notice' );
	}

	if ( ! class_exists( 'WC_GST_Settings' ) ) {
		require_once __DIR__ . '/class-gst-woocommerce-addon.php';
	}

	if ( class_exists( 'WC_GST_Settings' ) ) {
		$gst_settings = new WC_GST_Settings();
		$gst_settings->init();
	}
}
}

try {
	woo_gst_bootstrap_plugin();
} catch ( Throwable $e ) {
	add_action(
		'admin_notices',
		function () use ( $e ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: error message */
						__( 'GST Invoice for WooCommerce failed to load: %s', 'woo-gst' ),
						$e->getMessage()
					)
				)
			);
		}
	);
}

// HPOS Compatibility
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Admin menu
if ( ! function_exists( 'admin_menu_woo_settings' ) ) {
	function admin_menu_woo_settings() {
		add_menu_page(
			'WooGST',
			'WooGST',
			'edit_posts',
			'woogst',
			'admin_menu_woo_settings_content',
			plugins_url( 'woo-gst/images/gst.png' )
		);
	}
	add_action( 'admin_menu', 'admin_menu_woo_settings' );
}

// HubSpot Embed Code
if ( ! function_exists( 'gst_enqueue_hubspot_script' ) ) {
	function gst_enqueue_hubspot_script() {
		if ( ! is_admin() ) {
			return;
		}

		wp_enqueue_script(
			'hs-script-loader',
			'//js.hs-scripts.com/24401330.js',
			array(),
			null,
			true
		);
	}
	add_action( 'admin_enqueue_scripts', 'gst_enqueue_hubspot_script' );
}

if ( ! function_exists( 'admin_menu_woo_settings_content' ) ) {
	function admin_menu_woo_settings_content() {
		?>
		<div class="woogst-block">
			<a href="https://gstforecom.com/" target="_blank">
				<img style="width:98%" src="<?php echo esc_url( plugins_url( 'woo-gst/images/Woogst_Banner.jpg' ) ); ?>" alt="GST Invoice for WooCommerce Banner">
			</a>
		</div>
		<?php
	}
}
