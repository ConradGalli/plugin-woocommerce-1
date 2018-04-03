<?php
/**
 * PayZen V2-Payment Module version 1.5.0 for WooCommerce 2.x-3.x. Support contact : support@payzen.eu.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 *
 * @author    Lyra Network (http://www.lyra-network.com/)
 * @author    Alsacréations (Geoffrey Crofte http://alsacreations.fr/a-propos#geoffrey)
 * @copyright 2014-2018 Lyra Network and contributors
 * @license   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html  GNU General Public License (GPL v2)
 * @category  payment
 * @package   payzen
 */

/**
 * Plugin Name: WooCommerce PayZen Payment
 * Description: This plugin links your WordPress WooCommerce shop to the payment platform.
 * Author: Lyra Network
 * Contributors: Alsacréations (Geoffrey Crofte http://alsacreations.fr/a-propos#geoffrey)
 * Version: 1.5.0
 * Requires at least: 3.5
 * Tested up to: 4.9
 * WC requires at least: 2.0
 * WC tested up to: 3.3
 * Author URI: https://www.lyra-network.com
 * License: GPLv2 or later
 *
 * Text Domain: woo-payzen-payment
 * Domain Path: /languages/
 */

if (! defined('ABSPATH')) {
    exit; // exit if accessed directly
}

define('WC_PAYZEN_PLUGIN_URL', plugin_dir_url(__FILE__));

/* A global var to easily enable/disable features */
global $payzen_plugin_features;

$payzen_plugin_features = array(
    'qualif' => false,
    'prodfaq' => true,
    'multi' => true,
    'choozeo' => false,
    'klarna' => true
);

/* Check requirements */
function woocommerce_payzen_activation()
{
    $all_active_plugins = get_option('active_plugins');
    if (is_multisite()) {
        $all_active_plugins = array_merge($all_active_plugins, wp_get_active_network_plugins());
    }

    $all_active_plugins = apply_filters('active_plugins', $all_active_plugins);

    if (! stripos(implode($all_active_plugins), '/woocommerce.php')) {
        deactivate_plugins(plugin_basename(__FILE__)); // deactivate ourself

        // load translation files
        load_plugin_textdomain('woo-payzen-payment', false, plugin_basename(dirname(__FILE__)) . '/languages');

        $message = sprintf(__('Sorry ! In order to use WooCommerce %s Payment plugin, you need to install and activate the WooCommerce plugin.', 'woo-payzen-payment'), 'PayZen');
        wp_die($message, 'WooCommerce PayZen Payment', array('back_link' => true));
    }
}
register_activation_hook(__FILE__, 'woocommerce_payzen_activation');

// delete all data when uninstalling plugin
function woocommerce_payzen_uninstallation()
{
    global $wpdb;

    delete_option('woocommerce_payzen_settings');
    delete_option('woocommerce_payzenstd_settings');
    delete_option('woocommerce_payzenmulti_settings');
    delete_option('woocommerce_payzenchoozeo_settings');
    delete_option('woocommerce_payzenklarna_settings');
}
register_uninstall_hook(__FILE__, 'woocommerce_payzen_uninstallation');

/* Include gateway classes */
function woocommerce_payzen_init()
{
    global $payzen_plugin_features;

    // load translation files
    load_plugin_textdomain('woo-payzen-payment', false, plugin_basename(dirname(__FILE__)) . '/languages');

    if (! class_exists('WC_Gateway_Payzen')) {
        require_once 'class-wc-gateway-payzen.php';
    }

    if (! class_exists('WC_Gateway_PayzenStd')) {
        require_once 'class-wc-gateway-payzenstd.php';
    }

    if ($payzen_plugin_features['multi'] && ! class_exists('WC_Gateway_PayzenMulti')) {
        require_once 'class-wc-gateway-payzenmulti.php';
    }

    if ($payzen_plugin_features['choozeo'] && ! class_exists('WC_Gateway_PayzenChoozeo')) {
        require_once 'class-wc-gateway-payzenchoozeo.php';
    }

    if ($payzen_plugin_features['klarna'] && ! class_exists('WC_Gateway_PayzenKlarna')) {
        require_once 'class-wc-gateway-payzenklarna.php';
    }

    require_once 'includes/PayzenRequest.php';
    require_once 'includes/PayzenResponse.php';
}
add_action('woocommerce_init', 'woocommerce_payzen_init');

/* Add PayZen method to woocommerce methods */
function woocommerce_payzen_add_method($methods)
{
    global $payzen_plugin_features;

    $methods[] = 'WC_Gateway_Payzen';
    $methods[] = 'WC_Gateway_PayzenStd';

    if ($payzen_plugin_features['multi']) {
        $methods[] = 'WC_Gateway_PayzenMulti';
    }

    if ($payzen_plugin_features['choozeo']) {
        $methods[] = 'WC_Gateway_PayzenChoozeo';
    }

    if ($payzen_plugin_features['klarna']) {
        $methods[] = 'WC_Gateway_PayzenKlarna';
    }

    return $methods;
}
add_filter('woocommerce_payment_gateways', 'woocommerce_payzen_add_method');

/* Add a link from plugin list to parameters */
function woocommerce_payzen_add_link($links, $file)
{
    global $woocommerce, $payzen_plugin_features;

    // consider payment gateways tab change
    $base_url = 'admin.php?page=wc-settings&tab=checkout&section=';
    $url_gen = $base_url . 'wc_gateway_payzen';
    $url_std = $base_url . 'wc_gateway_payzenstd';
    $url_multi = $base_url . 'wc_gateway_payzenmulti';
    $url_choozeo = $base_url . 'wc_gateway_payzenchoozeo';
    $url_klarna = $base_url . 'wc_gateway_payzenklarna';

    // backward compatibility
    if (version_compare($woocommerce->version, '2.1.0', '<')) {
        $base_url = 'admin.php?page=woocommerce_settings&tab=payment_gateways&section=';
        $url_gen = $base_url . 'WC_Gateway_Payzen';
        $url_std = $base_url . 'WC_Gateway_PayzenStd';
        $url_multi = $base_url . 'WC_Gateway_PayzenMulti';
        $url_choozeo = $base_url . 'WC_Gateway_PayzenChoozeo';
        $url_klarna = $base_url . 'WC_Gateway_PayzenKlarna';
    }

    $links[] = '<a href="' . admin_url($url_gen) . '">' . __('General configuration', 'woo-payzen-payment') .'</a>';
    $links[] = '<a href="' . admin_url($url_std) . '">' . __('One-time Payment', 'woo-payzen-payment') .'</a>';

    if ($payzen_plugin_features['multi']) {
        $links[] = '<a href="' . admin_url($url_multi) . '">' . __('Payment in several times', 'woo-payzen-payment')
            . '</a>';
    }

    if ($payzen_plugin_features['choozeo']) {
        $links[] = '<a href="' . admin_url($url_choozeo) . '">' . __('Payment with Choozeo', 'woo-payzen-payment')
            . '</a>';
    }

    if ($payzen_plugin_features['klarna']) {
        $links[] = '<a href="' . admin_url($url_klarna) . '">' . __('Payment with Klarna', 'woo-payzen-payment')
            . '</a>';
    }

    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'woocommerce_payzen_add_link', 10, 2);

/* Retrieve blog_id from post when this is a PayZen IPN URL call */
if (is_multisite() && key_exists('vads_hash', $_POST) && $_POST['vads_hash']
    && key_exists('vads_order_info2', $_POST) && $_POST['vads_order_info2']) {
    global $wpdb, $current_blog, $current_site;

    $blog = substr($_POST['vads_order_info2'], strlen('blog_id='));
    switch_to_blog((int)$blog);

    // set current_blog global var
    $current_blog = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $wpdb->blogs WHERE blog_id = %s", $blog)
    );

    // set current_site global var
    $network_fnc = function_exists('get_network') ? 'get_network' : 'wp_get_network';
    $current_site = $network_fnc($current_blog->site_id);
    $current_site->blog_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT blog_id FROM $wpdb->blogs WHERE domain = %s AND path = %s",
            $current_site->domain,
            $current_site->path
        )
    );

    $current_site->site_name = get_site_option('site_name');
    if (! $current_site->site_name) {
        $current_site->site_name = ucfirst($current_site->domain);
    }
}
