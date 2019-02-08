<?php
/**
 * Copyright © Lyra Network and contributors.
 * This file is part of PayZen plugin for WooCommerce. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra-network.com/)
 * @author    Geoffrey Crofte, Alsacréations (https://www.alsacreations.fr/)
 * @copyright Lyra Network and contributors
 * @license   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU General Public License (GPL v2)
 */

if (! defined('ABSPATH')) {
    exit; // exit if accessed directly
}

class WC_Gateway_PayzenStd extends WC_Gateway_Payzen
{
    const ALL_COUNTRIES = '1';
    const SPECIFIC_COUNTRIES = '2';

    const SUBSCRIPTIONS_HANDLER = 'disabled';

    protected $payzen_countries = array();
    protected $payzen_currencies = array();

    protected $subscriptions_handler;

    public function __construct()
    {
        $this->id = 'payzenstd';
        $this->icon = apply_filters('woocommerce_payzenstd_icon', WC_PAYZEN_PLUGIN_URL . '/assets/images/payzen.png');
        $this->has_fields = true;
        $this->method_title = self::GATEWAY_NAME . ' - ' . __('One-time Payment', 'woo-payzen-payment');

        // init common vars
        $this->payzen_init();

        // load the form fields
        $this->init_form_fields();

        // load the module settings
        $this->init_settings();

        // define user set variables
        $this->title = $this->get_title();
        $this->description = $this->get_description();
        $this->testmode = ($this->get_general_option('ctx_mode') == 'TEST');
        $this->debug = ($this->get_general_option('debug') == 'yes') ? true : false;

        // use the selected susbscriptions handler
        $this->subscriptions_handler = Payzen_Subscriptions_Loader::getInstance(self::SUBSCRIPTIONS_HANDLER);

        if ($this->payzen_is_section_loaded()) {
            // reset standard payment admin form action
            add_action('woocommerce_settings_start', array($this, 'payzen_reset_admin_options'));

            // update standard payment admin form action
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // adding style to admin form action
            add_action('admin_head-woocommerce_page_' . $this->admin_page, array($this, 'payzen_admin_head_style'));

            // adding JS to admin form action
            add_action('admin_head-woocommerce_page_' . $this->admin_page, array($this, 'payzen_admin_head_script'));
        }

        // generate standard payment form action
        add_action('woocommerce_receipt_' . $this->id, array($this, 'payzen_generate_form'));

        // iframe payment endpoint action
        add_action('woocommerce_api_wc_gateway_payzenstd', array($this, 'payzen_generate_iframe_form'));

        // order needs payment filter
        add_filter('woocommerce_order_needs_payment', array($this, 'payzen_order_needs_payment'), 10, 2);
    }

    /**
     * Get icon function.
     *
     * @access public
     * @return string
     */
    public function get_icon()
    {
        global $woocommerce;
        $icon = '';

        if ($this->icon) {
            $icon = '<img style="width: 85px;" src="';
            $icon .= class_exists('WC_HTTPS') ? WC_HTTPS::force_https_url($this->icon) : $woocommerce->force_ssl($this->icon);
            $icon .= '" alt="' . $this->get_title() . '" />';
        }

        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    /**
     * Get title function.
     *
     * @access public
     * @return string
     */
    public function get_title()
    {
        $title = $this->get_option('title');

        if (is_array($title)) {
            $title = isset($title[get_locale()]) && $title[get_locale()] ? $title[get_locale()] : $title['en_US'];
        }

        return apply_filters('woocommerce_gateway_title', $title, $this->id);
    }

    /**
     * Get description function.
     *
     * @access public
     * @return string
     */
    public function get_description()
    {
        $description = $this->get_option('description');

        if (is_array($description)) {
            $description = isset($description[get_locale()]) && $description[get_locale()] ? $description[get_locale()] : $description['en_US'];
        }

        return apply_filters('woocommerce_gateway_description', $description, $this->id);
    }

    private function payzen_filter_allowed_countries($countries)
    {
        if (empty($this->payzen_countries)) {
            return $countries;
        } else {
            $allowed_countries = array();
            foreach ($this->payzen_countries as $code) {
                if (! isset($countries[$code])) {
                    continue;
                }

                $allowed_countries[$code] = $countries[$code];
            }

            return $allowed_countries;
        }
    }

    /**
     * Initialise gateway settings form fields.
     */
    public function init_form_fields()
    {
        // load common form fields to concat them with sub-module settings
        parent::init_form_fields();

        $countries = new WC_Countries();
        $allowed_countries = $this->payzen_filter_allowed_countries($countries->get_allowed_countries());

        $this->form_fields = array(
                // CMS config params
            'module_settings' => array(
                'title' => __('MODULE SETTINGS', 'woo-payzen-payment'),
                'type' => 'title'
            ),
            'enabled' => array(
                'title' => __('Activation', 'woo-payzen-payment'),
                'label' => __('Enable / disable', 'woo-payzen-payment'),
                'type' => 'checkbox',
                'default' => 'yes',
                'description' => __('Enables / disables standard payment.', 'woo-payzen-payment')
            ),
            'title' => array(
                'title' => __('Title', 'woo-payzen-payment'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woo-payzen-payment'),
                'default' => __('Pay by credit card', 'woo-payzen-payment')
            ),
            'description' => array(
                'title' => __('Description', 'woo-payzen-payment'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woo-payzen-payment'),
                'default' => __('You will be redirected to payment page after order confirmation.', 'woo-payzen-payment'),
                'css' => 'width: 35em;'
            ),

            // amount restrictions
            'restrictions' => array(
                'title' => __('RESTRICTIONS', 'woo-payzen-payment'),
                'type' => 'title'
            ),
            'allows_specific' => array(
                'custom_attributes' => array(
                    'onchange' => 'payzenUpdateSpecificCountriesDisplay()',
                ),
                'title' => __('Restrict to some countries', 'woo-payzen-payment'),
                'type' => 'select',
                'default' => '1',
                'options' => array(
                    self::ALL_COUNTRIES => __('All allowed countries', 'woo-payzen-payment'),
                    self::SPECIFIC_COUNTRIES => __('Specific countries', 'woo-payzen-payment')
                ),
                'class' => 'wc-enhanced-select'
            ),
            'specific_countries' => array(
                'title' => __('Authorized countries', 'woo-payzen-payment'),
                'type' => 'multiselect',
                'default' => '',
                'options' => $allowed_countries,
                'class' => 'wc-enhanced-select'
            ),
            'amount_min' => array(
                'title' => __('Minimum amount', 'woo-payzen-payment'),
                'type' => 'text',
                'default' => '',
                'description' => __('Minimum amount to activate this payment method.', 'woo-payzen-payment')
            ),
            'amount_max' => array(
                'title' => __('Maximum amount', 'woo-payzen-payment'),
                'type' => 'text',
                'default' => '',
                'description' => __('Maximum amount to activate this payment method.', 'woo-payzen-payment')
            ),

            // payment page
            'payment_page' => array(
                'title' => __('PAYMENT PAGE', 'woo-payzen-payment'),
                'type' => 'title'
            ),
            'capture_delay' => array(
                'title' => __('Capture delay', 'woo-payzen-payment'),
                'type' => 'text',
                'default' => '',
                'description' => sprintf(__('The number of days before the bank capture. Enter value only if different from %s general configuration.', 'woo-payzen-payment'), self::GATEWAY_NAME)
            ),
            'validation_mode' => array(
                'title' => __('Validation mode', 'woo-payzen-payment'),
                'type' => 'select',
                'default' => '-1',
                'options' => array(
                    '-1' => sprintf(__('%s general configuration', 'woo-payzen-payment'), self::GATEWAY_NAME),
                    '' => sprintf(__('%s Back Office configuration', 'woo-payzen-payment'), self::BACKOFFICE_NAME),
                    '0' => __('Automatic', 'woo-payzen-payment'),
                    '1' => __('Manual', 'woo-payzen-payment')
                ),
                'description' => sprintf(__('If manual is selected, you will have to confirm payments manually in your %s Back Office.', 'woo-payzen-payment'), self::BACKOFFICE_NAME),
                'class' => 'wc-enhanced-select'
            ),
            'payment_cards' => array(
                'title' => __('Card Types', 'woo-payzen-payment'),
                'type' => 'multiselect',
                'default' => array(),
                'options' => $this->get_supported_card_types(),
                'description' => __('The card type(s) that can be used for the payment. Select none to use gateway configuration.', 'woo-payzen-payment'),
                'class' => 'wc-enhanced-select'
            ),

            // advanced options
            'advanced_options' => array(
                'title' => __('ADVANCED OPTIONS', 'woo-payzen-payment'),
                'type' => 'title'
            ),
            'card_data_mode' => array(
                'title' => __('Card data entry mode', 'woo-payzen-payment'),
                'type' => 'select',
                'default' => 'DEFAULT',
                'options' => array(
                    'DEFAULT' => __('Card data entry on payment gateway', 'woo-payzen-payment'),
                    'MERCHANT' => __('Card type selection on merchant site', 'woo-payzen-payment'),
                    'IFRAME' => __('Payment page integrated to checkout process (iframe)', 'woo-payzen-payment')
                ),
                'description' => __('Select how the credit card data will be entered by buyer. Think to update payment method description to match your selected mode.', 'woo-payzen-payment'),
                'class' => 'wc-enhanced-select'
            ),
            'payment_by_token' => array(
                'title' => __('Payment by token', 'woo-payzen-payment'),
                'type' => 'select',
                'default' => '0',
                'options' => array(
                    '1' => __('Yes', 'woo-payzen-payment'),
                    '0' => __('No', 'woo-payzen-payment')
                ),
                'description' => sprintf(__('The payment by token allows to pay orders without re-entering bank data at each payment. The "Payment by token" option should be enabled on your %s store to use this feature.', 'woo-payzen-payment'), self::GATEWAY_NAME),
                'class' => 'wc-enhanced-select'
            )
        );

        // if WooCommecre Multilingual is not available (or installed version not allow gateways UI translation)
        // let's suggest our translation feature
        if (! class_exists('WCML_WC_Gateways')) {
            $this->form_fields['title']['type'] = 'multilangtext';
            $this->form_fields['title']['default'] = array(
                'en_US' => 'Pay by credit card',
                'en_GB' => 'Pay by credit card',
                'fr_FR' => 'Paiement par carte bancaire',
                'de_DE' => 'Zahlung mit EC-/Kreditkarte',
                'es_ES' => 'Pagar con tarjeta de crédito'
            );

            $this->form_fields['description']['type'] = 'multilangtext';
            $this->form_fields['description']['default'] = array(
                'en_US' => 'You will be redirected to payment page after order confirmation.',
                'en_GB' => 'You will be redirected to payment page after order confirmation.',
                'fr_FR' => 'Vous allez être redirigé(e) vers la page de paiement après confirmation de la commande.',
                'de_DE' => 'Sie werden zu den Zahlungsseiten nach Zahlungsbestätigung weitergeleitet.',
                'es_ES' => 'Será redireccionado a la página de pago después de la confirmación del pedido.'
            );
        }
    }

    public function validate_amount_min_field($key, $value = null)
    {
        if (empty($value)) {
            return '';
        }

        $new_value = parent::validate_text_field($key, $value);

        $new_value = str_replace(',', '.', $new_value);
        if (! is_numeric($new_value) || ($new_value < 0)) { // invalid value, restore old
            return $this->get_option($key);
        }

        return $new_value;
    }

    public function validate_amount_max_field($key, $value = null)
    {
        if (empty($value)) {
            return '';
        }

        $new_value = parent::validate_text_field($key, $value);

        $new_value = str_replace(',', '.', $new_value);
        if (! is_numeric($new_value) || ($new_value < 0)) { // invalid value, restore old
            return $this->get_option($key);
        }

        return $new_value;
    }

    protected function get_supported_card_types()
    {
        return PayzenApi::getSupportedCardTypes();
    }

    public function payzen_admin_head_script()
    {
        $prefix = $this->plugin_id . $this->id . '_';
        ?>
        <script type="text/javascript">
        //<!--
            jQuery(function() {
                payzenUpdateSpecificCountriesDisplay();
            });

            function payzenUpdateSpecificCountriesDisplay() {
                var allowSpecificElt = jQuery('#<?php echo esc_attr($prefix . 'allows_specific'); ?>');
                var allowAll = allowSpecificElt.val() === '1';
                var specificCountries = allowSpecificElt.parents('table').find('tr:eq(1)'); // second line of RESTRICTIONS section

                if (allowAll) {
                    specificCountries.hide();
                } else {
                    specificCountries.show();
                }
            }
        //-->
        </script>
        <?php
    }

    /**
     * Check if this gateway is enabled and available for the current cart.
     */
    public function is_available()
    {
        global $woocommerce;

        if (! parent::is_available()) {
            return false;
        }

        // check if authorized currency
        if (! $this->is_supported_currency()) {
            return false;
        }

        // check if authorized country
        if (! $this->is_available_for_country()) {
            return false;
        }

        if ($woocommerce->cart) {
            $amount = $woocommerce->cart->total;
            if (($this->get_option('amount_max') != '' && $amount > $this->get_option('amount_max'))
                || ($this->get_option('amount_min') != '' && $amount < $this->get_option('amount_min'))) {
                return false;
            }

            if ($this->subscriptions_handler && $this->subscriptions_handler->cart_contains_multiple_subscriptions($woocommerce->cart)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if this gateway is available for the current currency.
     */
    protected function is_supported_currency()
    {
        if (! empty($this->payzen_currencies)) {
            return in_array(get_woocommerce_currency(), $this->payzen_currencies);
        }

        return parent::is_supported_currency();
    }

    protected function is_available_for_country()
    {
        global $woocommerce;

        if (! $woocommerce->customer) {
            return false;
        }

        $customer = $woocommerce->customer;
        $country = method_exists($customer, 'get_billing_country') ? $customer->get_billing_country() : $customer->get_country();

        // check billing country
        if ($this->get_option('allows_specific') === self::ALL_COUNTRIES) {
            return empty($this->payzen_countries) || in_array($country, $this->payzen_countries);
        } else {
            return in_array($country, $this->get_option('specific_countries'));
        }
    }

    /**
     * Display payment fields and show method description if set.
     *
     * @access public
     * @return void
     */
    public function payment_fields()
    {
        global $woocommerce;

        $cust_id = $this->get_customer_property($woocommerce->customer, 'id');

        $can_pay_by_alias = $this->can_use_alias($cust_id) && $this->get_cust_identifier($cust_id)
            && (! $this->subscriptions_handler || ! $this->subscriptions_handler->cart_contains_subscription($woocommerce->cart));

        if (! $can_pay_by_alias) {
            parent::payment_fields();
        }

        switch ($this->get_option('card_data_mode')) {
            case 'MERCHANT':
                $card_keys = $this->get_option('payment_cards');
                $all_supported_cards = $this->get_supported_card_types();

                if (! is_array($card_keys) || in_array('', $card_keys)) {
                    $cards = $all_supported_cards;
                } else {
                    foreach ($card_keys as $key) {
                        $cards[$key] = $all_supported_cards[$key];
                    }
                }

                // get first array key
                reset($cards);
                $selected_value = key($cards);

                echo '<div style="margin-bottom: 15px;" id="' . $this->id .'_display_available_cards">';
                foreach ($cards as $key => $value) {
                    $lower_key = strtolower($key);

                    echo '<div style="display: inline-block;">';
                    if (count($cards) == 1) {
                        echo '<input type="hidden" id="' . $this->id . '_' . $lower_key . '" name="' . $this->id . '_card_type" value="' . $key . '">';
                    } else {
                        echo '<input type="radio" id="' . $this->id . '_' . $lower_key . '" name="' . $this->id . '_card_type" value="' . $key . '" style="vertical-align: middle;" '
                                . checked($key, $selected_value, false) . '>';
                    }

                    echo '<label for="' . $this->id . '_' . $lower_key . '" style="display: inline;">';

                    if (file_exists(dirname(__FILE__) . '/assets/images/' . $lower_key . '.png')) {
                        echo '<img src="' . WC_PAYZEN_PLUGIN_URL . '/assets/images/' . $lower_key . '.png"
                                   alt="' . $value . '"
                                   title="' . $value . '"
                                   style="vertical-align: middle; margin-right: 10px; height: 25px;">';
                    } else {
                        echo '<span style="vertical-align: middle; margin-right: 10px; height: 25px;">' . $value . '</span>';
                    }

                    echo '</label>';
                    echo '</div>';
                }

                echo '</div>';
                break;

            case 'IFRAME':
                // load css and create iframe
                wp_register_style('payzen', WC_PAYZEN_PLUGIN_URL . 'assets/css/payzen.css', array(), self::PLUGIN_VERSION);
                wp_enqueue_style('payzen');

                // iframe endpoint url
                $link = add_query_arg('wc-api', 'WC_Gateway_PayzenStd', home_url('/'));

                $html = '<div>
                         <iframe name="payzen-iframe" class="payzen-iframe" id="payzen_iframe" src="' . add_query_arg('loading', 'true', $link) . '" style="display: none;">
                         </iframe>';

                $html .= "\n".'<script type="text/javascript">';
                $html .= "\njQuery('form.checkout').on('checkout_place_order_payzenstd', function() {
                                jQuery.ajaxPrefilter(function(options, originalOptions, jqXHR) {
                                    if ((originalOptions.url.indexOf('wc-ajax=checkout') == -1)
                                        && (originalOptions.url.indexOf('action=woocommerce-checkout') == -1)) {
                                        return;
                                    }

                                    if (options.data.indexOf('payment_method=payzenstd') == -1) {
                                        return;
                                    }

                                    options.success = function(data, status, jqXHR) {
                                        if (typeof data  === 'string') { // for backward compatibility
                                            // get the valid JSON only from the returned string
                                            if (data.indexOf('<!--WC_START-->') >= 0)
                                                data = data.split('<!--WC_START-->')[1];

                                            if (data.indexOf('<!--WC_END-->') >= 0)
                                                data = data.split('<!--WC_END-->')[0];

                                            // parse
                                            data = jQuery.parseJSON(data);
                                        }

                                        var result = (data && data.result) ? data.result : false;
                                        if (result !== 'success') {
                                            originalOptions.success.call(null, data, status, jqXHR);
                                            return;
                                        }

                                        // unblock screen
                                        jQuery('form.checkout').unblock();

                                        jQuery('#" . $this->id . "_payment_by_token_description').hide();
                                        jQuery('.payment_method_payzenstd p:first-child').hide();
                                        jQuery('.payzen-iframe').show();

                                        jQuery('#payzen_iframe').attr('src', '$link');
                                    };
                                });
                            });";

                $html .= "\njQuery('input[type=\"radio\"][name=\"payment_method\"][value!=\"payzenstd\"]').click(function() {
                                jQuery('form.checkout').removeClass('processing').unblock();
                                jQuery('#" . $this->id . "_payment_by_token_description').show();
                                jQuery('.payment_method_payzenstd p:first-child').show();
                                jQuery('.payzen-iframe').hide();

                                jQuery('#payzen_iframe').attr('src', '" . add_query_arg('loading', 'true', $link) . "');
                            });";
                $html .= "\n</script>";
                $html .= "\n</div>";

                echo $html;
                break;

            default:
                break;
        }

        if ($can_pay_by_alias) {
            // display specific description for payment by token if enabled
            $this->display_payment_by_alias_interface($cust_id);
        }
    }

    private function can_use_alias($cust_id)
    {
        if (! $cust_id) {
            return false;
        }

        if ($this->id !== 'payzenstd') {
            return false;
        }

        return $this->get_option('payment_by_token') == '1';
    }

    private function get_cust_identifier($cust_id)
    {
        $saved_identifier = get_user_meta((int) $cust_id, 'payzen_identifier', true);
        return $saved_identifier;
    }

    private function display_payment_by_alias_interface($cust_id)
    {
        $saved_masked_pan = get_user_meta((int) $cust_id, 'payzen_masked_pan', true);

        $html = '<div id="' . $this->id . '_payment_by_token_description">
                    <ul id="' . $this->id . '_payment_by_token_description_1">
                        <li>
                            <input id="' . $this->id . '_use_identifier" type="hidden" value="1" name="' . $this->id . '_use_identifier" />
                            <span>' . sprintf(__('You will pay with your registered means of payment %s. No data entry is needed.', 'woo-payzen-payment'), '<b>' . $saved_masked_pan . '</b>') . '</span>
                        </li>

                        <li style="margin: 8px 0px 8px;">
                            <span>' . __('OR', 'woo-payzen-payment') . '</span>
                        </li>

                        <li>
                            <a href="javascript: void(0);" onclick="payzenSubmitForm(0)">' . __('Click here to pay with another means of payment.', 'woo-payzen-payment') . '</a>
                        </li>
                    </ul>
                    <ul id="' . $this->id . '_payment_by_token_description_2">
                        <li>' . __('You will enter payment data after order confirmation.', 'woo-payzen-payment') . '</li>

                        <li style="margin: 8px 0px 8px;">
                            <span>' . __('OR', 'woo-payzen-payment') . '</span>
                        </li>

                        <li>
                            <a href="javascript: void(0);" onclick="payzenSubmitForm(1)">' . __('Click here to pay with your registered means of payment.', 'woo-payzen-payment') . '</a>
                        </li>
                    </ul>
                </div>';

        $script = '<script type="text/javascript">
                       function payzenSubmitForm(use_identifier) {
                           if (use_identifier) {
                               jQuery("#' . $this->id . '_payment_by_token_description_1").show();
                               jQuery("#' . $this->id . '_payment_by_token_description_2").hide()
                               jQuery("#' . $this->id . '_display_available_cards").hide();
                           } else {
                               jQuery("#' . $this->id . '_payment_by_token_description_1").hide();
                               jQuery("#' . $this->id . '_payment_by_token_description_2").show()
                               jQuery("#' . $this->id . '_display_available_cards").show();
                           }

                           jQuery("#' . $this->id . '_use_identifier").val(use_identifier);
                       }

                       jQuery(function() {
                           payzenSubmitForm(1);
                       });
                   </script>';

        echo $html . $script;
    }

    /**
     * Process the payment and return the result.
     **/
    public function process_payment($order_id)
    {
        global $woocommerce;

        if ($this->get_option('card_data_mode') == 'MERCHANT') {
            $this->save_selected_card($order_id);
        }

        // save flag about use of saved identifier
        if (isset($_POST[$this->id . '_use_identifier'])) {
            set_transient($this->id . '_use_identifier_' . $order_id, $_POST[$this->id . '_use_identifier']);
        }

        $order = new WC_Order($order_id);

        if (version_compare($woocommerce->version, '2.1.0', '<')) {
            $pay_url = add_query_arg('order', $this->get_order_property($order, 'id'), add_query_arg('key', $this->get_order_property($order, 'order_key'), get_permalink(woocommerce_get_page_id('pay'))));
        } else {
            $pay_url = $order->get_checkout_payment_url(true);
        }

        return array(
            'result' => 'success',
            'redirect' => $pay_url
        );
    }

    protected function save_selected_card($order_id)
    {
        $selected_card = $_POST[$this->id . '_card_type'];

        // save selected card into database as transcient
        set_transient($this->id . '_card_type_' . $order_id, $selected_card);
    }

    /**
     * Order review and payment form page.
     **/
    public function payzen_generate_form($order_id)
    {
        global $woocommerce;

        $order = new WC_Order($order_id);

        echo '<div style="opacity: 0.6; padding: 10px; text-align: center; color: #555; border: 3px solid #aaa; background-color: #fff; cursor: wait; line-height: 32px;">';

        $img_url = WC_PAYZEN_PLUGIN_URL . 'assets/images/loading.gif';
        $img_url = class_exists('WC_HTTPS') ? WC_HTTPS::force_https_url($img_url) : $woocommerce->force_ssl($img_url);
        echo '<img src="' . esc_url($img_url) . '" alt="..." style="float:left; margin-right: 10px;"/>';
        echo __('Please wait, you will be redirected to the payment gateway.', 'woo-payzen-payment');
        echo '</div>';
        echo '<br />';
        echo '<p>' . __('If nothing happens in 10 seconds, please click the button below.', 'woo-payzen-payment') . '</p>';

        $this->payzen_fill_request($order);

        // log data that will be sent to payment gateway
        $this->log('Data to be sent to payment gateway : ' . print_r($this->payzen_request->getRequestFieldsArray(true /* to hide sensitive data */), true));

        $form = "\n".'<form action="' . esc_url($this->payzen_request->get('platform_url')) . '" method="post" id="' . $this->id . '_payment_form">';
        $form .= "\n" . $this->payzen_request->getRequestHtmlFields();
        $form .= "\n" . '  <input type="submit" class="button-alt" id="' . $this->id . '_payment_form_submit" value="' . sprintf(__('Pay via %s', 'woo-payzen-payment'), self::GATEWAY_NAME).'">';
        $form .= "\n" . '  <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woo-payzen-payment') . '</a>';
        $form .= "\n" . '</form>';

        $form .= "\n".'<script type="text/javascript">';
        $form .= "\nfunction payzen_submit_form() {
                    document.getElementById('" . $this->id . "_payment_form_submit').click();
                  }";
        $form .= "\nif (window.addEventListener) { // for all major browsers
                    window.addEventListener('load', payzen_submit_form, false);
                  } else if (window.attachEvent) { // for IE 8 and earlier versions
                    window.attachEvent('onload', payzen_submit_form);
                  }";
        $form .= "\n</script>\n";

        echo $form;
    }

    public function payzen_generate_iframe_form()
    {
        global $woocommerce;

        if (isset($_GET['loading']) && $_GET['loading']) {
            echo '<div style="text-align: center;">
                      <img src="' . esc_url(WC_PAYZEN_PLUGIN_URL . 'assets/images/loading_big.gif') . '">
                  </div>';
            die();
        }

        // order ID from session
        $order_id = $woocommerce->session->get('order_awaiting_payment');

        $order = new WC_Order((int)$order_id);
        $this->payzen_fill_request($order);

        // hide logos below payment fields
        $this->payzen_request->set('theme_config', '3DS_LOGOS=false;');

        $this->payzen_request->set('action_mode', 'IFRAME');
        $this->payzen_request->set('redirect_enabled', '1');
        $this->payzen_request->set('redirect_success_timeout', '0');
        $this->payzen_request->set('redirect_error_timeout', '0');

        // log data that will be sent to payment gateway
        $this->log('Data to be sent to payment gateway : ' . print_r($this->payzen_request->getRequestFieldsArray(true /* to hide sensitive data */), true));

        $form = "\n" . '<form action="' . esc_url($this->payzen_request->get('platform_url')) .'" method="post" id="' . $this->id . '_payment_iframe_form">';
        $form .= "\n".$this->payzen_request->getRequestHtmlFields();
        $form .= "\n".'</form>';

        $form .= "\n".'<script type="text/javascript">';
        $form .= "\nfunction payzen_submit_form() {
                        document.getElementById('" . $this->id . "_payment_iframe_form').submit();
                      }";
        $form .= "\nif (window.addEventListener) { // for all major browsers
                        window.addEventListener('load', payzen_submit_form, false);
                      } else if (window.attachEvent) { // for IE 8 and earlier versions
                        window.attachEvent('onload', payzen_submit_form);
                      }";
        $form .= "\n</script>\n";

        echo $form;
        die();
    }

    /**
     * Prepare form params to send to payment gateway.
     **/
    protected function payzen_fill_request($order)
    {
        global $woocommerce, $wpdb;

        $order_id = $this->get_order_property($order, 'id');
        $cust_id = $this->get_order_property($order, 'user_id');

        $this->log('Generating payment form for order #' . $order_id . '.');

        // get currency
        $currency = PayzenApi::findCurrencyByAlphaCode(get_woocommerce_currency());
        if ($currency == null) {
            $this->log('The store currency (' . get_woocommerce_currency() . ') is not supported by payment gateway.');

            wp_die(sprintf(__('The store currency (%s) is not supported by %s.', 'woo-payzen-payment'), get_woocommerce_currency(), self::GATEWAY_NAME));
        }

        // effective used version
        include ABSPATH . WPINC . '/version.php'; // $wp_version;
        $version = $wp_version . '_' . $woocommerce->version;

        // params
        $misc_params = array(
            'amount' => $currency->convertAmountToInteger($order->get_total()),
            'contrib' => self::CMS_IDENTIFIER . '_' . self::PLUGIN_VERSION . '/' . $version . '/' . PHP_VERSION,
            'currency' => $currency->getNum(),
            'order_id' => $order_id,
            'order_info' => $this->get_order_property($order, 'order_key'),
            'order_info2' => 'blog_id=' . $wpdb->blogid, // save blog_id for multisite cases

            // billing address info
            'cust_id' => $cust_id,
            'cust_email' => $this->get_order_property($order, 'billing_email'),
            'cust_first_name' => $this->get_order_property($order, 'billing_first_name'),
            'cust_last_name' => $this->get_order_property($order, 'billing_last_name'),
            'cust_address' => $this->get_order_property($order, 'billing_address_1') . ' ' .  $this->get_order_property($order, 'billing_address_2'),
            'cust_zip' => $this->get_order_property($order, 'billing_postcode'),
            'cust_country' => $this->get_order_property($order, 'billing_country'),
            'cust_phone' => str_replace(array('(', '-', ' ', ')'), '', $this->get_order_property($order, 'billing_phone')),
            'cust_city' => $this->get_order_property($order, 'billing_city'),
            'cust_state' => $this->get_order_property($order, 'billing_state'),

            // shipping address info
            'ship_to_first_name' => $this->get_order_property($order, 'shipping_first_name'),
            'ship_to_last_name' => $this->get_order_property($order, 'shipping_last_name'),
            'ship_to_street' => $this->get_order_property($order, 'shipping_address_1'),
            'ship_to_street2' => $this->get_order_property($order, 'shipping_address_2'),
            'ship_to_city' => $this->get_order_property($order, 'shipping_city'),
            'ship_to_state' => $this->get_order_property($order, 'shipping_state'),
            'ship_to_country' => $this->get_order_property($order, 'shipping_country'),
            'ship_to_zip' => $this->get_order_property($order, 'shipping_postcode'),

            'shipping_amount' => $currency->convertAmountToInteger($this->get_shipping_with_tax($order)),

            // return URLs
            'url_return' => add_query_arg('wc-api', 'WC_Gateway_Payzen', home_url('/'))
        );
        $this->payzen_request->setFromArray($misc_params);

        // VAT amount for colombian payment means
        $this->payzen_request->set('totalamount_vat', $currency->convertAmountToInteger($order->get_total_tax()));

        // activate 3ds ?
        $threeds_mpi = null;
        if ($this->get_general_option('3ds_min_amount') != '' && $order->get_total() < $this->get_general_option('3ds_min_amount')) {
            $threeds_mpi = '2';
        }

        $this->payzen_request->set('threeds_mpi', $threeds_mpi);

        // detect language
        $locale = get_locale() ? substr(get_locale(), 0, 2) : null;
        if ($locale && PayzenApi::isSupportedLanguage($locale)) {
            $this->payzen_request->set('language', $locale);
        } else {
            $this->payzen_request->set('language', $this->get_general_option('language'));
        }

        // available languages
        $langs = $this->get_general_option('available_languages');
        if (is_array($langs) && ! in_array('', $langs)) {
            $this->payzen_request->set('available_languages', implode(';', $langs));
        }

        if (isset($this->form_fields['card_data_mode'])) {
            // payment cards
            if ($this->get_option('card_data_mode') == 'MERCHANT') {
                $selected_card = get_transient($this->id . '_card_type_' . $order_id);
                $this->payzen_request->set('payment_cards', $selected_card);

                delete_transient($this->id . '_card_type_' . $order_id);
            } else {
                $cards = $this->get_option('payment_cards');
                if (is_array($cards) && ! in_array('', $cards)) {
                    $this->payzen_request->set('payment_cards', implode(';', $cards));
                }
            }
        }

        // enable automatic redirection ?
        $this->payzen_request->set('redirect_enabled', ($this->get_general_option('redirect_enabled') == 'yes') ? true : false);

        // redirection messages
        $success_message = $this->get_general_option('redirect_success_message');
        $success_message = isset($success_message[get_locale()]) && $success_message[get_locale()] ? $success_message[get_locale()] :
            (is_array($success_message) ? $success_message['en_US'] : $success_message);
        $this->payzen_request->set('redirect_success_message', $success_message);

        $error_message = $this->get_general_option('redirect_error_message');
        $error_message = isset($error_message[get_locale()]) && $error_message[get_locale()] ? $error_message[get_locale()] :
            (is_array($error_message) ? $error_message['en_US'] : $error_message);
        $this->payzen_request->set('redirect_error_message', $error_message);

        // other configuration params
        $config_keys = array(
            'site_id', 'key_test', 'key_prod', 'ctx_mode', 'platform_url', 'capture_delay', 'validation_mode',
            'redirect_success_timeout', 'redirect_error_timeout', 'return_mode', 'sign_algo'
        );

        foreach ($config_keys as $key) {
            $this->payzen_request->set($key, $this->get_general_option($key));
        }

        // check if capture_delay and validation_mode are overriden in sub-modules
        if (is_numeric($this->get_option('capture_delay'))) {
            $this->payzen_request->set('capture_delay', $this->get_option('capture_delay'));
        }

        if ($this->get_option('validation_mode') !== '-1') {
            $this->payzen_request->set('validation_mode', $this->get_option('validation_mode'));
        }

        if ($this->subscriptions_handler && $this->subscriptions_handler->cart_contains_subscription($woocommerce->cart)
            && ($info = $this->subscriptions_handler->subscription_info($order))) {

            $this->payzen_request->set('sub_amount', $currency->convertAmountToInteger($info['amount']));
            $this->payzen_request->set('sub_currency', $currency->getNum()); // same as general order currency

            $desc = 'RRULE:FREQ=' . $info['frequency']. ';INTERVAL=' . $info['interval'];
            if (isset($info['end_date']) && $info['end_date']) {
                $desc .= ';UNTIL=' . $info['end_date'];
            }

            $this->payzen_request->set('sub_desc', $desc);

            $this->payzen_request->set('sub_effect_date', $info['effect_date']);

            // initial amount
            if (isset($info['init_amount']) && $info['init_amount'] && isset($info['init_number']) && $info['init_number']) {
                $this->payzen_request->set('sub_init_amount', $currency->convertAmountToInteger($info['init_amount']));
                $this->payzen_request->set('sub_init_amount_number', $info['init_number']);
            }

            if ($order->get_total() > 0) {
                $this->payzen_request->set('page_action', 'REGISTER_PAY_SUBSCRIBE');
            } else {
                // only subscriptions
                if ($saved_identifier = $this->get_cust_identifier($cust_id)) {
                    $this->payzen_request->set('identifier', $saved_identifier);
                    $this->payzen_request->set('page_action', 'SUBSCRIBE');
                } else {
                    $this->payzen_request->set('page_action', 'REGISTER_SUBSCRIBE');
                }
            }
        } elseif ($this->can_use_alias($cust_id)) { // if option enabled
            if ($saved_identifier = $this->get_cust_identifier($cust_id)) {
                $this->payzen_request->set('identifier', $saved_identifier);

                if (! get_transient($this->id . '_use_identifier_' . $order_id)) { // customer choose to not use alias
                    $this->payzen_request->set('page_action', 'REGISTER_UPDATE_PAY');
                }

                // delete flag about use of saved identifier
                delete_transient($this->id . '_use_identifier_' . $order_id);
            } else {
                $this->payzen_request->set('page_action', 'ASK_REGISTER_PAY');
            }
        }
    }

    private function get_shipping_with_tax($order)
    {
        $shipping = 0;

        if (method_exists($order, 'get_shipping_total')) {
            $shipping += $order->get_shipping_total();
        } elseif (method_exists($order, 'get_total_shipping')) {
            $shipping += $order->get_total_shipping(); // WC old versions
        } else {
            $shipping += $order->get_shipping(); // WC older versions
        }

        $shipping += $order->get_shipping_tax();

        return $shipping;
    }

    public function payzen_order_needs_payment($is_active, $order)
    {
        global $woocommerce;

        if (($order->get_total() == 0) && ($this->get_order_property($order, 'payment_method') === $this->id)) {
            return $this->subscriptions_handler && $this->subscriptions_handler->cart_contains_subscription($woocommerce->cart);
        }

        return $is_active;
    }
}
