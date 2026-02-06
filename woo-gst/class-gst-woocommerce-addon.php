
<?php
/**
 * Custom Class
 *
 * @access public
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_GST_Settings {

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

        add_action( 'woocommerce_email_after_order_table', array( $this, 'fn_woocommerce_gstin_invoice_fields' ) );
        add_action( 'admin_notices', array( $this, 'print_pro_notice' ) );
        add_filter( 'plugin_row_meta', array( $this, 'fn_add_extra_links' ), 10, 2 );
    }

    /**
     * Prints the notice of pro version (escaped)
     */
    public function print_pro_notice() {
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
     * Show GSTIN on emails (escaped)
     */
    public function fn_woocommerce_gstin_invoice_fields( $order ) {
        ?>
        <p>
            <strong><?php esc_html_e( 'GSTIN Number:', 'woo-gst' ); ?></strong>
            <?php echo esc_html( get_option( 'woocommerce_gstin_number' ) ); ?>
        </p>
        <?php
    }

    /**
     * Load small admin JS via enqueue (no inline <script> tags in PHP output)
     */
    public function fn_load_custom_wp_admin_script( $hook = '' ) {
        // Optionally scope to WooCommerce settings screen only:
        // if ( 'woocommerce_page_wc-settings' !== $hook ) { return; }

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
        self::gst_insrt_tax_slab_rows();
        woocommerce_update_options( self::fn_get_settings() );
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
     * Insert tax classes and keep in sync with selected slabs
     */
    public static function fn_gst_callback() {
        global $wpdb;

        $table_prefix            = $wpdb->prefix . 'wc_tax_rate_classes';
        $a_currunt_tax_slabs     = array();
        $a_gst_tax_slabs         = array();
        $s_woocommerce_product_types = get_option( 'woocommerce_product_types' );

        if ( isset( $s_woocommerce_product_types ) && 'multiple' === $s_woocommerce_product_types ) {
            $s_product_types = get_option( 'woocommerce_gst_multi_select_slab' );
            if ( is_array( $s_product_types ) ) {
                $a_gst_tax_slabs = array_merge( $a_gst_tax_slabs, $s_product_types );
            }
        } elseif ( isset( $s_woocommerce_product_types ) && 'single' === $s_woocommerce_product_types ) {
            $s_product_types = get_option( 'woocommerce_gst_single_select_slab' );
            if ( ! empty( $s_product_types ) ) {
                $a_gst_tax_slabs[] = $s_product_types;
            }
        }

        $s_woocommerce_tax_classes = get_option( 'woocommerce_tax_classes' );

        if ( isset( $s_woocommerce_tax_classes ) ) {
            // $a_currunt_tax_slabs = explode( PHP_EOL, $s_woocommerce_tax_classes );
            $a_currunt_tax_slabs = array();

            $i_old_count   = count( $a_currunt_tax_slabs );
            $old_tax_slabs = $a_currunt_tax_slabs;

            foreach ( $a_gst_tax_slabs as $gst_tax_value ) {
                if ( ! in_array( $gst_tax_value, $a_currunt_tax_slabs, true ) ) {
                    $a_currunt_tax_slabs[] = $gst_tax_value;
                }
            }

            $i_new_count = count( $a_currunt_tax_slabs );
            if ( $i_new_count === $i_old_count ) {
                return;
            }

            $diff1 = array_diff( $old_tax_slabs, $a_currunt_tax_slabs );
            $diff2 = array_diff( $a_currunt_tax_slabs, $old_tax_slabs );

            if ( ! empty( $diff1 ) || ! empty( $diff2 ) ) {
                $tax_slab_array = $a_currunt_tax_slabs;

                if ( function_exists( 'woogst_get_woo_version_number' ) && woogst_get_woo_version_number() >= '3.7.0' ) {
                    foreach ( $tax_slab_array as $tax_value ) {
                        if ( empty( $tax_value ) ) {
                            continue;
                        }
                        $slug = str_replace( '%', '', $tax_value );

                        // PREPARED: avoid raw interpolation.
                        $tax_rate_class_id = $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT tax_rate_class_id FROM $table_prefix WHERE name = %s",
                                $tax_value
                            )
                        );

                        if ( ( null === $tax_rate_class_id || '' === $tax_rate_class_id ) ) {
                            $wpdb->insert(
                                $table_prefix,
                                array( 'name' => $tax_value, 'slug' => $slug ),
                                array( '%s', '%s' )
                            );
                        }
                    }
                }
            } else {
                return;
            }
        }

        $a_currunt_tax_slabs = ( ! $a_currunt_tax_slabs ) ? $a_gst_tax_slabs : $a_currunt_tax_slabs;
        $a_currunt_tax_slabs = implode( PHP_EOL, $a_currunt_tax_slabs );
        update_option( 'woocommerce_tax_classes', $a_currunt_tax_slabs );
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

        $table_prefix = $wpdb->prefix . 'woocommerce_tax_rates';

        $s_woocommerce_tax_classes = get_option( 'woocommerce_tax_classes' );
        $a_currunt_tax_slabs = array();

        if ( ! empty( $s_woocommerce_tax_classes ) ) {
            $a_currunt_tax_slabs = explode( PHP_EOL, $s_woocommerce_tax_classes );
        }

        foreach ( $a_multiple_slabs as $a_multiple_slab ) {
            $slab_name = preg_replace( '/%/', '', $a_multiple_slab );
            $state_tax = floatval( $slab_name ) / 2;

            $state    = get_option( 'woocommerce_store_state' );
            $ut_state = array( 'CH', 'AN', 'DN', 'DD', 'LD' );

            if ( isset( $state ) ) {
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
                'custom_attributes'  => array( 'required' => 'required', 'readonly' => 'readonly' ),
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
                'name'        => __( 'GST nonce', 'woo-gst' ),
                'desc'        => __( 'GST nonce.', 'woo-gst' ),
                'id'          => 'woocommerce_gst_nonce',
                'css'         => 'min-width:150px;',
                'std'         => 'left',
                'default'     => wp_nonce_field( 'wc_gst_nonce', 'custom_gst_nonce' ),
                'type'        => 'hidden',
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
