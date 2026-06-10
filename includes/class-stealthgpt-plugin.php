<?php
/**
 * Core plugin loader / singleton.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires together every component of the plugin.
 */
final class StealthGPT_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var StealthGPT_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings component.
	 *
	 * @var StealthGPT_Settings
	 */
	public $settings;

	/**
	 * API client component.
	 *
	 * @var StealthGPT_Api_Client
	 */
	public $api;

	/**
	 * Runs orchestrator.
	 *
	 * @var StealthGPT_Runs
	 */
	public $runs;

	/**
	 * Webhook handler.
	 *
	 * @var StealthGPT_Webhook
	 */
	public $webhook;

	/**
	 * Polling scheduler.
	 *
	 * @var StealthGPT_Scheduler
	 */
	public $scheduler;

	/**
	 * Admin UI.
	 *
	 * @var StealthGPT_Admin
	 */
	public $admin;

	/**
	 * Get the singleton instance.
	 *
	 * @return StealthGPT_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Initialize components and register hooks.
	 */
	public function run() {
		$this->settings  = new StealthGPT_Settings();
		$this->api       = new StealthGPT_Api_Client( $this->settings );
		$this->scheduler = new StealthGPT_Scheduler();
		$this->runs      = new StealthGPT_Runs( $this->api, $this->settings, $this->scheduler );
		$this->webhook   = new StealthGPT_Webhook( $this->settings, $this->runs );
		$this->scheduler->set_runs( $this->runs, $this->api );

		$this->settings->register();
		$this->webhook->register();
		$this->scheduler->register();
		$this->runs->register();

		if ( is_admin() ) {
			$this->admin = new StealthGPT_Admin( $this->settings, $this->runs );
			$this->admin->register();
		}
	}

	/**
	 * Activation hook: generate the per-site webhook secret if missing.
	 */
	public static function activate() {
		if ( ! get_option( 'stealthgpt_webhook_secret' ) ) {
			add_option( 'stealthgpt_webhook_secret', wp_generate_password( 32, false ) );
		}
	}

	/**
	 * Deactivation hook: clear any scheduled polling actions.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'stealthgpt_poll_run', array(), 'stealthgpt' );
		}
	}
}
