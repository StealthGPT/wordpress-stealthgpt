<?php
/**
 * Settings: BYO API token + per-site webhook secret.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages plugin options via the WordPress Settings API.
 */
class StealthGPT_Settings {

	const OPTION_GROUP   = 'stealthgpt_settings';
	const OPTION_NAME    = 'stealthgpt_settings';
	const SECRET_OPTION  = 'stealthgpt_webhook_secret';
	const BALANCE_NOTICE = 'stealthgpt_balance_notice';

	/**
	 * Register settings, sections, and fields.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register the option, section, and fields with the Settings API.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'stealthgpt_main',
			__( 'StealthGPT API', 'stealthgpt' ),
			array( $this, 'render_section_intro' ),
			'stealthgpt'
		);

		add_settings_field(
			'api_token',
			__( 'API token', 'stealthgpt' ),
			array( $this, 'render_token_field' ),
			'stealthgpt',
			'stealthgpt_main'
		);

		add_settings_field(
			'default_output',
			__( 'Default output', 'stealthgpt' ),
			array( $this, 'render_output_field' ),
			'stealthgpt',
			'stealthgpt_main'
		);
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	private function defaults() {
		return array(
			'api_token'      => '',
			'default_output' => 'draft',
		);
	}

	/**
	 * Get the stored options merged with defaults.
	 *
	 * @return array
	 */
	public function get_options() {
		$options = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		return wp_parse_args( $options, $this->defaults() );
	}

	/**
	 * Get the stored API token. Never exposed via REST.
	 *
	 * @return string
	 */
	public function get_api_token() {
		$options = $this->get_options();
		return isset( $options['api_token'] ) ? (string) $options['api_token'] : '';
	}

	/**
	 * Get the per-site webhook secret, generating it on demand if missing.
	 *
	 * @return string
	 */
	public function get_webhook_secret() {
		$secret = get_option( self::SECRET_OPTION );
		if ( ! $secret ) {
			$secret = wp_generate_password( 32, false );
			update_option( self::SECRET_OPTION, $secret );
		}
		return (string) $secret;
	}

	/**
	 * Sanitize submitted settings and validate the token via the balance API.
	 *
	 * The token is never echoed back; a masked preview is shown in the field.
	 *
	 * @param array $input Raw submitted values.
	 * @return array Sanitized values.
	 */
	public function sanitize( $input ) {
		$current = $this->get_options();
		$output  = $this->defaults();

		// Output mode is fixed to draft in v1, but stored for forward-compat.
		$output['default_output'] = 'draft';

		$submitted_token = isset( $input['api_token'] ) ? trim( sanitize_text_field( $input['api_token'] ) ) : '';

		// An all-mask value means "leave the saved token unchanged".
		if ( '' === $submitted_token || $this->is_masked( $submitted_token ) ) {
			$output['api_token'] = $current['api_token'];
		} else {
			$output['api_token'] = $submitted_token;
		}

		$this->validate_token_and_notify( $output['api_token'] );

		return $output;
	}

	/**
	 * Validate a token against the balance endpoint and queue an admin notice.
	 *
	 * @param string $token Token to validate.
	 */
	private function validate_token_and_notify( $token ) {
		if ( '' === $token ) {
			$this->set_balance_notice( 'warning', __( 'No API token saved yet.', 'stealthgpt' ) );
			return;
		}

		$api    = new StealthGPT_Api_Client( $this );
		$result = $api->get_balance( $token );

		if ( is_wp_error( $result ) ) {
			$this->set_balance_notice( 'error', $result->get_error_message() );
			return;
		}

		$credits  = isset( $result['credits'] ) ? (int) $result['credits'] : 0;
		$has_payg = ! empty( $result['payg'] );

		if ( $has_payg ) {
			$message = sprintf(
				/* translators: %d: number of prepaid credits. */
				__( 'API token is valid. Credits: %d (pay-as-you-go is active).', 'stealthgpt' ),
				$credits
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of prepaid credits. */
				__( 'API token is valid. Credits: %d.', 'stealthgpt' ),
				$credits
			);
		}

		$this->set_balance_notice( 'success', $message );
	}

	/**
	 * Store a one-time admin notice about the balance check.
	 *
	 * @param string $type    One of success|error|warning.
	 * @param string $message Notice text.
	 */
	private function set_balance_notice( $type, $message ) {
		set_transient(
			self::BALANCE_NOTICE,
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Retrieve and clear the stored balance notice.
	 *
	 * @return array|null
	 */
	public function pull_balance_notice() {
		$notice = get_transient( self::BALANCE_NOTICE );
		if ( $notice ) {
			delete_transient( self::BALANCE_NOTICE );
		}
		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * Whether a string looks like our masked token preview.
	 *
	 * @param string $value Value to test.
	 * @return bool
	 */
	private function is_masked( $value ) {
		return (bool) preg_match( '/^\xE2\x80\xA2+/', $value ) || false !== strpos( $value, '••••' );
	}

	/**
	 * Produce a masked preview of the saved token for display.
	 *
	 * @return string
	 */
	public function get_masked_token() {
		$token = $this->get_api_token();
		if ( '' === $token ) {
			return '';
		}
		$last4 = substr( $token, -4 );
		return '••••••••••••' . $last4;
	}

	/**
	 * Render the settings section introduction.
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Enter your StealthGPT API token. Output is always saved to a draft for your review.', 'stealthgpt' ) . '</p>';
		echo '<p><a href="https://www.stealthgpt.ai/stealthapi" target="_blank" rel="noopener noreferrer">' .
			esc_html__( 'Get your API token', 'stealthgpt' ) . '</a></p>';
	}

	/**
	 * Render the API token input field.
	 */
	public function render_token_field() {
		$masked = $this->get_masked_token();
		printf(
			'<input type="password" id="stealthgpt_api_token" name="%1$s[api_token]" value="%2$s" class="regular-text" autocomplete="off" placeholder="%3$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $masked ),
			esc_attr__( 'api-token from stealthgpt.ai', 'stealthgpt' )
		);
		echo '<p class="description">' . esc_html__( 'Stored securely in your site options and never shown in full or exposed via the REST API.', 'stealthgpt' ) . '</p>';
	}

	/**
	 * Render the (read-only) default output field.
	 */
	public function render_output_field() {
		echo '<select id="stealthgpt_default_output" name="' . esc_attr( self::OPTION_NAME ) . '[default_output]" disabled>';
		echo '<option value="draft" selected>' . esc_html__( 'Draft (always)', 'stealthgpt' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Generated content is always saved as a draft. You publish it after review.', 'stealthgpt' ) . '</p>';
	}
}
