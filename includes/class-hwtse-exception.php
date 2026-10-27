<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin exception with a machine readable code.
 */
class HWTSE_Exception extends Exception {

	public string $error_code;

	public function __construct( string $message, string $error_code = 'E_HWTSE', int $http_status = 400 ) {
		parent::__construct( $message, $http_status );
		$this->error_code = $error_code;
	}
}
