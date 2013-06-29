<?php
class WC_WPAS {
	private $api_key;

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

		// hardcoded api key - verify that the request is coming from a trusted source
		$this->api_key = 'test_key';
	}

	function process_postback() {
		global $woocommerce;

		$postback = $this->parse_postback();

		if( $postback['api_key'] != $this->api_key ) wp_die( 'Cheatin\' eh?' );

		$_POST['billing_email'] = $postback['username'];
		$_POST['billing_first_name'] = $postback['first_name'];
		$_POST['billing_last_name'] = $postback['last_name'];

		// Hook in
		add_filter( 'woocommerce_checkout_fields' , array( $this, 'temporarily_remove_required_fields' ) );
		add_filter( 'woocommerce_cart_needs_payment' , array( $this, 'temporarily_disable_payment' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'order_complete' ) );

		$user = get_user_by( 'login', $postback['username'] );

		// user exists in the system
		if( $user !== false ) {
			$user_id = $user->ID;
			// log the user in
			wp_set_current_user( $user_id, $postback['username'] );
			wp_set_auth_cookie( $user_id );
			do_action( 'wp_login', $postback['username'] );
		}
		else {
			// user does not exist, create a new user account for them (let Woo handle it though)
			$password = wp_generate_password();
			$_POST['account_password'] = $password;
			$_POST['account_password-2'] = $password;
		}

		$_REQUEST['_n'] = wp_create_nonce( 'woocommerce-process_checkout' );

		$woocommerce->cart->empty_cart();
		$woocommerce->cart->add_to_cart( $postback['sku'], 1 );
		$checkout = $woocommerce->checkout();
		$checkout->process_checkout();

		// remove temp filters
		remove_filter( 'woocommerce_checkout_fields' , array( $this, 'temporarily_remove_required_fields' ) );
		remove_filter( 'woocommerce_cart_needs_payment' , array( $this, 'temporarily_disable_payment' ) );
		remove_action( 'woocommerce_payment_complete', array( $this, 'order_complete' ) );

		// check for errors
		// echo '<pre>' . print_r( $woocommerce->errors, true ) . '</pre>';

		exit;
	}

	function parse_postback() {
		if( isset( $_GET['user'] ) ) { // test the URL directly
			$postback['username'] = $_GET['user'];
			$postback['first_name'] = $_GET['first_name'];
			$postback['last_name'] = $_GET['last_name'];
			$postback['sku'] = $_GET['sku'];
			$postback['api_key'] = $_GET['api_key'];
		}
		else { // using the WP App Store postback data structure
			$postback['username'] = $_POST['customer']['email'];
			$postback['first_name'] = $_POST['customer']['first_name'];
			$postback['last_name'] = $_POST['customer']['last_name'];
			$postback['sku'] = $_POST['product']['sku'];
			$postback['api_key'] = $_POST['api_key'];
		}
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
		//$fields['billing']['billing_email']['required'] = false;
		$fields['billing']['billing_phone']['required'] = false;
		return $fields;
	}

	function temporarily_disable_payment() {
		return false;
	}

	function order_complete( $order_id ) {
		$order = new WC_Order( $order_id );
		$order->add_order_note( 'This order was automatically added as a result of a purchase originating at WP App Store.' );
		add_post_meta( $order_id, '_woocommerce_is_wpas_integration', true, true );
	}

}
?>