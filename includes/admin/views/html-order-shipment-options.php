<?php

use MyParcelNL\WooCommerce\Includes\Admin\OrderSettingsRows;
use WPO\WC\MyParcelBE\Entity\SettingsFieldArguments;

/**
 * @var WC_Order $order
 */

defined('ABSPATH') or die();

try {
    $deliveryOptions = WCMYPABE_Admin::getDeliveryOptionsFromOrder($order);
} catch (Exception $e) {
    return;
}

?>
<div class="wcmpbe wcmpbe__shipment-options">
    <table>
    <?php

    WCMYPABE_Admin::renderPickupLocation($deliveryOptions);

    $optionRows = OrderSettingsRows::getOptionsRows($deliveryOptions, $order);
    $optionRows = OrderSettingsRows::filterRowsByCountry($order->get_shipping_country(), $optionRows);

    $namePrefix = WCMYPABE_Admin::SHIPMENT_OPTIONS_FORM_NAME . "[{$order->get_id()}]";

    foreach ($optionRows as $optionRow) :
        $class = new SettingsFieldArguments($optionRow, $namePrefix);

        // Cast boolean values to the correct enabled/disabled values.
        if (is_bool($optionRow["value"])) {
            $optionRow["value"] = $optionRow["value"] ? WCMPBE_Settings_Data::ENABLED : WCMPBE_Settings_Data::DISABLED;
        }

        $class->setValue($optionRow["value"]);
        ?>

        <tr>
            <td>
                <label for="<?php echo esc_attr($class->getName()) ?>">
                    <?php echo esc_html($class->getArgument('label')); ?>
                </label>
            </td>
            <td>
                <?php
                if (isset($optionRow['help_text'])) {
                    printf("<span class='ml-auto'>%s</span>", wp_kses(wc_help_tip($optionRow['help_text']), true));
                }
                ?>
            </td>
            <td>
                <?php WCMPBE_Settings_Callbacks::renderField($class); ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <tr>
        <td colspan="2">
            <div class="button wcmpbe__shipment-options__save">
                <?php
                esc_html_e('Save', 'woocommerce-myparcelbe');
                WCMYPABE_Admin::renderSpinner();
                ?>
            </div>
        </td>
    </tr>
    </table>
</div>
