<?php
/*
Plugin Name: WooCommerce WP App Store Integration
Plugin URI: http://deliciousbrains.com
Description: A WordPress plugin that handles the WP App Store postback
Author: Delicious Brains
Version: 0.1
Author URI: http://deliciousbrains.com
*/

// Copyright (c) 2013 Delicious Brains. All rights reserved.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************

require_once 'class/wc-wpas.php';

function woocommerce_wpappstore_integration_init() {
	global $wc_wpas;
	$wc_wpas = new WC_WPAS();
}

add_action( 'init', 'woocommerce_wpappstore_integration_init' );
