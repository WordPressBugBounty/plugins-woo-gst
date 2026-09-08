<?php
/**
 * Custom Class
 *
 * @access public
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_GST_Settings' ) ) {
class WC_GST_Settings {

    const CHECKOUT_GSTIN_FIELD_ID      = 'woo-gst/customer-gstin';
    const CHECKOUT_HAS_GSTIN_FIELD_ID  = 'woo-gst/has-gstin-number';
    const ORDER_GSTIN_META_KEY         = '_woo_gst_gstin_number';
    const ORDER_HAS_GSTIN_META_KEY     = '_woo_gst_has_gstin_number';

    /**
     * Bootstraps the class and hooks required actions & filters.
     */
    public function init() {
        add_filter( 'woocommerce_settings_tabs_array', array( $this, 'fn_add_settings_tab' ), 50 );
        add_action( 'woocommerce_settings_tabs_settings_gst_tab', array( $this, 'fn_settings_tab' ) );
        add_action( 'woocommerce_update_options_settings_gst_tab', array( $this, 'fn_update_settings' ) );
        add_action( 'woocommerce_update_options_tax', array( $this, 'fn_update_tax_settings' ) );
        add_action( 'woocommerce_update_options_settings_gst_tab', array( $this, 'fn_update_tax_settings' ) );
        add_action( 'woocommerce_product_options_general_product_data', array( $this, 'fn_add_product_custom_meta_box' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'fn_save_license_field' ) );

        // Load admin JS properly via enqueue, not echoing <script>.
        add_action( 'admin_enqueue_scripts', array( $this, 'fn_load_custom_wp_admin_script' ) );

        // Customer GSTIN on checkout (classic + block).
        add_filter( 'woocommerce_checkout_fields', array( $this, 'fn_add_checkout_gstin_fields' ), 110 );
        add_action( 'woocommerce_checkout_process', array( $this, 'fn_validate_checkout_gstin' ) );
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'fn_save_checkout_gstin_field' ), 10, 2 );
        add_action( 'woocommerce_init', array( $this, 'fn_register_block_checkout_gstin_field' ) );
        add_action( 'woocommerce_set_additional_field_value', array( $this, 'fn_sync_block_gstin_to_billing_meta' ), 10, 4 );
        add_action( 'woocommerce_blocks_validate_location_contact_fields', array( $this, 'fn_validate_block_contact_fields' ), 10, 3 );
        add_action( 'wp_enqueue_scripts', array( $this, 'fn_enqueue_checkout_assets' ) );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'fn_display_admin_order_customer_gstin' ) );

        add_action( 'woocommerce_email_after_order_table', array( $this, 'fn_woocommerce_gstin_invoice_fields' ) );
        add_action( 'admin_notices', array( $this, 'print_pro_notice' ) );
        add_filter( 'plugin_row_meta', array( $this, 'fn_add_extra_links' ), 10, 2 );
    }

    /**
     * Prints the notice of pro version (escaped)
     */
    public function print_pro_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen ) {
            return;
        }

        $show_on = ( 'woocommerce_page_wc-settings' === $screen->id )
            || ( 'plugins' === $screen->id )
            || ( isset( $_GET['page'] ) && 'woogst' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) );

        if ( ! $show_on ) {
            return;
        }

        $class    = 'notice notice-success is-dismissible';
        $pro_link = defined( 'GST_PRO_LINK' ) ? GST_PRO_LINK : '';

        printf(
            '<div class="%1$s"><p>%2$s <a href="%3$s" target="_blank" rel="noopener">%4$s</a>.</p></div>',
            esc_attr( $class ),
            esc_html__( 'For more features of GST Invoice for WooCommerce', 'woo-gst' ),
            esc_url( $pro_link ),
            esc_html__( 'download PRO version', 'woo-gst' )
        );
    }

    /**
     * Get the WooCommerce checkout page ID.
     *
     * @return int
     */
    private function get_checkout_page_id() {
        if ( ! function_exists( 'wc_get_page_id' ) ) {
            return 0;
        }

        $page_id = wc_get_page_id( 'checkout' );

        return ( $page_id > 0 ) ? (int) $page_id : 0;
    }

    /**
     * Whether the store checkout page uses the WooCommerce Checkout block.
     *
     * @return bool
     */
    private function store_uses_block_checkout() {
        $page_id = $this->get_checkout_page_id();

        if ( ! $page_id || ! function_exists( 'has_block' ) ) {
            return false;
        }

        return has_block( 'woocommerce/checkout', $page_id );
    }

    /**
     * Enqueue checkout GSTIN toggle script and styles.
     */
    public function fn_enqueue_checkout_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
            return;
        }

        if ( ! defined( 'gst_RELATIVE_PATH' ) ) {
            return;
        }

        $asset_version   = '2.1';
        $is_block_checkout = $this->store_uses_block_checkout();

        wp_enqueue_style(
            'woo-gst-checkout',
            gst_RELATIVE_PATH . 'css/custom.css',
            array(),
            $asset_version
        );

        wp_enqueue_script(
            'woo-gst-checkout',
            gst_RELATIVE_PATH . 'js/checkout-gstin.js',
            array( 'jquery' ),
            $asset_version,
            true
        );

        wp_localize_script(
            'woo-gst-checkout',
            'wooGstCheckout',
            array(
                'isBlockCheckout' => $is_block_checkout,
            )
        );
    }

    /**
     * Add GSTIN fields to classic checkout via the standard fields API.
     *
     * Compatible with WooCommerce Checkout Manager and other field-editor plugins.
     *
     * @param array $fields Checkout fields.
     * @return array
     */
    public function fn_add_checkout_gstin_fields( $fields ) {
        if ( $this->store_uses_block_checkout() ) {
            return $fields;
        }

        $fields['billing']['woo_gst_has_gstin_number'] = array(
            'type'     => 'checkbox',
            'label'    => __( 'Have GSTIN Number ?', 'woo-gst' ),
            'required' => false,
            'class'    => array( 'form-row-wide', 'woo-gst-has-gstin-toggle' ),
            'priority' => 195,
        );

        $fields['billing']['woo_gst_gstin_number'] = array(
            'type'        => 'text',
            'label'       => __( 'GSTIN Number', 'woo-gst' ),
            'placeholder' => __( 'GSTIN Number', 'woo-gst' ),
            'required'    => false,
            'class'       => array( 'form-row-wide', 'woo-gst-gstin-input-row' ),
            'priority'    => 196,
        );

        return $fields;
    }

    /**
     * Validate GSTIN when customer opts in on classic checkout.
     */
    public function fn_validate_checkout_gstin() {
        if ( empty( $_POST['woo_gst_has_gstin_number'] ) ) {
            return;
        }

        if ( empty( $_POST['woo_gst_gstin_number'] ) ) {
            wc_add_notice( __( 'Please enter your GSTIN Number.', 'woo-gst' ), 'error' );
            return;
        }

        $gstin = self::sanitize_gstin( wp_unslash( $_POST['woo_gst_gstin_number'] ) );
        if ( strlen( $gstin ) !== 15 ) {
            wc_add_notice( __( 'Please enter a valid 15-character GSTIN Number.', 'woo-gst' ), 'error' );
        }
    }

    /**
     * Validate block checkout GSTIN when the customer opts in.
     *
     * @param WP_Error $errors Validation errors.
     * @param array    $fields Contact field values.
     * @param string   $group  Field group.
     */
    public function fn_validate_block_contact_fields( $errors, $fields, $group ) {
        if ( empty( $fields[ self::CHECKOUT_HAS_GSTIN_FIELD_ID ] ) ) {
            return;
        }

        if ( empty( $fields[ self::CHECKOUT_GSTIN_FIELD_ID ] ) ) {
            $errors->add( 'woo_gst_gstin', __( 'Please enter your GSTIN Number.', 'woo-gst' ) );
            return;
        }

        $gstin = self::sanitize_gstin( $fields[ self::CHECKOUT_GSTIN_FIELD_ID ] );
        if ( strlen( $gstin ) !== 15 ) {
            $errors->add( 'woo_gst_gstin', __( 'Please enter a valid 15-character GSTIN Number.', 'woo-gst' ) );
        }
    }

    /**
     * Register customer GSTIN fields for WooCommerce Checkout block.
     */
    public function fn_register_block_checkout_gstin_field() {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            return;
        }

        if ( ! $this->store_uses_block_checkout() ) {
            return;
        }

        try {
            woocommerce_register_additional_checkout_field(
                array(
                    'id'            => self::CHECKOUT_HAS_GSTIN_FIELD_ID,
                    'label'         => __( 'Have GSTIN Number ?', 'woo-gst' ),
                    'optionalLabel' => __( 'Have GSTIN Number ? (optional)', 'woo-gst' ),
                    'location'      => 'contact',
                    'type'          => 'checkbox',
                    'required'      => false,
                )
            );

            woocommerce_register_additional_checkout_field(
                array(
                    'id'            => self::CHECKOUT_GSTIN_FIELD_ID,
                    'label'         => __( 'GSTIN Number', 'woo-gst' ),
                    'optionalLabel' => __( 'GSTIN Number (optional)', 'woo-gst' ),
                    'location'      => 'contact',
                    'type'          => 'text',
                    'required'      => false,
                    'attributes'    => array(
                        'maxLength' => 15,
                        'title'     => __( 'Enter your 15-character GSTIN', 'woo-gst' ),
                    ),
                )
            );
        } catch ( Throwable $e ) {
            return;
        }
    }

    /**
     * Save customer GSTIN from classic checkout.
     *
     * @param int   $order_id Order ID.
     * @param array $data     Posted checkout data.
     */
    public function fn_save_checkout_gstin_field( $order_id, $data = array() ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $has_gstin = ! empty( $_POST['woo_gst_has_gstin_number'] ) ? '1' : '';
        $order->update_meta_data( self::ORDER_HAS_GSTIN_META_KEY, $has_gstin );

        $gstin = '';
        if ( $has_gstin && isset( $_POST['woo_gst_gstin_number'] ) ) {
            $gstin = self::sanitize_gstin( wp_unslash( $_POST['woo_gst_gstin_number'] ) );
        }

        $order->update_meta_data( self::ORDER_GSTIN_META_KEY, $gstin );
        $order->update_meta_data( '_billing_gstin', $gstin );
        $order->save();
    }

    /**
     * Mirror block checkout GSTIN values to order meta used by emails and admin.
     *
     * @param string         $key       Field key.
     * @param mixed          $value     Field value.
     * @param string         $group     Field group.
     * @param WC_Order|mixed $wc_object Order or customer object.
     */
    public function fn_sync_block_gstin_to_billing_meta( $key, $value, $group, $wc_object ) {
        if ( ! $wc_object instanceof WC_Order ) {
            return;
        }

        if ( self::CHECKOUT_HAS_GSTIN_FIELD_ID === $key ) {
            $wc_object->update_meta_data( self::ORDER_HAS_GSTIN_META_KEY, $value ? '1' : '' );
            return;
        }

        if ( self::CHECKOUT_GSTIN_FIELD_ID === $key ) {
            $gstin = self::sanitize_gstin( $value );
            $wc_object->update_meta_data( self::ORDER_GSTIN_META_KEY, $gstin );
            $wc_object->update_meta_data( '_billing_gstin', $gstin );
        }
    }

    /**
     * Show customer GSTIN on the order edit screen.
     *
     * @param WC_Order $order Order object.
     */
    public function fn_display_admin_order_customer_gstin( $order ) {
        $gstin = self::get_order_customer_gstin( $order );
        if ( '' === $gstin ) {
            return;
        }

        echo '<p><strong>' . esc_html__( 'Customer GSTIN:', 'woo-gst' ) . '</strong> ' . esc_html( $gstin ) . '</p>';
    }

    /**
     * Sanitize a GSTIN value.
     *
     * @param mixed $gstin Raw GSTIN.
     * @return string
     */
    private static function sanitize_gstin( $gstin ) {
        $gstin = sanitize_text_field( (string) $gstin );
        $gstin = preg_replace( '/\s+/', '', $gstin );

        return strtoupper( $gstin );
    }

    /**
     * Get customer GSTIN stored on an order.
     *
     * @param WC_Order|int|null $order Order object or ID.
     * @return string
     */
    public static function get_order_customer_gstin( $order ) {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( $order );
        }

        if ( ! $order instanceof WC_Order ) {
            return '';
        }

        $meta_keys = array(
            self::ORDER_GSTIN_META_KEY,
            '_billing_gstin',
            '_wc_other/' . self::CHECKOUT_GSTIN_FIELD_ID,
            '_wc_billing/' . self::CHECKOUT_GSTIN_FIELD_ID,
        );

        foreach ( $meta_keys as $meta_key ) {
            $gstin = $order->get_meta( $meta_key, true );
            if ( ! empty( $gstin ) ) {
                return self::sanitize_gstin( $gstin );
            }
        }

        return '';
    }

    /**
     * Show store and customer GSTIN on order emails.
     *
     * @param WC_Order|bool $order Order object.
     */
    public function fn_woocommerce_gstin_invoice_fields( $order ) {
        $store_gstin    = get_option( 'woocommerce_gstin_number' );
        $customer_gstin = self::get_order_customer_gstin( $order );

        if ( empty( $store_gstin ) && '' === $customer_gstin ) {
            return;
        }

        if ( ! empty( $store_gstin ) ) {
            ?>
            <p>
                <strong><?php esc_html_e( 'Store GSTIN:', 'woo-gst' ); ?></strong>
                <?php echo esc_html( $store_gstin ); ?>
            </p>
            <?php
        }

        if ( '' !== $customer_gstin ) {
            ?>
            <p>
                <strong><?php esc_html_e( 'Customer GSTIN:', 'woo-gst' ); ?></strong>
                <?php echo esc_html( $customer_gstin ); ?>
            </p>
            <?php
        }
    }

    /**
     * Load small admin JS via enqueue (no inline <script> tags in PHP output)
     */
    public function fn_load_custom_wp_admin_script( $hook = '' ) {
        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return;
        }

        if ( empty( $_GET['tab'] ) || 'settings_gst_tab' !== sanitize_text_field( wp_unslash( $_GET['tab'] ) ) ) {
            return;
        }

        $js = <<<JS
jQuery(document).ready(function($){
  function toggleSlabs(){
    var v = $('#woocommerce_product_types').val();
    if (v === 'single') {
      $('select[name="woocommerce_gst_multi_select_slab[]"]').closest('tr').hide();
      $('select[name="woocommerce_gst_single_select_slab"]').closest('tr').show();
    } else {
      $('select[name="woocommerce_gst_single_select_slab"]').closest('tr').hide();
      $('select[name="woocommerce_gst_multi_select_slab[]"]').closest('tr').show();
    }
  }
  toggleSlabs();
  $(document).on('change', '#woocommerce_product_types', toggleSlabs);
});
JS;

        wp_enqueue_script( 'jquery' );
        wp_add_inline_script( 'jquery', $js, 'after' );
    }

    public function fn_add_product_custom_meta_box() {
        woocommerce_wp_text_input(
            array(
                'id'                => 'hsn_prod_id',
                'label'             => __( 'HSN/SAC Code', 'woo-gst' ),
                'description'       => __( 'HSN/SAC Code is mandatory for GST.', 'woo-gst' ),
                'custom_attributes' => array( 'required' => 'required' ),
                'value'             => get_post_meta( get_the_ID(), 'hsn_prod_id', true ),
            )
        );
    }

    public function fn_save_license_field( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $value = '';
        if ( isset( $_POST['hsn_prod_id'] ) ) {
            $value = sanitize_text_field( wp_unslash( $_POST['hsn_prod_id'] ) );
        }
        update_post_meta( $post_id, 'hsn_prod_id', $value );
    }

    /**
     * Add a new settings tab to the WooCommerce settings tabs array.
     */
    public static function fn_add_settings_tab( $settings_tabs ) {
        $settings_tabs['settings_gst_tab'] = __( 'GST Settings', 'woo-gst' );
        return $settings_tabs;
    }

    /**
     * Output settings via WooCommerce admin fields API.
     */
    public static function fn_settings_tab() {
        woocommerce_admin_fields( self::fn_get_settings() );
    }

    /**
     * Save settings.
     */
    public static function fn_update_settings() {
        woocommerce_update_options( self::fn_get_settings() );
        self::gst_insrt_tax_slab_rows();
        self::fn_gst_callback();
    }

    /**
     * Trigger GST callback on tax tab save
     */
    public static function fn_update_tax_settings() {
        if (
            isset( $_POST['custom_gst_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['custom_gst_nonce'] ) ), 'wc_gst_nonce' )
        ) {
            self::fn_gst_callback();
        }
    }

    /**
     * Get GST tax slabs selected in plugin settings.
     *
     * @return string[]
     */
    private static function get_selected_gst_tax_slabs() {
        $a_gst_tax_slabs             = array();
        $s_woocommerce_product_types = get_option( 'woocommerce_product_types' );

        if ( 'multiple' === $s_woocommerce_product_types ) {
            $s_product_types = get_option( 'woocommerce_gst_multi_select_slab' );
            if ( is_array( $s_product_types ) ) {
                $a_gst_tax_slabs = array_merge( $a_gst_tax_slabs, $s_product_types );
            }
        } elseif ( 'single' === $s_woocommerce_product_types ) {
            $s_product_types = get_option( 'woocommerce_gst_single_select_slab' );
            if ( ! empty( $s_product_types ) ) {
                $a_gst_tax_slabs[] = $s_product_types;
            }
        }

        return array_values( array_filter( array_map( 'trim', $a_gst_tax_slabs ) ) );
    }

    /**
     * Insert tax classes and keep in sync with selected slabs.
     */
    public static function fn_gst_callback() {
        $a_gst_tax_slabs = self::get_selected_gst_tax_slabs();

        if ( empty( $a_gst_tax_slabs ) ) {
            return;
        }

        $woo_version = function_exists( 'woogst_get_woo_version_number' ) ? woogst_get_woo_version_number() : null;
        $uses_wc_tax = class_exists( 'WC_Tax' ) && ( ! $woo_version || version_compare( $woo_version, '3.7.0', '>=' ) );

        if ( $uses_wc_tax ) {
            $existing_tax_classes = WC_Tax::get_tax_classes();

            foreach ( $a_gst_tax_slabs as $tax_value ) {
                if ( in_array( $tax_value, $existing_tax_classes, true ) ) {
                    continue;
                }

                $result = WC_Tax::create_tax_class( $tax_value );
                if ( ! is_wp_error( $result ) ) {
                    $existing_tax_classes[] = $tax_value;
                }
            }

            return;
        }

        // Legacy WooCommerce (< 3.7): store classes in the option.
        $s_woocommerce_tax_classes = get_option( 'woocommerce_tax_classes', '' );
        $a_currunt_tax_slabs       = ! empty( $s_woocommerce_tax_classes )
            ? array_filter( array_map( 'trim', explode( "\n", $s_woocommerce_tax_classes ) ) )
            : array();

        foreach ( $a_gst_tax_slabs as $gst_tax_value ) {
            if ( ! in_array( $gst_tax_value, $a_currunt_tax_slabs, true ) ) {
                $a_currunt_tax_slabs[] = $gst_tax_value;
            }
        }

        update_option( 'woocommerce_tax_classes', implode( "\n", $a_currunt_tax_slabs ) );
    }

    /**
     * Insert tax slab rows into woocommerce_tax_rates
     */
    public static function gst_insrt_tax_slab_rows() {
        global $wpdb;

        $a_multiple_slabs = array();

        if ( isset( $_POST['woocommerce_product_types'] ) ) {
            $product_type = sanitize_text_field( wp_unslash( $_POST['woocommerce_product_types'] ) );

            if ( 'multiple' === $product_type && ! empty( $_POST['woocommerce_gst_multi_select_slab'] ) ) {
                $multi = wp_unslash( $_POST['woocommerce_gst_multi_select_slab'] );
                $multi = is_array( $multi ) ? array_map( 'sanitize_text_field', $multi ) : array();
                $a_multiple_slabs = array_merge( $a_multiple_slabs, $multi );

            } elseif ( 'single' === $product_type && isset( $_POST['woocommerce_gst_single_select_slab'] ) ) {
                $single            = sanitize_text_field( wp_unslash( $_POST['woocommerce_gst_single_select_slab'] ) );
                $a_multiple_slabs[] = $single;
            }
        }

        if ( empty( $a_multiple_slabs ) ) {
            $a_multiple_slabs = self::get_selected_gst_tax_slabs();
        }

        $table_prefix = $wpdb->prefix . 'woocommerce_tax_rates';

        foreach ( $a_multiple_slabs as $a_multiple_slab ) {
            $slab_name = preg_replace( '/%/', '', $a_multiple_slab );
            $state_tax = floatval( $slab_name ) / 2;

            $state    = get_option( 'woocommerce_store_state' );
            // UTs that use UTGST (not SGST). DL/PY/JK keep SGST (have legislature).
            $ut_state = array( 'CH', 'AN', 'DN', 'DD', 'DH', 'LD', 'LA' );

            if ( ! empty( $state ) ) {
                $tax_slab_row_cgst  = $state_tax . '% CGST';
                $tax_slab_row_utgst = $state_tax . '% UTGST';
                $tax_slab_row_sgst  = $state_tax . '% SGST';
                $tax_slab_row_igst  = $slab_name . '% IGST';

                $table_tax_prefix = $wpdb->prefix . 'woocommerce_tax_rates';

                // PREPARED lookups.
                $select_table_tax_cgst = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT tax_rate_id FROM $table_tax_prefix WHERE tax_rate_name = %s",
                        $tax_slab_row_cgst
                    )
                );

                $select_table_tax_utgst = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT tax_rate_id FROM $table_tax_prefix WHERE tax_rate_name = %s",
                        $tax_slab_row_utgst
                    )
                );

                $select_table_tax_sgst = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT tax_rate_id FROM $table_tax_prefix WHERE tax_rate_name = %s",
                        $tax_slab_row_sgst
                    )
                );

                $select_table_tax_igst = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT tax_rate_id FROM $table_tax_prefix WHERE tax_rate_name = %s",
                        $tax_slab_row_igst
                    )
                );

                if ( null === $select_table_tax_cgst || '' === $select_table_tax_cgst ) {
                    $wpdb->insert(
                        $table_prefix,
                        array(
                            'tax_rate_country'  => 'IN',
                            'tax_rate_state'    => $state,
                            'tax_rate'          => $state_tax,
                            'tax_rate_name'     => $state_tax . '% CGST',
                            'tax_rate_priority' => 1,
                            'tax_rate_compound' => 0,
                            'tax_rate_shipping' => 0,
                            'tax_rate_order'    => 0,
                            'tax_rate_class'    => $slab_name,
                        ),
                        array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
                    );
                }

                if ( in_array( $state, $ut_state, true ) ) {
                    if ( null === $select_table_tax_utgst || '' === $select_table_tax_utgst ) {
                        $wpdb->insert(
                            $table_prefix,
                            array(
                                'tax_rate_country'  => 'IN',
                                'tax_rate_state'    => $state,
                                'tax_rate'          => $state_tax,
                                'tax_rate_name'     => $state_tax . '% UTGST',
                                'tax_rate_priority' => 2,
                                'tax_rate_compound' => 0,
                                'tax_rate_shipping' => 0,
                                'tax_rate_order'    => 0,
                                'tax_rate_class'    => $slab_name,
                            ),
                            array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
                        );
                    }
                } else {
                    if ( null === $select_table_tax_sgst || '' === $select_table_tax_sgst ) {
                        $wpdb->insert(
                            $table_prefix,
                            array(
                                'tax_rate_country'  => 'IN',
                                'tax_rate_state'    => $state,
                                'tax_rate'          => $state_tax,
                                'tax_rate_name'     => $state_tax . '% SGST',
                                'tax_rate_priority' => 2,
                                'tax_rate_compound' => 0,
                                'tax_rate_shipping' => 0,
                                'tax_rate_order'    => 0,
                                'tax_rate_class'    => $slab_name,
                            ),
                            array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
                        );
                    }
                }

                if ( null === $select_table_tax_igst || '' === $select_table_tax_igst ) {
                    $wpdb->insert(
                        $table_prefix,
                        array(
                            'tax_rate_country'  => 'IN',
                            'tax_rate_state'    => '',
                            'tax_rate'          => $slab_name,
                            'tax_rate_name'     => $slab_name . '% IGST',
                            'tax_rate_priority' => 1,
                            'tax_rate_compound' => 0,
                            'tax_rate_shipping' => 0,
                            'tax_rate_order'    => 0,
                            'tax_rate_class'    => $slab_name,
                        ),
                        array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
                    );
                }
            }
        }
    }

    /**
     * Settings fields for WooCommerce admin fields API.
     */
    public static function fn_get_settings() {
        $state    = get_option( 'woocommerce_store_state' );
        $settings = array(
            'section_title' => array(
                'name' => __( 'Select Product Type', 'woo-gst' ),
                'type' => 'title',
                'desc' => '',
                'id'   => 'wc_settings_gst_tab_section_title',
            ),

            'GSTIN_number' => array(
                'name'               => __( 'GSTIN Number', 'woo-gst' ),
                'desc'               => __( 'This GSTIN number displays on your invoice.', 'woo-gst' ),
                'id'                 => 'woocommerce_gstin_number',
                'css'                => 'min-width:150px;',
                'std'                => 'left',
                'default'            => '',
                'custom_attributes'  => array( 'required' => 'required' ),
                'type'               => 'text',
            ),

            'store_state' => array(
                'name'               => __( 'Store location state', 'woo-gst' ),
                'desc'               => __( 'Please insert state code of store location.', 'woo-gst' ),
                'id'                 => 'woocommerce_store_state',
                'css'                => 'min-width:150px;',
                'std'                => 'left',
                'default'            => $state,
                'custom_attributes'  => array( 'required' => 'required' ),
                'type'               => 'text',
            ),

            'prod_types' => array(
                'name'        => __( 'Select Product Types', 'woo-gst' ),
                'desc'        => __( 'Select single or multiple tax slab.', 'woo-gst' ),
                'id'          => 'woocommerce_product_types',
                'css'         => 'min-width:150px;height:auto;',
                'std'         => 'left',
                'default'     => 'left',
                'type'        => 'select',
                'options'     => array(
                    'single'   => __( 'Single', 'woo-gst' ),
                    'multiple' => __( 'Multiple', 'woo-gst' ),
                ),
                'desc_tip'    => true,
            ),

            'woocommerce_gst_multi_select_slab' => array(
                'name'        => __( 'Select Multiple Tax Slabs', 'woo-gst' ),
                'desc'        => __( 'Multiple tax slabs.', 'woo-gst' ),
                'id'          => 'woocommerce_gst_multi_select_slab',
                'css'         => 'min-width:150px;',
                'std'         => 'left',
                'default'     => 'left',
                'type'        => 'multi_select_countries',
                'options'     => array(
                    '0%'  => __( '0%', 'woo-gst' ),
                    '5%'  => __( '5%', 'woo-gst' ),
                    '12%' => __( '12%', 'woo-gst' ),
                    '18%' => __( '18%', 'woo-gst' ),
                    '28%' => __( '28%', 'woo-gst' ),
                ),
                'desc_tip'    => true,
            ),

            'woocommerce_gst_single_select_slab' => array(
                'name'        => __( 'Select Tax Slab', 'woo-gst' ),
                'desc'        => __( 'Tax slab.', 'woo-gst' ),
                'id'          => 'woocommerce_gst_single_select_slab',
                'css'         => 'min-width:150px;height:auto;',
                'std'         => 'left',
                'default'     => 'left',
                'type'        => 'select',
                'options'     => array(
                    '0%'  => __( '0%', 'woo-gst' ),
                    '5%'  => __( '5%', 'woo-gst' ),
                    '12%' => __( '12%', 'woo-gst' ),
                    '18%' => __( '18%', 'woo-gst' ),
                    '28%' => __( '28%', 'woo-gst' ),
                ),
                'desc_tip'    => true,
            ),

            'gst_nonce' => array(
                'name'    => __( 'GST nonce', 'woo-gst' ),
                'desc'    => '',
                'id'      => 'custom_gst_nonce',
                'default' => wp_create_nonce( 'wc_gst_nonce' ),
                'type'    => 'hidden',
            ),

            'section_end' => array(
                'type' => 'sectionend',
                'id'   => 'wc_settings_gst_tab_section_end',
            ),
        );

        return apply_filters( 'wc_settings_gst_tab_settings', $settings );
    }

    public function fn_add_extra_links( $links, $file ) {
        if ( defined( 'gst_BASENAME' ) && $file === gst_BASENAME ) {
            $row_meta = array(
                'pro' => '<a href="' . esc_url( defined( 'GST_PRO_LINK' ) ? GST_PRO_LINK : '' ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'PRO Plugin', 'woo-gst' ) . '">' . esc_html__( 'PRO Plugin', 'woo-gst' ) . '</a>',
            );

            return array_merge( $links, $row_meta );
        }

        return (array) $links;
    }
}
}
