<?php

if ( ! defined('ABSPATH')) exit; // Exit if accessed directly

if ( ! class_exists('WooCommerce_MyParcelBE_Settings')) :

/**
 * Create & render settings page
 */
class WooCommerce_MyParcelBE_Settings {

    public $options_page_hook;

    public function __construct() {
        $this->callbacks = include('class-wcmp-settings-callbacks.php');
        add_action('admin_menu', array($this, 'menu'));
        add_filter(
            'plugin_action_links_' . WooCommerce_MyParcelBE()->plugin_basename, array(
                $this,
                'add_settings_link'
            )
        );

        add_action('admin_init', array($this, 'general_settings'));
        add_action('admin_init', array($this, 'export_defaults_settings'));
        add_action('admin_init', array($this, 'checkout_settings'));

        // notice for WC MyParcel Belgium plugin
        add_action('woocommerce_myparcelbe_before_settings_page', array($this, 'myparcelbe_be_notice'), 10, 1);
    }

    /**
     * Add settings item to WooCommerce menu
     */
    public function menu() {
        add_submenu_page(
            'woocommerce',
            __('MyParcel BE', 'woocommerce-myparcelbe'),
            __('MyParcel BE', 'woocommerce-myparcelbe'),
            'manage_options',
            'woocommerce_myparcelbe_settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Add settings link to plugins page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=woocommerce_myparcelbe_settings">' . __('Settings', 'woocommerce-myparcelbe') . '</a>';
        array_push($links, $settings_link);

        return $links;
    }

    public function settings_page() {
        $settings_tabs = apply_filters(
            'woocommerce_myparcelbe_settings_tabs',
            array(
                'general'         => __('General', 'woocommerce-myparcelbe'),
                'export_defaults' => __('Default export settings', 'woocommerce-myparcelbe'),
                'checkout'        => __('Checkout', 'woocommerce-myparcelbe'),
            )
        );

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce MyParcel BE Settings', 'woocommerce-myparcelbe'); ?></h1>
            <h2 class="nav-tab-wrapper">
                <?php
                foreach ($settings_tabs as $tab_slug => $tab_title) {
                    printf('<a href="?page=woocommerce_myparcelbe_settings&tab=%1$s" class="nav-tab nav-tab-%1$s %2$s">%3$s</a>', $tab_slug, (($active_tab == $tab_slug) ? 'nav-tab-active' : ''), $tab_title);
                }
                ?>
            </h2>

            <?php do_action('woocommerce_myparcelbe_before_settings_page', $active_tab); ?>

            <form method="post" action="options.php" id="woocommerce-myparcelbe-settings" class="wcmp_shipment_options">
                <?php
                do_action('woocommerce_myparcelbe_before_settings', $active_tab);
                settings_fields('woocommerce_myparcelbe_' . $active_tab . '_settings');
                do_settings_sections('woocommerce_myparcelbe_' . $active_tab . '_settings');
                do_action('woocommerce_myparcelbe_after_settings', $active_tab);

                submit_button();
                ?>
            </form>

            <?php do_action('woocommerce_myparcelbe_after_settings_page', $active_tab); ?>

        </div>
        <?php
    }

    public function myparcelbe_be_notice() {
        $base_country = WC()->countries->get_base_country();

        // save or check option to hide notice
        if (isset($_GET['myparcelbe_hide_be_notice'])) {
            update_option('myparcelbe_hide_be_notice', true);
            $hide_notice = true;
        } else {
            $hide_notice = get_option('myparcelbe_hide_be_notice');
        }

        // link to hide message when one of the premium extensions is installed
        if ( ! $hide_notice && $base_country == 'BE') {
            $myparcelbe_belgium_link = '<a href="https://wordpress.org/plugins/wc-myparcel-belgium/" target="blank">WC MyParcel Belgium</a>';
            $text = sprintf(
                __('It looks like your shop is based in Netherlands. This plugin is for MyParcel Belgium. If you are using MyParcel Netherlands, download the %s plugin instead!', 'woocommerce-myparcelbe'),
                $myparcelbe_belgium_link
            );
            $dismiss_button = sprintf(
                '<a href="%s" style="display:inline-block; margin-top: 10px;">%s</a>',
                add_query_arg('myparcelbe_hide_be_notice', 'true'),
                __('Hide this message', 'woocommerce-myparcelbe')
            );
            printf('<div class="notice notice-warning"><p>%s %s</p></div>', $text, $dismiss_button);
        }
    }

    /**
     * Register General settings
     */
    public function general_settings() {
        $option_group = 'woocommerce_myparcelbe_general_settings';

        // Register settings.
        $option_name = 'woocommerce_myparcelbe_general_settings';
        register_setting($option_group, $option_name, array($this->callbacks, 'validate'));

        // Create option in wp_options.
        if (false === get_option($option_name)) {
            $this->default_settings($option_name);
        }

        // API section.
        add_settings_section(
            'api',
            __('API settings', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'section'),
            $option_group
        );

        add_settings_field(
            'api_key',
            __('Key', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'text_input'),
            $option_group,
            'api',
            array(
                'option_name' => $option_name,
                'id'          => 'api_key',
                'size'        => 50,
            )
        );

        // General section.
        add_settings_section(
            'general',
            __('General settings', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'section'),
            $option_group
        );

        add_settings_field(
            'download_display',
            __('Label display', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'radio_button'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'download_display',
                'options'     => array(
                    'download' => __('Download PDF', 'woocommerce-myparcelbe'),
                    'display'  => __('Open the PDF in a new tab', 'woocommerce-myparcelbe'),
                ),
            )
        );
        add_settings_field(
            'label_format',
            __('Label format', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'radio_button'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'label_format',
                'options'     => array(
                    'A4' => __('Standard printer (A4)', 'woocommerce-myparcelbe'),
                    'A6' => __('Label Printer (A6)', 'woocommerce-myparcelbe'),
                ),
            )
        );

        add_settings_field(
            'print_position_offset',
            __('Ask for print start position', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'print_position_offset',
                'description' => __('This option enables you to continue printing where you left off last time', 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
            'email_tracktrace',
            __('Track & Trace in email', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'email_tracktrace',
                'description' => __('Add the Track & Trace code to emails to the customer.<br/><strong>Note!</strong> When you select this option, make sure you have not enabled the Track & Trace email in your MyParcel BE backend.', 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
            'myaccount_tracktrace',
            __('Track & Trace in My Account', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'myaccount_tracktrace',
                'description' => __('Show Track & Trace trace code and link in My Account.', 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
            'process_directly',
            __('Process shipments directly', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'process_directly',
                'description' => __('When you enable this option, shipments will be directly processed when sent to MyParcel BE.', 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
            'order_status_automation',
            __('Order status automation', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'order_status_automation',
                'description' => __('Automatically set order status to a predefined status after successful MyParcel BE export.<br/>Make sure <strong>Process shipments directly</strong> is enabled when you use this option together with the <strong>Track & Trace in email</strong> option, otherwise the Track & Trace code will not be included in the customer email.', 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
            'automatic_order_status',
            __('Automatic order status', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'order_status_select'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'automatic_order_status',
                'class'       => 'automatic_order_status',
            )
        );

        add_settings_field(
            'keep_shipments',
            __('Keep old shipments', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'keep_shipments',
                'default'     => 0,
                'description' => __('With this option enabled, data from previous shipments (Track & Trace links) will be kept in the order when you export more than once.', 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
            'barcode_in_note',
            __('Place barcode inside note', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'barcode_in_note',
                'class'       => 'barcode_in_note',
                'description' => __('Place the barcode inside a note of the order', 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
            'barcode_in_note_title',
            __('Title before the barcode', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'text_input'),
            $option_group,
            'general',
            array(
                'option_name' => $option_name,
                'id'          => 'barcode_in_note_title',
                'class'       => 'barcode_in_note_title',
                'default'     => 'Tracking code:',
                'size'        => 25,
                'description' => __('You can change the text before the barcode inside an note', 'woocommerce-myparcelbe'),
            )
        );

        // Diagnostics section.
        add_settings_section(
            'diagnostics',
            __('Diagnostic tools', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'section'),
            $option_group
        );

        add_settings_field(
            'error_logging',
            __('Log API communication', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'diagnostics',
            array(
                'option_name' => $option_name,
                'id'          => 'error_logging',
                'description' => '<a href="' . esc_url_raw(admin_url('admin.php?page=wc-status&tab=logs')) . '" target="_blank">' . __('View logs', 'woocommerce-myparcelbe') . '</a> (wc-myparcelbe)',
            )
        );
    }

    /**
     * Register Export defaults settings
     */
    public function export_defaults_settings() {
        $option_group = 'woocommerce_myparcelbe_export_defaults_settings';

        // Register settings.
        $option_name = 'woocommerce_myparcelbe_export_defaults_settings';
        register_setting($option_group, $option_name, array($this->callbacks, 'validate'));

        // Create option in wp_options.
        if (false === get_option($option_name)) {
            $this->default_settings($option_name);
        }

        // API section.
        add_settings_section(
            'defaults',
            __('Default export settings', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'section'),
            $option_group
        );

        if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>=')) {
            add_settings_field(
                'shipping_methods_package_types',
                __('Package types', 'woocommerce-myparcelbe'),
                array($this->callbacks, 'shipping_methods_package_types'),
                $option_group,
                'defaults',
				array(
                    'option_name'   => $option_name,
                    'id'            => 'shipping_methods_package_types',
                    'package_types' => WooCommerce_MyParcelBE()->export->get_package_types(),
                    'description'   => __('Select one or more shipping methods for each MyParcel BE package type', 'woocommerce-myparcelbe'),
                )
            );
        } else {
            add_settings_field(
                'package_type',
                __('Shipment type', 'woocommerce-myparcelbe'),
                array($this->callbacks, 'select'),
                $option_group,
                'defaults',
				array(
                    'option_name' => $option_name,
                    'id'          => 'package_type',
                    'default'     => (string) WooCommerce_MyParcelBE_Export::PACKAGE,
                    'options'     => WooCommerce_MyParcelBE()->export->get_package_types(),
                )
            );
        }

        add_settings_field(
            'connect_email',
            __('Connect customer email', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'defaults',
            array(
                'option_name' => $option_name,
                'id'          => 'connect_email',
                'description' => sprintf(__('When you connect the customer email, MyParcel BE can send a Track & Trace email to this address. In your %sMyParcel BE backend%s you can enable or disable this email and format it in your own style.', 'woocommerce-myparcelbe'), '<a href="https://backoffice.sendmyparcel.be/settings/account" target="_blank">', '</a>')
            )
        );

        add_settings_field(
			'connect_phone',
            __('Connect customer phone', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'defaults',
			array(
                'option_name' => $option_name,
                'id'          => 'connect_phone',
                'description' => __("When you connect the customer's phone number, the courier can use this for the delivery of the parcel. This greatly increases the delivery success rate for foreign shipments.", 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
			'signature',
            __('Signature on delivery', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'defaults',
            array(
                'option_name' => $option_name,
                'id'          => 'signature',
                'description' => __('When the package is presented at the home address, a signuture will be required.', 'woocommerce-myparcelbe')
            )
        );

        add_settings_field(
			'insured',
            __('Insured shipment (to €500)', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'defaults',
			array(
                'option_name' => $option_name,
                'id'          => 'insured',
                'description' => __('There is no default insurance on the domestic shipments. If you want to insure, you can do this. We insure the purchase value of your product, with a maximum insured value of € 500.', 'woocommerce-myparcelbe'),
                'class'       => 'insured',
            )
        );

        add_settings_field(
			'label_description',
            __('Label description', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'text_input'),
            $option_group,
            'defaults',
            array(
                'option_name' => $option_name,
                'id'          => 'label_description',
                'size'        => '25',
                'description' => __("With this option, you can add a description to the shipment. This will be printed on the top left of the label, and you can use this to search or sort shipments in the MyParcel BE Backend. Use <strong>[ORDER_NR]</strong> to include the order number, <strong>[DELIVERY_DATE]</strong> to include the delivery date.", 'woocommerce-myparcelbe'),
            )
        );

        add_settings_field(
            'package_contents',
            __('Customs shipment type', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'select'),
            $option_group,
            'world_shipments',
			array(
                'option_name' => $option_name,
                'id'          => 'package_contents',
                'options'     => array(
                    1 => __('Commercial goods', 'woocommerce-myparcelbe'),
                    2 => __('Commercial samples', 'woocommerce-myparcelbe'),
                    3 => __('Documents', 'woocommerce-myparcelbe'),
                    4 => __('Gifts', 'woocommerce-myparcelbe'),
                    5 => __('Return shipment', 'woocommerce-myparcelbe'),
                ),
            )
        );
    }

    /**
     * Register Checkout settings
     */
    public function checkout_settings() {
        $option_group = 'woocommerce_myparcelbe_checkout_settings';

        // Register settings.
        $option_name = 'woocommerce_myparcelbe_checkout_settings';
        register_setting($option_group, $option_name, array($this->callbacks, 'validate'));

        // Create option in wp_options.
        if (false === get_option($option_name)) {
            $this->default_settings($option_name);
        }

        add_settings_field(
            'use_split_address_fields',
            __('MyParcel BE address fields', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox' ),
            $option_group,
            'processing_parameters',
            array(
                'option_name' => $option_name,
                'id'          => 'use_split_address_fields',
                'class'       => 'use_split_address_fields',
                'description' => __('When enabled the checkout will use the MyParcel BE address fields. This means there will be three separate fields for street name, number and suffix. Want to use the WooCommerce default fields? Leave this option unchecked.', 'woocommerce-myparcelbe'),
            )
        );

        add_settings_field(
            'myparcelbe_checkout',
            __('Enable MyParcel BE delivery options', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'checkbox'),
            $option_group,
            'processing_parameters',
            array(
                'option_name' => $option_name,
                'id'          => 'myparcelbe_checkout',
            )
        );

        add_settings_field(
            'checkout_display',
            __('Display for', 'woocommerce-myparcelbe'),
            array($this->callbacks, 'select'),
            $option_group,
            'processing_parameters',
            array(
                'option_name' => $option_name,
                'id'          => 'checkout_display',
                'options'     => array(
                    'selected_methods' => __('Shipping methods associated with Parcels', 'woocommerce-myparcelbe'),
                    'all_methods'      => __('All shipping methods', 'woocommerce-myparcelbe'),
                ),
                'description' => __('You can link the delivery options to specific shipping methods by adding them to the package types under \'Standard export settings\'. The delivery options are not visible at foreign addresses.', 'woocommerce-myparcelbe'),
            )
        );

        // Checkout options section.
        add_settings_section(
            'processing_parameters', __('Shipment processing parameters', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'section'
        ), $option_group
        );

        // Place of the checkout
        add_settings_field(
            'checkout_place', __('Checkout position', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'select'
        ), $option_group, 'processing_parameters', array(
                'option_name' => $option_name,
                'id'          => 'checkout_position',
                'options'     => array(
                    'woocommerce_after_checkout_billing_form'  => __('Show checkout options after billing details', 'woocommerce-myparcelbe'),
                    'woocommerce_after_checkout_shipping_form' => __('Show checkout options after shipping details', 'woocommerce-myparcelbe'),
                    'woocommerce_after_order_notes'            => __('Show checkout options after notes', 'woocommerce-myparcelbe'),
                ),
                'description' => __('You can change the place of the checkout options on the checkout page. By default it will be placed after shipping details.', 'woocommerce-myparcelbe'),
            )
        );

        $days_of_the_week = array(
            '0' => __('Sunday', 'woocommerce-myparcelbe'),
            '1' => __('Monday', 'woocommerce-myparcelbe'),
            '2' => __('Tuesday', 'woocommerce-myparcelbe'),
            '3' => __('Wednesday', 'woocommerce-myparcelbe'),
            '4' => __('Thursday', 'woocommerce-myparcelbe'),
            '5' => __('Friday', 'woocommerce-myparcelbe'),
            '6' => __('Saturday', 'woocommerce-myparcelbe'),
        );

        add_settings_field(
            'dropoff_days', __('Drop-off days', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'enhanced_select'
        ), $option_group, 'processing_parameters', array(
                'option_name' => $option_name,
                'id'          => 'dropoff_days',
                'options'     => $days_of_the_week,
                'description' => __('Days of the week on which you hand over parcels to bpost', 'woocommerce-myparcelbe'),
            )
        );

        add_settings_field(
            'cutoff_time', __('Cut-off time', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'text_input'
        ), $option_group, 'processing_parameters', array(
                'option_name' => $option_name,
                'id'          => 'cutoff_time',
                'type'        => 'text',
                'size'        => '5',
                'description' => __('Time at which you stop processing orders for the day (format: hh:mm)', 'woocommerce-myparcelbe'),
            )
        );

        add_settings_field(
            'dropoff_delay', __('Drop-off delay', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'text_input'
        ), $option_group, 'processing_parameters', array(
                'option_name' => $option_name,
                'id'          => 'dropoff_delay',
                'type'        => 'text',
                'size'        => '5',
                'description' => __('Number of days you need to process an order.', 'woocommerce-myparcelbe'),
            )
        );

        add_settings_field(
            'deliverydays_window', __('Delivery days window', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'checkbox'
        ), $option_group, 'processing_parameters', array(
                'option_name' => $option_name,
                'id'          => 'deliverydays_window',
                'description' => __('Show the delivery date inside the checkout.', 'woocommerce-myparcelbe'),
            )
        );

        // Delivery options section.
        add_settings_section(
            'delivery_options', __('Delivery options', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'section'
        ), $option_group
        );

        add_settings_field(
            'header_delivery_options_title', __('Delivery options title', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'text_input'
        ), $option_group, 'delivery_options', array(
                'option_name' => $option_name,
                'id'          => 'header_delivery_options_title',
                'size'        => '53',
                'title'       => 'Delivery options title',
                'current'     => self::get_checkout_setting_title('header_delivery_options_title'),
                'description' => __('You can place a delivery title above the MyParcel BE options. When there is no title, it will not be visible.', 'woocommerce-myparcelbe'),
            )
        );

        add_settings_field(
            'at_home_delivery', __('Home delivery title', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'text_input'
        ), $option_group, 'delivery_options', array(
                'option_name' => $option_name,
                'id'          => 'at_home_delivery_title',
                'size'        => '53',
                'title'       => 'Delivered at home or at work',
                'current'     => self::get_checkout_setting_title('at_home_delivery_title'),
            )
        );

        add_settings_field(
            'standard', __('Standard delivery title', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'text_input'
        ), $option_group, 'delivery_options', array(
                'option_name' => $option_name,
                'id'          => 'standard_title',
                'size'        => '53',
                'title'       => 'Standard delivery',
                'current'     => self::get_checkout_setting_title('standard_title'),
                'description' => __('When there is no title, the delivery time will automatically be visible.', 'woocommerce-myparcelbe'),
            )
        );

        add_settings_field(
            'signature', __('Signature on delivery', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'delivery_option_enable'
        ), $option_group, 'delivery_options', array(
                'has_title'   => true,
                'has_price'   => true,
                'option_name' => $option_name,
                'id'          => 'signature',
                'title'       => 'Signature on delivery',
                'current'     => self::get_checkout_setting_title('signature_title'),
                'size'        => 30,
            )
        );

        add_settings_field(
            'pickup', __('bpost pickup', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'delivery_option_enable'
        ), $option_group, 'delivery_options', array(
                'has_title'   => true,
                'has_price'   => true,
                'option_name' => $option_name,
                'id'          => 'pickup',
                'class'       => 'pickup',
                'title'       => 'Pickup',
                'current'     => self::get_checkout_setting_title('pickup_title'),
                'size'        => 30,
                'option_description' => sprintf(__('Enter an amount that is either positive or negative. For example, do you want to give a discount for using this function or do you want to charge extra for this delivery option.', 'woocommerce-myparcelbe')),
            )
        );

//        add_settings_field(
//            'saturday_cutoff', __('Enable saturday delivery', 'woocommerce-myparcelbe'), array(
//            $this->callbacks,
//            'delivery_option_enable'
//        ), $option_group, 'delivery_options', array(
//                'has_title'          => false,
//                'has_price'          => false,
//                'has_cutoff_time'    => true,
//                'option_name'        => $option_name,
//                'id'                 => 'saturday_cutoff',
//                'size'               => 30,
//                'option_description' => sprintf(__('<strong>Note: Your drop-off days must include Saturday and cut-off time on Saturday must be before 15:00 (14:30 recommended). </strong> More information about the Saturday delivery service of bpost can be found %s here%s.', 'woocommerce-myparcelbe'), '<a href="https://blog.myparcel.nl/maandagbezorging/" target="_blank">', '</a>'),
//            )
//        );

        // Customizations section
        add_settings_section(
            'customizations', __('Customizations', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'section'
        ), $option_group
        );

        add_settings_field(
            'custom_css', __('Custom styles', 'woocommerce-myparcelbe'), array(
            $this->callbacks,
            'textarea'
        ), $option_group, 'customizations', array(
                'option_name' => $option_name,
                'id'          => 'custom_css',
                'width'       => '80',
                'height'      => '8',
            )
        );
    }

    /**
     * Set default settings.
     * @return void.
     */
    public function default_settings($option) {
        switch($option) {
            case 'woocommerce_myparcelbe_general_settings':
                $default = array(
                    'download_display' => 'download',
                    'label_format'     => 'A4',
                );
            break;
            case 'woocommerce_myparcelbe_checkout_settings':
                $default = self::get_checkout_settings();
            break;
            case 'woocommerce_myparcelbe_export_defaults_settings':
            default:
                $default = array();
            break;
        }

        if (false === get_option($option)) {
            add_option($option, $default);
        } else {
            update_option($option, $default);
        }
    }

    /**
     * @param $key
     *
     * @return string
     */
    public static function get_checkout_setting_title($key) {
        $checkout_settings = self::get_checkout_settings();
        $setting = $checkout_settings[$key];

        return __($setting, 'woocommerce-myparcelbe');
    }

    /**
     * @return array
     */
    public static function get_checkout_settings() {
        return array(
            'pickup_enabled'                 => '0',
            'dropoff_days'                   => array(1, 2, 3, 4, 5),
            'dropoff_delay'                  => '0',
            'deliverydays_window'            => '1',
            'checkout_position'              => 'woocommerce_after_checkout_billing_form',
            'header_delivery_options_title'  => 'Delivery options',
            'at_home_delivery_title'         => 'Delivered at home or at work',
            'standard_title'                 => 'Standard delivery',
            'signature_title'                => 'Signature on delivery',
            'pickup_title'                   => 'bpost Pickup',
            'use_split_address_fields'       => '0',
        );
    }
}

endif; // class_exists

return new WooCommerce_MyParcelBE_Settings();