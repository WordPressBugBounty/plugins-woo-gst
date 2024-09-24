<?php
function fn_gst_admin_notice__error() {
	$class = 'notice notice-error';
	$message = __( 'GST Addon is enabled but not effective. It requires WooCommerce in order to work.', 'gst' );

	printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message ); 
}
/**
 * Functions used by plugins
 */
if ( ! class_exists( 'WC_GST_Dependencies' ) )
	require_once 'class-wc-dependencies.php';

/**
 * WC Detection
 */
if ( ! function_exists( 'fn_is_woocommerce_active' ) ) {
	function fn_is_woocommerce_active() {
		return WC_GST_Dependencies::fn_woocommerce_active_check();
	}
}

if ( ! function_exists( 'pr' ) ) {
	function pr($arr) {
		echo "<pre>";print_r($arr); echo "</pre>";
	}
}

if ( ! function_exists( 'woogst_get_woo_version_number' ) ) {
	function woogst_get_woo_version_number() {
	        // If get_plugins() isn't available, require it
		if ( ! function_exists( 'get_plugins' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		
	        // Create the plugins folder and file variables
		$plugin_folder = get_plugins( '/' . 'woocommerce' );
		$plugin_file = 'woocommerce.php';
		
		// If the plugin version number is set, return it 
		if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
			return $plugin_folder[$plugin_file]['Version'];

		} else {
		// Otherwise return null
			return NULL;
		}
	}
}

?>