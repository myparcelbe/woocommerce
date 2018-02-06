jQuery( function( $ ) {
	var myparcelbe_update_timer = false;
	window.myparcelbe_checkout_updating = false;
	window.myparcelbe_force_update = false;
	window.myparcelbe_selected_shipping_method = '';
	window.myparcelbe_updated_shipping_method = '';

	// reference jQuery for MyParcelbe iFrame
	window.mypajQuery = $;
	
	// replace iframe placeholder with actual iframe
	$('.myparcelbe-iframe-placeholder').replaceWith( '<iframe id="myparcelbe-iframe" src="" frameborder="0" scrolling="auto" style="width: 100%; display: none;">Bezig met laden...</iframe>');
	// show if we have to
	if ( window.myparcelbe_initial_hide == false ) {
		$('#myparcelbe-iframe').show();
	}

	// set iframe object load functions
	var iframe_object = $('#myparcelbe-iframe')
		.load( function() {
			var $MyPaiFrame = $('#myparcelbe-iframe')[0];
			window.MyPaWindow = $MyPaiFrame.contentWindow ? $MyPaiFrame.contentWindow : $MyPaiFrame.contentDocument.defaultView;
			MyPaLoaded();
		});
	// load iframe content
	iframe_object.attr( 'src', wc_myparcelbe_frontend.iframe_url );

	window.MyPaSetHeight = function() {
		setTimeout(function () {
			var iframeheight = MyPaWindow.document.body.scrollHeight;
			// console.log(iframeheight);
			$('#myparcelbe-iframe').height(iframeheight);
		}, 500);

		// $('#myparcelbe-iframe').height($('#myparcelbe-iframe').contents().height());
	}

	window.MyPaLoaded = function() {
		window.update_myparcelbe_settings();
		MyPaWindow.initSettings( window.mypabe.settings );
		MyPaSetHeight();
	}

	// set iframe height when delivery options changed
	$( document ).on('change', '#mypa-chosen-delivery-options input', function() {
		MyPaSetHeight(); // may need a trick to prevent height from updating 10x
		window.myparcelbe_checkout_updating = true;
		$('body').trigger('update_checkout');
	});

	// make delivery options update at least once (but don't hammer)
	// myparcelbe_update_timer = setTimeout( update_myparcelbe_delivery_options_action, '500' );

	// hide checkout options if not NL
	$( '#billing_country, #shipping_country' ).change(function() {
		window.myparcelbe_force_update = true; // in case the shipping method doesn't change
		// check_country();
		update_myparcelbe_settings();
	});

	// multi-step checkout doesn't trigger update_checkout when postcode changed
	$( document ).on('change','.wizard .content #billing_street_name, .wizard .content #billing_house_number, .wizard .content #billing_postcode',function() {
		update_myparcelbe_settings();
	});

	// hide checkout options for non parcel shipments
	$( document ).on( 'updated_checkout', function() {
		window.myparcelbe_checkout_updating = false; //done updating
		if ( typeof window.myparcelbe_delivery_options_always_display !== 'undefined' && window.myparcelbe_delivery_options_always_display == 'yes') {
			show_myparcelbe_delivery_options();
		} else if ( window.myparcelbe_delivery_options_shipping_methods.length > 0 ) {
			// check if shipping is user choice or fixed
			if ( $( '#order_review .shipping_method' ).length > 1 ) {
				var shipping_method = $( '#order_review .shipping_method:checked').val();
			} else {
				var shipping_method = $( '#order_review .shipping_method').val();
			}

			if ( typeof shipping_method === 'undefined' ) {
				// no shipping method selected, hide by default
				hide_myparcelbe_delivery_options();
				return;
			}

			if (shipping_method.indexOf('table_rate:') !== -1) {
				// WC Table Rates
				// use shipping_method = method_id:instance_id:rate_id
			} else {
				// none table rates
				// strip instance_id if present
				if (shipping_method.indexOf(':') !== -1) {
					shipping_method = shipping_method.substring(0, shipping_method.indexOf(':'));
				}
				var shipping_class = $('#myparcelbe_highest_shipping_class').val();
				// add class refinement if we have a shipping class
				if (shipping_class) {
					shipping_method_class = shipping_method+':'+shipping_class;
				}
			}
			
			if ( shipping_class && $.inArray(shipping_method_class, window.myparcelbe_delivery_options_shipping_methods) > -1 ) {
				window.myparcelbe_updated_shipping_method = shipping_method_class;
				show_myparcelbe_delivery_options();
				window.myparcelbe_selected_shipping_method = shipping_method_class;
			} else if ( $.inArray(shipping_method, window.myparcelbe_delivery_options_shipping_methods) > -1 ) {
				// fallback to bare method if selected in settings
				window.myparcelbe_updated_shipping_method = shipping_method;
				show_myparcelbe_delivery_options();
				window.myparcelbe_selected_shipping_method = shipping_method;
			} else {
				shipping_method_now = typeof shipping_method_class !== 'undefined' ? shipping_method_class : shipping_method;
				window.myparcelbe_updated_shipping_method = shipping_method_now;
				hide_myparcelbe_delivery_options();
				window.myparcelbe_selected_shipping_method = shipping_method_now;
			}
		} else {
			// not sure if we should already hide by default?
			hide_myparcelbe_delivery_options();
		}
	});

	// update myparcelbe settings object with address when shipping or billing address changes
	window.update_myparcelbe_settings = function() {
		var settings = get_settings();
		if (settings == false) {
			return;
		}

		var billing_postcode = $( '#billing_postcode' ).val();
		var billing_house_number = $( '#billing_house_number' ).val();
		var billing_street_name = $( '#billing_street_name' ).val();

		var shipping_postcode = $( '#shipping_postcode' ).val();
		var shipping_house_number = $( '#shipping_house_number' ).val();
		var shipping_street_name = $( '#shipping_street_name' ).val();

		var use_shipping = $( '#ship-to-different-address-checkbox' ).is(':checked');

		if (!use_shipping && billing_postcode && billing_house_number) {
			window.mypabe.settings.postal_code = billing_postcode.replace(/\s+/g, '');
			window.mypabe.settings.number = billing_house_number;
			window.mypabe.settings.street = billing_street_name;
			update_myparcelbe_delivery_options()
		} else if (shipping_postcode && shipping_house_number) {
			window.mypabe.settings.postal_code = shipping_postcode.replace(/\s+/g, '');;
			window.mypabe.settings.number = shipping_house_number;
			window.mypabe.settings.street = shipping_street_name;
			update_myparcelbe_delivery_options()
		}

	}
	
	// billing or shipping changes
	$( '#billing_postcode, #billing_house_number, #shipping_postcode, #shipping_house_number' ).change(function() {
		update_myparcelbe_settings();
	});


	$( '#billing_postcode, #billing_house_number, #shipping_postcode, #shipping_house_number' ).change();

	// any delivery option selected/changed - update checkout for fees
	$('#mypabe-chosen-delivery-options').on('change', 'input', function() {
		window.myparcelbe_checkout_updating = true;
		// disable signed & recipient only when switching to pickup location
		mypabe_postnl_data = JSON.parse( $('#mypabe-chosen-delivery-options #mypa-input').val() );
		if (typeof mypabe_postnl_data.location != 'undefined' ) {
			$('#mypa-signed, #mypa-recipient-only').prop( "checked", false );
		}
		jQuery('body').trigger('update_checkout');
	});

	// pickup location selected
	// $('#mypa-location-container').on('change', 'input[type=radio]', function() {
	// 	var pickup_location = $( this ).val();
	// });
	// 
	function get_settings() {
		if (typeof window.mypabe!= 'undefined' && typeof window.mypabe.settings != 'undefined') {
			return window.mypabe.settings;
		} else {
			return false;
		}
	}

	function check_country() {
		country = get_shipping_country();
		if (country != 'NL') {
			hide_myparcelbe_delivery_options();
		} else {
			$( '#myparcelbe-iframe' ).show();
			$( '#mypa-options-enabled' ).prop('checked', true);
		}
	}

	function get_shipping_country() {
		if ( $( '#ship-to-different-address-checkbox' ).is(':checked') ) {
			country = $( '#shipping_country' ).val();
		} else {
			country = $( '#billing_country' ).val();
		}

		return country;
	}

	function hide_myparcelbe_delivery_options() {
		$( '#myparcelbe-iframe' ).hide();
		$( '#mypa-options-enabled' ).prop('checked', false);
		// clear delivery options
		if ( is_updated_shipping_method() ) { // prevents infinite updated_checkout - update_checkout loop
			$( '#mypa-chosen-delivery-options #mypa-input' ).val('');
			$( '#mypa-chosen-delivery-options :checkbox' ).prop('checked', false);
			jQuery('body').trigger('update_checkout');
		}
	}

	function show_myparcelbe_delivery_options() {
		// show only if NL
		check_country();
		if ( is_updated_shipping_method() ) { // prevents infinite updated_checkout - update_checkout loop
			update_myparcelbe_settings();
		}
	}


	function update_myparcelbe_delivery_options() {
		// Small timeout to prevent multiple requests when several fields update at the same time
		clearTimeout( myparcelbe_update_timer );
		myparcelbe_update_timer = setTimeout( update_myparcelbe_delivery_options_action, '5' );
	}

	function update_myparcelbe_delivery_options_action() {
		country = get_shipping_country();
		if ( window.myparcelbe_checkout_updating !== true && country == 'NL' && typeof MyPaWindow != 'undefined' && typeof MyPaWindow.mypabe!= 'undefined' ) {
			MyPaWindow.mypabe.settings = window.mypabe.settings;
			MyPaWindow.updateMyPa();
		}
	}

	function is_updated_shipping_method() {
		if ( window.myparcelbe_updated_shipping_method != window.myparcelbe_selected_shipping_method || window.myparcelbe_force_update === true ) {
			window.myparcelbe_force_update = false; // only force once
			return true;
		} else {
			return false;
		}
	}

});
