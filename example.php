<?php
// Make sure you run `composer install`!
// include the library.
require_once( 'qbo-connect.php' );

/**
 * Example Zao\QBO_API\Connect usage
 * To test it out, go to your site's WP dashboard:
 * YOURSITE-URL/wp-admin/?api-connect
 */
function qp_api_initiate_sample_connection() {
	if ( ! isset( $_GET['api-connect'] ) ) {
		return;
	}

	global $api_connect; // hold this in a global for demonstration purposes.

	// Output our errors/notices in the admin dashboard.
	add_action( 'all_admin_notices', 'qp_api_show_sample_connection_notices' );

	// Get the connect object
	$api_connect = new Zao\QBO_API\Connect();

	// Consumer credentials
	$client = array(
		// App credentials set up on the server
		'client_id'     => 'YOUR CLIENT KEY',
		'client_secret' => 'YOUR CLIENT SECRET',
		// Must match stored callback URL setup on server.
		'callback_uri'  => admin_url() . '?api-connect',
		// Test in sandbox mode.
		'sandbox'       => true,
		// 'autoredirect_authoriziation' => false,
	);

	/*
	 * Initate the API connection.
	 *
	 * if the oauth connection is not yet authorized, (and autoredirect_authoriziation
	 * is not explicitly set to false) you will be auto-redirected to the other site to
	 * receive authorization.
	 */
	$initiated = $api_connect->init( $client );

	// Remove old errors
	$api_connect->delete_stored_error();

	// If you need to reset the stored connection data for any reason:
	if ( isset( $_GET['reset-connection'] ) ) {
		$api_connect->reset_connection();
		wp_die( 'Connection deleted. <a href="'. esc_url( remove_query_arg( 'reset-connection' ) ) .'">Try again?</a>' );
	}

	// If oauth discovery failed, the WP_Error object will explain why.
	if ( is_wp_error( $initiated ) ) {
		// Save this error to the library's error storage (to output as admin notice)
		return $api_connect->update_stored_error( $initiated );
	}

	/*
	 * if autoredirect_authoriziation IS set to false, you'll need to use the
	 * authorization URL to redirect the user to login for authorization.
	 */
	// $authorization_url = $api_connect->get_full_authorization_url();
	// wp_redirect( $authorization_url );
	// exit();
	//
	// // OR:
	// $maybe_error = $api_connect->redirect_to_login();
	// if ( is_wp_error( $maybe_error ) ) {
	// 	wp_die( $authorization_url->get_error_message(), $authorization_url->get_error_code() );
	// }
}
add_action( 'admin_init', 'qp_api_initiate_sample_connection' );

function qp_api_show_sample_connection_notices() {
	global $api_connect;

	/*
	 * If something went wrong in the process, errors will be stored.
	 * We can fetch them this way.
	 */
	if ( $api_connect->get_stored_error() ) {

		$message = '<div id="message" class="error"><p><strong>Error Message:</strong> ' . $api_connect->get_stored_error_message() . '</p></div>';
		$message .= '<div id="message" class="error"><p><strong>Error request arguments:</strong></p><xmp>' . $api_connect->get_stored_error_request_args() . '</xmp></div>';

		// Output message, and bail.
		return print( $message );
	}

	$reset_button = '<p><a class="button-secondary" href="'. add_query_arg( 'reset-connection', true ) .'">' . __( 'Reset Connection', 'rest-connect-ui' ) . '</a></p>';

	$company = $api_connect->get_company_info();

	if ( is_wp_error( $company ) ) {

		echo '<div id="message" class="error">';
		echo wpautop( $company->get_error_message() );
		echo '</div>';

	} else {

		$props = array();
		foreach ( get_object_vars( $company ) as $prop_name => $prop_value ) {
			$props[] = '<tr><td>'. print_r( $prop_name, true ) .'</td><td>'. print_r( $prop_value, true ) .'</td></tr>';
		}

		echo '<div id="message" class="updated">';
		echo '
		<table class="wp-list-table widefat">
			<thead>
				<tr>
					<th>' . __( 'Connected Company Name', 'qbo-connect-ui' ) . '</th>
					<th>' . __( 'Connected Company ID', 'qbo-connect-ui' ) . '</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>'. esc_html( $company->CompanyName ) .'</td>
					<td>'. esc_html( $company->Id ) .'</td>
				</tr>
			</tbody>
		</table>
		<br>
		<table class="wp-list-table widefat">
			<thead>
				<tr>
					<th>'. __( 'Company Property:', 'qbo-connect-ui' ) .'</th>
					<th>'. __( 'Company Property Value:', 'qbo-connect-ui' ) .'</th>
				</tr>
			</thead>
			<tbody>
				'. implode( "\n", $props ) .'
			</tbody>
		</table>
		';
		echo '</div>';
	}
}
