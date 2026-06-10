<?php
/**
 * StealthGPT REST API client.
 *
 * Thin wrapper around the WordPress HTTP API. Talks only to the public
 * async StealthGPT endpoints over HTTPS using the `api-token` header.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for the StealthGPT public REST API.
 */
class StealthGPT_Api_Client {

	const BASE_URL = 'https://www.stealthgpt.ai';

	const ENDPOINT_BALANCE       = '/api/stealthify/balance';
	const ENDPOINT_HUMANIZE_RUNS = '/api/stealthify/runs';
	const ENDPOINT_AGENT_RUNS    = '/api/stealthify/agent/runs';

	/**
	 * Settings component (source of the API token).
	 *
	 * @var StealthGPT_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param StealthGPT_Settings $settings Settings component.
	 */
	public function __construct( StealthGPT_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Validate a token by calling the balance endpoint.
	 *
	 * @param string $token API token to validate.
	 * @return array|WP_Error Decoded balance payload, or WP_Error on failure.
	 */
	public function get_balance( $token = '' ) {
		return $this->request(
			'GET',
			self::ENDPOINT_BALANCE,
			array(
				'token' => $token,
			)
		);
	}

	/**
	 * Start a humanization-only run.
	 *
	 * @param array  $body            Strict request body.
	 * @param string $idempotency_key Optional idempotency key.
	 * @return array|WP_Error Decoded 202 payload, or WP_Error.
	 */
	public function create_humanize_run( array $body, $idempotency_key = '' ) {
		return $this->request(
			'POST',
			self::ENDPOINT_HUMANIZE_RUNS,
			array(
				'body'            => $body,
				'idempotency_key' => $idempotency_key,
			)
		);
	}

	/**
	 * Get the status of a humanization run.
	 *
	 * @param string $run_id Run identifier.
	 * @return array|WP_Error Decoded status payload, or WP_Error.
	 */
	public function get_humanize_status( $run_id ) {
		return $this->request( 'GET', self::ENDPOINT_HUMANIZE_RUNS . '/' . rawurlencode( $run_id ) );
	}

	/**
	 * Start a full content-pipeline (agent) run.
	 *
	 * @param array  $body            Strict request body.
	 * @param string $idempotency_key Optional idempotency key.
	 * @return array|WP_Error Decoded 202 payload, or WP_Error.
	 */
	public function create_agent_run( array $body, $idempotency_key = '' ) {
		return $this->request(
			'POST',
			self::ENDPOINT_AGENT_RUNS,
			array(
				'body'            => $body,
				'idempotency_key' => $idempotency_key,
			)
		);
	}

	/**
	 * Get the status of an agent run.
	 *
	 * @param string $run_id Run identifier.
	 * @return array|WP_Error Decoded status payload, or WP_Error.
	 */
	public function get_agent_status( $run_id ) {
		return $this->request( 'GET', self::ENDPOINT_AGENT_RUNS . '/' . rawurlencode( $run_id ) );
	}

	/**
	 * Perform an HTTP request against the StealthGPT API.
	 *
	 * @param string $method HTTP method (GET|POST).
	 * @param string $path   API path beginning with a slash.
	 * @param array  $args   {
	 *     Optional. Request arguments.
	 *
	 *     @type string $token           Override token (defaults to stored token).
	 *     @type array  $body            Request body for POST requests.
	 *     @type string $idempotency_key Optional idempotency key header.
	 * }
	 * @return array|WP_Error Decoded JSON body on 2xx, otherwise WP_Error.
	 */
	private function request( $method, $path, array $args = array() ) {
		$token = isset( $args['token'] ) && '' !== $args['token']
			? $args['token']
			: $this->settings->get_api_token();

		if ( empty( $token ) ) {
			return new WP_Error(
				'stealthgpt_no_token',
				__( 'No StealthGPT API token is configured. Add it on the StealthGPT settings page.', 'stealthgpt' )
			);
		}

		$headers = array(
			'api-token' => $token,
			'Accept'    => 'application/json',
		);

		$request_args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 20,
		);

		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/json';
			if ( ! empty( $args['idempotency_key'] ) ) {
				$headers['idempotency-key'] = substr( (string) $args['idempotency_key'], 0, 255 );
			}
			$request_args['headers'] = $headers;
			$request_args['body']    = wp_json_encode( isset( $args['body'] ) ? $args['body'] : array() );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $request_args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'stealthgpt_http_error',
				/* translators: %s: underlying transport error message. */
				sprintf( __( 'Could not reach StealthGPT: %s', 'stealthgpt' ), $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		return $this->map_error( $status, is_array( $data ) ? $data : array() );
	}

	/**
	 * Map a non-2xx response to a descriptive WP_Error.
	 *
	 * @param int   $status HTTP status code.
	 * @param array $data   Decoded response body.
	 * @return WP_Error
	 */
	private function map_error( $status, array $data ) {
		$message = isset( $data['message'] ) ? (string) $data['message'] : '';

		switch ( $status ) {
			case 401:
				$message = $message ? $message : __( 'Invalid StealthGPT API token.', 'stealthgpt' );
				$code    = 'stealthgpt_unauthorized';
				break;
			case 402:
				$message = $message ? $message : __( 'StealthGPT billing required. Check your credits or payment method.', 'stealthgpt' );
				$code    = 'stealthgpt_billing';
				break;
			case 400:
				$message = $message ? $message : __( 'StealthGPT rejected the request as invalid.', 'stealthgpt' );
				$code    = 'stealthgpt_bad_request';
				break;
			case 404:
				$message = $message ? $message : __( 'StealthGPT run not found.', 'stealthgpt' );
				$code    = 'stealthgpt_not_found';
				break;
			default:
				$message = $message ? $message : __( 'StealthGPT returned an unexpected error.', 'stealthgpt' );
				$code    = 'stealthgpt_server_error';
				break;
		}

		$error = new WP_Error( $code, $message, array( 'status' => $status ) );

		if ( isset( $data['info'] ) ) {
			$error->add_data( array( 'info' => $data['info'] ), $code );
		}

		return $error;
	}
}
