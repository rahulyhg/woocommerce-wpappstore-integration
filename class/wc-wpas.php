<?php
class WC_WPAS {
	private $api_key;
	private $postback;
	private $user_was_created = false;
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
		$mailer = $woocommerce->mailer();

		$postback = $this->parse_postback();
		$this->postback = $postback;

		if( $postback['api_key'] != $this->api_key ) wp_die( 'Cheatin\' eh?' );

		$_POST['billing_email'] = $postback['username'];
		$_POST['billing_first_name'] = $postback['first_name'];
		$_POST['billing_last_name'] = $postback['last_name'];

		$_POST['terms'] = 1; // Accept terms & conditions

		// Hook in our custom actions and filters
		add_filter( 'woocommerce_checkout_fields' , array( $this, 'temporarily_remove_required_fields' ) );
		add_filter( 'woocommerce_cart_needs_payment' , array( $this, 'temporarily_disable_payment' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'order_complete' ) );
		remove_action( 'woocommerce_order_status_completed_notification', array( $mailer->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );

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
			$this->user_was_created = true;
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
			$postback['commission'] = $_GET['commission'];
		}
		else { // using the WP App Store postback data structure
			$postback['username'] = $_POST['customer']['email'];
			$postback['first_name'] = $_POST['customer']['first_name'];
			$postback['last_name'] = $_POST['customer']['last_name'];
			$postback['sku'] = $_POST['product']['sku'];
			$postback['api_key'] = $_POST['api_key'];
			$postback['commission'] = $_POST['commission']['amount'];
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
		update_post_meta( $order_id, '_order_total', $this->postback['commission'] );
		$order = new WC_Order( $order_id );
		$order->add_order_note( 'Order created from a WP App Store postback.' );
		add_post_meta( $order_id, '_woocommerce_is_wpas_integration', true, true );
		$this->send_order_complete_email( $order );
	}

	function send_order_complete_email( $order ) {
		global $woocommerce, $wpdb;
		$order_date = date_i18n( woocommerce_date_format(), strtotime( $order->order_date ) );
		$blog_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$subject = sprintf( 'Your %s order from %s is complete - download your files', $blog_name, $order_date );
		$headers = "Content-Type: text/html\r\n";
		$to = $order->billing_email;

		$email_heading = 'Your order is complete - download your files';

		ob_start();
		woocommerce_get_template( 'emails/email-header.php', array( 'email_heading' => $email_heading ) );
		?>

		<p><?php printf( __( "Hi there. Your recent order on %s has been completed. Your order details are shown below for your reference:", 'woocommerce' ), get_option( 'blogname' ) ); ?></p>

		<?php do_action('woocommerce_email_before_order_table', $order, false); ?>

		<h2><?php echo __( 'Order:', 'woocommerce' ) . ' ' . $order->get_order_number(); ?></h2>

		<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
			<thead>
				<tr>
					<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Product', 'woocommerce' ); ?></th>
					<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php _e( 'Download', 'woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php

				$items = $order->get_items();
				$show_download_links = true;
				foreach( $items as $item ) :

					// Get/prep product data
					$_product = $order->get_product_from_item( $item );
					?>
					<tr>
						<td style="text-align:left; vertical-align:middle; border: 1px solid #eee;"><?php
							echo 	apply_filters( 'woocommerce_order_product_title', $item['name'], $_product );
						?>
						</td>
						<td style="text-align:left; vertical-align:middle; border: 1px solid #eee;">
							<?php
							// File URLs
							if ( $show_download_links && $_product->exists() && $_product->is_downloadable() ) {
								$download_file_urls = $order->get_downloadable_file_urls( $item['product_id'], $item['variation_id'], $item );
								foreach ( $download_file_urls as $file_url => $download_file_url ) {
									echo '<a href="' . $download_file_url . '" target="_blank">' . preg_replace( '/\?.*/', '', basename( $file_url ) ) . '</a>';
								}
							}
							?>
						</td>
					</tr>

				<?php endforeach; ?>
			</tbody>
		</table>

		<?php do_action('woocommerce_email_after_order_table', $order, false); ?>

		<?php do_action( 'woocommerce_email_order_meta', $order, false ); ?>

		<?php

		if( $this->user_was_created ) :

			$key = $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM $wpdb->users WHERE user_login = %s", $order->billing_email ) );

			if ( empty( $key ) ) {

				// Generate something random for a key...
				$key = wp_generate_password( 20, false );

				do_action('retrieve_password_key', $order->billing_email, $key);

				// Now insert the new md5 key into the db
				$wpdb->update( $wpdb->users, array( 'user_activation_key' => $key ), array( 'user_login' => $order->billing_email ) );
			}

			$lost_password_link = home_url( $path = '/my-account/lost-password/' );
			$args = array(
				'key'	=> $key,
				'login'	=> $order->billing_email,
			);
			$lost_password_link = add_query_arg( $args, $lost_password_link );

		endif;

		?>

		<h2><?php _e( 'Login details', 'woocommerce' ); ?></h2>

		<?php if ($order->billing_email) : ?>
			<p><strong><?php _e( 'Email:', 'woocommerce' ); ?></strong> <?php echo $order->billing_email; ?></p>
			<?php if( $this->user_was_created ) : ?>
			<p>You may reset your password <a href="<?php echo $lost_password_link; ?>">here</a>.</p>
			<?php else : ?>
			<p>You may log in <a href="<?php echo home_url( '/my-account/'); ?>">here</a>.</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		woocommerce_get_template( 'emails/email-footer.php' );

		$message = ob_get_clean();

		add_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );
		add_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ) );

		wp_mail( $to, $subject, $message, $headers );

		remove_filter( 'wp_mail_from', array( $this, 'get_from_address' ) );
		remove_filter( 'wp_mail_from_name', array( $this, 'get_from_name' ) );
		remove_filter( 'wp_mail_content_type', array( $this, 'get_content_type' ) );
	}

	function get_from_name() {
		return wp_specialchars_decode( esc_html( get_option( 'woocommerce_email_from_name' ) ) );
	}

	function get_from_address() {
		return sanitize_email( get_option( 'woocommerce_email_from_address' ) );
	}

	function get_content_type() {
		return 'text/html';
	}

}