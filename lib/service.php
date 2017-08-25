<?php
namespace Zao\QBO_API;

use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Data;
use Exception;
use WP_Error;

/**
 * Docs:
 * API: https://developer.intuit.com/docs/api/
 * API Sample Code: https://github.com/IntuitDeveloperRelations/SampleCodeSnippets/tree/master/APISampleCode/V3QBO
 * SDK Docs: https://github.com/intuit/QuickBooks-V3-PHP-SDK/blob/master/README.md
 */
class Service {

	protected $service = null;
	protected $args = array();

	public function __construct( array $args ) {
		$this->args = wp_parse_args( $args, array(
			'auth_mode'       => 'oauth2',
			'ClientID'        => '',
			'ClientSecret'    => '',
			'accessTokenKey'  => '',
			'refreshTokenKey' => '',
			'QBORealmID'      => '',
			'baseUrl'         => 'https://sandbox-quickbooks.api.intuit.com/',
		) );
	}

	/**
	 * Returns QuickBooksOnline\API\DataService\DataService object.
	 *
	 * @since  0.1.0
	 *
	 * @param  $reset
	 * @return QuickBooksOnline\API\DataService\DataService
	 */
	public function get_service( $reset = false ) {
		if ( null === $this->service || $reset ) {
			$this->service = DataService::Configure( $this->args );
			$location = apply_filters( 'zao_qbo_api_connect_log_location', '' );
			if ( $location ) {
				$this->service->setLogLocation( $location );
			}
		}

		return $this->service;
	}

	public function query( $query ) {
		return $this->call_or_refresh_token( array( $this->get_service(), 'Query' ), array( $query ) );
	}

	public function get_preferences() {
		return $this->call_or_refresh_token( array( $this, '_get_preferences' ) );
	}

	protected function _get_preferences() {
		$preferences = $this->get_service()->FindById( new Data\IPPPreferences );
		$error       = $this->get_service()->getLastError();

		if ( $error ) {
			return new WP_Error(
				'wc_qbo_integration_preferences_fail',
				__( 'Could not find the Quickbooks preferences.', 'qbo-connect' ),
				$error
			);
		}

		return $preferences;
	}

	public function get_company_info() {
		return $this->call_or_refresh_token( array( $this, '_get_company_info' ) );
	}

	protected function _get_company_info() {
		try {

			$company_info = $this->get_service()->getCompanyInfo();
			$error        = $this->get_service()->getLastError();

			if ( $error ) {
				// echo "<p>The Status code is: " . $error->getHttpStatusCode() . "\n</p>";
				// echo "<p>The Helper message is: " . $error->getOAuthHelperError() . "\n</p>";
				// echo "<p>The Response message is: " . $error->getResponseBody() . "\n</p>";

				return new WP_Error(
					'wc_qbo_integration_company_fail',
					__( 'Could not find the Quickbooks company.', 'qbo-connect' ),
					$error
				);
			}

			return $company_info;

		} catch ( Exception $e ) {
			return new WP_Error(
				'wc_qbo_integration_company_fail',
				__( 'Could not find the Quickbooks company.', 'qbo-connect' ),
				$e
			);
		}
	}

	public function __call( $method, $args ) {
		$to_call = array( $this->get_service(), $method );
		if ( 0 === strpos( $method, 'create_' ) ) {
			$facade_class = $this->get_facade_class_from_method( 'create_', $method );
			if ( $facade_class ) {
				$to_call = array( $this, 'facade_create' );
				$args = array( $facade_class, $args );
			}
		} elseif ( 0 === strpos( $method, 'update_' ) ) {
			$facade_class = $this->get_facade_class_from_method( 'update_', $method );
			if ( $facade_class ) {
				$to_call = array( $this, 'facade_update' );
				$args = array( $facade_class, $args );
			}
		}

		$result = $this->call_or_refresh_token( $to_call, $args );

		return $result;
	}

	protected function facade_create( $facade_class, $args) {
		$created_obj = call_user_func_array( array( $facade_class, 'create' ), $args );

		return array( $created_obj, $this->get_service()->Add( $created_obj ) );
	}

	protected function facade_update( $facade_class, $args ) {
		$updated_obj = call_user_func_array( array( $facade_class, 'update' ), $args );

		return array( $updated_obj, $this->get_service()->Update( $updated_obj ) );
	}

	public function get_facade_class_from_method( $prefix, $method ) {
		return $this->is_facade_class(
			ucfirst( str_replace( $prefix, '', $method ) )
		);
	}

	public function is_facade_class( $facade_class ) {
		$facade_class = '\\QuickBooksOnline\\API\\Facades\\' . $facade_class;

		return class_exists( $facade_class ) ? $facade_class : false;
	}

	public function call_or_refresh_token( $to_call, $args = null ) {
		$result = $this->call_func( $to_call, $args );

		if ( $this->check_if_needing_to_refresh_token() ) {
			$refreshed = $this->refresh_token();
			if ( $refreshed ) {
				$result = $this->call_func( $to_call, $args );
			}
		}

		return $result;
	}

	public function call_func( $to_call, $args = null ) {
		return ! is_array( $args )
			? call_user_func( $to_call )
			: call_user_func_array( $to_call, $args );
	}

	public function check_if_needing_to_refresh_token() {
		$error = $this->get_service()->getLastError();

		return $error
			&& is_callable( array( $error, 'getHttpStatusCode' ) )
			&& 401 === $error->getHttpStatusCode();
	}

	public function refresh_token() {
		$token = apply_filters( 'zao_qbo_api_connect_refresh_token', false );

		if ( ! isset( $token->access_token, $token->refresh_token ) ) {
			return false;
		}

		$this->args['accessTokenKey']  = $token->access_token;
		$this->args['refreshTokenKey'] = $token->refresh_token;

		return $this->get_service( true );
	}

}
