<?php
namespace Zao\QBO_API;

class Discover {

	const SANDBOX_DISCOVERY_URL = 'https://developer.api.intuit.com/.well-known/openid_sandbox_configuration';
	const DISCOVERY_URL     = 'https://developer.api.intuit.com/.well-known/openid_configuration';

	protected $auth_urls = array(
		'issuer'                   => 'https://oauth.platform.intuit.com/op/v1',
		'authorization_endpoint'   => 'https://appcenter.intuit.com/connect/oauth2',
		'token_endpoint'           => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
		'userinfo_endpoint'        => 'https://sandbox-accounts.platform.intuit.com/v1/openid_connect/userinfo',
		'revocation_endpoint'      => 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke',
		'jwks_uri'                 => 'https://oauth.platform.intuit.com/op/v1/jwks',
	);

	protected $sandbox = true;

	/**
	 * Transients Store
	 *
	 * @var Storage\Transient_Store_Interface
	 */
	protected $transient;

	public function __construct( Storage\Transient_Store_Interface $transient ) {
		$this->transient = $transient
			->set_key( 'qbo_api_connect_discovery' )
			->set_expiration( WEEK_IN_SECONDS );

		$this->auth_urls = (object) $this->auth_urls;
	}

	public function set_sandbox( $sandbox ) {
		$this->sandbox = !! $sandbox;
		return $this;
	}

	public function maybe_do_discovery() {
		$discovery = $this->transient->get();

		// If not found, or expired, re-fetch discovery.
		if ( ! $discovery || $this->transient->is_expired() ) {

			$url = $this->sandbox
				? self::SANDBOX_DISCOVERY_URL
				: self::DISCOVERY_URL;

			$gotten = wp_remote_get( $url );
			$code = wp_remote_retrieve_response_code( $gotten );

			if ( 200 === $code ) {
				try {
					$discovery = Connect::get_json_if_json( wp_remote_retrieve_body( $gotten ) );

					$this->transient->set( $discovery );
					$this->auth_urls = (object) $discovery;

				} catch ( \Exception $e ) {}
			}

		} else {
			$this->auth_urls = $discovery;
		}

		return $this;
	}

	public function delete_transient() {
		return $this->transient->delete();
	}

	/**
	 * Magic getter for our object.
	 *
	 * @since  0.1.0
	 * @param string $field
	 * @throws Exception Throws an exception if the field is invalid.
	 * @return mixed
	 */
	public function __get( $field ) {
		switch ( $field ) {
			case 'auth_urls':
			case 'transient':
				return $this->$field;
			default:
				throw new \Exception( 'Invalid '. __CLASS__ .' property: ' . $field );
		}
	}

}
