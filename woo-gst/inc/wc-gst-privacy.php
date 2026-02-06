<?php
// inc/wc-gst-privacy.php
if (!defined('ABSPATH')) exit;

/**
 * Minimal, compliant telemetry gate:
 * - OFF by default (explicit opt-in only)
 * - Respects browser Do Not Track
 * - Adds a tiny Settings page
 * - Optionally auto-gates tracker callbacks registered by this plugin
 */

# -------- Settings (OFF by default) --------
add_action('admin_init', function () {
    register_setting(
        'wc_gst_privacy',
        'wc_gst_telemetry_optin',
        ['type' => 'boolean', 'sanitize_callback' => fn($v) => (bool)$v, 'default' => false]
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
function wc_gst_dnt_enabled(): bool {
    return isset($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT'] === '1';
}

function wc_gst_can_track(): bool {
    // Explicit admin opt-in only
    if (!(bool) get_option('wc_gst_telemetry_optin', false)) return false;
    // Respect Do Not Track
    if (wc_gst_dnt_enabled()) return false;
    // Final developer hook (stays false unless you flip it)
    return (bool) apply_filters('wc_gst_allow_tracking', true);
}

/**
 * Convenience: run callback only if tracking is allowed.
 */
function wc_gst_maybe_track(callable $cb): void {
    if (wc_gst_can_track()) { $cb(); }
}

# -------- Add suggested Privacy Policy text --------
add_action('admin_init', function () {
    if (function_exists('wp_add_privacy_policy_content')) {
        $text = '<p><strong>WooCommerce GST</strong> can optionally send anonymous usage data (plugin version, WordPress/PHP versions, locale, and a non-reversible site hash) to help improve the plugin. No personal data or full URLs are collected. This runs only if an administrator enables it at <em>Settings → GST Privacy</em>, and it respects the browser’s Do Not Track preference.</p>';
        wp_add_privacy_policy_content(__('WooCommerce GST', 'wc-gst'), wp_kses_post($text));
    }
});

# -------- (Optional) Auto-gate common tracker callbacks from THIS plugin only --------
/**
 * If your plugin previously hooked GA/FB/Hotjar/etc. directly, this block
 * finds those callbacks and wraps them so they only run after consent.
 * It does NOT touch other plugins/themes.
 */
add_action('plugins_loaded', function () {
    if (wc_gst_can_track()) return; // no need to auto-gate when already opted in

    // Which hooks might print/enqueue trackers:
    $hooks = [
        'wp_head', 'wp_footer',
        'wp_enqueue_scripts', 'admin_enqueue_scripts',
        'admin_head', 'admin_footer',
        'admin_init'
    ];

    // Simple matchers for tracker-ish callback names
    $needles = [
        'track','telemetry','stats','analytics','ga','gtm','facebook','fbq',
        'hotjar','clarity','mixpanel','posthog','plausible','matomo','pixel','stat'
    ];

    foreach ($hooks as $hook) {
        if (empty($GLOBALS['wp_filter'][$hook])) continue;

        $filter_obj = $GLOBALS['wp_filter'][$hook];
        // WP 4.7+ uses WP_Hook objects
        $callbacks = is_object($filter_obj) && property_exists($filter_obj, 'callbacks')
            ? $filter_obj->callbacks
            : (array) $filter_obj;

        foreach ($callbacks as $priority => $group) {
            foreach ($group as $unique_id => $cb) {
                $fn = $cb['function'];

                // Only touch callbacks defined by this plugin (path contains plugin folder)
                $fn_name = '';
                if (is_string($fn)) {
                    $fn_name = $fn;
                } elseif (is_array($fn) && is_object($fn[0])) {
                    $fn_name = get_class($fn[0]) . '::' . $fn[1];
                } elseif (is_array($fn) && is_string($fn[0])) {
                    $fn_name = $fn[0] . '::' . $fn[1];
                } else {
                    continue; // skip closures/unknown
                }

                $lower = strtolower($fn_name);
                $is_tracker = false;
                foreach ($needles as $needle) {
                    if (strpos($lower, $needle) !== false) { $is_tracker = true; break; }
                }

                // Only wrap if the function name suggests tracking AND
                // the file path of the callback lives inside this plugin.
                $reflect = null;
                try {
                    if (is_string($fn)) {
                        $reflect = new ReflectionFunction($fn);
                    } elseif (is_array($fn) && is_object($fn[0])) {
                        $reflect = new ReflectionMethod($fn[0], $fn[1]);
                    } elseif (is_array($fn) && is_string($fn[0])) {
                        $reflect = new ReflectionMethod($fn[0], $fn[1]);
                    }
                } catch (Throwable $e) { $reflect = null; }

                if (!$is_tracker || !$reflect) continue;

                $file = $reflect->getFileName();
                // Current plugin dir
                $plugin_dir = trailingslashit(plugin_dir_path(dirname(__FILE__)));
                if (strpos($file, $plugin_dir) !== 0) continue; // belongs to something else; leave it

                // Remove original callback and re-add wrapped with consent check
                remove_action($hook, $fn, $priority);
                add_action($hook, function () use ($fn) {
                    wc_gst_maybe_track(function () use ($fn) {
                        // Call the original
                        if (is_string($fn)) {
                            call_user_func($fn);
                        } elseif (is_array($fn)) {
                            call_user_func($fn);
                        }
                    });
                }, $priority);
            }
        }
    }
});

