<?php
// inc/wc-gst-privacy.php
if (!defined('ABSPATH')) exit;

/**
 * Minimal, compliant telemetry gate:
 * - OFF by default (explicit opt-in only)
 * - Respects browser Do Not Track
 * - Adds a tiny Settings page
 */

# -------- Settings (OFF by default) --------
add_action('admin_init', function () {
    register_setting(
        'wc_gst_privacy',
        'wc_gst_telemetry_optin',
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'wc_gst_sanitize_telemetry_optin',
            'default'           => false,
        )
    );
});

# -------- Settings UI (does not affect GST features) --------
add_action('admin_menu', function () {
    add_options_page(
        __('WooCommerce GST — Privacy', 'wc-gst'),
        __('GST Privacy', 'wc-gst'),
        'manage_options',
        'wc-gst-privacy',
        function () {
            ?>
            <div class="wrap">
                <h1><?php echo esc_html__('WooCommerce GST — Privacy', 'wc-gst'); ?></h1>
                <form method="post" action="options.php">
                    <?php settings_fields('wc_gst_privacy'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Share anonymous usage data', 'wc-gst'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="wc_gst_telemetry_optin" value="1"
                                        <?php checked( (bool) get_option('wc_gst_telemetry_optin', false) ); ?> />
                                    <?php esc_html_e('I agree to share anonymous usage data to improve the plugin.', 'wc-gst'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Disabled by default. We also respect your browser’s “Do Not Track.”', 'wc-gst'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        }
    );
});

# -------- Helper gates --------
if ( ! function_exists( 'wc_gst_sanitize_telemetry_optin' ) ) {
	function wc_gst_sanitize_telemetry_optin( $v ) {
		return (bool) $v;
	}
}

if ( ! function_exists( 'wc_gst_dnt_enabled' ) ) {
	function wc_gst_dnt_enabled() {
		return isset( $_SERVER['HTTP_DNT'] ) && '1' === $_SERVER['HTTP_DNT'];
	}
}

if ( ! function_exists( 'wc_gst_can_track' ) ) {
	function wc_gst_can_track() {
		if ( ! (bool) get_option( 'wc_gst_telemetry_optin', false ) ) {
			return false;
		}
		if ( wc_gst_dnt_enabled() ) {
			return false;
		}
		return (bool) apply_filters( 'wc_gst_allow_tracking', true );
	}
}

if ( ! function_exists( 'wc_gst_maybe_track' ) ) {
	function wc_gst_maybe_track( $cb ) {
		if ( is_callable( $cb ) && wc_gst_can_track() ) {
			$cb();
		}
	}
}

# -------- Add suggested Privacy Policy text --------
add_action('admin_init', function () {
    if (function_exists('wp_add_privacy_policy_content')) {
        $text = '<p><strong>WooCommerce GST</strong> can optionally send anonymous usage data (plugin version, WordPress/PHP versions, locale, and a non-reversible site hash) to help improve the plugin. No personal data or full URLs are collected. This runs only if an administrator enables it at <em>Settings → GST Privacy</em>, and it respects the browser’s Do Not Track preference.</p>';
        wp_add_privacy_policy_content(__('WooCommerce GST', 'wc-gst'), wp_kses_post($text));
    }
});
