<?php

if (! defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

if (class_exists('WCMYPABE_Upgrade_Banner')) {
    return new WCMYPABE_Upgrade_Banner();
}

/**
 * Admin banner on the plugin settings and orders pages telling merchants still
 * on 4.x that MyParcel for WooCommerce 6.0 is available. It renders on
 * admin_notices, so it only shows while 4.x is active and is gone once the
 * merchant moves to 6.x. Dismissal is remembered per shop.
 */
class WCMYPABE_Upgrade_Banner
{
    private const OPTION_DISMISSED   = 'woocommerce_myparcelbe_v6_notice_dismissed';
    private const DISMISS_PARAM      = 'myparcelbe_hide_v6_notice';
    private const SETTINGS_SCREEN    = 'woocommerce_page_wcmpbe_settings';
    private const ORDERS_SCREEN      = 'edit-shop_order';
    private const ORDERS_SCREEN_HPOS = 'woocommerce_page_wc-orders';
    private const HOME_SCREEN        = 'woocommerce_page_wc-admin';
    private const PLUGINS_SCREEN     = 'plugins';
    private const MIGRATION_URL      = 'https://developer.myparcel.nl/nl/documentatie/10.woocommerce-v6.0.html';

    public function __construct()
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (isset($_GET[self::DISMISS_PARAM])) {
            update_option(self::OPTION_DISMISSED, 'yes');
        }

        if ('yes' === get_option(self::OPTION_DISMISSED)) {
            return;
        }

        $screen         = get_current_screen();
        $allowedScreens = [
            self::SETTINGS_SCREEN,
            self::ORDERS_SCREEN,
            self::ORDERS_SCREEN_HPOS,
            self::HOME_SCREEN,
            self::PLUGINS_SCREEN,
        ];
        if (! $screen || ! in_array($screen->id, $allowedScreens, true)) {
            return;
        }

        $link = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url(self::MIGRATION_URL),
            esc_html__('notice_v6_upgrade_link', 'woocommerce-myparcelbe')
        );

        $dismiss = sprintf(
            '<a class="notice-dismiss" href="%s"><span class="screen-reader-text">%s</span></a>',
            esc_url(add_query_arg(self::DISMISS_PARAM, 'true')),
            esc_html__('Hide this message', 'woocommerce-myparcelbe')
        );

        printf(
            '<div class="notice notice-info is-dismissible"><h3>%s</h3><p>%s</p>%s</div>',
            esc_html__('notice_v6_upgrade_title', 'woocommerce-myparcelbe'),
            // The body translation holds a %s placeholder for the link.
            sprintf(esc_html__('notice_v6_upgrade_body', 'woocommerce-myparcelbe'), $link),
            $dismiss
        );
    }
}

return new WCMYPABE_Upgrade_Banner();
