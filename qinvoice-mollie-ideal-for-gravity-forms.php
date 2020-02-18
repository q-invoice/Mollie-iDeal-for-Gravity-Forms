<?php

/*
Plugin Name: q-invoice Mollie iDeal for Gravity Forms
Plugin URI: https://github.com/q-invoice/Mollie-iDeal-for-Gravity-Forms
Description: Accept iDeal (and other) payments for your Gravity Forms
Version: 0.0.1
Author: q-invoice
Author URI: http://www.q-invoice.com
Text Domain: qinvoice-mollie-ideal-for-gravity-forms
Domain Path: /languages

*/


add_action('gform_loaded', array('GF_Qinvoice_Mollie_Bootstrap', 'load'), 5);

class GF_Qinvoice_Mollie_Bootstrap
{

    public static function load()
    {

        if (!method_exists('GFForms', 'include_addon_framework')) {
            return;
        }

        require_once('vendor/autoload.php');
        require_once('class-qinvoice-mollie.php');

        GFAddOn::register('QinvoiceMollie');

        add_action('wp', array('QinvoiceMollie', 'handle_confirmation'), 4);

    }

}
