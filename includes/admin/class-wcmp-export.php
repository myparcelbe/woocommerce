<?php

use MyParcelNL\Sdk\src\Exception\ApiException;
use MyParcelNL\Sdk\src\Exception\MissingFieldException;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\BpostConsignment;
use MyParcelNL\Sdk\src\Support\Arr;
use WPO\WC\MyParcelBE\Compatibility\Order as WCX_Order;
use WPO\WC\MyParcelBE\Compatibility\WC_Core as WCX;
use WPO\WC\MyParcelBE\Compatibility\WCMP_ChannelEngine_Compatibility as ChannelEngine;

if (! defined("ABSPATH")) {
    exit;
} // Exit if accessed directly

if (class_exists("WCMP_Export")) {
    return new WCMP_Export();
}

class WCMP_Export
{
    // Package types
    public const PACKAGE = 1;

    public const EXPORT = "wcmp_export";

    public const ADD_SHIPMENTS = "add_shipments";
    public const ADD_RETURN    = "add_return";
    public const GET_LABELS    = "get_labels";
    public const MODAL_DIALOG  = "modal_dialog";

    /**
     * Maximum characters length of item description.
     */
    public const DESCRIPTION_MAX_LENGTH = 50;

    public const DEFAULT_POSITIONS = [2, 4, 1, 3];

    /**
     * Shipping methods that can never have delivery options.
     */
    public const DISALLOWED_SHIPPING_METHODS = [
        WCMP_Shipping_Methods::LOCAL_PICKUP,
    ];

    public $order_id;
    public $success;
    public $errors;
    public $myParcelCollection;

    private $prefix_message;

    public function __construct()
    {
        $this->success = [];
        $this->errors  = [];

        require("class-wcmp-rest.php");
        require("class-wcmp-api.php");

        add_action("admin_notices", [$this, "admin_notices"]);

        add_action("wp_ajax_" . self::EXPORT, [$this, "export"]);
    }

    /**
     * Get the value of a shipment option. Check if it was set manually, through the delivery options for example,
     *  if not get the value of the default export setting for given settingName.
     *
     * @param bool|null $option Condition to check.
     * @param string    $settingName Name of the setting to fall back to.
     *
     * @return bool
     */
    public static function getChosenOrDefaultShipmentOption($option, string $settingName): bool
    {
        if ($valueFromSetting = WCMP()->setting_collection->isEnabled($settingName)) {
            return $valueFromSetting;
        }

        return (bool) $option;
    }

    /**
     * @param $item
     * @param $order
     *
     * @return mixed|string
     */
    public static function get_item_display_name($item, $order)
    {
        // set base name
        $name = $item['name'];

        // add variation name if available
        $product = $order->get_product_from_item($item);
        if ($product && isset($item['variation_id']) && $item['variation_id'] > 0 && method_exists($product, 'get_variation_attributes')) {
            $name .= woocommerce_get_formatted_variation($product->get_variation_attributes());
        }

        return $name;
    }

    public function admin_notices()
    {
        // only do this when the user that initiated this
        if (isset($_GET["myparcelbe_done"])) {
            $action_return = get_option("wcmyparcelbe_admin_notices");
            $print_queue   = get_option("wcmyparcelbe_print_queue", []);
            $error_notice = get_option("wcmyparcel_admin_error_notices");
            if (! empty($action_return)) {
                foreach ($action_return as $type => $message) {
                    if (! in_array($type, ["success", "error"])) {
                        continue;
                    }

                    if ($type === "success" && ! empty($print_queue)) {
                        $print_queue_store = sprintf(
                            '<input type="hidden" value=\'%s\' class="wcmp__print-queue">',
                            json_encode(
                                [
                                    "shipment_ids" => $print_queue["shipment_ids"],
                                    "order_ids"    => $print_queue["order_ids"],
                                    "offset"       => $print_queue["offset"],
                                ]
                            )
                        );

                        // Empty queue
                        delete_option("wcmyparcelbe_print_queue");
                    }

                    printf(
                        '<div class="wcmp__notice is-dismissible notice notice-%s"><p>%s</p>%s</div>',
                        $type,
                        $message,
                        $print_queue_store ?? ""
                    );
                }
                // destroy after reading
                delete_option("wcmyparcelbe_admin_notices");
                wp_cache_delete("wcmyparcelbe_admin_notices", "options");
            }
        }

        if (isset($_GET["myparcelbe"])) {
            switch ($_GET["myparcelbe"]) {
                case "no_consignments":
                    $message = __(
                        "You have to export the orders to MyParcel before you can print the labels!",
                        "woocommerce-myparcelbe"
                    );
                    printf('<div class="wcmp__notice notice notice-error"><p>%s</p></div>', $message);
                    break;
                default:
                    break;
            }
        }
    }

    /**
     * Export selected orders.
     *
     * @access public
     * @return void
     * @throws ApiException
     * @throws MissingFieldException
     * @throws Exception
     */
    public function export()
    {
        // Check the nonce
        if (! check_ajax_referer(WCMP::NONCE_ACTION, "_wpnonce", false)) {
            die("Ajax security check failed. Did you pass a valid nonce in \$_REQUEST['_wpnonce']?");
        }

        if (! is_user_logged_in()) {
            wp_die(__("You do not have sufficient permissions to access this page.", "woocommerce-myparcelbe"));
        }

        $return = [];

        // Check the user privileges (maybe use order ids for filter?)
        if (apply_filters(
            "wc_myparcelbe_check_privs",
            ! current_user_can("manage_woocommerce_orders") && ! current_user_can("edit_shop_orders")
        )) {
            $return["error"] = __(
                "You do not have sufficient permissions to access this page.",
                "woocommerce-myparcelbe"
            );
            echo json_encode($return);
            die();
        }

        $dialog  = $_REQUEST["dialog"] ?? null;
        $print   = $_REQUEST["print"] ?? null;
        $offset  = (bool) $_REQUEST["offset"] ?? 0;
        $request = $_REQUEST["request"];

        $order_ids    = $this->sanitize_posted_array($_REQUEST["order_ids"] ?? []);
        $shipment_ids = $this->sanitize_posted_array($_REQUEST["shipment_ids"] ?? []);

        include_once("class-wcmp-export-consignments.php");

        if (empty($shipment_ids) && empty($order_ids)) {
            $this->errors[] = __("You have not selected any orders!", "woocommerce-myparcelbe");
        } else {
            try {
                switch ($request) {
                    // Creating consignments.
                    case self::ADD_SHIPMENTS:
                        $this->addShipments($order_ids, $shipment_ids, $offset, $print);
                        break;

                    // Creating a return shipment.
                    case self::ADD_RETURN:
                        if (empty($order_ids)) {
                            $this->errors[] = __("You have not selected any orders!", "woocommerce-myparcelbe");
                            break;
                        }

                        $return = $this->add_return($order_ids);
                        break;

                    // Downloading labels.
                    case self::GET_LABELS:
                        $return = $this->printLabels($order_ids, $shipment_ids, $offset);
                        break;

                    case self::MODAL_DIALOG:
                        $order_ids = $this->filterOrderDestinations($order_ids);
                        $this->modal_dialog($order_ids, $dialog);
                        break;
                }
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                $this->errors[] = "$request: {$errorMessage}";
                add_option("wcmyparcel_admin_error_notices", $errorMessage);
            }
        }

        // display errors directly if PDF requested or modal
        if (in_array($request, [self::ADD_RETURN, self::GET_LABELS, self::MODAL_DIALOG]) && ! empty($this->errors)) {
            echo $this->parse_errors($this->errors);
            die();
        }

        // format errors for html output
        if (! empty($this->errors)) {
            $return["error"] = $this->parse_errors($this->errors);
        }

        // if we're directed here from modal, show proper result page
        if (isset($_REQUEST["modal"])) {
            $this->modal_success_page($request, $return);
        } else {
            // return JSON response
            echo json_encode($return);
            die();
        }
    }

    /**
     * @param string|array $array
     *
     * @return array
     */
    public function sanitize_posted_array($array): array
    {
        if (is_array($array)) {
            return $array;
        }

        // check for JSON
        if (is_string($array) && strpos($array, "[") !== false) {
            $array = json_decode(stripslashes($array));
        }

        return (array) $array;
    }

    /**
     * @param $order_ids
     * @param $process
     *
     * @return array
     * @throws ApiException
     * @throws MissingFieldException
     * @throws ErrorException
     * @throws Exception
     */
    public function add_shipments(array $order_ids, bool $process)
    {
        $return                   = [];
        $orderIdsWithNewShipments = [];
        $collection               = new MyParcelCollection();
        $processDirectly          = WCMP()->setting_collection->isEnabled(WCMP_Settings::SETTING_PROCESS_DIRECTLY) || $process === true;

        WCMP_Log::add("*** Creating shipments started ***");

        /**
         * Loop over the order ids and create consignments for each order.
         */
        foreach ($order_ids as $order_id) {
            $order                      = WCX::get_order($order_id);
            $orderIdsWithNewShipments[] = $order_id;

            $extra_params = WCX_Order::get_meta($order, WCMP_Admin::META_SHIPMENT_OPTIONS_EXTRA);
            $collo_amount = isset($extra_params["collo_amount"]) ? $extra_params["collo_amount"] : 1;

            /**
             * Create a real multi collo shipment if available, otherwise loop over the collo_amount and add separate
             * consignments to the collection.
             */
            if (WCMP_Data::HAS_MULTI_COLLO) {
                $consignment = (new WCMP_Export_Consignments($order))->getConsignment();

                $collection->addMultiCollo($consignment, $collo_amount);
            } else {
                for ($i = 0; $i < $collo_amount; $i++) {
                    $consignment = (new WCMP_Export_Consignments($order))->getConsignment();

                    $collection->addConsignment($consignment);
                }
            }

            WCMP_Log::add("Shipment data for order {$order_id}.");
        }

        $collection = $collection->createConcepts();

        if ($processDirectly) {
            $collection->setLinkOfLabels();
        }

        foreach ($orderIdsWithNewShipments as $order_id) {
            $order          = WCX::get_order($order_id);
            $consignmentIds = ($collection->getConsignmentsByReferenceIdGroup($order_id))->getConsignmentIds();

            foreach ($consignmentIds as $consignmentId) {
                $shipment["shipment_id"] = $consignmentId;

                $this->saveShipmentData($order, $shipment);
                $this->updateOrderStatus($order);

                $this->success[$order_id] = $consignmentId;
            }

            if ($processDirectly) {
                $this->getShipmentData($consignmentIds, $order);
            }

            WCX_Order::update_meta_data(
                $order,
                WCMP_Admin::META_LAST_SHIPMENT_IDS,
                $consignmentIds
            );
        }

        if (! empty($this->success)) {
            $return["success"]     = sprintf(
                __("%s shipments successfully exported to MyParcel", "woocommerce-myparcelbe"),
                count($collection->getConsignmentIds())
            );
            $return["success_ids"] = $collection->getConsignmentIds();

            WCMP_Log::add($return["success"]);
            WCMP_Log::add("ids: " . implode(", ", $return["success_ids"]));
        }

        return $return;
    }

    /**
     * @param array $order_ids
     *
     * @return array
     * @throws Exception
     */
    public function add_return(array $order_ids)
    {
        $return = [];

        WCMP_Log::add("*** Creating return shipments started ***");

        foreach ($order_ids as $order_id) {
            try {
                $return_shipments = [$this->prepare_return_shipment_data($order_id)];
                WCMP_Log::add("Return shipment data for order {$order_id}:", print_r($return_shipments, true));

                $api      = $this->init_api();
                $response = $api->add_shipments($return_shipments, "return");

                WCMP_Log::add("API response (order {$order_id}):\n" . print_r($response, true));

                $ids = Arr::get($response, "body.data.ids");

                if ($ids && ! empty($ids)) {
                    $order                    = WCX::get_order($order_id);
                    $ids                      = array_shift($response["body"]["data"]["ids"]);
                    $shipment_id              = $ids["id"];
                    $this->success[$order_id] = $shipment_id;

                    $shipment = [
                        "shipment_id" => $shipment_id,
                    ];

                    // save shipment data in order meta
                    $this->saveShipmentData($order, $shipment);
                } else {
                    WCMP_Log::add("\$response\[\"body.data.ids\"] empty or not found.", print_r($response, true));
                    throw new Exception("\$response\[\"body.data.ids\"] empty or not found.");
                }
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                $this->errors[$order_id] = $errorMessage;
                add_option('wcmyparcel_admin_error_notices', $errorMessage);
            }
        }

        return $return;
    }

    /**
     * @param array       $shipment_ids
     * @param array       $order_ids
     * @param int         $offset
     * @param string|null $displayOverride - Overrides display setting.
     *
     * @return array
     * @throws Exception
     */
    public function downloadOrGetUrlOfLabels(
        array $shipment_ids,
        array $order_ids = [],
        int $offset = 0,
        string $displayOverride = null
    ) {
        $return = [];

        WCMP_Log::add("*** downloadOrGetUrlOfLabels() ***");
        WCMP_Log::add("Shipment IDs: " . implode(", ", $shipment_ids));

        try {
            $api = $this->init_api();

            // positions are defined on landscape, but paper is filled portrait-wise
            $positions = array_slice(self::DEFAULT_POSITIONS, $offset % 4);

            $displaySetting = WCMP()->setting_collection->getByName(WCMP_Settings::SETTING_DOWNLOAD_DISPLAY);
            $display        = ($displayOverride ?? $displaySetting) === "display";
            $api->getShipmentLabels($shipment_ids, $order_ids, $positions, $display);
        } catch (Exception $e) {
            add_option('wcmyparcel_admin_error_notice', $e->getMessage());
        }

        return $return;
    }

    /**
     * @param array       $order_ids
     * @param int         $offset
     * @param string|null $display
     *
     * @return array
     * @throws Exception
     */
    public function getOrderLabels(array $order_ids, int $offset = 0, string $display = null)
    {
        $shipment_ids = $this->getShipmentIds($order_ids, ["only_last" => true]);

        if (empty($shipment_ids)) {
            WCMP_Log::add(" *** Failed label request(not exported yet) ***");

            throw new Exception(__(
                "The selected orders have not been exported to MyParcel yet! ",
                "woocommerce-myparcelbe"
            ));
        }

        return $this->downloadOrGetUrlOfLabels(
            $shipment_ids,
            $order_ids,
            $offset,
            $display
        );
    }

    /**
     * @param $order_ids
     */
    public function modal_dialog($order_ids, $dialog): void
    {
        // check for JSON
        if (is_string($order_ids) && strpos($order_ids, "[") !== false) {
            $order_ids = json_decode(stripslashes($order_ids));
        }

        // cast as array for single exports
        $order_ids = (array) $order_ids;
        require("views/html-bulk-options-form.php");
        die();
    }

    /**
     * @param $request
     * @param $result
     */
    public function modal_success_page($request, $result)
    {
        require("views/html-modal-result-page.php");
        die();
    }

    /**
     * @return WCMP_API
     * @throws Exception
     */
    public function init_api()
    {
        $key = $this->getSetting(WCMP_Settings::SETTING_API_KEY);

        if (! ($key)) {
            throw new ErrorException(__("No API key found in MyParcel BE settings", "woocommerce-myparcelbe"));
        }

        return new WCMP_API($key);
    }

    /**
     * TODO: There are no options being passed right now but these will be necessary for NL.
     *
     * @param $order_id
     * @param $options
     *
     * @return array
     * @throws Exception
     */
    public function prepare_return_shipment_data($order_id, $options = [])
    {
        $order = WCX::get_order($order_id);

        $shipping_name =
            method_exists($order, "get_formatted_shipping_full_name") ? $order->get_formatted_shipping_full_name()
                : trim($order->get_shipping_first_name() . " " . $order->get_shipping_last_name());

        // set name & email
        $return_shipment_data = [
            "name"    => $shipping_name,
            "email"   => WCX_Order::get_prop($order, "billing_email"),
            "carrier" => BpostConsignment::CARRIER_ID, // default to Bpost for now
        ];

        if (! Arr::get($return_shipment_data, "email")) {
            throw new Exception(__("No e-mail address found in order.", "woocommerce-myparcelbe"));
        }

        // add options if available
        if (! empty($options)) {
            // convert insurance option
            if (! isset($options["insurance"]) && isset($options["insured_amount"])) {
                if ($options["insured_amount"] > 0) {
                    $options["insurance"] = [
                        "amount"   => (int) $options["insured_amount"] * 100,
                        "currency" => "EUR",
                    ];
                }
                unset($options["insured_amount"]);
                unset($options["insured"]);
            }
            // PREVENT ILLEGAL SETTINGS
            // convert numeric strings to int
            $int_options = ["package_type", "delivery_type", "signature", "return "];
            foreach ($options as $key => &$value) {
                if (in_array($key, $int_options)) {
                    $value = (int) $value;
                }
            }
            // remove frontend insurance option values
            if (isset($options["insured_amount"])) {
                unset($options["insured_amount"]);
            }
            if (isset($options["insured"])) {
                unset($options["insured"]);
            }
            $return_shipment_data["options"] = $options;
        }

        // get parent
        $shipment_ids = $this->getShipmentIds(
            (array) $order_id,
            [
                "exclude_concepts" => true,
                "only_last"        => true,
            ]
        );

        if (! empty($shipment_ids)) {
            $return_shipment_data["parent"] = (int) array_pop($shipment_ids);
        }

        return $return_shipment_data;
    }

    /**
     * @param WC_Order $order
     *
     * @return mixed|void
     * @throws Exception
     */
    public static function getRecipientFromOrder(WC_Order $order)
    {
        $isUsingMyParcelFields = WCX_Order::get_meta($order, "_billing_street_name") || WCX_Order::get_meta($order, "_billing_house_number");
        $shippingName          = self::getShippingName($order);
        $connectEmail          = WCMP()->setting_collection->isEnabled(WCMP_Settings::SETTING_CONNECT_EMAIL);
        $connectPhone          = WCMP()->setting_collection->isEnabled(WCMP_Settings::SETTING_CONNECT_PHONE);
        $shippingCountry       = WCX_Order::get_prop($order, "shipping_country");

        $address = [
            "cc"                     => (string) WCX_Order::get_prop($order, "shipping_country"),
            "city"                   => (string) WCX_Order::get_prop($order, "shipping_city"),
            "person"                 => $shippingName,
            "company"                => (string) WCX_Order::get_prop($order, "shipping_company"),
            "email"                  => $connectEmail ? WCX_Order::get_prop($order, "billing_email") : "",
            "phone"                  => $connectPhone ? WCX_Order::get_prop($order, "billing_phone") : "",
            "street_additional_info" => WCX_Order::get_prop($order, "shipping_address_2"),
        ];

        if ($shippingCountry) {
            $pgAddress = WCX_Order::get_meta($order, WCMP_Admin::META_PGADDRESS);

            if ($pgAddress) {
                $processedAddress = self::processPgAddress($order, $isUsingMyParcelFields);
            }

            if (!$pgAddress) {
                $processedAddress = self::processAddress($order, $isUsingMyParcelFields);
            }
        } else {
            $processedAddress = [
                "postal_code"            => (string) WCX_Order::get_prop($order, "shipping_postcode"),
                "street"                 => (string) WCX_Order::get_prop($order, "shipping_address_1"),
                "street_additional_info" => (string) WCX_Order::get_prop($order, "shipping_address_2"),
                "region"                 => (string) WCX_Order::get_prop($order, "shipping_state"),
            ];
        }

        $address = array_merge($address, $processedAddress);

        return apply_filters("wc_myparcelbe_recipient", $address, $order);
    }

    /**
     * @param array $order_id
     * @param array $track_traces
     *
     * @internal param $shipment_ids
     */
    public static function addTrackTraceNoteToOrder(int $order_id, array $track_traces): void
    {
        if (! WCMP()->setting_collection->isEnabled(WCMP_Settings::SETTING_BARCODE_IN_NOTE)) {
            return;
        }

        $prefix_message = WCMP()->setting_collection->getByName(WCMP_Settings::SETTING_BARCODE_IN_NOTE_TITLE);

        // Select the barcode text of the MyParcel settings
        $prefix_message = $prefix_message ? $prefix_message . " " : "";

        $order = WCX::get_order($order_id);
        $order->add_order_note($prefix_message . implode(", ", $track_traces));
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    private function getSetting(string $name)
    {
        return WCMP()->setting_collection->getByName($name);
    }

    /**
     * @param array $order_ids
     * @param array $args
     *
     * @return array
     */
    public function getShipmentIds(array $order_ids, array $args): array
    {
        $shipment_ids = [];

        foreach ($order_ids as $order_id) {
            $order           = WCX::get_order($order_id);
            $order_shipments = WCX_Order::get_meta($order, WCMP_Admin::META_SHIPMENTS);

            if (empty($order_shipments)) {
                continue;
            }

            $order_shipment_ids = [];
            // exclude concepts or only concepts
            foreach ($order_shipments as $shipment_id => $shipment) {
                if (isset($args["exclude_concepts"]) && empty($shipment["track_trace"])) {
                    continue;
                }
                if (isset($args["only_concepts"]) && ! empty($shipment["track_trace"])) {
                    continue;
                }

                $order_shipment_ids[] = $shipment_id;
            }

            if (isset($args["only_last"])) {
                $last_shipment_ids = WCX_Order::get_meta($order, WCMP_Admin::META_LAST_SHIPMENT_IDS);

                if (! empty($last_shipment_ids) && is_array($last_shipment_ids)) {
                    foreach ($order_shipment_ids as $order_shipment_id) {
                        if (in_array($order_shipment_id, $last_shipment_ids)) {
                            $shipment_ids[] = $order_shipment_id;
                        }
                    }
                } else {
                    $shipment_ids[] = array_pop($order_shipment_ids);
                }
            } else {
                $shipment_ids[] = array_merge($shipment_ids, $order_shipment_ids);
            }
        }

        return $shipment_ids;
    }

    /**
     * @param WC_Order $order
     * @param array    $shipment
     *
     * @return void
     * @throws Exception
     */
    public function saveShipmentData(WC_Order $order, array $shipment): void
    {
        if (empty($shipment)) {
            throw new Exception("save_shipment_data requires a valid \$shipment.");
        }

        $old_shipments                           = [];
        $new_shipments                           = [];
        $new_shipments[$shipment["shipment_id"]] = $shipment;

        if (WCX_Order::has_meta($order, WCMP_Admin::META_SHIPMENTS)) {
            $old_shipments = WCX_Order::get_meta($order, WCMP_Admin::META_SHIPMENTS);
        }

        $new_shipments = array_replace_recursive($old_shipments, $new_shipments);

        WCX_Order::update_meta_data($order, WCMP_Admin::META_SHIPMENTS, $new_shipments);
    }

    /**
     * TODO: For MyParcel NL, currently not necessary for BE.
     *
     * @param $shipping_method
     * @param $shipping_class
     * @param $shippingCountry
     *
     * @return int|string
     */
    public function get_package_type_from_shipping_method($shipping_method, $shipping_class, $shippingCountry)
    {
        $packageTypes = WCMP()->setting_collection->getByName(WCMP_Settings::SETTING_SHIPPING_METHODS_PACKAGE_TYPES);

        $package_type             = self::PACKAGE;
        $shipping_method_id_class = "";

        if ($packageTypes) {
            if (strpos($shipping_method, "table_rate:") === 0 && class_exists("WC_Table_Rate_Shipping")) {
                // Automattic / WooCommerce table rate
                // use full method = method_id:instance_id:rate_id
                $shipping_method_id = $shipping_method;
            } else { // non table rates

                if (strpos($shipping_method, ":") !== false) {
                    // means we have method_id:instance_id
                    $shipping_method    = explode(":", $shipping_method);
                    $shipping_method_id = $shipping_method[0];
                } else {
                    $shipping_method_id = $shipping_method;
                }

                // add class if we have one
                if (! empty($shipping_class)) {
                    $shipping_method_id_class = "{$shipping_method_id}:{$shipping_class}";
                }
            }

            foreach ($packageTypes as $packageType => $shippingMethods) {
                if ($this->isActiveMethod(
                    $shipping_method_id,
                    $shippingMethods,
                    $shipping_method_id_class,
                    $shipping_class
                )) {
                    $package_type = $packageType;
                    break;
                }
            }
        }

        return $package_type;
    }

    /**
     * @param string $package_type
     *
     * @return string
     */
    public function get_package_type(string $package_type): string
    {
        return WCMP_Data::getPackageTypes()[$package_type] ?? __("Unknown", "woocommerce-myparcelbe");
    }

    /**
     * @param $errors
     *
     * @return mixed|string
     */
    public function parse_errors($errors)
    {
        $parsed_errors = [];

        foreach ($errors as $key => $error) {
            // check if we have an order_id
            if ($key > 10) {
                $parsed_errors[] = sprintf(
                    "<strong>%s %s:</strong> %s",
                    __("Order", "woocommerce-myparcelbe"),
                    $key,
                    $error
                );
            } else {
                $parsed_errors[] = $error;
            }
        }

        if (count($parsed_errors) == 1) {
            $html = array_shift($parsed_errors);
        } else {
            foreach ($parsed_errors as &$parsed_error) {
                $parsed_error = "<li>{$parsed_error}</li>";
            }
            $html = sprintf("<ul>%s</ul>", implode("\n", $parsed_errors));
        }

        return $html;
    }

    public function get_shipment_status_name($status_code)
    {
        $shipment_statuses = [
            1  => __("pending - concept", "woocommerce-myparcelbe"),
            2  => __("pending - registered", "woocommerce-myparcelbe"),
            3  => __("enroute - handed to carrier", "woocommerce-myparcelbe"),
            4  => __("enroute - sorting", "woocommerce-myparcelbe"),
            5  => __("enroute - distribution", "woocommerce-myparcelbe"),
            6  => __("enroute - customs", "woocommerce-myparcelbe"),
            7  => __("delivered - at recipient", "woocommerce-myparcelbe"),
            8  => __("delivered - ready for pickup", "woocommerce-myparcelbe"),
            9  => __("delivered - package picked up", "woocommerce-myparcelbe"),
            30 => __("inactive - concept", "woocommerce-myparcelbe"),
            31 => __("inactive - registered", "woocommerce-myparcelbe"),
            32 => __("inactive - enroute - handed to carrier", "woocommerce-myparcelbe"),
            33 => __("inactive - enroute - sorting", "woocommerce-myparcelbe"),
            34 => __("inactive - enroute - distribution", "woocommerce-myparcelbe"),
            35 => __("inactive - enroute - customs", "woocommerce-myparcelbe"),
            36 => __("inactive - delivered - at recipient", "woocommerce-myparcelbe"),
            37 => __("inactive - delivered - ready for pickup", "woocommerce-myparcelbe"),
            38 => __("inactive - delivered - package picked up", "woocommerce-myparcelbe"),
            99 => __("inactive - unknown", "woocommerce-myparcelbe"),
        ];

        if (isset($shipment_statuses[$status_code])) {
            return $shipment_statuses[$status_code];
        } else {
            return __("Unknown status", "woocommerce-myparcelbe");
        }
    }

    /**
     * Retrieves, updates and returns shipment data for given id.
     *
     * @param array    $ids
     * @param WC_Order $order
     *
     * @return array
     * @throws Exception
     */
    public function getShipmentData(array $ids, WC_Order $order): array
    {
        $data     = [];
        $api      = $this->init_api();
        $response = $api->get_shipments($ids);

        $shipments = Arr::get($response, "body.data.shipments");

        if (! $shipments) {
            return [];
        }

        foreach ($shipments as $shipment) {
            if (! isset($shipment["id"])) {
                return [];
            }

            // if shipment id matches and status is not concept, get track trace barcode and status name
            $status        = $this->get_shipment_status_name($shipment["status"]);
            $track_trace   = $shipment["barcode"];
            $shipment_id   = $shipment["id"];
            $shipment_data = compact("shipment_id", "status", "track_trace", "shipment");
            $this->saveShipmentData($order, $shipment_data);

            ChannelEngine::updateMetaOnExport($order, $track_trace);

            $data[$shipment_id] = $shipment_data;
        }

        return $data;
    }

    /**
     * @param $item
     * @param $order
     *
     * @return float
     */
    public static function getItemWeightKg($item, WC_Order $order): float
    {
        $product = $order->get_product_from_item($item);

        if (empty($product)) {
            return 0;
        }

        $weight      = (int) $product->get_weight();
        $weight_unit = get_option("woocommerce_weight_unit");
        switch ($weight_unit) {
            case "g":
                $product_weight = $weight / 1000;
                break;
            case "lbs":
                $product_weight = $weight * 0.45359237;
                break;
            case "oz":
                $product_weight = $weight * 0.0283495231;
                break;
            default:
                $product_weight = $weight;
                break;
        }

        $item_weight = (float) $product_weight * (int) $item["qty"];

        return (float) $item_weight;
    }

    /**
     * @param $chosenMethod
     *
     * @return null|WC_Shipping_Method
     */
    public static function getShippingMethod(string $chosenMethod): ?WC_Shipping_Method
    {
        if (version_compare(WOOCOMMERCE_VERSION, "2.6", "<") || $chosenMethod ===
            WCMP_Shipping_Methods::LEGACY_FLAT_RATE) {
            return self::getLegacyShippingMethod($chosenMethod);
        }

        [$methodSlug, $methodInstance] = WCMP_Checkout::splitShippingMethodString($chosenMethod);

        $isDisallowedShippingMethod = in_array($methodSlug, self::DISALLOWED_SHIPPING_METHODS);
        $isManualOrder              = empty($methodInstance);

        if ($isDisallowedShippingMethod || $isManualOrder) {
            return null;
        }

        return WC_Shipping_Zones::get_shipping_method($methodInstance) ?? null;
    }

     /**
     * @param string $chosen_method
     *
     * @return null|WC_Shipping_Method
     */
    private static function getLegacyShippingMethod(string $chosen_method): ?WC_Shipping_Method
    {
        // only for flat rate or legacy flat rate
        if (! in_array(
            $chosen_method,
            [
                WCMP_Shipping_Methods::FLAT_RATE,
                WCMP_Shipping_Methods::LEGACY_FLAT_RATE,
            ]
        )) {
            return null;
        }

        $shipping_methods = WC()->shipping()->load_shipping_methods();

        if (! isset($shipping_methods[$chosen_method])) {
            return null;
        }

        return $shipping_methods[$chosen_method];
    }

    /**
     * @param $shipping_method
     * @param $found_shipping_classes
     *
     * @return bool|int
     */
    public function getShippingClass($shipping_method, $found_shipping_classes)
    {
        // get most expensive class
        // adapted from $shipping_method->calculate_shipping()
        $highest_class_cost = 0;
        $highest_class      = false;
        foreach ($found_shipping_classes as $shipping_class => $products) {
            // Also handles BW compatibility when slugs were used instead of ids
            $shipping_class_term    = get_term_by("slug", $shipping_class, "product_shipping_class");
            $shipping_class_term_id = "";

            if ($shipping_class_term != null) {
                $shipping_class_term_id = $shipping_class_term->term_id;
            }

            $class_cost_string = $shipping_class_term && $shipping_class_term_id ? $shipping_method->get_option(
                "class_cost_" . $shipping_class_term_id,
                $shipping_method->get_option("class_cost_" . $shipping_class, "")
            ) : $shipping_method->get_option("no_class_cost", "");

            if ($class_cost_string === "") {
                continue;
            }

            $has_costs  = true;
            $class_cost = $this->wc_flat_rate_evaluate_cost(
                $class_cost_string,
                [
                    "qty"  => array_sum(wp_list_pluck($products, "quantity")),
                    "cost" => array_sum(wp_list_pluck($products, "line_total")),
                ],
                $shipping_method
            );
            if ($class_cost > $highest_class_cost && ! empty($shipping_class_term_id)) {
                $highest_class_cost = $class_cost;
                $highest_class      = $shipping_class_term->term_id;
            }
        }

        return $highest_class;
    }

    /**
     * Adapted from WC_Shipping_Flat_Rate - Protected method
     * Evaluate a cost from a sum/string.
     *
     * @param string $sum
     * @param array  $args
     * @param        $flat_rate_method
     *
     * @return string
     */
    public function wc_flat_rate_evaluate_cost(string $sum, array $args, $flat_rate_method)
    {
        if (version_compare(WOOCOMMERCE_VERSION, "2.6", ">=")) {
            include_once(WC()->plugin_path() . "/includes/libraries/class-wc-eval-math.php");
        } else {
            include_once(WC()->plugin_path() . "/includes/shipping/flat-rate/includes/class-wc-eval-math.php");
        }

        // Allow 3rd parties to process shipping cost arguments
        $args           = apply_filters("woocommerce_evaluate_shipping_cost_args", $args, $sum, $flat_rate_method);
        $locale         = localeconv();
        $decimals       = [
            wc_get_price_decimal_separator(),
            $locale["decimal_point"],
            $locale["mon_decimal_point"],
            ",",
        ];
        $this->fee_cost = $args["cost"];

        // Expand shortcodes
        add_shortcode("fee", [$this, "wc_flat_rate_fee"]);

        $sum = do_shortcode(
            str_replace(
                ["[qty]", "[cost]"],
                [$args["qty"], $args["cost"]],
                $sum
            )
        );

        remove_shortcode("fee");

        // Remove whitespace from string
        $sum = preg_replace("/\s+/", "", $sum);

        // Remove locale from string
        $sum = str_replace($decimals, ".", $sum);

        // Trim invalid start/end characters
        $sum = rtrim(ltrim($sum, "\t\n\r\0\x0B+*/"), "\t\n\r\0\x0B+-*/");

        // Do the math
        return $sum ? WC_Eval_Math::evaluate($sum) : 0;
    }

    /**
     * Adapted from WC_Shipping_Flat_Rate - Protected method
     * Work out fee (shortcode).
     *
     * @param array $atts
     *
     * @return string
     */
    public function wc_flat_rate_fee($atts)
    {
        $atts = shortcode_atts(
            [
                "percent" => "",
                "min_fee" => "",
                "max_fee" => "",
            ],
            $atts
        );

        $calculated_fee = 0;

        if ($atts["percent"]) {
            $calculated_fee = $this->fee_cost * (floatval($atts["percent"]) / 100);
        }

        if ($atts["min_fee"] && $calculated_fee < $atts["min_fee"]) {
            $calculated_fee = $atts["min_fee"];
        }

        if ($atts["max_fee"] && $calculated_fee > $atts["max_fee"]) {
            $calculated_fee = $atts["max_fee"];
        }

        return $calculated_fee;
    }

    /**
     * Filter out orders shipping to country codes that are not in the allowed list.
     *
     * @param $order_ids
     *
     * @return mixed
     * @throws Exception
     */
    public function filterOrderDestinations(array $order_ids): array
    {
        foreach ($order_ids as $key => $order_id) {
            $order           = WCX::get_order($order_id);
            $shippingCountry = WCX_Order::get_prop($order, "shipping_country");

            if (! WCMP_Country_Codes::isAllowedDestination($shippingCountry)) {
                unset($order_ids[$key]);
            }
        }

        return $order_ids;
    }

    /**
     * @param $shipment_id
     *
     * @return mixed
     * @throws ErrorException
     * @throws Exception
     */
    private function getShipmentBarcodeFromApi($shipment_id)
    {
        $api      = $this->init_api();
        $response = $api->get_shipments($shipment_id);

        if (! isset($response["body"]["data"]["shipments"][0]["barcode"])) {
            throw new ErrorException("No MyParcel barcode found for shipment id; " . $shipment_id);
        }

        return $response["body"]["data"]["shipments"][0]["barcode"];
    }

    /**
     * @param $shipping_method_id
     * @param $package_type_shipping_methods
     * @param $shipping_method_id_class
     * @param $shipping_class
     *
     * @return bool
     */
    private function isActiveMethod(
        $shipping_method_id,
        $package_type_shipping_methods,
        $shipping_method_id_class,
        $shipping_class
    ) {
        //support WooCommerce flat rate
        // check if we have a match with the predefined methods
        if (in_array($shipping_method_id, $package_type_shipping_methods)) {
            return true;
        }
        if (in_array($shipping_method_id_class, $package_type_shipping_methods)) {
            return true;
        }

        // fallback to bare method (without class) (if bare method also defined in settings)
        if (! empty($shipping_method_id_class)
            && in_array(
                $shipping_method_id_class,
                $package_type_shipping_methods
            )) {
            return true;
        }

        // support WooCommerce Table Rate Shipping by WooCommerce
        if (! empty($shipping_class) && in_array($shipping_class, $package_type_shipping_methods)) {
            return true;
        }

        // support WooCommerce Table Rate Shipping by Bolder Elements
        $newShippingClass = str_replace(":", "_", $shipping_class);
        if (! empty($shipping_class) && in_array($newShippingClass, $package_type_shipping_methods)) {
            return true;
        }

        return false;
    }

    /**
     * Update the status of given order based on the automatic order status settings.
     *
     * @param WC_Order $order
     */
    private function updateOrderStatus(WC_Order $order): void
    {
        if (WCMP()->setting_collection->isEnabled(WCMP_Settings::SETTING_ORDER_STATUS_AUTOMATION)) {
            $order->update_status(
                $this->getSetting(WCMP_Settings::SETTING_AUTOMATIC_ORDER_STATUS),
                __("MyParcel shipment created:", "woocommerce-myparcelbe")
            );
        }
    }

    /**
     * @param array $order_ids
     * @param array $shipment_ids
     * @param int   $offset
     *
     * @return array
     * @throws Exception
     */
    private function printLabels(array $order_ids, array $shipment_ids, int $offset)
    {
        if (! empty($shipment_ids)) {
            $return = $this->downloadOrGetUrlOfLabels(
                $shipment_ids,
                $order_ids,
                $offset
            );
        } else {
            $order_ids = $this->filterOrderDestinations($order_ids);
            $return    = $this->getOrderLabels($order_ids, $offset);
        }

        return $return;
    }

    /**
     * @param $order_ids
     * @param $shipment_ids
     * @param $offset
     * @param $print
     *
     * @return array|void
     * @throws ApiException
     * @throws ErrorException
     * @throws MissingFieldException
     * @throws Exception
     */
    private function addShipments($order_ids, $shipment_ids, $offset, $print)
    {
        $order_ids = $this->filterOrderDestinations($order_ids);

        if (empty($order_ids)) {
            $this->errors[] =
                __(
                    "The order(s) you have selected have invalid shipping countries.",
                    "woocommerce-myparcelbe"
                );

            return;
        }

        // if we're going to print directly, we need to process the orders first, regardless of the settings
        $process = $print === "yes" ? true : false;
        $return  = $this->add_shipments($order_ids, $process);

        // When adding shipments, store $return for use in admin_notice
        // This way we can refresh the page (JS) to show all new buttons
        if ($print === "no" || $print === "after_reload") {
            update_option("wcmyparcelbe_admin_notices", $return);
            if ($print === "after_reload") {
                $print_queue = [
                    "order_ids"    => $order_ids,
                    "shipment_ids" => $return["success_ids"],
                    "offset"       => isset($offset) && is_numeric($offset) ? $offset % 4 : 0,
                ];
                update_option("wcmyparcelbe_print_queue", $print_queue);
            }
        }

        return $return;
    }

    /**
     * Save created track & trace information as meta data to the corresponding order(s).
     *
     * @param MyParcelCollection $collection
     * @param array              $order_ids
     */
    public static function saveTrackTracesToOrders(MyParcelCollection $collection, array $order_ids): void
    {
        foreach ($order_ids as $order_id) {
            $trackTraces = [];

            foreach ($collection->getConsignmentsByReferenceId($order_id) as $consignment) {
                /**
                 * @var AbstractConsignment $consignment
                 */
                array_push($trackTraces, $consignment->getBarcode());
            }

            WCMP_Export::addTrackTraceNoteToOrder($order_id, $trackTraces);
        }
    }

    /**
     * @param WC_Order $order
     *
     * @return string
     */
    public static function getShippingName(WC_Order $order): string
    {
        if (method_exists($order, "get_formatted_shipping_full_name")) {
            return $order->get_formatted_shipping_full_name();
        }

        return trim($order->get_shipping_first_name() . " " . $order->get_shipping_last_name());
    }

    /**
     * @param WC_Order $order
     * @param bool     $isUsingMyParcelFields
     *
     * @return array
     * @throws Exception
     */
    public static function processPgAddress(WC_Order $order, bool $isUsingMyParcelFields): array
    {
        $billingName = method_exists($order, "get_formatted_billing_full_name")
            ? $order->get_formatted_billing_full_name()
            : trim(
                $order->get_billing_first_name() . " " . $order->get_billing_last_name()
            );
        $processedAddress = [
            "city"        => (string) WCX_Order::get_prop($order, "billing_city"),
            "person"      => $billingName,
            "company"     => (string) WCX_Order::get_prop($order, "billing_company"),
            "postal_code" => (string) WCX_Order::get_prop($order, "billing_postcode"),
        ];

        if ($isUsingMyParcelFields) {
            $processedAddress["street"]        = (string) WCX_Order::get_meta($order, "_billing_street_name");
            $processedAddress["number"]        = (string) WCX_Order::get_meta($order, "_billing_house_number");
            $processedAddress["number_suffix"] =
                (string) WCX_Order::get_meta($order, "_billing_house_number_suffix");

            return $processedAddress;
        }

        // Split the address line 1 into three parts
        preg_match(
            WCMP_BE_Postcode_Fields::SPLIT_STREET_REGEX,
            WCX_Order::get_prop($order, "billing_address_1"),
            $address_parts
        );
        $processedAddress["street"]                 = (string) $address_parts["street"];
        $processedAddress["number"]                 = (string) $address_parts["number"];
        $processedAddress["number_suffix"]          =
            array_key_exists("number_suffix", $address_parts) // optional
                ? (string) $address_parts["number_suffix"] : "";
        $processedAddress["street_additional_info"] = WCX_Order::get_prop($order, "billing_address_2");

        return $processedAddress;
    }

    /**
     * @param WC_Order $order
     * @param bool     $isUsingMyParcelFields
     *
     * @return string[]
     * @throws Exception
     */
    public static function processAddress(WC_Order $order, bool $isUsingMyParcelFields): array
    {
        $processedAddress = [
            "postal_code" => (string) WCX_Order::get_prop($order, "shipping_postcode"),
        ];

        if ($isUsingMyParcelFields) {
            $processedAddress["street"]        = (string) WCX_Order::get_meta($order, "_shipping_street_name");
            $processedAddress["number"]        = (string) WCX_Order::get_meta($order, "_shipping_house_number");
            $processedAddress["number_suffix"] =
                (string) WCX_Order::get_meta($order, "_shipping_house_number_suffix");

            return $processedAddress;
        }

        // Split the address line 1 into three parts
        preg_match(
            WCMP_BE_Postcode_Fields::SPLIT_STREET_REGEX,
            WCX_Order::get_prop($order, "shipping_address_1"),
            $address_parts
        );

        $processedAddress["street"]        = (string) $address_parts["street"];
        $processedAddress["number"]        = (string) $address_parts["number"];
        $processedAddress["number_suffix"] = array_key_exists("number_suffix", $address_parts) // optional
            ? (string) $address_parts["number_suffix"] : "";

        return $processedAddress;
    }
}

return new WCMP_Export();
