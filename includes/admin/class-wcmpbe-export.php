<?php

use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;
use MyParcelNL\Sdk\src\Exception\ApiException;
use MyParcelNL\Sdk\src\Exception\MissingFieldException;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\BpostConsignment;
use MyParcelNL\Sdk\src\Support\Arr;
use MyParcelNL\Sdk\src\Support\Str;
use WPO\WC\MyParcelBE\Compatibility\Order as WCX_Order;
use WPO\WC\MyParcelBE\Compatibility\WC_Core as WCX;
use WPO\WC\MyParcelBE\Compatibility\WCMPBE_ChannelEngine_Compatibility as ChannelEngine;

if (! defined("ABSPATH")) {
    exit;
} // Exit if accessed directly

if (class_exists("WCMPBE_Export")) {
    return new WCMPBE_Export();
}

class WCMPBE_Export
{
    public const EXPORT = "wcmpbe_export";

    public const ADD_SHIPMENTS = "add_shipments";
    public const ADD_RETURN    = "add_return";
    public const GET_LABELS    = "get_labels";
    public const MODAL_DIALOG  = "modal_dialog";

    /**
     * Maximum characters length of item description.
     */
    public const ITEM_DESCRIPTION_MAX_LENGTH  = 50;
    public const ORDER_DESCRIPTION_MAX_LENGTH = 45;

    public const COOKIE_EXPIRE_TIME = 20;

    public const DEFAULT_POSITIONS = [2, 4, 1, 3];
    public const SUFFIX_CHECK_REG  = "~^([a-z]{1}\d{1,3}|-\d{1,4}\d{2}\w{1,2}|[a-z]{1}[a-z\s]{0,3})(?:\W|$)~i";

    /**
     * Shipping methods that can never have delivery options.
     */
    public const DISALLOWED_SHIPPING_METHODS = [
        WCMPBE_Shipping_Methods::LOCAL_PICKUP,
    ];

    public const COUNTRY_CODE_NL = 'NL';
    public const COUNTRY_CODE_BE = 'BE';

    public $order_id;
    public $success;
    public $errors;
    public $myParcelCollection;

    private $prefix_message;

    public function __construct()
    {
        $this->success = [];
        $this->errors  = [];

        require_once("class-wcmpbe-rest.php");
        require_once("class-wcmpbe-api.php");

        add_action("admin_notices", [$this, "admin_notices"]);

        add_action("wp_ajax_" . self::EXPORT, [$this, "export"]);
    }

    /**
     * @param int $orderId
     *
     * @throws ApiException
     * @throws ErrorException
     * @throws MissingFieldException
     */
    public function exportByOrderId(int $orderId): void
    {
        $automaticExport = WCMYPABE()->setting_collection->isEnabled(WCMPBE_Settings::SETTING_AUTOMATIC_EXPORT);

        if ($orderId && $automaticExport) {
            $export = new self();
            $export->addShipments([(string)$orderId], 0, false);
        }
    }

    /**
     * Get the value of a shipment option. Check if it was set manually, through the delivery options for example,
     *  if not get the value of the default export setting for given settingName.
     *
     * @param mixed  $option Chosen value.
     * @param string $settingName Name of the setting to fall back to if there is no chosen value.
     *
     * @return mixed
     */
    public static function getChosenOrDefaultShipmentOption($option, string $settingName)
    {
        return $option ?? WCMYPABE()->setting_collection->getByName($settingName);
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
            $error_notice  = get_option("wcmyparcelbe_admin_error_notices");

            if (! empty($action_return)) {
                foreach ($action_return as $type => $message) {
                    if (! in_array($type, ["success", "error"])) {
                        continue;
                    }

                    if ($type === "success" && ! empty($print_queue)) {
                        $print_queue_store = sprintf(
                            '<input type="hidden" value=\'%s\' class="wcmpbe__print-queue">',
                            wp_json_encode(
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
                        '<div class="wcmpbe__notice is-dismissible notice notice-%s"><p>%s</p>%s</div>',
                        esc_attr($type),
                        esc_html($message),
                        wp_kses($print_queue_store ?? '', ["input" => ["type" => [], "value" => [], "class" => []]])
                    );
                }
                // destroy after reading
                delete_option("wcmyparcelbe_admin_notices");
                wp_cache_delete("wcmyparcelbe_admin_notices", "options");
            }
        }

        if (! empty($error_notice)) {
            printf(
                '<div class="wcmpbe__notice is-dismissible notice notice-error"><p>%s</p>%s</div>',
                esc_html($error_notice),
                wp_kses($print_queue_store ?? '', ["input" => ["type" => [], "value" => [], "class" => []]])
            );
            // destroy after reading
            delete_option("wcmyparcelbe_admin_error_notices");
            wp_cache_delete("wcmyparcelbe_admin_error_notices", "options");
        }

        if (isset($_GET["myparcelbe"])) {
            switch ($_GET["myparcelbe"]) {
                case "no_consignments":
                    $message = __(
                        "You have to export the orders to MyParcel BE before you can print the labels!",
                        "woocommerce-myparcelbe"
                    );
                    printf('<div class="wcmpbe__notice is-dismissible notice notice-error"><p>%s</p></div>', esc_html($message));
                    break;
                default:
                    break;
            }
        }

        if (isset($_COOKIE['myparcelbe_response'])) {
            $response = sanitize_text_field(wp_unslash($_COOKIE['myparcelbe_response']));
            printf(
                '<div class="wcmpbe__notice is-dismissible notice notice-error"><p>%s</p></div>',
                esc_html($response)
            );
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
        if (! check_ajax_referer(WCMYPABE::NONCE_ACTION, "_wpnonce", false)) {
            die("Ajax security check failed. Did you pass a valid nonce in \$_REQUEST['_wpnonce']?");
        }

        if (! is_user_logged_in()) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'woocommerce-myparcelbe'));
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
            echo wp_json_encode($return);
            die();
        }

        $requestVars = array_merge(
            filter_input_array(INPUT_GET) ?? [],
            filter_input_array(INPUT_POST) ?? []
        );
        $print       = sanitize_text_field($requestVars['print'] ?? null);
        $offset      = (int) ($requestVars['offset'] ?? 0);
        $request     = sanitize_text_field($requestVars['request']);
        $dialog      = sanitize_text_field($requestVars['dialog'] ?? null);

        /**
         * @var $order_ids
         */
        $order_ids    = $this->onlyIntegersInArray($requestVars['order_ids'] ?? []);
        $shipment_ids = $this->onlyIntegersInArray($requestVars['shipment_ids'] ?? []);

        foreach ($order_ids as $key => $id) {
            $order = WCX::get_order($id);

            if (WCMYPABE_Admin::hasLocalPickup($order)) {
                unset($order_ids[$key]);
            }
        }

        if (empty($shipment_ids) && empty($order_ids)) {
            $this->errors[] = __('You have not selected any orders!', 'woocommerce-myparcelbe');
        } else {
            try {
                switch ($request) {
                    // Creating consignments.
                    case self::ADD_SHIPMENTS:
                        $this->addShipments($order_ids, $offset, $print);
                        break;

                    // Creating a return shipment.
                    case self::ADD_RETURN:
                        $return = $this->addReturn($order_ids, $requestVars['myparcelbe_options']);
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
                $errorMessage   = $e->getMessage();
                $this->errors[] = "$request: {$errorMessage}";
                add_option('wcmyparcelbe_admin_error_notices', $errorMessage);
            }
        }

        // display errors directly if PDF requested or modal
        if (! empty($this->errors) && in_array($request, [self::ADD_RETURN, self::GET_LABELS, self::MODAL_DIALOG])) {
            echo wp_kses($this->parse_errors($this->errors), ['ul' => [], 'li' => [], 'strong' => []]);
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
            echo wp_json_encode($return);
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

        return (array)$array;
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
        $return          = [];
        $collection      = new MyParcelCollection();
        $processDirectly = WCMYPABE()->setting_collection->isEnabled(WCMPBE_Settings::SETTING_PROCESS_DIRECTLY) || $process === true;

        WCMPBE_Log::add("*** Creating shipments started ***");

        /**
         * Loop over the order ids and create consignments for each order.
         */
        foreach ($order_ids as $order_id) {
            $order = WCX::get_order($order_id);

            try {
                $exportConsignments = new WCMPBE_Export_Consignments($order);
                $consignment        = $exportConsignments->getConsignment();
            } catch (Exception $ex) {
                $errorMessage            = "Order {$order_id} could not be exported to MyParcel because: {$ex->getMessage()}";
                $this->errors[$order_id] = $errorMessage;

                setcookie('myparcelbe_response', $this->errors[$order_id], time() + self::COOKIE_EXPIRE_TIME, "/");
                WCMPBE_Log::add($this->errors[$order_id]);

                continue;
            }

            $this->addConsignments($exportConsignments->getOrderSettings(), $collection, $consignment);
            WCMPBE_Log::add("Shipment data for order {$order_id}.");
        }

        $collection = $collection
            ->setUserAgents(self::getUserAgents())
            ->createConcepts();

        if ($processDirectly) {
            $collection->setLinkOfLabels();
        }

        foreach ($order_ids as $order_id) {
            if (isset($this->errors[$order_id])) {
                continue;
            }

            $order          = WCX::get_order($order_id);
            $consignmentIds = ($collection->getConsignmentsByReferenceIdGroup((string)$order_id))->getConsignmentIds();

            foreach ($consignmentIds as $consignmentId) {
                $shipment["shipment_id"] = $consignmentId;
                $this->saveShipmentData($order, $shipment);
                $this->success[$order_id] = $consignmentId;
            }

            if ($processDirectly) {
                $this->getShipmentData($consignmentIds, $order);
            }

            $api = $this->init_api();
            $api->updateOrderStatus($order, WCMPBE_Settings_Data::CHANGE_STATUS_AFTER_EXPORT);

            WCX_Order::update_meta_data(
                $order,
                WCMYPABE_Admin::META_LAST_SHIPMENT_IDS,
                $consignmentIds
            );
        }

        if (! empty($this->success)) {
            $return["success"]     = sprintf(
                // TRANSLATORS: %s number of shipments
                __("%s shipments successfully exported to MyParcel BE", "woocommerce-myparcelbe"),
                count($collection->getConsignmentIds())
            );
            $return["success_ids"] = $collection->getConsignmentIds();

            WCMPBE_Log::add($return["success"]);
            WCMPBE_Log::add("ids: " . implode(", ", $return["success_ids"]));
        }

        return $return;
    }

    /**
     * @param array $order_ids
     * @param array $options
     *
     * @return array
     */
    public function addReturn(array $order_ids, ?array $options = []): array
    {
        $return = [];

        WCMPBE_Log::add("*** Creating return shipments started ***");

        foreach ($order_ids as $order_id) {
            try {
                $return_shipments = [
                    $this->prepareReturnShipmentData(
                        $order_id,
                        $options
                            ? $options[$order_id]
                            : null
                    ),
                ];

                WCMPBE_Log::add("Return shipment data for order $order_id:", print_r($return_shipments, true));

                $api      = $this->init_api();
                $response = $api->add_shipments($return_shipments, "return");

                WCMPBE_Log::add("API response (order $order_id):\n" . print_r($response, true));

                $ids = Arr::get($response, "body.data.ids");

                if ($ids) {
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
                    WCMPBE_Log::add("\$response\[\"body.data.ids\"] empty or not found.", print_r($response, true));
                    throw new Exception("\$response\[\"body.data.ids\"] empty or not found.");
                }
            } catch (Exception $e) {
                $errorMessage            = $e->getMessage();
                $this->errors[$order_id] = $errorMessage;
                add_option('wcmyparcelbe_admin_error_notices', $errorMessage);
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

        WCMPBE_Log::add("*** downloadOrGetUrlOfLabels() ***");
        WCMPBE_Log::add("Shipment IDs: " . implode(", ", $shipment_ids));

        try {
            $api = $this->init_api();

            // positions are defined on landscape, but paper is filled portrait-wise
            $positions = array_slice(self::DEFAULT_POSITIONS, $offset % 4);

            $displaySetting = WCMYPABE()->setting_collection->getByName(WCMPBE_Settings::SETTING_DOWNLOAD_DISPLAY);
            $display        = ($displayOverride ?? $displaySetting) === "display";
            $api->getShipmentLabels($shipment_ids, $order_ids, $positions, $display);
        } catch (Exception $e) {
            throw new Exception(esc_html($e->getMessage()));
            add_option('wcmyparcelbe_admin_error_notice', $e->getMessage());
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
            WCMPBE_Log::add(" *** Failed label request(not exported yet) ***");

            throw new Exception(esc_html__('The selected orders have not been exported to MyParcel yet! ', 'woocommerce-myparcelbe'));
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
        $order_ids = (array)$order_ids;
        require("views/html-send-return-email-form.php");
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
     * @return WCMPBE_API
     * @throws Exception
     */
    public function init_api()
    {
        $key = $this->getSetting(WCMPBE_Settings::SETTING_API_KEY);

        if (! ($key)) {
            throw new ErrorException(esc_html__('No API key found in MyParcel settings', 'woocommerce-myparcelbe'));
        }

        return new WCMPBE_API($key);
    }

    /**
     * TODO: There are no options being passed right now but these will be necessary for BE.
     *
     * @param            $order_id
     * @param array|null $options
     *
     * @return array
     * @throws Exception
     */
    public function prepareReturnShipmentData($order_id, ?array $options = []): array
    {
        $order = WCX::get_order($order_id);

        $shipping_name =
            method_exists($order, "get_formatted_shipping_full_name") ? $order->get_formatted_shipping_full_name()
                : trim($order->get_shipping_first_name() . " " . $order->get_shipping_last_name());

        // set name & email
        $return_shipment_data = [
            "name"    => $shipping_name,
            "email"   => WCX_Order::get_prop($order, "billing_email"),
            "carrier" => BpostConsignment::CARRIER_ID, // default to bpost for now
        ];

        if (! Arr::get($return_shipment_data, "email")) {
            throw new Exception(esc_html__('No e-mail address found in order.', 'woocommerce-myparcelbe'));
        }

        // add options if available
        if (! empty($options)) {
            // convert insurance option
            if (! isset($options["insurance"]) && isset($options["insured_amount"])) {
                if ($options["insured_amount"] > 0) {
                    $options["insurance"] = [
                        "amount"   => (int)$options["insured_amount"] * 100,
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
                    $value = (int)$value;
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
            (array)$order_id,
            [
                "exclude_concepts" => true,
                "only_last"        => true,
            ]
        );

        if (! empty($shipment_ids)) {
            $return_shipment_data["parent"] = (int)array_pop($shipment_ids);
        }

        return $return_shipment_data;
    }

    /**
     * @param int   $order_id
     * @param array $track_traces
     *
     * @internal param $shipment_ids
     */
    public static function addTrackTraceNoteToOrder(int $order_id, array $track_traces): void
    {
        if (! WCMYPABE()->setting_collection->isEnabled(WCMPBE_Settings::SETTING_BARCODE_IN_NOTE)) {
            return;
        }

        $prefix_message = WCMYPABE()->setting_collection->getByName(WCMPBE_Settings::SETTING_BARCODE_IN_NOTE_TITLE);

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
        return WCMYPABE()->setting_collection->getByName($name);
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
            $order_shipments = WCX_Order::get_meta($order, WCMYPABE_Admin::META_SHIPMENTS);

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
                $last_shipment_ids = WCX_Order::get_meta($order, WCMYPABE_Admin::META_LAST_SHIPMENT_IDS);

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

        if (WCX_Order::has_meta($order, WCMYPABE_Admin::META_SHIPMENTS)) {
            $old_shipments = WCX_Order::get_meta($order, WCMYPABE_Admin::META_SHIPMENTS);
        }

        $new_shipments = array_replace_recursive($old_shipments, $new_shipments);

        WCX_Order::update_meta_data($order, WCMYPABE_Admin::META_SHIPMENTS, $new_shipments);
    }

    /**
     * @param WC_Order $order
     * @param string   $shippingMethodId
     *
     * @return int|null
     * @throws Exception
     */
    public function getOrderShippingClass(WC_Order $order, string $shippingMethodId = ''): ?int
    {
        if (empty($shippingMethodId)) {
            $orderShippingMethods = $order->get_items('shipping');

            if (! empty($orderShippingMethods)) {
                // we're taking the first (we're not handling multiple shipping methods as of yet)
                $orderShippingMethod = array_shift($orderShippingMethods);
                $shippingMethodId    = $orderShippingMethod['method_id'];
            } else {
                return null;
            }
        }

        $shippingMethod = $this->getShippingMethod($shippingMethodId);

        if (empty($shippingMethod)) {
            return null;
        }

        return $shippingMethodId;
    }

    /**
     * Determine appropriate package type for this order.
     *
     * @param WC_Order                            $order
     * @param AbstractDeliveryOptionsAdapter|null $deliveryOptions
     *
     * @return string
     * @throws Exception
     */
    public function getPackageTypeFromOrder(WC_Order $order, AbstractDeliveryOptionsAdapter $deliveryOptions = null): string
    {
        $packageTypeFromDeliveryOptions = $deliveryOptions
            ? $deliveryOptions->getPackageType()
            : null;

        if ($packageTypeFromDeliveryOptions) {
            return $packageTypeFromDeliveryOptions;
        }

        // Get pre 4.0.0 package type if it exists.
        if (WCX_Order::has_meta($order, WCMYPABE_Admin::META_SHIPMENT_OPTIONS_LT_4_0_0)) {
            $shipmentOptions = WCX_Order::get_meta($order, WCMYPABE_Admin::META_SHIPMENT_OPTIONS_LT_4_0_0);

            return (string)WCMPBE_Data::getPackageTypeId($shipmentOptions['package_type']);
        }

        $packageType = AbstractConsignment::DEFAULT_PACKAGE_TYPE_NAME;

        // get shipping methods from order
        $orderShippingMethods = $order->get_items('shipping');

        if (! empty($orderShippingMethods)) {
            // we're taking the first (we're not handling multiple shipping methods as of yet)
            $orderShippingMethod = array_shift($orderShippingMethods);
            $orderShippingMethod = $orderShippingMethod['method_id'];

            $orderShippingClass = WCX_Order::get_meta($order, WCMYPABE_Admin::META_HIGHEST_SHIPPING_CLASS);
            if (empty($orderShippingClass)) {
                $orderShippingClass = $this->getOrderShippingClass($order, $orderShippingMethod);
            }

            $packageType = self::getPackageTypeFromShippingMethod(
                $orderShippingMethod,
                $orderShippingClass
            );
        }

        return $this->getAllowedPackageType($order, $packageType);
    }

    /**
     * @param $shippingMethod
     * @param $shippingClass
     *
     * @return string
     */
    public static function getPackageTypeFromShippingMethod($shippingMethod, $shippingClass): string
    {
        $packageType           = AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
        if (! isset($shippingMethod)) {
            return $packageType;
        }

        $shippingMethodIdClass = $shippingMethod;

        if (Str::startsWith($shippingMethod, 'table_rate:') && class_exists('WC_Table_Rate_Shipping')) {
            // Automattic / WooCommerce table rate
            // use full method = method_id:instance_id:rate_id
            $shippingMethodId = $shippingMethod;
        } else {
            // non table rates
            if (Str::contains($shippingMethodIdClass, ':')) {
                // means we have method_id:instance_id
                $shippingMethod   = explode(':', $shippingMethod);
                $shippingMethodId = $shippingMethod[0];
            } else {
                $shippingMethodId = $shippingMethod;
            }

            // add class if we have one
            if (! empty($shippingClass)) {
                $shippingMethodIdClass = "{$shippingMethodId}:{$shippingClass}";
            }
        }

        $packageTypes = WCMYPABE()->setting_collection->getByName(WCMPBE_Settings::SETTING_SHIPPING_METHODS_PACKAGE_TYPES);
        foreach ($packageTypes as $packageTypeKey => $packageTypeShippingMethods) {
            if (self::isActiveMethod(
                $shippingMethodId,
                $packageTypeShippingMethods,
                $shippingMethodIdClass,
                $shippingClass
            )) {
                $packageType = $packageTypeKey;
                break;
            }
        }

        return self::getPackageTypeAsString($packageType);
    }

    /**
     * @param string|null $packageType
     *
     * @return string
     */
    public static function getPackageTypeHuman(?string $packageType): string
    {
        if ($packageType) {
            $packageType = WCMPBE_Data::getPackageTypeHuman($packageType);
        }

        return $packageType ?? __("Unknown", "woocommerce-myparcelbe");
    }

    /**
     * Will convert any package type to a valid string package type.
     *
     * @param mixed $packageType
     *
     * @return string
     */
    public static function getPackageTypeAsString($packageType): string
    {
        if (is_numeric($packageType)) {
            $packageType = WCMPBE_Data::getPackageTypeName($packageType);
        }

        if (! is_string($packageType) || ! in_array($packageType, WCMPBE_Data::getPackageTypes())) {
            // Log data when this occurs but don't actually throw an exception.
            $type = gettype($packageType);
            WCMPBE_Log::add(new Exception("Tried to convert invalid value to package type: $packageType ($type)"));

            $packageType = null;
        }

        return $packageType ?? AbstractConsignment::DEFAULT_PACKAGE_TYPE_NAME;
    }

    /**
     * @param WC_Order $order
     * @param string   $packageType
     *
     * @return string
     *
     * @throws Exception
     */
    public function getAllowedPackageType(WC_Order $order, string $packageType): string
    {
        $shippingCountry      = WCX_Order::get_prop($order, "shipping_country");
        $isMailbox            = AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME === $packageType;
        $isDigitalStamp       = AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME === $packageType;
        $isDefaultPackageType = AbstractConsignment::CC_BE !== $shippingCountry && ($isMailbox || $isDigitalStamp);

        if ($isDefaultPackageType) {
            $packageType = AbstractConsignment::DEFAULT_PACKAGE_TYPE_NAME;
        }

        return $packageType;
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

        if (1 === count($parsed_errors)) {
            $html = array_shift($parsed_errors);
        } else {
            foreach ($parsed_errors as &$parsed_error) {
                $parsed_error = "<li>$parsed_error</li>";
            }
            $html = sprintf('<ul>%s</ul>', implode("\n", $parsed_errors));
        }

        return $html;
    }

    public function getShipmentStatusName($status_code)
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
            12 => __("printed - letter", "woocommerce-myparcelbe"),
            14 => __("printed - digital stamp", "woocommerce-myparcelbe"),
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
            $status        = $this->getShipmentStatusName($shipment["status"]);
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
     * Returns the weight in grams.
     *
     * @param int|float $weight
     *
     * @return int
     */
    public static function convertWeightToGrams($weight): int
    {
        $weightUnit  = get_option('woocommerce_weight_unit');
        $floatWeight = (float)$weight;

        switch ($weightUnit) {
            case 'kg':
                $weight = $floatWeight * 1000;
                break;
            case 'lbs':
                $weight = $floatWeight / 0.45359237;
                break;
            case 'oz':
                $weight = $floatWeight / 0.0283495231;
                break;
            default:
                $weight = $floatWeight;
                break;
        }

        return (int)ceil($weight);
    }

    /**
     * @return array
     */
    public static function getDigitalStampRangeOptions(): array
    {
        $options = [];

        foreach (WCMPBE_Data::getDigitalStampRanges() as $key => $tierRange) {
            $options[$tierRange['average']] = $tierRange['min'] . " - " . $tierRange['max'] . " gram";
        }

        return $options;
    }

    /**
     * @param $chosenMethod
     *
     * @throws \Exception
     */
    public static function getShippingMethod(string $chosenMethod)
    {
        if (version_compare(WOOCOMMERCE_VERSION, "2.6", "<") || $chosenMethod === WCMPBE_Shipping_Methods::LEGACY_FLAT_RATE) {
            return self::getLegacyShippingMethod($chosenMethod);
        }

        [$methodSlug, $methodInstance] = WCMPBE_Checkout::splitShippingMethodString($chosenMethod);

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
                WCMPBE_Shipping_Methods::FLAT_RATE,
                WCMPBE_Shipping_Methods::LEGACY_FLAT_RATE,
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
     * @return int|null
     */
    public function getShippingClass($shipping_method, $found_shipping_classes): ?int
    {
        // get most expensive class
        // adapted from $shipping_method->calculate_shipping()
        $highest_class_cost = 0;
        $highest_class      = null;
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
            $order            = WCX::get_order($order_id);
            $shipping_country = WCX_Order::get_prop($order, "shipping_country");

            if (! WCMPBE_Country_Codes::isAllowedDestination($shipping_country)) {
                unset($order_ids[$key]);
            }
        }

        return $order_ids;
    }

    /**
     * @param int                 $colloAmount
     * @param MyParcelCollection  $collection
     * @param AbstractConsignment $consignment
     *
     * @throws MissingFieldException
     */
    public function addFakeMultiCollo(int $colloAmount, MyParcelCollection $collection, AbstractConsignment $consignment): void
    {
        for ($i = 1; $i <= $colloAmount; $i++) {
            $collection->addConsignment($consignment);
        }
    }

    /**
     * @param \OrderSettings      $orderSettings
     * @param MyParcelCollection  $collection
     * @param AbstractConsignment $consignment
     *
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    public function addMultiCollo(
        OrderSettings $orderSettings,
        MyParcelCollection $collection,
        AbstractConsignment $consignment
    ): void
    {
        $this->addFakeMultiCollo($orderSettings->getColloAmount(), $collection, $consignment);
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
            throw new ErrorException(esc_html("No MyParcel barcode found for shipment id; $shipment_id"));
        }

        return $response["body"]["data"]["shipments"][0]["barcode"];
    }

    /**
     * @return array
     */
    public static function getUserAgents(): array
    {
        return [
            'MyParcelBE-WooCommerce' => (new WCMYPABE())->version,
            'WooCommerce'            => WooCommerce::instance()->version,
            'Wordpress'              => get_bloginfo('version'),
        ];
    }

    /**
     * @param $shipping_method_id
     * @param $package_type_shipping_methods
     * @param $shipping_method_id_class
     * @param $shipping_class
     *
     * @return bool
     */
    private static function isActiveMethod(
        $shipping_method_id,
        $package_type_shipping_methods,
        $shipping_method_id_class,
        $shipping_class
    )
    {
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
     * @param $offset
     * @param $print
     *
     * @return array|void
     * @throws ApiException
     * @throws ErrorException
     * @throws MissingFieldException
     * @throws Exception
     */
    private function addShipments($order_ids, $offset, $print)
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

            WCMPBE_Export::addTrackTraceNoteToOrder($order_id, $trackTraces);
        }
    }

    /**
     * Adds one or more consignments to the collection, depending on the collo amount.
     *
     * @param \OrderSettings                                            $orderSettings
     * @param \MyParcelNL\Sdk\src\Helper\MyParcelCollection             $collection
     * @param \MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment $consignment
     *
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    private function addConsignments(
        OrderSettings $orderSettings,
        MyParcelCollection $collection,
        AbstractConsignment $consignment
    ): void
    {
        $colloAmount = $orderSettings->getColloAmount();

        if ($colloAmount > 1) {
            $this->addMultiCollo($orderSettings, $collection, $consignment);

            return;
        }

        $collection->addConsignment($consignment);
    }

    /**
     * @param string|array $array
     *
     * @return array
     */
    public function onlyIntegersInArray($array): array
    {
        // check for JSON
        if (is_string($array) && false !== strpos($array, '[')) {
            $array = json_decode(stripslashes($array), false);
        }

        return array_map(static function ($value) { return (int) $value; }, (array) $array);
    }
}

return new WCMPBE_Export();
