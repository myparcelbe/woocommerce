<?php

use WPO\WC\MyParcelBE\Compatibility\Order as WCX_Order;
use WPO\WC\MyParcelBE\Compatibility\WC_Core as WCX;

if (! defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

if (class_exists('WCMPBE_Postcode_Fields')) {
    return new WCMPBE_Postcode_Fields();
}

class WCMPBE_Postcode_Fields
{
    /*
     * Regular expression used to split street name from house number.
     * This regex goes from right to left
     * Contains php keys to store the data in an array
     * Taken from https://github.com/myparcel/sdk
     */
    public const SPLIT_STREET_REGEX = '~(?P<street>.*?)\s?(?P<street_suffix>(?P<number>[\d]+)[\s-]{0,2}(?P<number_suffix>[a-zA-Z/\s]{0,5}$|[0-9/]{0,5}$|\s[a-zA-Z]{1}[0-9]{0,3}$|\s[0-9]{2}[a-zA-Z]{0,3}$))$~';

    public const COUNTRIES_WITH_SPLIT_ADDRESS_FIELDS = ['NL', 'BE'];

    /**
     * @var array|string
     */
    private $postedValues;

    public function __construct()
    {
        // Load styles
        add_action('wp_enqueue_scripts', [&$this, 'add_styles_scripts']);

        // Load scripts
        add_action('admin_enqueue_scripts', [&$this, 'admin_scripts_styles']);

        add_action("wp_loaded", [$this, "initialize"], 9999);
    }

    public function initialize()
    {
        if (WCMYPABE()->setting_collection->isEnabled('use_split_address_fields')) {
            // Add street name & house number checkout fields.
            if (version_compare(WOOCOMMERCE_VERSION, '2.0') >= 0) {
                // WC 2.0 or newer is used, the filter got a $country parameter, yay!
                add_filter(
                    'woocommerce_billing_fields',
                    [&$this, 'modifyBillingFields'],
                    apply_filters('wcmpbe_checkout_fields_priority', 10, 'billing'),
                    2
                );
                add_filter(
                    'woocommerce_shipping_fields',
                    [&$this, 'modifyShippingFields'],
                    apply_filters('wcmpbe_checkout_fields_priority', 10, 'shipping'),
                    2
                );
            } else {
                // Backwards compatibility
                add_filter('woocommerce_billing_fields', [&$this, 'modifyBillingFields']);
                add_filter('woocommerce_shipping_fields', [&$this, 'modifyShippingFields']);
            }

            // Localize checkout fields (limit custom checkout fields to NL and BE)
            add_filter('woocommerce_country_locale_field_selectors', [&$this, 'country_locale_field_selectors']);
            add_filter('woocommerce_default_address_fields', [&$this, 'default_address_fields']);
            add_filter('woocommerce_get_country_locale', [&$this, 'woocommerce_locale_be'], 1, 1); // !

            // Load custom order data.
            add_filter('woocommerce_load_order_data', [&$this, 'load_order_data']);

            // Custom shop_order details.
            add_filter('woocommerce_admin_billing_fields', [&$this, 'admin_billing_fields']);
            add_filter('woocommerce_admin_shipping_fields', [&$this, 'admin_shipping_fields']);
            add_filter('woocommerce_found_customer_details', [$this, 'customer_details_ajax']);
            add_action('save_post', [&$this, 'save_custom_fields']);

            // add to user profile page
            add_filter('woocommerce_customer_meta_fields', [&$this, 'user_profile_fields']);

            add_action(
                'woocommerce_checkout_update_order_meta',
                [&$this, 'merge_street_number_suffix'],
                20,
                2
            );
            add_filter(
                'woocommerce_process_checkout_field_billing_postcode',
                [&$this, 'clean_billing_postcode']
            );
            add_filter(
                'woocommerce_process_checkout_field_shipping_postcode',
                [&$this, 'clean_shipping_postcode']
            );

            // Save the order data in WooCommerce 2.2 or later.
            if (version_compare(WOOCOMMERCE_VERSION, '2.2') >= 0) {
                add_action('woocommerce_checkout_update_order_meta', [&$this, 'save_order_data'], 10, 2);
            }

            $this->load_woocommerce_filters();
        } else { // if NOT using old fields
            add_action('woocommerce_after_checkout_validation', [&$this, 'validate_address_fields'], 10, 2);
        }

        // Processing checkout
        add_filter('woocommerce_validate_postcode', [&$this, 'validate_postcode'], 10, 3);

        // set later priority for woocommerce_billing_fields / woocommerce_shipping_fields
        // when Checkout Field Editor is active
        if (function_exists('thwcfd_is_locale_field')
            || function_exists(
                'wc_checkout_fields_modify_billing_fields'
            )) {
            add_filter('be_checkout_fields_priority', 1001);
        }

        // Hide state field for countries without states (backwards compatible fix for bug #4223)
        if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
            add_filter('woocommerce_countries_allowed_country_states', [&$this, 'hide_states']);
        }
    }

    public function load_woocommerce_filters()
    {
        // Custom address format.
        if (version_compare(WOOCOMMERCE_VERSION, '2.0.6', '>=')) {
            add_filter('woocommerce_localisation_address_formats', [$this, 'localisation_address_formats']);
            add_filter(
                'woocommerce_formatted_address_replacements',
                [$this, 'formatted_address_replacements'],
                1,
                2
            );
            add_filter(
                'woocommerce_order_formatted_billing_address',
                [$this, 'order_formatted_billing_address'],
                1,
                2
            );
            add_filter(
                'woocommerce_order_formatted_shipping_address',
                [$this, 'order_formatted_shipping_address'],
                1,
                2
            );
            add_filter(
                'woocommerce_user_column_billing_address',
                [$this, 'user_column_billing_address'],
                1,
                2
            );
            add_filter(
                'woocommerce_user_column_shipping_address',
                [$this, 'user_column_shipping_address'],
                1,
                2
            );
            add_filter(
                'woocommerce_my_account_my_address_formatted_address',
                [$this, 'my_account_my_address_formatted_address'],
                1,
                3
            );
        }
    }

    /**
     * Load styles & scripts.
     */
    public function add_styles_scripts()
    {
        if (! is_checkout() && ! is_account_page()) {
            return;
        }

        // Enqueue styles for delivery options
        wp_enqueue_style(
            'checkout',
            WCMYPABE()->plugin_url() . '/assets/css/checkout.css',
            false,
            WC_MYPARCEL_BE_VERSION
        );

        if (! WCMYPABE()->setting_collection->isEnabled('use_split_address_fields')) {
            return;
        }

        if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<=')) {
            // Backwards compatibility for https://github.com/woothemes/woocommerce/issues/4239
            wp_register_script(
                'checkout',
                WCMYPABE()->plugin_url() . '/assets/js/checkout.js',
                ['jquery', 'wc-checkout'],
                WC_MYPARCEL_BE_VERSION,
                true
            );
            wp_enqueue_script('checkout');
        }

        if (is_account_page()) {
            // Disable regular address fields for NL on account page - Fixed in WC 2.1 but not on init...
            wp_register_script(
                'account-page',
                WCMYPABE()->plugin_url() . '/assets/js/account-page.js',
                ['jquery'],
                WC_MYPARCEL_BE_VERSION,
                true
            );
            wp_enqueue_script('account-page');
        }
    }

    /**
     * Load admin styles & scripts.
     */
    public function admin_scripts_styles($hook)
    {
        global $post_type;
        if ($post_type == 'shop_order') {
            wp_enqueue_style(
                'checkout-admin',
                WCMYPABE()->plugin_url() . '/assets/css/checkout-admin.css',
                [], // deps
                WC_MYPARCEL_BE_VERSION
            );
        }
    }

    /**
     * Hide default Dutch address fields
     *
     * @param array $locale woocommerce country locale field settings
     *
     * @return array $locale
     */
    public function woocommerce_locale_be(array $locale): array
    {
        foreach (self::COUNTRIES_WITH_SPLIT_ADDRESS_FIELDS as $cc) {
            $locale[$cc]['address_1'] = [
                'required' => false,
                'hidden'   => true,
            ];

            $locale[$cc]['address_2'] = [
                'hidden' => true,
            ];

            $locale[$cc]['state'] = [
                'hidden'   => true,
                'required' => false,
            ];

            $locale[$cc]['street_name'] = [
                'required' => true,
                'hidden'   => false,
            ];

            $locale[$cc]['house_number'] = [
                'required' => true,
                'hidden'   => false,
            ];

            $locale[$cc]['house_number_suffix'] = [
                'required' => false,
                'hidden'   => false,
            ];
        }
        return $locale;
    }

    /**
     * @param array  $fields
     * @param string $country
     *
     * @return array
     */
    public function modifyBillingFields(array $fields, string $country = ''): array
    {
        return $this->modifyCheckoutFields($fields, $country, 'billing');
    }

    /**
     * @param array  $fields
     * @param string $country
     *
     * @return array
     */
    public function modifyShippingFields(array $fields, string $country = ''): array
    {
        return $this->modifyCheckoutFields($fields, $country, 'shipping');
    }

    /**
     * New checkout billing/shipping fields
     *
     * @param array  $fields Default fields.
     *
     * @param string $country
     * @param string $form
     *
     * @return array $fields New fields.
     */
    public function modifyCheckoutFields(array $fields, string $country, string $form): array
    {
        if (isset($fields['_country'])) {
            // some weird bug on the my account page
            $form = '';
        }

        // Set required to true if country is using custom address fields
        $required = self::isCountryWithSplitAddressFields($country);

        // Add street name
        $fields[$form . '_street_name'] = [
            'label'    => __("street_name", "woocommerce-myparcelbe"),
            'class'    => apply_filters('wcmpbe_custom_address_field_class', ['form-row-third first']),
            'required' => $required, // Only required for BE
            'priority' => 60,
        ];

        // Add house number
        $fields[$form . '_house_number'] = [
            'label'    => __("No.", "woocommerce-myparcelbe"),
            'class'    => apply_filters('wcmpbe_custom_address_field_class', ['form-row-third']),
            'required' => $required, // Only required for BE
            'type'     => 'number',
            'priority' => 61,
        ];

        // Add house number suffix
        $fields[$form . '_house_number_suffix'] = [
            'label'     => __("suffix", "woocommerce-myparcelbe"),
            'class'     => apply_filters('wcmpbe_custom_address_field_class', ['form-row-third last']),
            'required'  => false,
            'maxlength' => 4,
            'priority'  => 62,
        ];

        // Create new ordering for checkout fields
        $order_keys = [
            $form . '_first_name',
            $form . '_last_name',
            $form . '_company',
            $form . '_country',
            $form . '_address_1',
            $form . '_address_2',
            $form . '_street_name',
            $form . '_house_number',
            $form . '_house_number_suffix',
            $form . '_postcode',
            $form . '_city',
            $form . '_state',
        ];

        if ($form === 'billing') {
            array_push(
                $order_keys,
                $form . '_email',
                $form . '_phone'
            );
        }

        $new_order = [];

        // Create reordered array and fill with old array values
        foreach ($order_keys as $key) {
            $new_order[$key] = $fields[$key] ?? '';
        }

        // Merge (&overwrite) field array
        $fields = array_merge($new_order, $fields);

        return $fields;
    }

    /**
     * Hide state field for countries without states (backwards compatible fix for WooCommerce bug #4223)
     *
     * @param array $allowed_states states per country
     *
     * @return array
     */
    public function hide_states($allowed_states)
    {
        $hidden_states = [
            'AF' => [],
            'AT' => [],
            'BE' => [],
            'BI' => [],
            'CZ' => [],
            'DE' => [],
            'DK' => [],
            'FI' => [],
            'FR' => [],
            'HU' => [],
            'IS' => [],
            'IL' => [],
            'KR' => [],
            'NL' => [],
            'NO' => [],
            'PL' => [],
            'PT' => [],
            'SG' => [],
            'SK' => [],
            'SI' => [],
            'LK' => [],
            'SE' => [],
            'VN' => [],
        ];
        return $hidden_states + $allowed_states;
    }

    /**
     * Localize checkout fields live
     *
     * @param array $locale_fields list of fields filtered by locale
     *
     * @return array $locale_fields with custom fields added
     */
    public function country_locale_field_selectors($locale_fields)
    {
        $custom_locale_fields = [
            'street_name'         => '#billing_street_name_field, #shipping_street_name_field',
            'house_number'        => '#billing_house_number_field, #shipping_house_number_field',
            'house_number_suffix' => '#billing_house_number_suffix_field, #shipping_house_number_suffix_field',
        ];

        $locale_fields = array_merge($locale_fields, $custom_locale_fields);

        return $locale_fields;
    }

    /**
     * Make BE checkout fields hidden by default
     *
     * @param array $fields default checkout fields
     *
     * @return array $fields default + custom checkout fields
     */
    public function default_address_fields($fields)
    {
        $custom_fields = [
            'street_name'         => [
                'hidden'   => true,
                'required' => false,
            ],
            'house_number'        => [
                'hidden'   => true,
                'required' => false,
            ],
            'house_number_suffix' => [
                'hidden'   => true,
                'required' => false,
            ],
        ];

        $fields = array_merge($fields, $custom_fields);

        return $fields;
    }

    /**
     * Load order custom data.
     *
     * @param array $data Default WC_Order data.
     *
     * @return array       Custom WC_Order data.
     */
    public function load_order_data($data)
    {
        // Billing
        $data['billing_street_name']         = '';
        $data['billing_house_number']        = '';
        $data['billing_house_number_suffix'] = '';

        // Shipping
        $data['shipping_street_name']         = '';
        $data['shipping_house_number']        = '';
        $data['shipping_house_number_suffix'] = '';

        return $data;
    }

    /**
     * Custom billing admin edit fields.
     *
     * @param array $fields Default WC_Order data.
     *
     * @return array         Custom WC_Order data.
     */
    public function admin_billing_fields($fields)
    {
        $fields['street_name'] = [
            'label' => __("street_name", "woocommerce-myparcelbe"),
            'show'  => true,
        ];

        $fields['house_number'] = [
            'label' => __("No.", "woocommerce-myparcelbe"),
            'show'  => true,
        ];

        $fields['house_number_suffix'] = [
            'label' => __("suffix", "woocommerce-myparcelbe"),
            'show'  => true,
        ];

        return $fields;
    }

    /**
     * Custom shipping admin edit fields.
     *
     * @param array $fields Default WC_Order data.
     *
     * @return array         Custom WC_Order data.
     */
    public function admin_shipping_fields($fields)
    {
        $fields['street_name'] = [
            'label' => __("street_name", "woocommerce-myparcelbe"),
            'show'  => true,
        ];

        $fields['house_number'] = [
            'label' => __("No.", "woocommerce-myparcelbe"),
            'show'  => true,
        ];

        $fields['house_number_suffix'] = [
            'label' => __("suffix", "woocommerce-myparcelbe"),
            'show'  => true,
        ];

        return $fields;
    }

    /**
     * Custom user profile edit fields.
     */
    public function user_profile_fields($meta_fields)
    {
        $myparcelbe_billing_fields = [
            'billing_street_name'         => [
                'label'       => __("Street", "woocommerce-myparcelbe"),
                'description' => '',
            ],
            'billing_house_number'        => [
                'label'       => __("Number", "woocommerce-myparcelbe"),
                'description' => '',
            ],
            'billing_house_number_suffix' => [
                'label'       => __("Suffix", "woocommerce-myparcelbe"),
                'description' => '',
            ],
        ];
        $myparcelbe_shipping_fields = [
            'shipping_street_name'         => [
                'label'       => __("Street", "woocommerce-myparcelbe"),
                'description' => '',
            ],
            'shipping_house_number'        => [
                'label'       => __("Number", "woocommerce-myparcelbe"),
                'description' => '',
            ],
            'shipping_house_number_suffix' => [
                'label'       => __("Suffix", "woocommerce-myparcelbe"),
                'description' => '',
            ],
        ];

        // add myparcelbe fields to billing section
        $billing_fields                   = array_merge(
            $meta_fields['billing']['fields'],
            $myparcelbe_billing_fields
        );
        $billing_fields                   = $this->array_move_keys(
            $billing_fields,
            ['billing_street_name', 'billing_house_number', 'billing_house_number_suffix'],
            'billing_address_2',
            'after'
        );
        $meta_fields['billing']['fields'] = $billing_fields;

        // add myparcelbe fields to shipping section
        $shipping_fields                   = array_merge(
            $meta_fields['shipping']['fields'],
            $myparcelbe_shipping_fields
        );
        $shipping_fields                   = $this->array_move_keys(
            $shipping_fields,
            ['shipping_street_name', 'shipping_house_number', 'shipping_house_number_suffix'],
            'shipping_address_2',
            'after'
        );
        $meta_fields['shipping']['fields'] = $shipping_fields;

        return $meta_fields;
    }

    /**
     * Add custom fields in customer details ajax.
     * called when clicking the "Load billing/shipping address" button on Edit Order view
     *
     * @return array
     */
    public function customer_details_ajax($customer_data)
    {
        $postedValues = $this->getPostedValues();
        $user_id      = (int) trim(stripslashes($postedValues['user_id']));
        $type_to_load = esc_attr(trim(stripslashes($postedValues['type_to_load'])));

        $custom_data = [
            $type_to_load . '_street_name'         => get_user_meta($user_id, $type_to_load . '_street_name', true),
            $type_to_load . '_house_number'        => get_user_meta(
                $user_id,
                $type_to_load . '_house_number',
                true
            ),
            $type_to_load . '_house_number_suffix' => get_user_meta(
                $user_id,
                $type_to_load . '_house_number_suffix',
                true
            ),
        ];

        return array_merge($customer_data, $custom_data);
    }

    /**
     * Save custom fields from admin.
     */
    public function save_custom_fields($post_id): void
    {
        $post_type = get_post_type($post_id);
        $postedValues = $this->getPostedValues();
        if (('shop_order' === $post_type || 'shop_order_refund' === $post_type) && ! empty($postedValues)) {
            $order          = WCX::get_order($post_id);
            $addresses      = ['billing', 'shipping'];
            $address_fields = ['street_name', 'house_number', 'house_number_suffix'];
            foreach ($addresses as $address) {
                foreach ($address_fields as $address_field) {
                    if (isset($postedValues["_{$address}_{$address_field}"])) {
                        WCX_Order::update_meta_data(
                            $order,
                            "_{$address}_{$address_field}",
                            stripslashes($postedValues["_{$address}_{$address_field}"])
                        );
                    }
                }
            }
        }
    }

    /**
     * Merge street name, street number and street suffix into the default 'address_1' field
     *
     * @param mixed $order_id Order ID of checkout order.
     *
     * @return void
     */
    public function merge_street_number_suffix($order_id): void
    {
        $postedValues                   = $this->getPostedValues();
        $order                          = WCX::get_order($order_id);
        $billingHasCustomAddressFields  = self::isCountryWithSplitAddressFields($postedValues['billing_country']);
        $shippingHasCustomAddressFields = self::isCountryWithSplitAddressFields($postedValues['shipping_country']);
        $postedValues = $this->getPostedValues();

        if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<=')) {
            // old versions use 'shiptobilling'
            $shipToDifferentAddress = ! isset($postedValues['shiptobilling']);
        } else {
            // WC2.1
            $shipToDifferentAddress = isset($postedValues['ship_to_different_address']);
        }

        if ($billingHasCustomAddressFields) {
            // concatenate street & house number & copy to 'billing_address_1'
            $suffix = ! empty($postedValues['billing_house_number_suffix'])
                ? '-' . $postedValues['billing_house_number_suffix']
                : '';

            $billingHouseNumber = $postedValues['billing_house_number'] . $suffix;
            $billingAddress1    = $postedValues['billing_street_name'] . ' ' . $billingHouseNumber;
            WCX_Order::set_address_prop($order, 'address_1', 'billing', $billingAddress1);

            if (! $shipToDifferentAddress && $this->cart_needs_shipping_address()) {
                // use billing address
                WCX_Order::set_address_prop($order, 'address_1', 'shipping', $billingAddress1);
            }
        }

        if ($shippingHasCustomAddressFields && $shipToDifferentAddress) {
            // concatenate street & house number & copy to 'shipping_address_1'
            $suffix = ! empty($postedValues['shipping_house_number_suffix'])
                ? '-' . $postedValues['shipping_house_number_suffix']
                : '';

            $shippingHouseNumber = $postedValues['shipping_house_number'] . $suffix;
            $shippingAddress1    = $postedValues['shipping_street_name'] . ' ' . $shippingHouseNumber;
            WCX_Order::set_address_prop($order, 'address_1', 'shipping', $shippingAddress1);
        }
    }

    /**
     * validate BE postcodes
     *
     * @return bool $valid
     */
    public function validate_postcode($valid, $postcode, $country)
    {
        if ($country == 'BE') {
            $valid = (bool) preg_match('/^[1-9][0-9]{3}/i', trim($postcode));
        }

        return $valid;
    }

    /**
     * validate address field 1 for shipping and billing
     */
    public function validate_address_fields($address, $errors)
    {
        if (self::isCountryWithSplitAddressFields($address['billing_country'])
            && ! (bool) preg_match(
                self::SPLIT_STREET_REGEX,
                trim(
                    $address['billing_address_1']
                )
            )) {
            $errors->add('address', __("Please enter a valid billing address.", "woocommerce-myparcelbe"));
        }

        if (self::isCountryWithSplitAddressFields($address['shipping_country'])
            && ! empty($address['ship_to_different_address'])
            && ! (bool) preg_match(
                self::SPLIT_STREET_REGEX,
                trim(
                    $address['shipping_address_1']
                )
            )) {
            $errors->add('address', __("Please enter a valid shipping address.", "woocommerce-myparcelbe"));
        }
    }

    /**
     * Clean postcodes : remove space, dashes (& other non alphanumeric characters)
     *
     * @return $billing_postcode
     * @return $shipping_postcode
     */
    public function clean_billing_postcode()
    {
        $postedValues = $this->getPostedValues();
        if ('BE' === $postedValues['billing_country']) {
            $billing_postcode = preg_replace('/[^a-zA-Z0-9]/', '', $postedValues['billing_postcode']);
        } else {
            $billing_postcode = $postedValues['billing_postcode'];
        }

        return $billing_postcode;
    }

    public function clean_shipping_postcode()
    {
        $postedValues = $this->getPostedValues();
        if ('BE' === $postedValues['billing_country']) {
            $shipping_postcode = preg_replace('/[^a-zA-Z0-9]/', '', $postedValues['shipping_postcode']);
        } else {
            $shipping_postcode = $postedValues['shipping_postcode'];
        }

        return $shipping_postcode;
    }

    /**
     * Custom country address formats.
     *
     * @param array $formats Defaul formats.
     *
     * @return array          New NL format.
     */
    public function localisation_address_formats($formats)
    {
        $formats['BE'] = "{company}\n{name}\n{address_1}\n{address_2}\n{postcode} {city}\n{country}";

        return $formats;
    }

    /**
     * Custom country address format.
     *
     * @param array $replacements Default replacements.
     * @param array $args         Arguments to replace.
     *
     * @return array               New replacements.
     */
    public function formatted_address_replacements(array $replacements, array $args): array
    {
        $country             = $args['country'] ?? null;
        $house_number        = $args['house_number'] ?? null;
        $house_number_suffix = $args['house_number_suffix'] ?? null;
        $street_name         = $args['street_name'] ?? null;

        if (! empty($street_name) && self::isCountryWithSplitAddressFields($country)) {
            $replacements['{address_1}'] = $street_name . ' ' . $house_number . $house_number_suffix;
        }

        return $replacements;
    }

    /**
     * Custom order formatted billing address.
     *
     * @param array  $address Default address.
     * @param object $order   Order data.
     *
     * @return array          New address format.
     */
    public function order_formatted_billing_address($address, $order)
    {
        $address['street_name']         = WCX_Order::get_meta($order, '_billing_street_name', true, 'view');
        $address['house_number']        = WCX_Order::get_meta($order, '_billing_house_number', true, 'view');
        $address['house_number_suffix'] = WCX_Order::get_meta($order, '_billing_house_number_suffix', true, 'view');
        $address['house_number_suffix'] =
            ! empty($address['house_number_suffix']) ? '-' . $address['house_number_suffix'] : '';

        return $address;
    }

    /**
     * Custom order formatted shipping address.
     *
     * @param array  $address Default address.
     * @param object $order   Order data.
     *
     * @return array          New address format.
     */
    public function order_formatted_shipping_address($address, $order)
    {
        $address['street_name']         = WCX_Order::get_meta($order, '_shipping_street_name', true, 'view');
        $address['house_number']        = WCX_Order::get_meta($order, '_shipping_house_number', true, 'view');
        $address['house_number_suffix'] = WCX_Order::get_meta(
            $order,
            '_shipping_house_number_suffix',
            true,
            'view'
        );
        $address['house_number_suffix'] =
            ! empty($address['house_number_suffix']) ? '-' . $address['house_number_suffix'] : '';

        return $address;
    }

    /**
     * Custom user column billing address information.
     *
     * @param array $address Default address.
     * @param int   $user_id User id.
     *
     * @return array          New address format.
     */
    public function user_column_billing_address($address, $user_id)
    {
        $address['street_name']         = get_user_meta($user_id, 'billing_street_name', true);
        $address['house_number']        = get_user_meta($user_id, 'billing_house_number', true);
        $address['house_number_suffix'] =
            (get_user_meta($user_id, 'billing_house_number_suffix', true)) ? '-' . get_user_meta(
                    $user_id,
                    'billing_house_number_suffix',
                    true
                ) : '';

        return $address;
    }

    /**
     * Custom user column shipping address information.
     *
     * @param array $address Default address.
     * @param int   $user_id User id.
     *
     * @return array          New address format.
     */
    public function user_column_shipping_address($address, $user_id)
    {
        $address['street_name']         = get_user_meta($user_id, 'shipping_street_name', true);
        $address['house_number']        = get_user_meta($user_id, 'shipping_house_number', true);
        $address['house_number_suffix'] =
            (get_user_meta($user_id, 'shipping_house_number_suffix', true)) ? '-' . get_user_meta(
                    $user_id,
                    'shipping_house_number_suffix',
                    true
                ) : '';

        return $address;
    }

    /**
     * Custom my address formatted address.
     *
     * @param array  $address     Default address.
     * @param int    $customer_id Customer ID.
     * @param string $name        Field name (billing or shipping).
     *
     * @return array            New address format.
     */
    public function my_account_my_address_formatted_address($address, $customer_id, $name)
    {
        $address['street_name']         = get_user_meta($customer_id, $name . '_street_name', true);
        $address['house_number']        = get_user_meta($customer_id, $name . '_house_number', true);
        $address['house_number_suffix'] =
            (get_user_meta($customer_id, $name . '_house_number_suffix', true)) ? '-' . get_user_meta(
                    $customer_id,
                    $name . '_house_number_suffix',
                    true
                ) : '';

        return $address;
    }

    /**
     * Get a posted address field after sanitization and validation.
     *
     * @param string $key
     * @param string $type billing for shipping
     *
     * @return string
     */
    public function get_posted_address_data($key, $posted, $type = 'billing')
    {
        if ('billing' === $type
            || (! $posted['ship_to_different_address']
                && $this->cart_needs_shipping_address())) {
            $return = isset($posted['billing_' . $key]) ? $posted['billing_' . $key] : '';
        }     elseif ('shipping' === $type && ! $this->cart_needs_shipping_address()) {
            $return = '';
        } else {
            $return = isset($posted['shipping_' . $key]) ? $posted['shipping_' . $key] : '';
        }

        return $return;
    }

    public function cart_needs_shipping_address()
    {
        if (is_object(WC()->cart) && method_exists(WC()->cart, 'needs_shipping_address')
            && function_exists('wc_ship_to_billing_address_only')) {
            if (WC()->cart->needs_shipping_address() || wc_ship_to_billing_address_only()) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    /**
     * Save order data.
     *
     * @param int   $order_id
     * @param array $posted
     *
     * @return void
     */
    public function save_order_data($order_id, $posted)
    {
        $order = WCX::get_order($order_id);
        // Billing.
        WCX_Order::update_meta_data(
            $order,
            '_billing_street_name',
            $this->get_posted_address_data('street_name', $posted)
        );
        WCX_Order::update_meta_data(
            $order,
            '_billing_house_number',
            $this->get_posted_address_data('house_number', $posted)
        );
        WCX_Order::update_meta_data(
            $order,
            '_billing_house_number_suffix',
            $this->get_posted_address_data('house_number_suffix', $posted)
        );

        // Shipping.
        WCX_Order::update_meta_data(
            $order,
            '_shipping_street_name',
            $this->get_posted_address_data('street_name', $posted, 'shipping')
        );
        WCX_Order::update_meta_data(
            $order,
            '_shipping_house_number',
            $this->get_posted_address_data('house_number', $posted, 'shipping')
        );
        WCX_Order::update_meta_data(
            $order,
            '_shipping_house_number_suffix',
            $this->get_posted_address_data('house_number_suffix', $posted, 'shipping')
        );
    }

    /**
     * Helper function to move array elements (one or more) to a position before a specific key
     *
     * @param array  $array         Main array to modify
     * @param mixed  $keys          Single key or array of keys of element(s) to move
     * @param string $reference_key key to put elements before or after
     * @param string $position      before or after
     *
     * @return array                 reordered array
     */
    public function array_move_keys($array, $keys, $reference_key, $position = 'before')
    {
        // cast $key as array
        $keys = (array) $keys;

        if (! isset($array[$reference_key])) {
            return $array;
        }

        $move = [];
        foreach ($keys as $key) {
            if (! isset($array[$key])) {
                continue;
            }
            $move[$key] = $array[$key];
            unset ($array[$key]);
        }

        if ($position == 'before') {
            $move_to_pos = array_search($reference_key, array_keys($array));
        } else { // after
            $move_to_pos = array_search($reference_key, array_keys($array)) + 1;
        }

        return array_slice($array, 0, $move_to_pos, true) + $move + array_slice(
                $array,
                $move_to_pos,
                null,
                true
            );
    }


    private function getPostedValues():array
    {
        $input = filter_input_array(INPUT_POST);
        if (! $input) { // handles false, null and empty array
            return [];
        }
        $postedValues = wp_unslash($input);
        /**
         * Can be accessed by WooCommerce update order review ajax as well as our own frontend.
         */
        if ($postedValues
            && false === wp_verify_nonce($postedValues['security'] ?? '', 'update-order-review')
            && false === wp_verify_nonce($postedValues['wcmpbe_nonce'] ?? '', 'wcmpbe_frontend')
            && false === wp_verify_nonce($postedValues['woocommerce-process-checkout-nonce'] ?? '', 'woocommerce-process_checkout')
            && false === wp_verify_nonce($postedValues['_wpnonce'] ?? '', 'update-post_' . ($postedValues['post_ID'] ?? '0'))
        ) {
            _ajax_wp_die_handler('Invalid nonce.');
        }
        return $postedValues;
    }

    /**
     * @param string|null $country
     *
     * @return bool
     */
    private static function isCountryWithSplitAddressFields(?string $country): bool
    {
        return in_array($country, self::COUNTRIES_WITH_SPLIT_ADDRESS_FIELDS);
    }
}

return new WCMPBE_Postcode_Fields();
