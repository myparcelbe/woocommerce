<?php
/**
 * Create & render settings page
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( !class_exists( 'WooCommerce_MyParcelBE_Settings' ) ) :

class WooCommerce_MyParcelBE_Settings {

	public $options_page_hook;

	public function __construct() {
		$this->callbacks = include( 'class-wcmp-settings-callbacks.php' );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_filter( 'plugin_action_links_'.WooCommerce_MyParcelBE()->plugin_basename, array( $this, 'add_settings_link' ) );

		add_action( 'admin_init', array( $this, 'general_settings' ) );
		add_action( 'admin_init', array( $this, 'export_defaults_settings' ) );
		add_action( 'admin_init', array( $this, 'checkout_settings' ) );

		// notice for WC MyParcelbe Belgium plugin
		add_action( 'woocommerce_myparcelbe_before_settings_page', array( $this, 'myparcelBE_be_notice'), 10, 1 );
	}

	/**
	 * Add settings item to WooCommerce menu
	 */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'MyParcelbe', 'woocommerce-myparcelbe' ),
			__( 'MyParcelbe', 'woocommerce-myparcelbe' ),
			'manage_options',
			'woocommerce_myparcelbe_settings',
			array( $this, 'settings_page' )
		);	
	}
	
	/**
	 * Add settings link to plugins page
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="admin.php?page=woocommerce_myparcelbe_settings">'. __( 'Settings', 'woocommerce-myparcelbe' ) . '</a>';
		array_push( $links, $settings_link );
		return $links;
	}

	public function settings_page() {
		$settings_tabs = apply_filters( 'woocommerce_myparcelbe_settings_tabs', array (
				'general'			=> __( 'General', 'woocommerce-myparcelbe' ),
				'export_defaults'	=> __( 'Default export settings', 'woocommerce-myparcelbe' ),
				'checkout'			=> __( 'Checkout', 'woocommerce-myparcelbe' ),
			)
		);

		$active_tab = isset( $_GET[ 'tab' ] ) ? $_GET[ 'tab' ] : 'general';
		?>
		<div class="wrap">
			<h1><?php _e( 'WooCommerce MyParcelbe Settings', 'woocommerce-myparcelbe' ); ?></h1>
			<h2 class="nav-tab-wrapper">
			<?php
			foreach ($settings_tabs as $tab_slug => $tab_title ) {
				printf('<a href="?page=woocommerce_myparcelbe_settings&tab=%1$s" class="nav-tab nav-tab-%1$s %2$s">%3$s</a>', $tab_slug, (($active_tab == $tab_slug) ? 'nav-tab-active' : ''), $tab_title);
			}
			?>
			</h2>

			<?php do_action( 'woocommerce_myparcelbe_before_settings_page', $active_tab ); ?>
				
			<form method="post" action="options.php" id="woocommerce-myparcelbe-settings" class="wcmp_shipment_options">
				<?php
					do_action( 'woocommerce_myparcelbe_before_settings', $active_tab );
					settings_fields( 'woocommerce_myparcelbe_'.$active_tab.'_settings' );
					do_settings_sections( 'woocommerce_myparcelbe_'.$active_tab.'_settings' );
					do_action( 'woocommerce_myparcelbe_after_settings', $active_tab );

					submit_button();
				?>
			</form>

			<?php do_action( 'woocommerce_myparcelbe_after_settings_page', $active_tab ); ?>

		</div>
		<?php
	}

	public function myparcelbe_be_notice( $active_tab ) {
		$base_country = WC()->countries->get_base_country();

		// save or check option to hide notice
		if ( isset( $_GET['myparcelbe_hide_be_notice'] ) ) {
			update_option( 'myparcelbe_hide_be_notice', true );
			$hide_notice = true;
		} else {
			$hide_notice = get_option( 'myparcelbe_hide_be_notice' );
		}

		// link to hide message when one of the premium extensions is installed
		if ( !$hide_notice && $base_country == 'BE' ) {
			$myparcelbe_belgium_link = '<a href="https://wordpress.org/plugins/wc-myparcelbe-belgium/" target="blank">WC MyParcelbe Belgium</a>';
			$text = sprintf(__( 'It looks like your shop is based in Belgium. This plugin is for MyParcelbe Netherlands. If you are using MyParcelbe Belgium, download the %s plugin instead!', 'woocommerce-myparcelbe' ), $myparcelbe_belgium_link);
			$dismiss_button = sprintf('<a href="%s" style="display:inline-block; margin-top: 10px;">%s</a>', add_query_arg( 'myparcelbe_hide_be_notice', 'true' ), __( 'Hide this message', 'woocommerce-myparcelbe' ) );
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
		register_setting( $option_group, $option_name, array( $this->callbacks, 'validate' ) );

		// Create option in wp_options.
		if ( false === get_option( $option_name ) ) {
			$this->default_settings( $option_name );
		}

		// API section.
		add_settings_section(
			'api',
			__( 'API settings', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'section' ),
			$option_group
		);

		// add_settings_field(
		// 	'api_username',
		// 	__( 'Username', 'woocommerce-myparcelbe' ),
		// 	array( $this->callbacks, 'text_input' ),
		// 	$option_group,
		// 	'api',
		// 	array(
		// 		'option_name'	=> $option_name,
		// 		'id'			=> 'api_username',
		// 		'size'			=> 50,
		// 	)
		// );

		add_settings_field(
			'api_key',
			__( 'Key', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'api',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'api_key',
				'size'			=> 50,
			)
		);

		// General section.
		add_settings_section(
			'general',
			__( 'General settings', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'section' ),
			$option_group
		);

		add_settings_field(
			'download_display',
			__( 'Label display', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'radio_button' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'download_display',
				'options' 		=> array(
					'download'	=> __( 'Download PDF' , 'woocommerce-myparcelbe' ),
					'display'	=> __( 'Open de PDF in a new tab' , 'woocommerce-myparcelbe' ),
				),
			)
		);
		add_settings_field(
			'label_format',
			__( 'Label format', 'woocommerce-myparcel' ),
			array( $this->callbacks, 'radio_button' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'label_format',
				'options' 		=> array(
					'A4'	=> __( 'Standard printer (A4)' , 'woocommerce-myparcel' ),
					'A6'	=> __( 'Label Printer (A6)' , 'woocommerce-myparcel' ),
				),
			)
		);

		add_settings_field(
			'print_position_offset',
			__( 'Ask for print start position', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'print_position_offset',
				'description'	=> __( 'This option enables you to continue printing where you left off last time', 'woocommerce-myparcelbe' )
			)
		);

		add_settings_field(
			'email_tracktrace',
			__( 'Track&trace in email', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'email_tracktrace',
				'description'	=> __( 'Add the track&trace code to emails to the customer.<br/><strong>Note!</strong> When you select this option, make sure you have not enabled the track & trace email in your MyParcelbe backend.', 'woocommerce-myparcelbe' )
			)
		);

		add_settings_field(
			'myaccount_tracktrace',
			__( 'Track&trace in My Account', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'myaccount_tracktrace',
				'description'	=> __( 'Show track&trace trace code & link in My Account.', 'woocommerce-myparcelbe' )
			)
		);

		add_settings_field(
			'process_directly',
			__( 'Process shipments directly', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'process_directly',
				'description'	=> __( 'When you enable this option, shipments will be directly processed when sent to myparcelbe.', 'woocommerce-myparcelbe' )
			)
		);

		add_settings_field(
			'order_status_automation',
			__( 'Order status automation', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'order_status_automation',
				'description'	=> __( 'Automatically set order status to a predefined status after succesfull MyParcelbe export.<br/>Make sure <strong>Process shipments directly</strong> is enabled when you use this option together with the <strong>Track&trace in email</strong> option, otherwise the track&trace code will not be included in the customer email.', 'woocommerce-myparcelbe' )
			)
		);		

		add_settings_field(
			'automatic_order_status',
			__( 'Automatic order status', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'order_status_select' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'automatic_order_status',
				'class'			=> 'automatic_order_status',
			)
		);		

		add_settings_field(
			'keep_shipments',
			__( 'Keep old shipments', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'general',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'keep_shipments',
				'default'		=> 0,
				'description'	=> __( 'With this option enabled, data from previous shipments (track & trace links) will be kept in the order when you export more than once.', 'woocommerce-myparcelbe' )
			)
		);

		// Diagnostics section.
		add_settings_section(
			'diagnostics',
			__( 'Diagnostic tools', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'section' ),
			$option_group
		);

		add_settings_field(
			'error_logging',
			__( 'Log API communication', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'diagnostics',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'error_logging',
				'description'	=> '<a href="'.esc_url_raw( admin_url( 'admin.php?page=wc-status&tab=logs' ) ).'" target="_blank">'.__( 'View logs', 'woocommerce-myparcelbe' ).'</a> (wc-myparcelbe)',
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
		register_setting( $option_group, $option_name, array( $this->callbacks, 'validate' ) );

		// Create option in wp_options.
		if ( false === get_option( $option_name ) ) {
			$this->default_settings( $option_name );
		}

		// API section.
		add_settings_section(
			'defaults',
			__( 'Default export settings', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'section' ),
			$option_group
		);


		if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '>=' ) ) {
			add_settings_field(
				'shipping_methods_package_types',
				__( 'Package types', 'woocommerce-myparcelbe' ),
				array( $this->callbacks, 'shipping_methods_package_types' ),
				$option_group,
				'defaults',
				array(
					'option_name'	=> $option_name,
					'id'			=> 'shipping_methods_package_types',
					'package_types'	=> WooCommerce_MyParcelBE()->export->get_package_types(),
					'description'	=> __( 'Select one or more shipping methods for each MyParcelbe package type', 'woocommerce-myparcelbe' ),
				)
			);
		} else {
			add_settings_field(
				'package_type',
				__( 'Shipment type', 'woocommerce-myparcelbe' ),
				array( $this->callbacks, 'select' ),
				$option_group,
				'defaults',
				array(
					'option_name'	=> $option_name,
					'id'			=> 'package_type',
					'default'		=> '1',
					'options' 		=> WooCommerce_MyParcelBE()->export->get_package_types(),
				)
			);			
		}

		add_settings_field(
			'connect_email',
			__( 'Connect customer email', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'connect_email',
				'description'	=> sprintf(__( 'When you connect the customer email, MyParcelbe can send a Track&Trace email to this address. In your %sMyParcelbe backend%s you can enable or disable this email and format it in your own style.', 'woocommerce-myparcelbe' ), '<a href="https://backoffice.myparcelbe.nl/ttsettingstable" target="_blank">', '</a>')
			)
		);

		add_settings_field(
			'connect_phone',
			__( 'Connect customer phone', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'connect_phone',
				'description'	=> __( "When you connect the customer's phone number, the courier can use this for the delivery of the parcel. This greatly increases the delivery success rate for foreign shipments.", 'woocommerce-myparcelbe' )
			)
		);

		add_settings_field(
			'large_format',
			__( 'Extra large size', 'woocommerce-myparcelbe' ).' (+ &euro;2.45)',
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'large_format',
				'description'	=> __( 'Enable this option when your shipment is bigger than 100 x 70 x 50 cm, but smaller than 175 x 78 x 58 cm. An extra fee of &euro;&nbsp;2,45 will be charged.<br/><strong>Note!</strong> If the parcel is bigger than 175 x 78 x 58 of or heavier than 30 kg, the pallet rate of &euro;&nbsp;70,00 will be charged.', 'woocommerce-myparcelbe' )
			)
		);
		
		add_settings_field(
			'only_recipient',
			__( 'Home address only', 'woocommerce-myparcelbe' ).' (+ &euro;0.29)',
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'only_recipient',
				'description'	=> __( "If you don't want the parcel to be delivered at the neighbours, choose this option.", 'woocommerce-myparcelbe' )
			)
		);
		
		add_settings_field(
			'signature',
			__( 'Signature on delivery', 'woocommerce-myparcelbe' ).' (+ &euro;0.36)',
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'signature',
				'description'	=> __( 'The parcel will be offered at the delivery address. If the recipient is not at home, the parcel will be delivered to the neighbours. In both cases, a signuture will be required.', 'woocommerce-myparcelbe' )
			)
		);
		
		// add_settings_field(
		// 	'home_address_signature',
		// 	__( 'Home address only + signature on delivery', 'woocommerce-myparcelbe' ).' (+ &euro;0.42)',
		// 	array( $this->callbacks, 'checkbox' ),
		// 	$option_group,
		// 	'defaults',
		// 	array(
		// 		'option_name'	=> $option_name,
		// 		'id'			=> 'home_address_signature',
		// 		'description'	=> __( 'This is the secure option. The parcel will only be delivered at the recipient address, who has to sign for delivery. This way you can be certain the parcel will be handed to the recipient.', 'woocommerce-myparcelbe' )
		// 	)
		// );
		
		add_settings_field(
			'return',
			__( 'Return if no answer', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'return',
				'description'	=> __( 'By default, a parcel will be offered twice. After two unsuccessful delivery attempts, the parcel will be available at the nearest pickup point for two weeks. There it can be picked up by the recipient with the note that was left by the courier. If you want to receive the parcel back directly and NOT forward it to the pickup point, enable this option.', 'woocommerce-myparcelbe' )
			)
		);
		
		add_settings_field(
			'insured',
			__( 'Insured shipment (from + &euro;0.50)', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'insured',
				'description'	=> __( 'By default, there is no insurance on the shipments. If you still want to insure the shipment, you can do that from &euro;0.50. We insure the purchase value of the shipment, with a maximum insured value of &euro; 5.000. Insured parcels always contain the options "Home address only" en "Signature for delivery"', 'woocommerce-myparcelbe' ),
				'class'			=> 'insured',
			)
		);

		add_settings_field(
			'insured_amount',
			__( 'Insured amount', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'select' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'insured_amount',
				'default'		=> 'standard',
				'class'			=> 'insured_amount',
				'options' 		=> array(
					'49'		=> __( 'Insured up to &euro; 50 (+ &euro; 0.50)' , 'woocommerce-myparcelbe' ),
					'249'		=> __( 'Insured up to  &euro; 250 (+ &euro; 1.00)' , 'woocommerce-myparcelbe' ),
					'499'		=> __( 'Insured up to  &euro; 500 (+ &euro; 1.65)' , 'woocommerce-myparcelbe' ),
					''			=> __( '> &euro; 500 insured (+ &euro; 1.65 / &euro; 500)' , 'woocommerce-myparcelbe' ),
				),
			)
		);

		add_settings_field(
			'insured_amount_custom',
			__( 'Insured amount (in euro)', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'insured_amount_custom',
				'size'			=> '5',
				'class'			=> 'insured_amount',
			)
		);

		add_settings_field(
			'label_description',
			__( 'Label description', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'label_description',
				'size'			=> '25',
				'description'	=> __( "With this option, you can add a description to the shipment. This will be printed on the top left of the label, and you can use this to search or sort shipments in the MyParcelbe Backend. Use <strong>[ORDER_NR]</strong> to include the order number, <strong>[DELIVERY_DATE]</strong> to include the delivery date.", 'woocommerce-myparcelbe' ),
			)
		);

		add_settings_field(
			'empty_parcel_weight',
			__( 'Empty parcel weight (grams)', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'defaults',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'empty_parcel_weight',
				'size'			=> '5',
				'description'	=> __( 'Default weight of your empty parcel, rounded to grams.', 'woocommerce-myparcelbe' ),
			)
		);

		// World Shipments section.
		add_settings_section(
			'world_shipments',
			__( 'World Shipments', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'section' ),
			$option_group
		);

		add_settings_field(
			'hs_code',
			__( 'Default HS Code', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'world_shipments',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'hs_code',
				'size'			=> '5',
				'description'	=> sprintf(__( 'You can find HS codes on the %ssite of the Dutch Customs%s.', 'woocommerce-myparcelbe' ), '<a href="http://tarief.douane.nl/tariff/index.jsf" target="_blank">','</a>')

			)
		);
		add_settings_field(
			'package_contents',
			__( 'Customs shipment type', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'select' ),
			$option_group,
			'world_shipments',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'package_contents',
				'options' 		=> array(
					1 => __( 'Commercial goods' , 'woocommerce-myparcelbe' ),
					2 => __( 'Commercial samples' , 'woocommerce-myparcelbe' ),
					3 => __( 'Documents' , 'woocommerce-myparcelbe' ),
					4 => __( 'Gifts' , 'woocommerce-myparcelbe' ),
					5 => __( 'Return shipment' , 'woocommerce-myparcelbe' ),
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
		register_setting( $option_group, $option_name, array( $this->callbacks, 'validate' ) );

		// Create option in wp_options.
		if ( false === get_option( $option_name ) ) {
			$this->default_settings( $option_name );
		}

		// Delivery options section.
		add_settings_section(
			'delivery_options',
			__( 'Delivery options', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'section' ),
			$option_group
		);


		add_settings_field(
			'myparcelbe_checkout',
			__( 'Enable MyParcelbe delivery options', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'delivery_options',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'myparcelbe_checkout',
			)
		);

		add_settings_field(
			'checkout_display',
			__( 'Display for', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'select' ),
			$option_group,
			'delivery_options',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'checkout_display',
				'options' 		=> array(
					'selected_methods'	=> __( 'Shipping methods associated with Parcels' , 'woocommerce-myparcelbe' ),
					'all_methods'		=> __( 'All shipping methods' , 'woocommerce-myparcelbe' ),
				),
				'description'	=> __( 'To associate specific shipping methods with parcels, see the Default export settings tab. Note that the delivery options will be automatically hidden for foreign addresses, regardless of this setting', 'woocommerce-myparcelbe' ),
			)
		);

		add_settings_field(
			'only_recipient',
			__( 'Home address only', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'delivery_option_enable' ),
			$option_group,
			'delivery_options',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'only_recipient',
			)
		);

		add_settings_field(
			'signed',
			__( 'Signature on delivery', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'delivery_option_enable' ),
			$option_group,
			'delivery_options',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'signed',
			)
		);

		add_settings_field(
			'night',
			__( 'Evening delivery', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'delivery_option_enable' ),
			$option_group,
			'delivery_options',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'night',
			)
		);

		add_settings_field(
			'morning',
			__( 'Morning delivery', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'delivery_option_enable' ),
			$option_group,
			'delivery_options',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'morning',
			)
		);

		add_settings_field(
			'pickup',
			__( 'PostNL pickup', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'delivery_option_enable' ),
			$option_group,
			'delivery_options',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'pickup',
			)
		);

		add_settings_field(
			'pickup_express',
			__( 'Early PostNL pickup', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'delivery_option_enable' ),
			$option_group,
			'delivery_options',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'pickup_express',
			)
		);

		// Checkout options section.
		add_settings_section(
			'processing_parameters',
			__( 'Shipment processing parameters', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'section' ),
			$option_group
		);

		$days_of_the_week = array(
			'0' => __( 'Sunday', 'woocommerce-myparcelbe' ),
			'1' => __( 'Monday', 'woocommerce-myparcelbe' ),
			'2' => __( 'Tuesday', 'woocommerce-myparcelbe' ),
			'3' => __( 'Wednesday', 'woocommerce-myparcelbe' ),
			'4' => __( 'Thursday', 'woocommerce-myparcelbe' ),
			'5' => __( 'Friday', 'woocommerce-myparcelbe' ),
			'6' => __( 'Saturday', 'woocommerce-myparcelbe' ),
		);

		add_settings_field(
			'dropoff_days',
			__( 'Dropoff days', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'enhanced_select' ),
			$option_group,
			'processing_parameters',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'dropoff_days',
				'options'		=> $days_of_the_week,
				'description'	=> __( 'Days of the week on which you hand over parcels to PostNL', 'woocommerce-myparcelbe' ),
			)
		);

		add_settings_field(
			'cutoff_time',
			__( 'Cut-off time', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'processing_parameters',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'cutoff_time',
				'type'			=> 'text',
				'size'			=> '5',
				'description'	=> __( 'Time at which you stop processing orders for the day (format: hh:mm)', 'woocommerce-myparcelbe' ),
			)
		);

		add_settings_field(
			'dropoff_delay',
			__( 'Dropoff delay', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'processing_parameters',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'dropoff_delay',
				'type'			=> 'number',
				'size'			=> '2',
				'description'	=> __( 'Number of days you take to process an order', 'woocommerce-myparcelbe' ),
			)
		);

		add_settings_field(
			'deliverydays_window',
			__( 'Delivery days window', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'processing_parameters',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'deliverydays_window',
				'type'			=> 'number',
				'size'			=> '2',
				'description'	=> __( 'Number of days you allow the customer to postpone a shipment', 'woocommerce-myparcelbe' ),
			)
		);

		add_settings_field(
			'monday_delivery',
			__( 'Enable monday delivery', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'processing_parameters',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'monday_delivery',
			)
		);

		add_settings_field(
			'saturday_cutoff_time',
			__( 'Cut-off time for monday delivery', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'text_input' ),
			$option_group,
			'processing_parameters',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'saturday_cutoff_time',
				'type'			=> 'text',
				'size'			=> '5',
				'description'	=> __( 'Time at which you stop processing orders on saturday for monday delivery (format: hh:mm)', 'woocommerce-myparcelbe' ),
			)
		);

		// Customizations section
		add_settings_section(
			'customizations',
			__( 'Customizations', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'section' ),
			$option_group
		);

		add_settings_field(
			'base_color',
			__( 'Base color', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'color_picker' ),
			$option_group,
			'customizations',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'base_color',
				'size'			=> '10',
				'description'	=> __( 'Color of the header & tabs (cyan by default)', 'woocommerce-myparcelbe' ),
			)
		);


		add_settings_field(
			'highlight_color',
			__( 'Highlight color', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'color_picker' ),
			$option_group,
			'customizations',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'highlight_color',
				'size'			=> '10',
				'description'	=> __( 'Color of the selections/highlights (orange by default)', 'woocommerce-myparcelbe' ),
			)
		);

		add_settings_field(
			'custom_css',
			__( 'Custom styles', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'textarea' ),
			$option_group,
			'customizations',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'custom_css',
				'width'			=> '80',
				'height'		=> '8',
			)
		);

		add_settings_field(
			'autoload_google_fonts',
			__( 'Automatically load Google fonts', 'woocommerce-myparcelbe' ),
			array( $this->callbacks, 'checkbox' ),
			$option_group,
			'customizations',
			array(
				'option_name'	=> $option_name,
				'id'			=> 'autoload_google_fonts',
			)
		);
	}

	
	/**
	 * Set default settings.
	 * 
	 * @return void.
	 */
	public function default_settings( $option ) {
		// $default = array(
		// 	'process'			=> '1',
		// 	'keep_consignments'	=> '0',
		// 	'download_display'	=> 'download',
		// 	'email'				=> '1',
		// 	'telefoon'			=> '1',
		// 	'extragroot'		=> '0',
		// 	'huisadres'			=> '0',
		// 	'handtekening'		=> '0',
		// 	'huishand'			=> '0',
		// 	'retourbgg'			=> '0',
		// 	'verzekerd'			=> '0',
		// 	'verzekerdbedrag'	=> '0',
		// 	'kenmerk'			=> '',
		// 	'verpakkingsgewicht'=> '0',
		// );
	
		// add_option( 'wcmyparcelbe_settings', $default );

		switch ( $option ) {
			case 'woocommerce_myparcelbe_general_settings':
				$default = array(

				);
				break;
			case 'woocommerce_myparcelbe_checkout_settings':
				$default = array (
					'pickup_enabled' => '1',
					'dropoff_days' => array ( 1,2,3,4,5 ),
					'dropoff_delay' => '0',
					'deliverydays_window' => '1',
				);
				break;
			case 'woocommerce_myparcelbe_export_defaults_settings':
			default:
				$default = array();
				break;
		}

		if ( false === get_option( $option ) ) {
			add_option( $option, $default );
		} else {
			update_option( $option, $default );
		}
	}
}

endif; // class_exists

return new WooCommerce_MyParcelBE_Settings();