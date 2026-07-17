<?php
/**
* Plugin Name: GST Invoice for WooCommerce
* Plugin URI: https://gstforecom.com/
* Description: Generate GST-compliant invoices and automated tax slabs (CGST, SGST, IGST, UTGST) for WooCommerce stores in India.
* Version: 2.0
* Requires Plugins: woocommerce
* Author: Stark Digital
* Author URI: https://starkdigital.net
* License: GPLv2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: woo-gst
* Domain Path: /languages
 */

if (!defined('ABSPATH'))
{
    exit; // Exit if accessed directly
}
require_once('inc/functions.php');
require_once __DIR__ . '/inc/wc-gst-privacy.php';

/**
 * Check WooCommerce exists
 */
if ( fn_is_woocommerce_active() ) {
	define('gst_RELATIVE_PATH', plugin_dir_url( __FILE__ ));
	define('gst_ABS_PATH', plugin_dir_path(__FILE__));
	define( 'gst_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
	define( 'gst_BASENAME', plugin_basename(__FILE__) );
	define( 'GST_PRO_LINK', 'https://gstforecom.com/?utm_source=wordpress&utm_medium=plugin_notice');
	
	require_once( 'class-gst-woocommerce-addon.php' );

	$gst_settings = new WC_GST_Settings();
	$gst_settings->init();

} else {
	add_action( 'admin_notices', 'fn_gst_admin_notice__error' );
}

//HPOS Compatibility
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

//User email capture
function admin_menu_woo_settings() {
    add_menu_page('WooGST', 'WooGST', 'edit_posts', 'woogst', 'admin_menu_woo_settings_content', plugins_url( 'woo-gst/images/gst.png' )); 
}
add_action('admin_menu', 'admin_menu_woo_settings');

//Start of HubSpot Embed Code 
function gst_enqueue_hubspot_script() {
    wp_enqueue_script(
        'hs-script-loader',
        '//js.hs-scripts.com/24401330.js',
        array(),
        null,
        true
    );
}
add_action('wp_enqueue_scripts', 'gst_enqueue_hubspot_script');


//End of HubSpot Embed Code

function admin_menu_woo_settings_content(){ 
	$query_string = '&form=submitted';
	
	?>
	
	<div class="woogst-block">
		<a href="https://gstforecom.com/" target="_blank">
			<img style="width:98%" src="<?php echo esc_url( plugins_url( 'woo-gst/images/Woogst_Banner.jpg' ) ); ?>" alt="GST Invoice for WooCommerce Banner">

		</a>
	</div> 
	<?php
}


