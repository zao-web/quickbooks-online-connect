<?php
namespace Zao\QBO_API;

use QuickBooksOnline\API\DataService\DataService;
use Exception;
use WP_Error;

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
	 * @return QuickBooksOnline\API\DataService\DataService
	 */
	public function get_service() {
		if ( null === $this->service ) {
			$this->service = DataService::Configure( $this->args );
			$this->service->setLogLocation( dirname( ini_get( 'error_log' ) ) );
		}

		return $this->service;
	}

	public function get_company_info() {
		try {

			$company_info = $this->get_service()->getCompanyInfo();
			$error        = $this->get_service()->getLastError();

			if ( $error ) {
				// echo "<p>The Status code is: " . $error->getHttpStatusCode() . "\n</p>";
				// echo "<p>The Helper message is: " . $error->getOAuthHelperError() . "\n</p>";
				// echo "<p>The Response message is: " . $error->getResponseBody() . "\n</p>";

				return new WP_Error(
					'wc_qbo_integration_company_fail',
					__( 'Could not find the Quickbooks company.', 'zwqoi' ),
					$error
				);
			}

			return $company_info;

		} catch ( Exception $e ) {
			return new WP_Error(
				'wc_qbo_integration_company_fail',
				__( 'Could not find the Quickbooks company.', 'zwqoi' ),
				$e
			);
		}
	}

}
