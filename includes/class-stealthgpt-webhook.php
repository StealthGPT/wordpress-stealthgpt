<?php
/**
 * Signed webhook endpoint for run completion callbacks.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles StealthGPT completion callbacks.
 *
 * The route is public (no auth cookie), so it is gated entirely by HMAC
 * signature verification against the per-site webhook secret.
 */
class StealthGPT_Webhook {

	const REPLAY_WINDOW = 300;

	/**
	 * Settings.
	 *
	 * @var StealthGPT_Settings
	 */
	private $settings;

	/**
	 * Runs orchestrator.
	 *
	 * @var StealthGPT_Runs
	 */
	private $runs;

	/**
	 * Constructor.
	 *
	 * @param StealthGPT_Settings $settings Settings.
	 * @param StealthGPT_Runs     $runs     Runs orchestrator.
	 */
	public function __construct( StealthGPT_Settings $settings, StealthGPT_Runs $runs ) {
		$this->settings = $settings;
		$this->runs     = $runs;
	}

	/**
	 * Register the webhook route.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the public, HMAC-gated webhook route.
	 */
	public function register_routes() {
		register_rest_route(
			StealthGPT_Runs::REST_NAMESPACE,
			'/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verify the signature and apply the terminal run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$raw       = $request->get_body();
		$signature = $request->get_header( 'x-stealthgpt-signature' );
		$timestamp = $request->get_header( 'x-stealthgpt-timestamp' );

		if ( ! $this->verify( $raw, $signature, $timestamp ) ) {
			return new WP_REST_Response( null, 401 );
		}

		$run = json_decode( $raw, true );
		if ( ! is_array( $run ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$run_id = isset( $run['runId'] ) ? (string) $run['runId'] : '';
		if ( '' === $run_id || ! $this->runs->get_post_id_for_run( $run_id ) ) {
			// Unknown run (misrouted or already finalized and forgotten).
			// StealthGPT only retries on 429/5xx, so 404 won't cause re-delivery.
			return new WP_REST_Response( null, 404 );
		}

		$this->runs->apply_terminal_run( $run );

		// 200 on a verified, known callback so delivery isn't retried.
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Verify the HMAC signature and timestamp freshness.
	 *
	 * @param string $raw       Raw request body.
	 * @param string $signature Signature header value (v1=<hex>).
	 * @param string $timestamp Unix-seconds timestamp header value.
	 * @return bool
	 */
	private function verify( $raw, $signature, $timestamp ) {
		if ( empty( $signature ) || empty( $timestamp ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::REPLAY_WINDOW ) {
			return false;
		}

		$secret = $this->settings->get_webhook_secret();
		if ( '' === $secret ) {
			return false;
		}

		$expected = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );

		return hash_equals( $expected, (string) $signature );
	}
}
