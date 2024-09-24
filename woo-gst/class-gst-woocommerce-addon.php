<?php
/**
 * Custom Class
 *
 * @access public
 * @return void
*/

class WC_GST_Settings {
    /**
     * Bootstraps the class and hooks required actions & filters.
     *
     */
    public function init() {

        add_filter( 'woocommerce_settings_tabs_array', array( $this ,'fn_add_settings_tab' ), 50 );
        add_action( 'woocommerce_settings_tabs_settings_gst_tab', array( $this , 'fn_settings_tab') );
        add_action( 'woocommerce_update_options_settings_gst_tab', array( $this , 'fn_update_settings') );
        add_action( 'woocommerce_update_options_tax', array( $this , 'fn_update_tax_settings') );
        // add_action( 'init', array( $this , 'fn_gst_callback') );
        add_action( 'woocommerce_update_options_settings_gst_tab', array( $this , 'fn_update_tax_settings') );
        add_action('woocommerce_product_options_general_product_data', array( $this , 'fn_add_product_custom_meta_box') );
        add_action( 'woocommerce_process_product_meta', array( $this , 'fn_save_license_field') );
        add_action( 'admin_print_scripts',  array( $this , 'fn_load_custom_wp_admin_script'), 999 );
        add_action( 'woocommerce_email_after_order_table', array( $this , 'fn_woocommerce_gstin_invoice_fields') );
        add_action( 'admin_notices', array( $this , 'print_pro_notice') );
        add_filter( 'plugin_row_meta', array( $this, 'fn_add_extra_links' ), 10, 2 );
    }

    /**
     * print_pro_notice
     * Prints the notice of pro version
     */
    public function print_pro_notice() {
        $class = 'notice notice-success is-dismissible';
        $pro_link = GST_PRO_LINK;

        printf( '<div class="%1$s"><p>For more feature of WooCommerce GST <a href="%2$s" target="_blank">download PRO version</a>.</p></div>', $class, $pro_link );
    }
    function fn_woocommerce_gstin_invoice_fields( $order ) {
        ?>
        <p><strong><?php _e('GSTIN Number:', 'woocommerce'); ?></strong> <?php echo get_option('woocommerce_gstin_number'); ?></p>
        <?php
    }

    public function fn_load_custom_wp_admin_script() {

       ?>
       <script>
        jQuery(document).ready(function($) {
            
            if($('#woocommerce_product_types').val() == 'multiple'){
                hide_singe();
            } else {
                hide_mutiple();
            }
            $('#woocommerce_product_types').change(function(){
                if($(this).val() == 'single'){
                    hide_mutiple();
                } else {
                    hide_singe();
                }
            }); 

            function hide_singe(){
                $('select[name="woocommerce_gst_single_select_slab"]').parents('tr:first').hide();
                $('select[name="woocommerce_gst_multi_select_slab[]"]').parents('tr:first').show();
            }

            function hide_mutiple(){
                $('select[name="woocommerce_gst_multi_select_slab[]"]').parents('tr:first').hide();
                $('select[name="woocommerce_gst_single_select_slab"]').parents('tr:first').show();
            }
        });
       </script>
       <?php
    }

    public function fn_add_product_custom_meta_box() {
        woocommerce_wp_text_input( 
            array( 
                'id'            => 'hsn_prod_id', 
                'label'         => __('HSN/SAC Code', 'woocommerce' ), 
                'description'   => __( 'HSN/SAC Code is mandatory for GST.', 'woocommerce' ),
                'custom_attributes' => array( 'required' => 'required' ),
                'value'         => get_post_meta( get_the_ID(), 'hsn_prod_id', true )
                )
            );
    }

    public function fn_save_license_field( $post_id ) {
        $value = ( $_POST['hsn_prod_id'] )? sanitize_text_field( $_POST['hsn_prod_id'] ) : '' ;
        update_post_meta( $post_id, 'hsn_prod_id', $value );
    }
    
    /**
     * Add a new settings tab to the WooCommerce settings tabs array.
     *
     * @param array $settings_tabs Array of WooCommerce setting tabs & their labels, excluding the Subscription tab.
     * @return array $settings_tabs Array of WooCommerce setting tabs & their labels, including the Subscription tab.
     */
    public static function fn_add_settings_tab( $settings_tabs ) {
        $settings_tabs['settings_gst_tab'] = __( 'GST Settings', 'woocommerce' );
        return $settings_tabs;
    }
    /**
     * Uses the WooCommerce admin fields API to output settings via the @see woocommerce_admin_fields() function.
     *
     * @uses woocommerce_admin_fields()
     * @uses self::fn_get_settings()
     */
    public static function fn_settings_tab() {
        woocommerce_admin_fields( self::fn_get_settings() );
    }
    /**
     * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
     *
     * @uses woocommerce_update_options()
     * @uses self::fn_get_settings()
     */
    public static function fn_update_settings() {
        self::gst_insrt_tax_slab_rows();
        woocommerce_update_options( self::fn_get_settings() );
    }

    /**
     * call to gst_callback function on tax tab save button.
     *
     */
    public static function fn_update_tax_settings() {
        if ( isset( $_POST['custom_gst_nonce'] ) && wp_verify_nonce( $_POST['custom_gst_nonce'], 'wc_gst_nonce' )){
            self::fn_gst_callback();
        }

    }

    /**
     * Uses the WooCommerce options API to save settings via the @see woocommerce_update_options() function.
     *
     * @uses woocommerce_set_gst_tax_slabs()
     * @uses self::gst_callback()
     */
    public static function fn_gst_callback() {
        global $wpdb;
        $table_prefix = $wpdb->prefix . "wc_tax_rate_classes";
        $a_currunt_tax_slabs = array();
        $a_gst_tax_slabs = array();
        $s_woocommerce_product_types = get_option( 'woocommerce_product_types' );

        if( isset( $s_woocommerce_product_types ) && $s_woocommerce_product_types == 'multiple' ){
            $s_product_types = get_option( 'woocommerce_gst_multi_select_slab' );
            $a_gst_tax_slabs = array_merge( $a_gst_tax_slabs, $s_product_types );

        } elseif( isset( $s_woocommerce_product_types ) && $s_woocommerce_product_types == 'single' ) {
            $s_product_types = get_option( 'woocommerce_gst_single_select_slab' );
            array_push( $a_gst_tax_slabs, $s_product_types );
        }

        $s_woocommerce_tax_classes = get_option('woocommerce_tax_classes');

        if( isset( $s_woocommerce_tax_classes ) ){
            // $a_currunt_tax_slabs = explode( PHP_EOL, $s_woocommerce_tax_classes );
            $a_currunt_tax_slabs = array();

            $i_old_count = count( $a_currunt_tax_slabs );
            $old_tax_slabs = $a_currunt_tax_slabs;
            foreach ( $a_gst_tax_slabs as $gst_tax_value ) {
                if ( !in_array( $gst_tax_value, $a_currunt_tax_slabs ) ) 
                    array_push( $a_currunt_tax_slabs, $gst_tax_value );
            }

            $i_new_count = count( $a_currunt_tax_slabs );
             if( $i_new_count == $i_old_count ){
                return;
            } 
            $diff1 = array_diff($old_tax_slabs,$a_currunt_tax_slabs);
            $diff2 = array_diff($a_currunt_tax_slabs,$old_tax_slabs);
            
            if(!empty($diff1) || !empty($diff2)) {
                $tax_slab_array = $a_currunt_tax_slabs;
                if(woogst_get_woo_version_number() >= '3.7.0') {
                    foreach ($tax_slab_array as $tax_value) {
                        $slug = str_replace('%', '', $tax_value);
                        $tax_rate_class_id = $wpdb->get_var("SELECT tax_rate_class_id FROM $table_prefix WHERE name='$tax_value'");
                        if(($tax_rate_class_id == NULL || empty($tax_rate_class_id)) && !empty($tax_value)) {
                            $wpdb->insert($table_prefix,array( 'name' => $tax_value, 'slug' => $slug),array( '%s','%s' ));
                        }
                    }

                }
            } else {
                return;
            }
        }
        $a_currunt_tax_slabs = ( !$a_currunt_tax_slabs ) ? $a_gst_tax_slabs : $a_currunt_tax_slabs ;
        $a_currunt_tax_slabs = implode( PHP_EOL, $a_currunt_tax_slabs );
        update_option( 'woocommerce_tax_classes', $a_currunt_tax_slabs );
    }

    /**
     * Uses this function to insert tax slab rows.
     *
     */
    public static function gst_insrt_tax_slab_rows() {

        global $wpdb;

        $a_multiple_slabs = array();
        if( isset( $_POST['woocommerce_product_types'] ) && $_POST['woocommerce_product_types'] == 'multiple' ){
            $multi_select_slab = $_POST['woocommerce_gst_multi_select_slab'];
            if( ! empty( $multi_select_slab ) )
                $a_multiple_slabs = array_merge( $a_multiple_slabs, $multi_select_slab );
        } elseif ( isset( $_POST['woocommerce_product_types'] ) ){
            $single_select_slab = $_POST['woocommerce_gst_single_select_slab'];
            array_push( $a_multiple_slabs, $single_select_slab );       
        }

        $table_prefix = $wpdb->prefix . "woocommerce_tax_rates";

        $s_woocommerce_tax_classes = get_option('woocommerce_tax_classes');
        $a_currunt_tax_slabs = array();


        if( !empty( $s_woocommerce_tax_classes ) )
            $a_currunt_tax_slabs = explode( PHP_EOL, $s_woocommerce_tax_classes );
            // $a_currunt_tax_slabs = array();


        
        foreach ( $a_multiple_slabs as $a_multiple_slab ) {

            // if( $a_multiple_slab != '0%' && ! in_array( $a_multiple_slab, $a_currunt_tax_slabs ) ){
                $slab_name = preg_replace('/%/', '', $a_multiple_slab);
                $state_tax ='';
                
                $state_tax = $slab_name / 2;

                $state = get_option( 'woocommerce_store_state' );
                $ut_state = array('CH','AN','DN','DD', 'LD');
                if( isset( $state ) ) :

                    $tax_slab_row_cgst = $state_tax."% CGST";
                    $tax_slab_row_utgst = $state_tax."% UTGST";
                    $tax_slab_row_sgst = $state_tax."% SGST";
                    $tax_slab_row_igst = $slab_name."% IGST";

                    $table_tax_prefix = $wpdb->prefix . "woocommerce_tax_rates";

                    $select_table_tax_cgst = $wpdb->get_var("SELECT tax_rate_id FROM $table_tax_prefix WHERE tax_rate_name='$tax_slab_row_cgst'");

                    $select_table_tax_utgst = $wpdb->get_var("SELECT tax_rate_id FROM $table_tax_prefix WHERE tax_rate_name='$tax_slab_row_utgst'");

                    $select_table_tax_sgst = $wpdb->get_var("SELECT tax_rate_id FROM $table_tax_prefix WHERE tax_rate_name='$tax_slab_row_sgst'");

                    $select_table_tax_igst = $wpdb->get_var("SELECT tax_rate_id FROM $table_tax_prefix WHERE tax_rate_name='$tax_slab_row_igst'");

                    

                    if( ($select_table_tax_cgst == NULL || empty($select_table_tax_cgst)) ){
                        $wpdb->insert($table_prefix,array( 'tax_rate_country' => 'IN', 'tax_rate_state' => $state,'tax_rate' => $state_tax,'tax_rate_name' => $state_tax."% CGST",'tax_rate_priority' => 1,'tax_rate_compound' => 0,'tax_rate_shipping' => 0,'tax_rate_order' => 0,'tax_rate_class' =>$slab_name),array( '%s','%s','%s','%s','%d','%d','%d','%d','%s'));
                    }

                    if(in_array($state, $ut_state)){

                        if( ($select_table_tax_utgst == NULL || empty($select_table_tax_utgst)) ){
                            $wpdb->insert($table_prefix,array( 'tax_rate_country' => 'IN', 'tax_rate_state' => $state,'tax_rate' => $state_tax,'tax_rate_name' => $state_tax."% UTGST",'tax_rate_priority' => 2,'tax_rate_compound' => 0,'tax_rate_shipping' => 0,'tax_rate_order' => 0,'tax_rate_class' =>$slab_name),array( '%s','%s','%s','%s','%d','%d','%d','%d','%s'));
                        }    
                    } else {
                        if( ($select_table_tax_sgst == NULL || empty($select_table_tax_sgst)) ){
                            $wpdb->insert($table_prefix,array( 'tax_rate_country' => 'IN', 'tax_rate_state' => $state,'tax_rate' => $state_tax,'tax_rate_name' => $state_tax."% SGST",'tax_rate_priority' => 2,'tax_rate_compound' => 0,'tax_rate_shipping' => 0,'tax_rate_order' => 0,'tax_rate_class' =>$slab_name),array( '%s','%s','%s','%s','%d','%d','%d','%d','%s'));
                        }    

                    }
                    
                    if( ($select_table_tax_igst == NULL || empty($select_table_tax_igst)) ){
                        $wpdb->insert($table_prefix,array( 'tax_rate_country' => 'IN', 'tax_rate_state' => '','tax_rate' => $slab_name,'tax_rate_name' => $slab_name."% IGST",'tax_rate_priority' => 1,'tax_rate_compound' => 0,'tax_rate_shipping' => 0,'tax_rate_order' => 0,'tax_rate_class' =>$slab_name),array( '%s','%s','%s','%s','%d','%d','%d','%d','%s'));
                    }    
                endif;
            // }
        }
        
    }

    /**
     * Get all the settings for this plugin for @see woocommerce_admin_fields() function.
     *
     * @return array Array of settings for @see woocommerce_admin_fields() function.
     */
    public static function fn_get_settings() {

        $state = get_option( 'woocommerce_store_state' );
        $settings = array(
            'section_title' => array(
                'name'     => __( 'Select Product Type', 'woocommerce' ),
                'type'     => 'title',
                'desc'     => '',
                'id'       => 'wc_settings_gst_tab_section_title'
            ),
            'GSTIN_number' => array(

                'name'    => __( 'GSTIN Number', 'woocommerce' ),

                'desc'    => __( 'This GSTIN number display on your invoice.', 'woocommerce' ),

                'id'      => 'woocommerce_gstin_number',

                'css'     => 'min-width:150px;',

                'std'     => 'left', // WooCommerce < 2.0

                'default' => '', // WooCommerce >= 2.0

                'custom_attributes' => array( 'required' => 'required' ),

                'type'    => 'text',

            ),
            'store_state' => array(

                'name'    => __( 'Store location state', 'woocommerce' ),

                'desc'    => __( 'Please insert state code of store location.', 'woocommerce' ),

                'id'      => 'woocommerce_store_state',

                'css'     => 'min-width:150px;',

                'std'     => 'left', // WooCommerce < 2.0

                'default' => $state, // WooCommerce >= 2.0

                'custom_attributes' => array( 'required' => 'required' ),

                'custom_attributes' => array('readonly' => 'readonly'),
                
                'type'    => 'text',

            ),
            'prod_types' => array(

                'name'    => __( 'Select Product Types', 'woocommerce' ),

                'desc'    => __( 'Select single or multiple tax slab.', 'woocommerce' ),

                'id'      => 'woocommerce_product_types',

                'css'     => 'min-width:150px;height:auto;',

                    'std'     => 'left', // WooCommerce < 2.0

                    'default' => 'left', // WooCommerce >= 2.0

                    'type'    => 'select',

                    'options' => array(

                        'single'        => __( 'Single', 'woocommerce' ),

                        'multiple'       => __( 'Multiple', 'woocommerce' ),

                    ),

                    'desc_tip' =>  true,

                ),
            'woocommerce_gst_multi_select_slab' => array(

                'name'    => __( 'Select Multiple Tax Slabs ', 'woocommerce' ),

                'desc'    => __( 'Multiple tax slabs.', 'woocommerce' ),

                'id'      => 'woocommerce_gst_multi_select_slab',

                'css'     => 'min-width:150px;',

                'std'     => 'left', // WooCommerce < 2.0

                'default' => 'left', // WooCommerce >= 2.0

                'type'    => 'multi_select_countries',

                'options' => array(

                    '0%'  => __( '0%', 'woocommerce' ),

                    '5%'  => __( '5%', 'woocommerce' ),

                    '12%' => __( '12%', 'woocommerce' ),

                    '18%' => __( '18%', 'woocommerce' ),

                    '28%' => __( '28%', 'woocommerce' ),

                ),

                'desc_tip' =>  true,

            ),

            'woocommerce_gst_single_select_slab' => array(

                'name'    => __( 'Select Tax Slab', 'woocommerce' ),

                'desc'    => __( 'Tax slab.', 'woocommerce' ),

                'id'      => 'woocommerce_gst_single_select_slab',

                'css'     => 'min-width:150px;height:auto;',

                'std'     => 'left', // WooCommerce < 2.0

                'default' => 'left', // WooCommerce >= 2.0

                'type'    => 'select',

                'options' => array(

                    '0%'  => __( '0%', 'woocommerce' ),

                    '5%'  => __( '5%', 'woocommerce' ),

                    '12%' => __( '12%', 'woocommerce' ),

                    '18%' => __( '18%', 'woocommerce' ),

                    '28%' => __( '28%', 'woocommerce' ),

                ),

                'desc_tip' =>  true,

            ),

            'gst_nonce' => array(

                'name'    => __( 'GST nonce', 'woocommerce' ),

                'desc'    => __( 'GST nonce.', 'woocommerce' ),

                'id'      => 'woocommerce_gst_nonce',

                'css'     => 'min-width:150px;',

                'std'     => 'left', // WooCommerce < 2.0

                'default' => wp_nonce_field( 'wc_gst_nonce', 'custom_gst_nonce' ), // WooCommerce >= 2.0
                
                'type'    => 'hidden',

            ),



            'section_end' => array(
                'type' => 'sectionend',
                'id' => 'wc_settings_gst_tab_section_end'
            )
        );
        return apply_filters( 'wc_settings_gst_tab_settings', $settings );
    }


    function fn_add_extra_links($links, $file) {

        if( $file == gst_BASENAME ) {

            $row_meta = array(
                'pro'    => '<a href="'.GST_PRO_LINK.'" target="_blank" title="' . __( 'PRO Plugin', 'woocommerce' ) . '">' . __( 'PRO Plugin', 'woocommerce' ) . '</a>',
            );

            return array_merge( $links, $row_meta );

        }

        return (array) $links;
    }

}
