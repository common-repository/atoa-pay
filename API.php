<?php

namespace AtoaPay;

use Exception;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class API {
	private $api_endpoint;

	private $access_secret;
	
	private $test_mode;

	public function __construct( $access_secret, $test_mode = true ) {
		$this->access_secret = $access_secret;
		$this->test_mode     = $test_mode;

		$this->set_endpoint( $test_mode );
	}

	private function set_endpoint( $test_mode ) {
		$this->api_endpoint = 'https://api.atoa.me/api/payments';
	}

	public function create_payment_request( $data ) {
		$api_url = $this->api_endpoint . '/process-payment';

		$response = wp_remote_post(
			$api_url,
			[
				'body'    => wp_json_encode( $data ),
				'headers' => $this->get_request_headers(),
			]
		);
		$result   = wp_remote_retrieve_body( $response );
		
		$result = json_decode( $result );
		if ( isset( $result->message ) ) {
			throw new Exception( $result->message );
		}

		if ( isset( $result->paymentRequestId ) ) {
			return $result;
		}

		throw new Exception( 'Something went wrong. Please try again later.' );
	}

	public function get_payment_status( $id ) {
		$endpoint = $this->api_endpoint . "/payment-status/{$id}?type=request";
		
		if ( $this->test_mode ) {
			$endpoint .= '&env=sandbox';
		}

		$response = wp_remote_get(
			$endpoint
		);
		$result   = wp_remote_retrieve_body( $response );

		$result = json_decode( $result );
		if ( isset( $result->message ) ) {
			throw new Exception( $result->message );
		}

		if ( isset( $result->paymentIdempotencyId ) ) {
			return $result;
		}

		throw new Exception( 'Something went wrong. Please try again later.' );
	}

	private function get_request_headers() {
		return [
			'Authorization' => 'Bearer ' . $this->access_secret,
			'Content-Type'  => 'application/json',
		];
	}
}
