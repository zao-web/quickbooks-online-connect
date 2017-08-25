<?php
namespace Zao\QBO_API\Storage;

use Exception;
use Zao\QBO_API\Storage\Transient_Store_Interface;

/**
 * Transient information class. By using options instead of WP transients,
 * we have more flexibility about when the stale data is replaced
 * (possibly with an async action).
 */
class Transients implements Transient_Store_Interface {

	protected $value = null;
	protected $expired = false;
	protected $key = '';
	protected $expiration = HOUR_IN_SECONDS;

	public function __construct( $key = '' ) {
		if ( $key ) {
			$this->set_key( $key );
		}
	}

	/**
	 * Retrieve stored option
	 *
	 * @return mixed Value of transient requested
	 */
	public function get( $force = false ) {
		if ( null === $this->value || $force ) {
			$gotten = $this->get_val_and_expiration( $this->get_key() );
			$this->value   = $gotten['value'];
			$this->expired = $gotten['expired'];
		}

		return $this->value;
	}

	/**
	 * Update the stored value
	 *
	 * @return Result of storage update/add
	 */
	public function set( $value ) {
		$this->value = $value;
		$this->update_db( $this->get_key() . '_exp', time() + $this->expiration );

		return $this->update_db( $this->get_key(), $this->value );
	}

	/**
	 * Handles deleting the stored data for a connection.
	 *
	 * @return bool Result of deletion from DB.
	 */
	public function delete(){
		$this->delete_from_db( $this->get_key() . '_exp' );

		$result = $this->delete_from_db( $this->get_key() );
		if ( $result ) {
			$this->value = null;
		}

		return $result;
	}

	/**
	 * Get transient key
	 */
	public function get_key() {
		if ( empty( $this->key ) ) {
			throw new Exception( 'Zao\QBO_API\Storage\Transients::$key is required.' );
		}

		return $this->key;
	}

	/**
	 * Set transient key
	 */
	public function set_key( $key ) {
		$this->key = $key;

		return $this;
	}

	/**
	 * Is the transient value expired
	 */
	public function is_expired() {
		return !! $this->expired;
	}

	/**
	 * Get transient expiration
	 */
	public function get_expiration() {
		return $this->expiration;
	}

	/**
	 * Set transient expiration
	 */
	public function set_expiration( $expiration ) {
		$this->expiration = $expiration;

		return $this;
	}

	protected function get_val_and_expiration( $key ) {
		$value   = $this->get_from_db( $key, 'VALUE_NOT_SET' );
		$expired = false;

		if ( 'VALUE_NOT_SET' === $value ) {
			$value = null;

			return compact( 'value', 'expired' );
		}

		$expiration = $this->get_from_db( $key . '_exp' );
		$expired = $expiration && $expiration < time();

		return compact( 'value', 'expired' );
	}

	protected function get_from_db() {
		return call_user_func_array( 'get_option', func_get_args() );
	}

	protected function delete_from_db() {
		return call_user_func_array( 'delete_option', func_get_args() );
	}

	protected function update_db() {
		return call_user_func_array( 'update_option', func_get_args() );
	}

}
