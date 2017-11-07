<?php
namespace Zao\QBO_API;

use Exception;
use WP_Error;

if ( ! class_exists( 'Zao\QBO_API\Connect' ) ) :

	if ( file_exists( 'vendor/autoload.php' ) ) {
		require_once 'vendor/autoload.php';
	}

	/**
	 * Connect to Quickbooks via OAuth 2.0
	 *
	 * Docs:
	 * API: https://developer.intuit.com/docs/api/
	 * API Sample Code: https://github.com/IntuitDeveloperRelations/SampleCodeSnippets/tree/master/APISampleCode/V3QBO
	 * SDK Docs: https://github.com/intuit/QuickBooks-V3-PHP-SDK/blob/master/README.md
	 *
	 * @author  Justin Sternberg <jt@zao.is>
	 * @package Connect
	 * @version 0.1.2
	 */
	class Connect {

		/**
		 * Connect version
		 */
		const VERSION = '0.1.3';

		/**
		 * Option storage
		 *
		 * @var Storage\Store_Interface
		 */
		protected $store;

		/**
		 * Error data storage
		 *
		 * @var Storage\Store_Interface
		 */
		protected $error_store;

		/**
		 * Discovery object
		 *
		 * @var Discover
		 */
		protected $discovery;

		/**
		 * The QuickBooks Online App Client ID and Secret, obtained from the developer dashboard.
		 *
		 * Required. Identifies which app is making the request. Obtain these values from
		 * the Keys tab on the app profile via My Apps on the developer site.
		 *
		 * There are two versions of these keys key:
		 * 	Development key—use only in the sandbox environment.
		 *  	Production key—use only in the production environment.
		 *
		 * @var string
		 */
		protected $client_id = '';
		protected $client_secret = '';

		/**
		 * The QuickBooks API URL. Will default to sandbox mode.
		 *
		 * for production: https://quickbooks.api.intuit.com/
		 *
		 * @var string
		 */
		protected $api_url = 'https://sandbox-quickbooks.api.intuit.com/';

		/**
		 * Whether client is in sandbox (developer testing) mode. Defaults to true.
		 *
		 * @var boolean
		 */
		protected $sandbox = true;

		/**
		 * Space-delimited set of permissions that the application requests.
		 * Defaults to the accounting scope.
		 *
		 * Identifies the QuickBooks API access that your application is requesting.
		 * The values passed in this parameter inform the consent screen that is shown to the user.
		 *
		 * @var string
		 */
		protected $scope = 'com.intuit.quickbooks.accounting';

		/**
		 * This app's authentication URI.
		 *
		 * Determines where the response is sent. The value of this parameter must exactly match
		 * one of the values listed for this app in the app settings. This includes the
		 * https scheme, the same case, and the trailing '/'. For the sandbox environment,
		 * this list can include http://localhost (do not use HTTPS with localhost).
		 * IP addresses are allowed for redirect URIs.
		 *
		 * @var string
		 */
		protected $callback_uri = '';

		/**
		 * Once authorized/authenticated, this is the token that must be used to access the QuickBooks API.
		 *
		 * @var string
		 */
		protected $access_token = '';

		/**
		 * Once authorized/authenticated, this is the token used when refreshing the access token.
		 *
		 * @var string
		 */
		protected $refresh_token  = '';

		/**
		 * Once authorized/authenticated, this is the Intuit assigned unique Id of the QuickBooks company,
		 * also referred to as the companyID.  For more information, see Realm ID.
		 *
		 * For use with Accounting API, only.
		 *
		 * @var string
		 */
		protected $realm_id       = '';

		/**
		 * Internal flag to inform processes that authorization is in progress.
		 *
		 * @var null
		 */
		protected $is_authorizing = null;

		/**
		 * Internal flag to inform processes that object initiation has completed.
		 *
		 * @var boolean
		 */
		protected $initiated      = false;

		/**
		 * Flag to determine if we should auto-redirect to the QB authentication
		 * if we have the Client ID and Client Secret.
		 *
		 * @var boolean
		 */
		protected $autoredirect_authoriziation = true;

		/**
		 * Flag to determine if we should reauthorize the connection.
		 *
		 * @since 0.1.2
		 *
		 * @var boolean
		 */
		protected $reauthorize_connection = false;

		/**
		 * Connect object constructor.
		 *
		 * @since 0.1.0
		 *
		 * @param array $storage_classes (optional) override the storage classes.
		 */
		public function __construct( $storage_classes = array() ) {
			$storage_classes = wp_parse_args( $storage_classes, array(
				'options_class' => 'Zao\QBO_API\Storage\Options',
				'transients_class' => 'Zao\QBO_API\Storage\Transients',
			) );

			$this->instantiate_storage_objects(
				new $storage_classes['options_class'](),
				new $storage_classes['options_class']( false )
			);

			$this->discovery = new Discover(
				new $storage_classes['transients_class']()
			);

			/**
			 * Provides method for refreshing the API token.
			 *
			 * @since 0.1.0
			 */
			add_filter( 'zao_qbo_api_connect_refresh_token', array( $this, 'request_refresh_token' ) );
		}

		/**
		 * Instantiates the storage objects for the options and transients.
		 *
		 * @since  0.1.0
		 *
		 * @param  Storage\Store_Interface $store       Option storage
		 * @param  Storage\Store_Interface $error_store Error option storage
		 */
		protected function instantiate_storage_objects(
			Storage\Store_Interface $store,
			Storage\Store_Interface $error_store
		) {
			$this->store       = $store->set_key( 'zqbo_apiconnect' );
			$this->error_store = $error_store->set_key( 'zqbo_apiconnect_error' );
		}

		/**
		 * Initate our connect object
		 *
		 * @since 0.1.0
		 *
		 * @param array $args Arguments containing 'client_id', 'client_secret',
		 *                    'callback_uri', 'sandbox', 'autoredirect_authoriziation',
		 *                    'reauthorize_connection'
		 */
		public function init( $args ) {
			foreach ( wp_parse_args( $args, array(
				'client_id'                   => $this->client_id,
				'client_secret'               => $this->client_secret,
				'callback_uri'                => $this->callback_uri,
				'sandbox'                     => $this->sandbox,
				'autoredirect_authoriziation' => $this->autoredirect_authoriziation,
				'reauthorize_connection'      => $this->reauthorize_connection,
			) ) as $key => $arg ) {
				$this->{$key} = $arg;
			}

			$this->set_object_properties();

			$this->api_url = $this->sandbox
				? 'https://sandbox-quickbooks.api.intuit.com/'
				: 'https://quickbooks.api.intuit.com/';

			$this->discovery
				->set_sandbox( $this->sandbox )
				->maybe_do_discovery();

			// If autoredirect is requested, and we are not yet authorized,
			// redirect to the other site to get authorization.
			$error = $this->maybe_redirect_to_authorization();

			// If authorization failed, we cannot proceed.
			if ( is_wp_error( $error ) ) {
				return $error;
			}

			$this->initiated = true;

			/**
			 * Provides a method for accesing connect object once initated.
			 *
			 * @since 0.1.0
			 *
			 * @param Zao\QBO_API\Connect $api The API connect object.
			 */
			do_action( 'qbo_connect_initiated', $this );

			// Ok, initiation is complete and successful.
			return $this->initiated;
		}

		/**
		 * Get the options from the DB and set the object properties.
		 *
		 * @since 0.1.0
		 */
		public function set_object_properties() {
			foreach ( $this->get_option() as $property => $value ) {
				if ( property_exists( $this, $property ) ) {
					$this->{$property} = $value;
				}
			}

			$creds = $this->get_option( 'token_credentials' );

			if ( is_object( $creds ) ) {
				$this->access_token = $creds->access_token;
				$this->refresh_token = $creds->refresh_token;
			}

			$this->realm_id = $this->get_option( 'realmId' );
		}

		/**
		 * Get the authorization (login) URL for the server with configured redirect.
		 *
		 * @since  0.1.0
		 *
		 * @return string|WP_Error Authorization URL or WP_Error.
		 */
		public function get_full_authorization_url() {
			$this->set_object_properties();

			if ( ! $this->client_id ) {
				return new WP_Error( 'qbo_connect_api_missing_client_data', __( 'Missing client key.', 'qbo-connect' ), $this->args() );
			}

			$this->set_callback_uri( $this->callback_uri ? $this->callback_uri : $this->get_requested_url() );

			$state = urlencode( 'step=authorize&nonce=' . wp_create_nonce( md5( __FILE__ ) ) );

			$url_params = array(
				'client_id'     => $this->client_id,
				'scope'         => $this->scope,
				'redirect_uri'  => urlencode( $this->callback_uri ),
				'response_type' => 'code',
				'state'         => $state,
			);

			$url = add_query_arg( $url_params, $this->request_authorize_url() );

			return $url;
		}

		/**
		 * Check if the authorization callback has been initiated.
		 *
		 * @since  0.1.0
		 *
		 * @return boolean
		 */
		public function is_authorizing() {
			if ( null !== $this->is_authorizing ) {
				return $this->is_authorizing;
			}

			$this->is_authorizing = false;

			// Nope, not trying to authorize.
			if ( ! isset(
				$_GET['state'],
				$_GET['code'],
				$_GET['realmId']
			) ) {
				return $this->is_authorizing;
			}

			parse_str( $_GET['state'], $to_verify );

			// Missing the proper state params.
			if (
				! isset(
					$to_verify['step'],
					$to_verify['nonce']
				)
				|| 'authorize' !== $to_verify['step']
				|| ! wp_verify_nonce( $to_verify['nonce'], md5( __FILE__ ) )
			) {
				return $this->is_authorizing;
			}

			$this->update_option( 'realmId', sanitize_text_field( $_GET['realmId'] ) );

			if ( empty( $_GET['error'] ) ) {
				// Ok, we're good to progress.

				$this->request_access( $_GET['code'] );
				$this->is_authorizing = true;

				return $this->is_authorizing;
			}

			// Let's figure out the error.
			switch ( $_GET['error'] ) {
				case 'access_denied':
					$error = 'The user did not authorize the request.';
					break;
				case 'invalid_scope':
					$error = 'An invalid scope string was sent in the request.';
					break;
				default:
					$error = 'unknown';
					break;
			}

			$error = new WP_Error(
				'qbo_connect_api_oauth_request_authorization_failed',
				sprintf( __( 'There was a problem completing the authorization request: "%s"', 'qbo-connect' ), $error ),
				$this->args()
			);

			$this->update_stored_error( $error );

			return $this->is_authorizing;
		}

		/**
		 * If autoredirect is enabled, and we are not yet authorized,
		 * redirect to the server to get authorization.
		 *
		 * @since  0.1.0
		 *
		 * @return bool|WP_Error  WP_Error is an issue, else redirects.
		 */
		public function maybe_redirect_to_authorization() {
			if (
				$this->autoredirect_authoriziation || $this->reauthorize_connection
				&& ! $this->is_authorizing()
				&& ( ! $this->token_acquired() || $this->reauthorize_connection )
			) {
				return $this->redirect_to_login();
			}

			return true;
		}

		/**
		 * Do the redirect to the authorization (login) URL.
		 *
		 * @since  0.1.0
		 *
		 * @return mixed  WP_Error if authorization URL lookup fails.
		 */
		public function redirect_to_login() {
			if ( ! $this->client_id ) {
				return new WP_Error( 'qbo_connect_api_missing_client_data', __( 'Missing client key.', 'qbo-connect' ), $this->args() );
			}

			$url = $this->get_full_authorization_url();
			if ( is_wp_error( $url ) ) {
				return $url;
			}

			// Second part of OAuth 1.0 authentication is to redirect the
			// resource owner to the login screen on the server.
			wp_redirect( $url );
			exit();
		}

		/**
		 * Exchange refresh token.
		 *
		 * @since  0.1.0
		 *
		 * @return mixed WP_Error if failure, else true
		 */
		public function request_refresh_token() {
			$creds = $this->get_option( 'token_credentials' );

			if ( empty( $creds->refresh_token ) ) {

				$error = new WP_Error( 'qbo_connect_api_missing_token_data', __( 'Missing authorization token credentials required for refreshing tokens.', 'qbo-connect' ), $this->args() );

				return $this->update_stored_error( $error );
			}

			$args = array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $creds->refresh_token,
			);

			return $this->_token_request( $args, array(
				'non_200_error' => array(
					'id' => 'qbo_connect_api_oauth_refresh_token_failed',
					'message_format' => __( "There was a problem refreshing the authentication token. Request response error code: %d, response body: %s", 'qbo-connect' ),
				),
				'json_read_error' => array(
					'id' => 'qbo_connect_api_oauth_refresh_token_failed',
					'message_format' => __( "There was a problem refreshing the authentication token: %s", 'qbo-connect' ),
				),
			) );
		}

		/**
		 * Exchange code for refresh and access tokens.
		 *
		 * After the app receives the authorization code,
		 * it exchanges the authorization code for refresh and access tokens.
		 *
		 * @since  0.1.0
		 *
		 * @param  string  $auth_code
		 * @return mixed   WP_Error if failure, else redirect to callback_uri.
		 */
		public function request_access( $auth_code ) {
			$args = array(
				'grant_type'   => 'authorization_code',
				'code'         => $auth_code,
				'redirect_uri' => $this->callback_uri,
			);

			$result = $this->_token_request( $args, array(
				'non_200_error' => array(
					'id' => 'qbo_connect_api_oauth_request_access_failed',
					'message_format' => __( "There was a problem completing authorization. Request response error code: %d, response body: %s", 'qbo-connect' ),
				),
				'json_read_error' => array(
					'id' => 'qbo_connect_api_oauth_request_access_failed',
					'message_format' => __( "There was a problem completing authorization: %s", 'qbo-connect' ),
				),
			) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			wp_redirect( $this->callback_uri );
			exit();
		}

		/**
		 * Make request for access token.
		 *
		 * @since  0.1.0
		 *
		 * @param  array  $args   Body request args.
		 * @param  array  $errors Error codes/descriptions if request fails.
		 *
		 * @return mixed
		 */
		protected function _token_request( $args, $errors ) {
			$this->set_callback_uri( $this->callback_uri ? $this->callback_uri : $this->get_requested_url() );

			$gotten = wp_remote_post( $this->request_token_url(), array(
				'headers' => $this->_token_request_headers(),
				'body'    => $args,
			) );

			$code  = wp_remote_retrieve_response_code( $gotten );
			$body  = wp_remote_retrieve_body( $gotten );
			$error = '';

			if ( 200 !== $code ) {

				$error = new WP_Error(
					$errors['non_200_error']['id'],
					sprintf( $errors['non_200_error']['message_format'], $code, $body ),
					$this->args()
				);

			} else {
				try {
					$this->update_option(
						'token_credentials',
						self::get_json_if_json( $body )
					);
				} catch ( Exception $e ) {
					$error = new WP_Error(
						$errors['json_read_error']['id'],
						sprintf( $errors['json_read_error']['message_format'], $e->getMessage() ),
						$this->args()
					);
				}
			}

			if ( is_wp_error( $error ) ) {
				return $this->update_stored_error( $error );
			}

			return $this->get_option( 'token_credentials' );
		}

		/**
		 * Required headers for the token request to the QuickBooks Online Authentication API.
		 *
		 * @since  0.1.0
		 *
		 * @return array
		 */
		protected function _token_request_headers() {
			$auth = 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret );
			return array(
				'Authorization' => $auth,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/x-www-form-urlencoded',
			);
		}

		/**
		 * Gets authorized company. Useful for testing authenticated connection.
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Company object or WP_Error object.
		 */
		public function get_company_info() {
			return $this->get_qb_data_service()->get_company_info();
		}

		/**
		 * Gets a QuickBooksOnline\API\DataService\DataService object wrapper.
		 *
		 * @since  0.1.0
		 *
		 * @return Service
		 */
		public function get_qb_data_service() {
			return new Service( $this->get_qb_data_service_args() );
		}

		/**
		 * Gets the arguments necessary for the
		 * QuickBooksOnline\API\DataService\DataService construct.
		 *
		 * @since  0.1.0
		 *
		 * @return Service
		 */
		public function get_qb_data_service_args() {
			return array(
				'ClientID'        => $this->client_id,
				'ClientSecret'    => $this->client_secret,
				'accessTokenKey'  => $this->access_token,
				'refreshTokenKey' => $this->refresh_token,
				'QBORealmID'      => $this->realm_id,
				'baseUrl'         => $this->api_url,
			);
		}

		/**
		 * Get the current URL
		 *
		 * @since  0.1.0
		 *
		 * @return string current URL
		 */
		public function get_requested_url() {
			$scheme = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] ) ? 'https' : 'http';
			$here = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
				// Strip the query string
				$here = str_replace( '?' . $_SERVER['QUERY_STRING'], '', $here );
			}

			return $here;
		}

		/**
		 * Tests whether the tokens were acquired.
		 * Only here for back-compat, but is not a technically correct term.
		 *
		 * @since  0.1.0
		 *
		 * @return bool
		 */
		public function connected() {
			return $this->token_acquired();
		}

		/**
		 * Tests whether the tokens were acquired.
		 *
		 * @since  0.1.3
		 *
		 * @return bool
		 */
		public function token_acquired() {
			return (bool) $this->get_option( 'token_credentials' );
		}

		/**
		 * Get current object data for debugging.
		 *
		 * @since  0.1.0
		 *
		 * @return array
		 */
		public function args() {
			return array(
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'api_url'       => $this->api_url,
				'auth_urls'     => $this->discovery->auth_urls,
				'callback_uri'  => $this->callback_uri,
				'access_token'  => $this->access_token,
				'refresh_token' => $this->refresh_token,
				'realm_id'      => $this->realm_id,
			);
		}

		/**
		 * Sets the api_url object property
		 *
		 * @since 0.1.0
		 *
		 * @param string  $value Value to set
		 */
		public function set_api_url( $value ) {
			$this->api_url = $value;
			return $this->api_url;
		}

		/**
		 * Sets the scope property
		 *
		 * @since 0.1.0
		 *
		 * @param string  $scope Scope to set
		 */
		public function set_scope( $scope ) {
			$this->scope = $scope;
			return $this->scope;
		}

		/**
		 * Sets the callback_uri object property
		 *
		 * @since 0.1.0
		 *
		 * @param string  $value Value to set
		 */
		public function set_callback_uri( $value ) {
			$this->callback_uri = $value;
			return $this->callback_uri;
		}

		/**
		 * Get the api_url and append included path
		 *
		 * @since  0.1.0
		 *
		 * @param  string  $path Option path to append
		 *
		 * @return string        REST request URL
		 */
		public function api_url( $path = '' ) {
			// Make sure we only have a path
			$path = str_ireplace( $this->api_url, '', $path );
			$path = ltrim( $path, '/' );
			return $path ? trailingslashit( $this->api_url ) . $path : $this->api_url;
		}

		/**
		 * Gets the request URL from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Request URL or error
		 */
		public function request_token_url() {
			return $this->discovery->auth_urls->token_endpoint;
		}

		/**
		 * Gets the authorization base URL from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Authorization URL or error
		 */
		public function request_authorize_url() {
			return $this->discovery->auth_urls->authorization_endpoint;
		}

		/**
		 * Retrieve stored option
		 *
		 * @since  0.1.0
		 *
		 * @param  string  $option Option array key
		 * @param  string  $key    Key for secondary array
		 * @param  boolean $force  Force a new call to get_option
		 *
		 * @return mixed           Value of option requested
		 */
		public function get_option( $option = 'all' ) {
			return $this->store->get( $option );
		}

		/**
		 * Update the options array
		 *
		 * @since  0.1.0
		 *
		 * @param  string  $option Option array key
		 * @param  mixed   $value  Value to be updated
		 * @param  boolean $set    Whether to set the updated value in the DB.
		 *
		 * @return                 Original $value if successful
		 */
		public function update_option( $option, $value, $set = true ) {
			return $this->store->update( $value, $option, '', $set );
		}

		/**
		 * Handles deleting the stored data for a connection
		 *
		 * @since  0.1.0
		 *
		 * @return bool  Result of delete_option
		 */
		public function delete_option( $option = '' ) {
			$this->discovery->delete_transient();
			return $this->store->delete( $option );
		}

		/**
		 * Fetches the zqbo_apiconnect_error message.
		 *
		 * @since  0.1.0
		 *
		 * @return string Stored error message value.
		 */
		public function get_stored_error_message() {
			$errors = $this->get_stored_error();
			return isset( $errors['message'] ) ? $errors['message'] : '';
		}

		/**
		 * Fetches the zqbo_apiconnect_error request_args.
		 *
		 * @since  0.1.0
		 *
		 * @return string Stored error request_args value.
		 */
		public function get_stored_error_request_args() {
			$errors = $this->get_stored_error();
			return isset( $errors['request_args'] ) ? $errors['request_args'] : '';
		}

		/**
		 * Fetches the zqbo_apiconnect_error option value.
		 *
		 * @since  0.1.0
		 *
		 * @return mixed  zqbo_apiconnect_error option value.
		 */
		public function get_stored_error() {
			return $this->error_store->get();
		}

		/**
		 * Updates/replaces the zqbo_apiconnect_error option.
		 *
		 * @since  0.1.0
		 *
		 * @param  string  $error Error message
		 *
		 * @return void
		 */
		public function update_stored_error( $error = '' ) {
			if ( '' !== $error ) {
				$this->error_store->set( array(
					'message'      => is_wp_error( $error ) ? $error->get_error_message() : $error,
					'request_args' => print_r( $this->args(), true ),
				) );

				return ! is_wp_error( $error )
					? new WP_Error( 'zqbo_apiconnect_error', $error, $this->args() )
					: $error;
			} else {
				$this->delete_stored_error();
			}

			return true;
		}

		/**
		 * Fetches the zqbo_apiconnect_error option value
		 *
		 * @since  0.1.0
		 *
		 * @return mixed  zqbo_apiconnect_error option value
		 */
		public function delete_stored_error() {
			return $this->error_store->delete();
		}

		/**
		 * Deletes all stored data for this connection.
		 *
		 * @since  0.1.0
		 */
		public function reset_connection() {
			$deleted = $this->delete_option();
			return $this->delete_stored_error() && $deleted;
		}

		/**
		 * Determines if a string is JSON, and if so, decodes it and returns it. Else returns unchanged body object.
		 *
		 * @since  0.1.0
		 *
		 * @param  string $body   HTTP retrieved body
		 *
		 * @return mixed  Decoded JSON object or unchanged body
		 */
		public static function get_json_if_json( $body ) {
			$json = $body ? self::is_json( $body ) : false;
			return $body && $json ? $json : $body;
		}

		/**
		 * Determines if a string is JSON, and if so, decodes it.
		 *
		 * @since  0.1.0
		 *
		 * @param  string $string String to check if is JSON
		 *
		 * @return boolean|array  Decoded JSON object or false
		 */
		public static function is_json( $string ) {
			$json = is_string( $string ) ? self::json_decode( $string ) : false;
			return $json && ( is_object( $json ) || is_array( $json ) )
				? $json
				: false;
		}

		/**
		 * Wrapper for json_decode that throws when an error occurs.
		 * Stolen from GuzzleHttp.
		 *
		 * @link  https://github.com/guzzle/guzzle/blob/113071af3b2b9eb96b05dab05472cbaed64a3df4/src/functions.php
		 *
		 * @param string $json    JSON data to parse
		 * @param bool $assoc     When true, returned objects will be converted
		 *                        into associative arrays.
		 * @param int    $depth   User specified recursion depth.
		 * @param int    $options Bitmask of JSON decode options.
		 *
		 * @return mixed
		 * @throws \InvalidArgumentException if the JSON cannot be decoded.
		 * @link http://www.php.net/manual/en/function.json-decode.php
		 */
		public static function json_decode( $json, $assoc = false, $depth = 512, $options = 0 ) {
			$data = \json_decode( $json, $assoc, $depth, $options );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				throw new \InvalidArgumentException(
					'json_decode error: ' . json_last_error_msg()
				);
			}

			return $data;
		}

		/**
		 * Magic getter for our object.
		 *
		 * @param string $field
		 *
		 * @throws Exception Throws an exception if the field is invalid.
		 *
		 * @return mixed
		 */
		public function __get( $field ) {
			switch ( $field ) {
				case 'auth_urls':
					return $this->discovery->auth_urls;
				case 'token_credentials':
					return $this->get_option( $field );
				default:
					return $this->{$field};
			}
		}

	}

endif;
