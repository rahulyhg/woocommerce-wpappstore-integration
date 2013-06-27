<?php
class WC_WPAS {

	function __construct() {
		/*
		The WooCommerce API lets plugins make a callback to a special URL which will then
		load the specified class (if it exists) and run an action. 

		To trigger the WooCommerce API you need to use a special URL. Pre-2.0 you could use: 
		http://yoursite.com/?wc-api=CALLBACK

		In WooCommerce 2.0 you can still use that, or you can use our endpoint: 
		http://yoursite.com/wc-api/CALLBACK/

		Source: http://docs.woothemes.com/document/wc_api-the-woocommerce-api-callback/
		*/
		add_action( 'woocommerce_api_wpappstore-integration', array( $this, 'process_postback' ) );
	}

	function process_postback() {
		global $woocommerce;

		$postback = $this->parse_postback();

		// Hook in
		add_filter( 'woocommerce_checkout_fields' , array( $this, 'temporarily_remove_required_fields' ) );
		add_filter( 'woocommerce_cart_needs_payment' , array( $this, 'temporarily_disable_payment' ) );

		$user = get_user_by( 'login', $postback['username'] );

		// user exists in the system
		if( $user !== false ) {
			$user_id = $user->ID;
		}
		else {
			// user does not exist, create a new user account for them
			$user_id = wp_create_user( $postback['username'], wp_generate_password(), $postback['username'] );
		}
		// log the user in
		wp_set_current_user( $user_id, $postback['username'] );
		wp_set_auth_cookie( $user_id );
		do_action( 'wp_login', $postback['username'] );

		$_REQUEST['_n'] = wp_create_nonce( 'woocommerce-process_checkout' );

		$woocommerce->cart->empty_cart();
		$woocommerce->cart->add_to_cart( $postback['sku'], 1 );
		$checkout = $woocommerce->checkout();
		$checkout->process_checkout();

		// remove temp filters
		remove_filter( 'woocommerce_checkout_fields' , array( $this, 'temporarily_remove_required_fields' ) );
		remove_filter( 'woocommerce_cart_needs_payment' , array( $this, 'temporarily_disable_payment' ) );

		exit;
	}

	function parse_postback() {
		$postback['username'] = $_GET['user'];
		$postback['sku'] = $_GET['sku'];
		return $postback;
	}

	// Our hooked in function - $fields is passed via the filter!
	function temporarily_remove_required_fields( $fields ) {
		$fields['billing']['billing_country']['required'] = false;
		$fields['billing']['billing_first_name']['required'] = false;
		$fields['billing']['billing_last_name']['required'] = false;
		$fields['billing']['billing_address_1']['required'] = false;
		$fields['billing']['billing_address_2']['required'] = false;
		$fields['billing']['billing_city']['required'] = false;
		$fields['billing']['billing_state']['required'] = false;
		$fields['billing']['billing_postcode']['required'] = false;
		$fields['billing']['billing_email']['required'] = false;
		$fields['billing']['billing_phone']['required'] = false;
	return $fields;
	}

	function temporarily_disable_payment() {
		return false;
	}

}
?>