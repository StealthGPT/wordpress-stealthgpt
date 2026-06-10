<?php
/**
 * Runs orchestrator: start runs, map runId <-> postId, write results.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates starting StealthGPT runs and persisting their results to drafts.
 */
class StealthGPT_Runs {

	const REST_NAMESPACE = 'stealthgpt/v1';

	const META_RUN_ID       = '_stealthgpt_run_id';
	const META_RUN_STATUS   = '_stealthgpt_run_status';
	const META_RUN_TYPE     = '_stealthgpt_run_type';
	const META_RUN_ERROR    = '_stealthgpt_run_error';
	const META_CREDITS      = '_stealthgpt_credits_spent';
	const META_REMAINING    = '_stealthgpt_remaining_credits';
	const META_BILLING      = '_stealthgpt_billing_mode';
	const META_WORDS        = '_stealthgpt_words';
	const META_DETECTION    = '_stealthgpt_detection_score';
	const MAP_OPTION_PREFIX = 'stealthgpt_run_map_';

	const TYPE_GENERATE = 'generate';
	const TYPE_HUMANIZE = 'humanize';

	/**
	 * API client.
	 *
	 * @var StealthGPT_Api_Client
	 */
	private $api;

	/**
	 * Settings.
	 *
	 * @var StealthGPT_Settings
	 */
	private $settings;

	/**
	 * Scheduler.
	 *
	 * @var StealthGPT_Scheduler
	 */
	private $scheduler;

	/**
	 * Constructor.
	 *
	 * @param StealthGPT_Api_Client $api       API client.
	 * @param StealthGPT_Settings   $settings  Settings.
	 * @param StealthGPT_Scheduler  $scheduler Scheduler.
	 */
	public function __construct( StealthGPT_Api_Client $api, StealthGPT_Settings $settings, StealthGPT_Scheduler $scheduler ) {
		$this->api       = $api;
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
	}

	/**
	 * Register the REST start route.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes for the editor/admin UI.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_start' ),
				'permission_callback' => array( $this, 'can_start' ),
				'args'                => array(
					'mode' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( self::TYPE_GENERATE, self::TYPE_HUMANIZE ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/status/(?P<post_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'can_start' ),
			)
		);
	}

	/**
	 * Capability gate for state-changing requests.
	 *
	 * REST nonce (X-WP-Nonce) is verified by WordPress core before this runs.
	 *
	 * @return bool|WP_Error
	 */
	public function can_start() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'stealthgpt_forbidden', __( 'You are not allowed to do that.', 'stealthgpt' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Handle a start request from the UI.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_start( WP_REST_Request $request ) {
		$mode = $request->get_param( 'mode' );

		if ( '' === $this->settings->get_api_token() ) {
			return new WP_Error( 'stealthgpt_no_token', __( 'Add your StealthGPT API token in settings first.', 'stealthgpt' ), array( 'status' => 400 ) );
		}

		if ( self::TYPE_HUMANIZE === $mode ) {
			return $this->start_humanize( $request );
		}
		return $this->start_generate( $request );
	}

	/**
	 * Start a humanization run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function start_humanize( WP_REST_Request $request ) {
		$source_post_id = absint( $request->get_param( 'source_post_id' ) );
		$text           = (string) $request->get_param( 'text' );

		if ( '' === trim( $text ) && $source_post_id ) {
			$source = get_post( $source_post_id );
			if ( ! $source || ! current_user_can( 'edit_post', $source_post_id ) ) {
				return new WP_Error(
					'stealthgpt_forbidden_source',
					__( 'You are not allowed to use that post as a source.', 'stealthgpt' ),
					array( 'status' => 403 )
				);
			}
			$text = wp_strip_all_tags( $source->post_content );
		}

		$text = $this->sanitize_freeform( $text );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'stealthgpt_empty', __( 'Provide some text to humanize.', 'stealthgpt' ), array( 'status' => 400 ) );
		}

		$quality = in_array( $request->get_param( 'quality_mode' ), array( 'fast', 'quality' ), true )
			? $request->get_param( 'quality_mode' )
			: 'quality';
		$model   = in_array( $request->get_param( 'model' ), array( 'heavy', 'lite' ), true )
			? $request->get_param( 'model' )
			: 'heavy';

		$post_id = $this->create_placeholder_draft( __( '[StealthGPT] Humanizing…', 'stealthgpt' ), $this->resolve_post_type( $request ) );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$body = array(
			'text'         => $text,
			'qualityMode'  => $quality,
			'model'        => $model,
			'outputFormat' => 'markdown',
		);
		$body = array_merge( $body, $this->webhook_fields() );

		$result = $this->api->create_humanize_run( $body, wp_generate_uuid4() );

		return $this->finish_start( $result, $post_id, self::TYPE_HUMANIZE );
	}

	/**
	 * Start a full content-pipeline (agent) run.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function start_generate( WP_REST_Request $request ) {
		$preset = $request->get_param( 'preset' );
		if ( ! in_array( $preset, array( 'academic', 'seo', 'social' ), true ) ) {
			return new WP_Error( 'stealthgpt_bad_preset', __( 'Choose a valid preset.', 'stealthgpt' ), array( 'status' => 400 ) );
		}

		$prompt = $this->sanitize_freeform( (string) $request->get_param( 'prompt' ) );
		if ( '' === trim( $prompt ) ) {
			return new WP_Error( 'stealthgpt_empty', __( 'Provide a prompt describing what to write.', 'stealthgpt' ), array( 'status' => 400 ) );
		}

		$body = array(
			'preset'                => $preset,
			'prompt'                => $prompt,
			'enableFactCheck'       => (bool) $request->get_param( 'enable_fact_check' ),
			'enableImageGeneration' => (bool) $request->get_param( 'enable_image_generation' ),
		);

		if ( 'social' === $preset ) {
			$platform = $request->get_param( 'platform' );
			if ( ! in_array( $platform, array( 'linkedin', 'medium' ), true ) ) {
				return new WP_Error( 'stealthgpt_bad_platform', __( 'Choose a platform for the social preset.', 'stealthgpt' ), array( 'status' => 400 ) );
			}
			$body['platform'] = $platform;
		}

		$body = array_merge( $body, $this->webhook_fields() );

		$post_id = $this->create_placeholder_draft( __( '[StealthGPT] Generating…', 'stealthgpt' ), $this->resolve_post_type( $request ) );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$result = $this->api->create_agent_run( $body, wp_generate_uuid4() );

		return $this->finish_start( $result, $post_id, self::TYPE_GENERATE );
	}

	/**
	 * Common post-create handling: persist mapping, schedule polling, respond.
	 *
	 * @param array|WP_Error $result  API create result.
	 * @param int            $post_id Placeholder draft id.
	 * @param string         $type    Run type.
	 * @return WP_REST_Response|WP_Error
	 */
	private function finish_start( $result, $post_id, $type ) {
		if ( is_wp_error( $result ) ) {
			// Clean up the placeholder draft so we don't leave orphans.
			wp_delete_post( $post_id, true );
			return $result;
		}

		$run_id = isset( $result['runId'] ) ? (string) $result['runId'] : '';
		if ( '' === $run_id ) {
			wp_delete_post( $post_id, true );
			return new WP_Error( 'stealthgpt_no_run_id', __( 'StealthGPT did not return a run id.', 'stealthgpt' ), array( 'status' => 502 ) );
		}

		update_post_meta( $post_id, self::META_RUN_ID, $run_id );
		update_post_meta( $post_id, self::META_RUN_TYPE, $type );
		update_post_meta( $post_id, self::META_RUN_STATUS, 'queued' );
		$this->map_run_to_post( $run_id, $post_id );

		$this->scheduler->schedule_poll( $run_id, 1 );

		return new WP_REST_Response(
			array(
				'runId'   => $run_id,
				'postId'  => $post_id,
				'status'  => 'queued',
				'editUrl' => get_edit_post_link( $post_id, 'raw' ),
			),
			202
		);
	}

	/**
	 * Build webhook fields for a create call.
	 *
	 * StealthGPT only accepts an HTTPS webhook URL with a public hostname, and
	 * will not deliver to private/loopback addresses anyway. On any other site
	 * we omit the webhook entirely and rely on the polling fallback.
	 *
	 * @return array Webhook fields, or an empty array for poll-only sites.
	 */
	private function webhook_fields() {
		/**
		 * Filter the webhook URL sent to StealthGPT.
		 *
		 * Useful for sites behind a reverse proxy, a custom domain, or a tunnel
		 * where rest_url() does not resolve to the publicly reachable address.
		 *
		 * @param string $url Default webhook URL from rest_url().
		 */
		$url = apply_filters( 'stealthgpt_webhook_url', rest_url( self::REST_NAMESPACE . '/webhook' ) );

		if ( ! $this->is_public_https_url( $url ) ) {
			return array();
		}

		return array(
			'webhookUrl'    => $url,
			'webhookSecret' => $this->settings->get_webhook_secret(),
		);
	}

	/**
	 * Whether a URL is HTTPS with a publicly routable hostname.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	private function is_public_https_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( '' === $host ) {
			return false;
		}

		if ( 'localhost' === $host ) {
			return false;
		}

		foreach ( array( '.local', '.localhost', '.test', '.example', '.invalid' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return false;
			}
		}

		// Reject loopback / private / reserved IP literals.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		// Require a dotted public domain (no bare single-label hosts).
		return false !== strpos( $host, '.' );
	}

	/**
	 * Sanitize free-form prompt/source text.
	 *
	 * The value is only sent to the StealthGPT API and never rendered
	 * unescaped, so unlike sanitize_textarea_field() this keeps angle
	 * brackets, tabs, and blank lines intact and only strips invalid UTF-8
	 * and control characters.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function sanitize_freeform( $text ) {
		$text = wp_check_invalid_utf8( (string) $text );
		return (string) preg_replace( '/[^\P{C}\n\t]/u', '', $text );
	}

	/**
	 * Resolve the placeholder post type from the request, with a cap check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string 'post' or 'page'.
	 */
	private function resolve_post_type( WP_REST_Request $request ) {
		$post_type = $request->get_param( 'post_type' );
		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return 'post';
		}

		$pto = get_post_type_object( $post_type );
		if ( ! $pto || ! current_user_can( $pto->cap->edit_posts ) ) {
			return 'post';
		}

		return $post_type;
	}

	/**
	 * Create a placeholder draft post.
	 *
	 * @param string $title     Placeholder title.
	 * @param string $post_type Post type for the draft.
	 * @return int|WP_Error Post id or error.
	 */
	private function create_placeholder_draft( $title, $post_type = 'post' ) {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => '',
				'post_status'  => 'draft',
				'post_type'    => $post_type,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return (int) $post_id;
	}

	/**
	 * Store the runId -> postId reverse map.
	 *
	 * @param string $run_id  Run id.
	 * @param int    $post_id Post id.
	 */
	private function map_run_to_post( $run_id, $post_id ) {
		update_option( self::MAP_OPTION_PREFIX . $run_id, (int) $post_id, false );
	}

	/**
	 * Resolve a runId to its draft post id.
	 *
	 * @param string $run_id Run id.
	 * @return int Post id, or 0 if unknown.
	 */
	public function get_post_id_for_run( $run_id ) {
		return (int) get_option( self::MAP_OPTION_PREFIX . $run_id, 0 );
	}

	/**
	 * Remove the reverse map entry for a run.
	 *
	 * @param string $run_id Run id.
	 */
	public function forget_run( $run_id ) {
		delete_option( self::MAP_OPTION_PREFIX . $run_id );
	}

	/**
	 * Get the run type stored on a post.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function get_run_type( $post_id ) {
		$type = get_post_meta( $post_id, self::META_RUN_TYPE, true );
		return self::TYPE_GENERATE === $type ? self::TYPE_GENERATE : self::TYPE_HUMANIZE;
	}

	/**
	 * Apply a terminal run object to its draft. Idempotent.
	 *
	 * @param array $run The terminal run object (from webhook or polling).
	 * @return bool True if applied, false if ignored (already terminal / unknown).
	 */
	public function apply_terminal_run( array $run ) {
		$run_id = isset( $run['runId'] ) ? (string) $run['runId'] : '';
		$status = isset( $run['status'] ) ? (string) $run['status'] : '';
		if ( '' === $run_id ) {
			return false;
		}

		$post_id = $this->get_post_id_for_run( $run_id );
		if ( ! $post_id ) {
			return false;
		}

		if ( ! get_post( $post_id ) ) {
			// The draft was deleted mid-run: drop the mapping and pending polls.
			$this->forget_run( $run_id );
			$this->scheduler->unschedule_poll( $run_id );
			return false;
		}

		$current = get_post_meta( $post_id, self::META_RUN_STATUS, true );
		if ( in_array( $current, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return false; // Already finalized; ignore duplicate delivery.
		}

		if ( 'completed' === $status ) {
			$this->write_completed( $post_id, $run );
		} elseif ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			$this->write_failed( $post_id, $run );
		} else {
			return false; // Non-terminal; nothing to finalize.
		}

		$this->forget_run( $run_id );
		$this->scheduler->unschedule_poll( $run_id );
		return true;
	}

	/**
	 * Write a completed run's content into the draft.
	 *
	 * @param int   $post_id Draft post id.
	 * @param array $run     Completed run object.
	 */
	private function write_completed( $post_id, array $run ) {
		$markdown = isset( $run['result'] ) ? (string) $run['result'] : '';
		$html     = StealthGPT_Markdown::to_blocks( $markdown );

		$title = $this->derive_title( $markdown );

		$update = array(
			'ID'           => $post_id,
			'post_content' => $html,
			'post_status'  => 'draft',
		);
		if ( $title ) {
			$update['post_title'] = $title;
		}
		wp_update_post( $update );

		update_post_meta( $post_id, self::META_RUN_STATUS, 'completed' );

		$this->store_billing_meta( $post_id, $run );
	}

	/**
	 * Mark a draft as failed/cancelled.
	 *
	 * @param int   $post_id Draft post id.
	 * @param array $run     Failed/cancelled run object.
	 */
	private function write_failed( $post_id, array $run ) {
		$status  = isset( $run['status'] ) ? (string) $run['status'] : 'failed';
		$message = '';
		if ( isset( $run['error'] ) && is_array( $run['error'] ) && isset( $run['error']['message'] ) ) {
			$message = (string) $run['error']['message'];
		}

		update_post_meta( $post_id, self::META_RUN_STATUS, $status );
		update_post_meta( $post_id, self::META_RUN_ERROR, sanitize_text_field( $message ) );
	}

	/**
	 * Persist billing / quality metadata for display.
	 *
	 * @param int   $post_id Draft post id.
	 * @param array $run     Completed run object.
	 */
	private function store_billing_meta( $post_id, array $run ) {
		if ( isset( $run['creditsSpent'] ) ) {
			update_post_meta( $post_id, self::META_CREDITS, (int) $run['creditsSpent'] );
		}
		if ( isset( $run['remainingCredits'] ) ) {
			update_post_meta( $post_id, self::META_REMAINING, (int) $run['remainingCredits'] );
		}
		if ( isset( $run['billingMode'] ) ) {
			update_post_meta( $post_id, self::META_BILLING, sanitize_text_field( (string) $run['billingMode'] ) );
		}
		if ( isset( $run['outputWords'] ) ) {
			update_post_meta( $post_id, self::META_WORDS, (int) $run['outputWords'] );
		} elseif ( isset( $run['wordsSpent'] ) ) {
			update_post_meta( $post_id, self::META_WORDS, (int) $run['wordsSpent'] );
		}
		if ( isset( $run['howLikelyToBeDetected'] ) ) {
			update_post_meta( $post_id, self::META_DETECTION, (int) $run['howLikelyToBeDetected'] );
		}
	}

	/**
	 * Derive a post title from the first markdown heading or line.
	 *
	 * @param string $markdown Markdown content.
	 * @return string Title, or empty string if none could be derived.
	 */
	private function derive_title( $markdown ) {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $markdown ) );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$line = preg_replace( '/^#{1,6}\s*/', '', $line );
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' !== $line ) {
				return sanitize_text_field( wp_html_excerpt( $line, 120, '' ) );
			}
		}
		return '';
	}

	/**
	 * REST status check for the UI to poll while a run is in progress.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_status( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'stealthgpt_forbidden_post',
				__( 'You are not allowed to view that post.', 'stealthgpt' ),
				array( 'status' => 403 )
			);
		}

		$status = get_post_meta( $post_id, self::META_RUN_STATUS, true );
		$error  = get_post_meta( $post_id, self::META_RUN_ERROR, true );

		if ( '' === $status && '' === get_post_meta( $post_id, self::META_RUN_ID, true ) ) {
			return new WP_Error(
				'stealthgpt_not_a_run',
				__( 'This post is not associated with a StealthGPT run.', 'stealthgpt' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'postId'  => $post_id,
				'status'  => $status ? $status : 'unknown',
				'error'   => $error ? $error : '',
				'editUrl' => get_edit_post_link( $post_id, 'raw' ),
			),
			200
		);
	}
}
