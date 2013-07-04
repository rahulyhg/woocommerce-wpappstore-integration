<?php
class WC_WPAS {
	private $api_key;
	private $sku_prefix;
	private $email_errors;

	function __construct() {
		// We are extending the WooCommerce API here, so your postback URL will be:
		// http://yoursite.com/wc-api/wpappstore-integration/
		add_action( 'woocommerce_api_wpappstore-integration', array( $this, 'process_postback' ) );

		// hardcoded api key - verify that the request is coming from a trusted source
		$this->api_key = '';

		// SKU prefix that you use on all your products in WP App Store
		$this->sku_prefix = '';

		// Email address where you'd like to receive errors
		$this->email_errors = '';
	}

	function process_postback() {
		global $woocommerce;

		$postback = $this->parse_postback();

		if( $postback['api_key'] != $this->api_key ) wp_die( 'Cheatin\' eh?' );

		$_POST['billing_email'] = $postback['username'];
		$_POST['billing_first_name'] = $postback['first_name'];
		$_POST['billing_last_name'] = $postback['last_name'];

		// Hook in our custom actions and filters
		add_filter( 'woocommerce_checkout_fields' , array( $this, 'temporarily_remove_required_fields' ) );
		add_filter( 'woocommerce_cart_needs_payment' , array( $this, 'temporarily_disable_payment' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'order_complete' ) );

		$user = get_user_by( 'login', $postback['username'] );

		// User exists in the system
		if( $user !== false ) {
			$user_id = $user->ID;
			// Log the user in
			wp_set_current_user( $user_id, $postback['username'] );
			wp_set_auth_cookie( $user_id );
			do_action( 'wp_login', $postback['username'] );
		}
		else {
			// User does not exist, create a new user account for them (let Woo handle it though)
			$password = wp_generate_password();
			$_POST['account_password'] = $password;
			$_POST['account_password-2'] = $password;
		}

		// Required, otherwise WooCommerce rejects the request
		$_REQUEST['_n'] = wp_create_nonce( 'woocommerce-process_checkout' );

		// It's possible that a session was manually created by a logged in user, if so we clear the cart for good measure
		$woocommerce->cart->empty_cart();

		// Here's where the magic happens, adds the product to the cart and processes the checkout
		// All the regular WooCommerce filters and actions are added so any licenses / subscriptions / email are also processed
		$woocommerce->cart->add_to_cart( str_replace( $this->sku_prefix, '', $postback['sku'] ), 1 );
		$checkout = $woocommerce->checkout();
		$checkout->process_checkout();

		// remove the temporary filters / actions, not sure if it's completely required, here for good measure anyway
		remove_filter( 'woocommerce_checkout_fields' , array( $this, 'temporarily_remove_required_fields' ) );
		remove_filter( 'woocommerce_cart_needs_payment' , array( $this, 'temporarily_disable_payment' ) );
		remove_action( 'woocommerce_payment_complete', array( $this, 'order_complete' ) );

		if ( $this->email_errors && $woocommerce->errors ) {
			mail( $this->email_errors, 'WP App Store postback error', print_r( $woocommerce->errors, true ) );
		}

		// check for errors
		// echo '<pre>' . print_r( $woocommerce->errors, true ) . '</pre>';

		exit;
	}

	// Here we parse the postback into a common format
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

	// Most of the billing information is not provided by the WP App Store postback
	// we disable the required fields here so that we don't run into errors later
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

	// The payment is processed via WP App Store, no need to process additional payment via WooCommerce
	function temporarily_disable_payment() {
		return false;
	}

	// The order is completed, here you can add custom code to add special comments / metadata
	// You may also want to do some custom actions, i.e. sending additional email notifications etc
	function order_complete( $order_id ) {
		$order = new WC_Order( $order_id );
		$order->add_order_note( 'This order was automatically added as a result of a purchase originating at WP App Store.' );
		add_post_meta( $order_id, '_woocommerce_is_wpas_integration', true, true );
	}

}
?>