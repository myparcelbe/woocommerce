<?php

if ( ! defined('ABSPATH')) exit; // Exit if accessed directly

if ( ! class_exists('WooCommerce_MyParcelBE_Assets')) :

    class WooCommerce_MyParcelBE_Assets {

        function __construct() {
            add_action('wp_enqueue_scripts', array($this, 'frontend_scripts_styles'));
            add_action('admin_enqueue_scripts', array($this, 'backend_scripts_styles'));
        }

        /**
         * Load styles & scripts
         */
        public function frontend_scripts_styles() {
            // return if not checkout or order received page
            if ( ! is_checkout() && ! is_order_received_page()) return;

            // if using split fields
            if (WooCommerce_MyParcelBE()->setting_collection->getByName('use_split_address_fields')) {
                wp_enqueue_script(
                    'wcmp-checkout-fields',
                    WooCommerce_MyParcelBE()->plugin_url() . '/assets/js/wcmp-checkout-fields.js',
                    array('jquery', 'wc-checkout'),
                    WC_MYPARCEL_BE_VERSION
                );
            }

            // return if myparcel checkout is not active
            if ( ! isset(WooCommerce_MyParcelBE()->bpost_settings['myparcelbe_bpost'])) return;

            if ( ! isset(WooCommerce_MyParcelBE()->dpd_settings['myparcelbe_dpd'])) return;

            wp_enqueue_script(
                'wc-myparcelbe',
                WooCommerce_MyParcelBE()->plugin_url() . '/assets/js/myparcelbe.js',
                array('jquery'),
                WC_MYPARCEL_BE_VERSION
            );

            wp_enqueue_script(
                'wc-myparcelbe-frontend',
                WooCommerce_MyParcelBE()->plugin_url() . '/assets/js/wcmp-frontend.js',
                array('jquery', 'wc-myparcelbe'),
                WC_MYPARCEL_BE_VERSION
            );

            wp_localize_script(
                'wc-myparcelbe-frontend',
                'wcmp_display_settings',
                array(
                    'isUsingSplitAddressFields' =>
                        WooCommerce_MyParcelBE()->setting_collection->getByName('use_split_address_fields')
                )
            );
        }

        /**
         * Load styles & scripts
         */
        public function backend_scripts_styles() {
            global $post_type;
            $screen = get_current_screen();
            if ($post_type == 'shop_order' || (is_object($screen) && strpos($screen->id, 'myparcel') !== false)) {
                // WC2.3+ load all WC scripts for shipping_method search!
                if (version_compare(WOOCOMMERCE_VERSION, '2.3', '>=')) {
                    wp_enqueue_script('woocommerce_admin');
                    wp_enqueue_script('iris');
                    if ( ! wp_script_is('wc-enhanced-select', 'registered')) {
                        $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
                        wp_register_script(
                            'wc-enhanced-select',
                            WC()->plugin_url() . '/assets/js/admin/wc-enhanced-select' . $suffix . '.js',
                            array('jquery', version_compare(WC()->version, '3.2.0', '>=') ? 'selectWoo' : 'select2'),
                            WC_VERSION
                        );
                    }
                    wp_enqueue_script('wc-enhanced-select');
                    wp_enqueue_script('jquery-ui-sortable');
                    wp_enqueue_script('jquery-ui-autocomplete');
                    wp_enqueue_style(
                        'woocommerce_admin_styles',
                        WC()->plugin_url() . '/assets/css/admin.css',
                        array(),
                        WC_VERSION
                    );
                }

                // Add the color picker css file
                wp_enqueue_style('wp-color-picker');
                wp_enqueue_script('thickbox');
                wp_enqueue_style('thickbox');
                wp_enqueue_script(
                    'wcmyparcelbe-export',
                    WooCommerce_MyParcelBE()->plugin_url() . '/assets/js/wcmp-admin.js',
                    array('jquery', 'thickbox', 'wp-color-picker'),
                    WC_MYPARCEL_BE_VERSION
                );
                wp_localize_script(
                    'wcmyparcelbe-export', 'wc_myparcelbe', array(
                        'ajax_url'         => admin_url('admin-ajax.php'),
                        'nonce'            => wp_create_nonce('wc_myparcelbe'),
                        'download_display' => WooCommerce_MyParcelBE()->setting_collection->getByName('download_display')
                            ? WooCommerce_MyParcelBE()->setting_collection->getByName('download_display')
                            : '',
                        'offset'           => WooCommerce_MyParcelBE()->setting_collection->getByName('print_position_offset')
                            ? WooCommerce_MyParcelBE()->setting_collection->getByName('print_position_offset')
                            : '',
                        'offset_icon'      => WooCommerce_MyParcelBE()->plugin_url() . '/assets/img/print-offset-icon.png',
                        'offset_label'     => __('Labels to skip', 'woocommerce-myparcel'),
                    )
                );

                wp_enqueue_style(
                    'wcmp-admin-styles',
                    WooCommerce_MyParcelBE()->plugin_url() . '/assets/css/wcmp-admin-styles.css',
                    array(),
                    WC_MYPARCEL_BE_VERSION,
                    'all'
                );

                // Legacy styles (WC 2.1+ introduced MP6 style with larger buttons)
                if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<=')) {
                    wp_enqueue_style(
                        'wcmp-admin-styles-legacy', WooCommerce_MyParcelBE()->plugin_url() . '/assets/css/wcmp-admin-styles-legacy.css', array(), WC_MYPARCEL_BE_VERSION, 'all'
                    );
                }
            }
        }
    }

endif; // class_exists

return new WooCommerce_MyParcelBE_Assets();