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
		
		echo '<pre>' . print_r( get_post_custom( 100 ), true ) . '</pre>';
		exit;

		$postback = $this->parse_postback();

		$user = get_user_by( 'login', $postback['username'] );

		// user exists in the system
		if( $user !== false ) {
			$user_id = $user->ID;
		}
		else {
			// user does not exist, create a new user account for them
			$user_id = wp_create_user( $postback['username'], wp_generate_password(), $postback['username'] );
		}



		exit;
	}

	function parse_postback() {
		$postback['username'] = $_GET['username'];
		return $postback;
	}

}
?>