<?php
/**
 * Action Scheduler polling fallback for run completion.
 *
 * Webhooks are SSRF-protected on StealthGPT's side and will not reach
 * private/localhost sites, so polling is the mandatory completion path
 * for non-public installs.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs status polls until a run reaches a terminal state.
 */
class StealthGPT_Scheduler {

	const HOOK         = 'stealthgpt_poll_run';
	const GROUP        = 'stealthgpt';
	const MAX_ATTEMPTS = 60;

	/*
	 * The attempt counter lives in post meta rather than in the action args:
	 * Action Scheduler matches args exactly on unschedule, so args must stay
	 * identical across reschedules for unschedule_poll() to find them.
	 */
	const META_POLL_ATTEMPTS = '_stealthgpt_poll_attempts';

	/**
	 * Runs orchestrator.
	 *
	 * @var StealthGPT_Runs|null
	 */
	private $runs = null;

	/**
	 * API client.
	 *
	 * @var StealthGPT_Api_Client|null
	 */
	private $api = null;

	/**
	 * Inject dependencies (set after construction to avoid a cycle).
	 *
	 * @param StealthGPT_Runs       $runs Runs orchestrator.
	 * @param StealthGPT_Api_Client $api  API client.
	 */
	public function set_runs( StealthGPT_Runs $runs, StealthGPT_Api_Client $api ) {
		$this->runs = $runs;
		$this->api  = $api;
	}

	/**
	 * Hook the poll action handler.
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'poll' ), 10, 1 );
	}

	/**
	 * Schedule a single poll for a run.
	 *
	 * @param string $run_id  Run id.
	 * @param int    $attempt Attempt number (1-based), used only for the delay.
	 */
	public function schedule_poll( $run_id, $attempt = 1 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action(
			time() + $this->delay_for_attempt( $attempt ),
			self::HOOK,
			array( 'run_id' => $run_id ),
			self::GROUP
		);
	}

	/**
	 * Cancel any pending polls for a run.
	 *
	 * @param string $run_id Run id.
	 */
	public function unschedule_poll( $run_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( 'run_id' => $run_id ), self::GROUP );
		}
	}

	/**
	 * Poll a run's status; finalize if terminal, otherwise reschedule.
	 *
	 * @param string $run_id Run id.
	 */
	public function poll( $run_id ) {
		if ( null === $this->runs || null === $this->api ) {
			return;
		}

		$post_id = $this->runs->get_post_id_for_run( $run_id );
		if ( ! $post_id ) {
			return; // Already finalized (e.g. via webhook) and forgotten.
		}

		$attempt = (int) get_post_meta( $post_id, self::META_POLL_ATTEMPTS, true ) + 1;
		update_post_meta( $post_id, self::META_POLL_ATTEMPTS, $attempt );

		$type   = $this->runs->get_run_type( $post_id );
		$status = ( StealthGPT_Runs::TYPE_GENERATE === $type )
			? $this->api->get_agent_status( $run_id )
			: $this->api->get_humanize_status( $run_id );

		if ( is_wp_error( $status ) ) {
			$this->reschedule_or_fail( $run_id, $post_id, $attempt, $status->get_error_message() );
			return;
		}

		$state = isset( $status['status'] ) ? (string) $status['status'] : '';
		if ( in_array( $state, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			$this->runs->apply_terminal_run( $status );
			return;
		}

		$this->reschedule_or_fail( $run_id, $post_id, $attempt, '' );
	}

	/**
	 * Reschedule the next poll, or mark the run as failed if exhausted.
	 *
	 * @param string $run_id  Run id.
	 * @param int    $post_id Post id.
	 * @param int    $attempt Current attempt.
	 * @param string $reason  Optional failure reason for the final attempt.
	 */
	private function reschedule_or_fail( $run_id, $post_id, $attempt, $reason ) {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$message = $reason ? $reason : __( 'Timed out waiting for StealthGPT to finish.', 'stealthgpt' );
			$this->runs->apply_terminal_run(
				array(
					'runId'  => $run_id,
					'status' => 'failed',
					'error'  => array(
						'code'    => 'poll_timeout',
						'message' => $message,
					),
				)
			);
			return;
		}
		$this->schedule_poll( $run_id, $attempt + 1 );
	}

	/**
	 * Compute the delay (in seconds) before a given attempt.
	 *
	 * Tight early polling, then backing off, to cover both quick humanize
	 * runs and multi-minute agent runs without hammering the API.
	 *
	 * @param int $attempt Attempt number (1-based).
	 * @return int Seconds.
	 */
	private function delay_for_attempt( $attempt ) {
		if ( $attempt <= 6 ) {
			return 15;
		}
		if ( $attempt <= 20 ) {
			return 30;
		}
		return 60;
	}
}
