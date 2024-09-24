<?php
/**
 * Plugin Name: WooCommerce GST
 * Description: WooCommerce addon for GST.
 * Author: Stark Digital
 * Author URI: https://www.starkdigital.net
 * Version: 1.6
 * Plugin URI: https://www.woocommercegst.co.in
 * WC requires at least: 3.0.0
 * WC tested up to: 8.9.1
 */

if (!defined('ABSPATH'))
{
    exit; // Exit if accessed directly
}
require_once('inc/functions.php');
/**
 * Check WooCommerce exists
 */
if ( fn_is_woocommerce_active() ) {
	define('gst_RELATIVE_PATH', plugin_dir_url( __FILE__ ));
	define('gst_ABS_PATH', plugin_dir_path(__FILE__));
	define( 'gst_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
	define( 'gst_BASENAME', plugin_basename(__FILE__) );
	define( 'GST_PRO_LINK', 'https://www.woocommercegst.co.in/?utm_source=wordpress&utm_medium=plugin_notice');
	
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

function admin_menu_woo_settings_content(){ 
	$query_string = '&form=submitted';
	
	?>
	<!-- Start of HubSpot Embed Code -->
		<script type="text/javascript" id="hs-script-loader" async defer src="//js.hs-scripts.com/24401330.js"></script>
	<!-- End of HubSpot Embed Code -->
	<div class="woogst-block">
		<a href="https://www.woocommercegst.co.in/" target="_blank">
			<img style="width:98%" src="<?php echo plugins_url( 'woo-gst/images/Woogst_Banner.jpg' );?>">
		</a>
	</div> 
	<?php
}


