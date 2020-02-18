<?php

use Mollie\Api\MollieApiClient;

GFForms::include_payment_addon_framework();

if (method_exists('GFForms', 'include_payment_addon_framework')) {

    class QinvoiceMollie extends GFPaymentAddOn
    {
        protected $_version = "0.0.1";
        protected $_min_gravityforms_version = "1.8.12";
        protected $_slug = 'mollie-ideal-for-gravity-forms-by-q-invoice';
        protected $_path = 'mollie-ideal-for-gravity-forms-by-q-invoice/';
        protected $_full_path = __FILE__;
        protected $_title = 'Gravity Forms Mollie iDeal Add-On by q-invoice';
        protected $_short_title = 'Mollie by q-invoice';
        protected $_supports_callbacks = true;
        protected $_requires_credit_card = false;
        protected $_supported_currencies = array('EUR','USD');

        const CALLBACK_PAGE = 'gravity_forms_mollie_qinvoice';

        /**
         * @var object|null $_instance If available, contains an instance of this class.
         */
        private static $_instance = null;

        /**
         * Returns an instance of this class, and stores it in the $_instance property.
         *
         * @return object $_instance An instance of this class.
         */
        public static function get_instance()
        {
            if (self::$_instance == null) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /**
         * Configures the settings which should be rendered on the Form Settings > Simple Add-On tab.
         *
         * @return array
         */
        public function plugin_settings_fields()
        {
            return array(
                array(
                    'title' => __('Mollie settings', 'mollie-ideal-for-gravity-forms-by-q-invoice'),
                    'fields' => array(
                        array(
                            'type' => 'helper_text',
                            'name' => 'help',
                            'label' => '',
                        ),
                        array(
                            'label' => __('Mollie API key', 'mollie-ideal-for-gravity-forms-by-q-invoice'),
                            'type' => 'text',
                            'name' => 'api_key',
                            'tooltip' => __('You can obtain your API key from your Mollie dashboard', 'mollie-ideal-for-gravity-forms-by-q-invoice'),
                            'class' => 'medium',
                            'feedback_callback' => array($this, 'is_valid_mollie_key'),
                        ),


                    ),
                ),
            );
        }

        public function get_entry_meta($entry_meta, $form_id)
        {
            $entry_meta['payment_status'] = array(
                'label' => 'Payment status',
                'is_numeric' => false,
                'is_default_column' => true,
                // 'update_entry_meta_callback' => array( $this, 'update_entry_meta' ),
                'filter' => array(
                    'operators' => array('is', 'isnot'),
                ),
            );
            $entry_meta['transaction_id'] = array(
                'label' => 'Transaction ID',
                'is_numeric' => false,
                'is_default_column' => true,
                // 'update_entry_meta_callback' => array( $this, 'update_entry_meta' ),
                'filter' => array(
                    'operators' => array('is', 'isnot'),
                ),
            );
            return $entry_meta;
        }

        public function settings_helper_text()
        {
            printf(__('No Mollie account? %sClick here to create one%s.', 'mollie-ideal-for-gravity-forms-by-q-invoice'), '<a target="blank" href="https://www.mollie.com/dashboard/signup/1162041">', '</a>');
        }

        public function feed_settings_fields()
        {

            $feed_settings_fields = parent::feed_settings_fields();

            $currency_options = array();
            foreach($this->_supported_currencies as $c){
                $currency_options = array(
                    'label' => $c,
                    'value' => $c,
                );
            }
            $fields = array(
                array(
                    'type' => 'helper_text',
                    'name' => 'help',
                    'label' => '',
                ),
                array(
                    'label' => 'API key',
                    'type' => 'select',
                    'name' => 'override',
                    'tooltip' => __('Override settings for this feed alone', 'mollie-ideal-for-gravity-forms-by-q-invoice'),
                    'choices' => array(
                        array(
                            'label' => 'Pick one',
                            'value' => '',
                        ),
                        array(
                            'label' => 'Use default API key',
                            'value' => 'default',
                        ),
                        array(
                            'label' => 'Specify a different key for this feed',
                            'value' => 'override',
                        ),

                    ),
                    'onchange' => 'jQuery(this).parents("form").submit();',
                ),
                array(
                    'label' => __('Mollie API key', 'mollie-ideal-for-gravity-forms-by-q-invoice'),
                    'type' => 'text',
                    'name' => 'api_key',
                    'tooltip' => __('You can obtain your API key from your Mollie dashboard', 'mollie-ideal-for-gravity-forms-by-q-invoice'),
                    'class' => 'medium',
                    'feedback_callback' => array($this, 'is_valid_mollie_key'),
                    'dependency' => array('field' => 'override', 'values' => array('override')),

                ),

                array(
                    'label' => __('Currency', 'mollie-ideal-for-gravity-forms-by-q-invoice'),
                    'type' => 'select',
                    'name' => 'currency',
                    'tooltip' => __('Specify the currency in which amounts are paid for this feed', 'mollie-ideal-for-gravity-forms-by-q-invoice'),
                    'class' => 'medium',
                    'choices' => array(
                        $currency_options
                    ),
                ),


            );

            $feed_settings_fields = parent::add_field_after('feedName', $fields, $feed_settings_fields);

            // Override transaction type
            $feed_settings_fields = $this->replace_field('transactionType',
                array(
                    array(
                        'type' => 'hidden',
                        'name' => 'transactionType',
                        'value' => 'product',
                    ),
                ), $feed_settings_fields);

            // Remove billing information
            $feed_settings_fields = $this->replace_field('billingInformation', array(), $feed_settings_fields);

            return $feed_settings_fields;

        }

        public function option_choices()
        {
            return false;
        }

        public function redirect_url($feed, $submission_data, $form, $entry)
        {
            $apiKey = $this->get_api_key($feed);
            if (!$apiKey) {
                $this->log_debug(__METHOD__ . '(): API key is missing.');
                return '';
            }


            try {
                $mollie = new MollieApiClient();
                $mollie->setApiKey($apiKey);
            } catch (Exception $e) {
                $this->log_debug(__METHOD__ . '(): Failed to instantiate API. Using key: .' . $apiKey . '. Error: ' . htmlspecialchars($e->getMessage()));
                return false;
            }

            // build variables for later use
            $return_url = $this->return_url($form['id'], $entry['id']);
            $webhook_url = $this->webhook_url($form['id'], $entry['id']);
            $payment_amount = rgar($submission_data, 'payment_amount');
            $order_id = '';

            $payment_data = array(
                "amount" => array(
                    "currency" => in_array($feed['meta']['currency'], $this->_supported_currencies) ? $feed['meta']['currency'] : 'EUR',
                    "value" => number_format($payment_amount,"2",".",""),
                ),
                "description" => $feed['meta']['feedName'],
                "redirectUrl" => $return_url,
                "webhookUrl" => $webhook_url,

            );

            $this->log_debug(__METHOD__ . '(): Payment data: .' . print_r($payment_data, true));

            try {
                $payment = $mollie->payments->create($payment_data);
            } catch (Exception $e) {
                $this->log_debug(__METHOD__ . '(): Failed to start payment. Error: .' . htmlspecialchars($e->getMessage()));
                return false;
            }

            // everything ok. update properties
            GFAPI::update_entry_property($entry['id'], 'payment_status', 'Processing');
            GFAPI::update_entry_property($entry['id'], 'transaction_id', $payment->id);
            gform_update_meta($entry['id'], 'payment_amount', $payment_amount);

            $payment_url = $payment->getCheckoutUrl();

            $this->log_debug(__METHOD__ . '(): Payment started. Redirecting to ' . $payment_url);
            return $payment_url;
        }

        private function return_url($form_id, $entry_id)
        {
            $url = (GFCommon::is_ssl() ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
            return add_query_arg('mollie_qinvoice_return', base64_encode($this->query_params($form_id, $entry_id)), $url);
        }

        private function webhook_url($form_id, $entry_id)
        {
            return (GFCommon::is_ssl() ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'] . preg_replace('/\?.*/', '', $_SERVER['REQUEST_URI']) . '?page=' . self::CALLBACK_PAGE . '&' . $this->query_params($form_id, $entry_id);
        }

        public function post_payment_action($entry, $action)
        {
            $this->log_debug(__METHOD__ . '(): Called.');
        }


        private function query_params($form_id, $entry_id)
        {
            return 'fid=' . $form_id . '&eid=' . $entry_id . '&hash=' . $this->get_hash($form_id, $entry_id);
        }

        private function get_hash($form_id, $entry_id)
        {
            return wp_hash($form_id, $entry_id);
        }

        private function check_hash($form_id, $entry_id, $hash)
        {
            return $hash == $this->get_hash($form_id, $entry_id);
        }

        public function is_callback_valid()
        {
            if (rgget('page') !== self::CALLBACK_PAGE) {
                return false;
            }
            return true;
        }

        public function callback()
        {

            if (!$this->is_gravityforms_supported()) {
                return false;
            }

            $this->log_debug(__METHOD__ . '(): called.');

            // Check the hash
            if (!$this->check_hash(rgget('fid'), rgget('eid'), rgget('hash'))) {
                $this->log_debug(__METHOD__ . '(): Incorrect hash!');
                return false;
            }

            if (empty(rgpost('id'))) {
                $this->log_error(__METHOD__ . '(): ID is missing from request');
                return false;
            }

            //	Get the entry
            $entry_id = rgget('eid');
            $entry = GFAPI::get_entry($entry_id);

            $feed = $this->get_payment_feed($entry);

            $apiKey = $this->get_api_key($feed);
            if (!$apiKey) {
                $this->log_debug(__METHOD__ . '(): API key is missing.');
                return '';
            }

            try {
                $mollie = new MollieApiClient;
                $mollie->setApiKey($apiKey);
            } catch (Exception $e) {
                $this->log_debug(__METHOD__ . '(): Failed to instantiate API. Using key: .' . $apiKey . '. Error: ' . htmlspecialchars($e->getMessage()));
                return false;
            }
            
            try {
                $payment = $mollie->payments->get(rgpost('id'));

            } catch (Exception $e) {
                $this->log_debug(__METHOD__ . '(): Failed to get payment: ' . htmlspecialchars($e->getMessage()));
            }

            $this->log_debug(__METHOD__ . '(): Payment object' . print_r($payment, true));

            if ($payment->isPaid() && !$payment->hasRefunds() && !$payment->hasChargebacks()) {

                $this->log_debug(__METHOD__ . '(): Mollie status paid');

                $action['type'] = 'complete_payment';
                $action['transaction_id'] = $payment->id;
                $action['amount'] = $payment->amount->value;
                $action['entry_id'] = $entry['id'];
                $action['payment_date'] = gmdate('y-m-d H:i:s');
                $action['payment_method'] = 'Mollie';
                $action['note'] = '';
                return $action;


            } else {

                $this->log_debug(__METHOD__ . '(): Mollie status not paid');

                $action['type'] = 'fail_payment';
                $action['transaction_id'] = $payment->id;
                $action['entry_id'] = $entry['id'];
                $action['amount'] = $payment->amount;
                $action['note'] = '';
                return $action;
            }

            return false;

        }

        public static function handle_confirmation($callback)
        {
            $instance = self::get_instance();

            if (!$instance->is_gravityforms_supported()) {
                return;
            }

            if (rgget('mollie_qinvoice_return')) {
                parse_str(base64_decode(rgget('mollie_qinvoice_return')), $query);

                if (!$instance->check_hash($query['fid'], $query['eid'], $query['hash'])) {
                    return;
                }

                $form = GFAPI::get_form($query['fid']);
                $entry = GFAPI::get_entry($query['eid']);

                if (!class_exists('GFFormDisplay')) {
                    require_once(GFCommon::get_base_path() . '/form_display.php');
                }

                $confirmation = GFFormDisplay::handle_confirmation($form, $entry, false);

                if (is_array($confirmation) && isset($confirmation['redirect'])) {
                    header("Location: {$confirmation['redirect']}");
                    exit;
                }

                GFFormDisplay::$submission[$form['id']] = array('is_confirmation' => true, 'confirmation_message' => $confirmation, 'form' => $form, 'lead' => $entry);
            }
        }

        public function is_valid_mollie_key($value)
        {
            return in_array(substr($value, 0, 5), array('live_', 'test_'));
        }

        private function get_api_key($feed)
        {
            if (isset($feed['meta']['override']) && $feed['meta']['override'] == 1) {
                return $feed['meta']['api_key'];
            } else {
                return $this->get_plugin_setting('api_key');
            }
        }

        public function minimum_requirements()
        {
            array(
                // Require WordPress version 4.6.2 or higher.
                'wordpress' => array(
                    'version' => '4.6.2',
                ),

                // Require PHP version 5.3 or higher.
                'php' => array(
                    'version' => '5.3',

                    // Require specific PHP extensions.
                    'extensions' => array(

                        // Require cURL version 1.0 or higher.
                        'curl' => array(
                            'version' => '1.0',
                        ),

                        // Require any version of mbstring.
                        'mbstring',
                    ),

                    // Require specific functions to be available.
                    'functions' => array(
                        'openssl_random_pseudo_bytes',
                        'mcrypt_create_iv',
                    ),
                ),

                // Require other add-ons to be present.
                'add-ons' => array(

                    // Require any version of the Mailchimp add-on.
                    'gravityformsmailchimp',

                    // Require the Stripe add-on and ensure the name matches.
                    'gravityformsstripe' => array(
                        'name' => 'Gravity Forms Stripe Add-On',
                    ),

                    // Require the PayPal add-on version 5.0 or higher.
                    'gravityformspaypal' => array(
                        'version' => '5.0',
                    ),
                ),

                // Required plugins.
                'plugins' => array(

                    // Require the REST API.
                    'rest-api/plugin.php',

                    // Require Jetpack and ensure the name matches.
                    'jetpack/jetpack.php' => 'Jetpack by WordPress.com',
                ),

                // Any additional custom requirements via callbacks.
                array($this, 'custom_requirement'),
            );
        }
    }
}