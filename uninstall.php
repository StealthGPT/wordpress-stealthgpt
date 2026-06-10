<?php
/**
 * Uninstall handler: remove all plugin options and metadata.
 *
 * Runs only when the user deletes the plugin from the WordPress admin.
 *
 * @package StealthGPT
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'stealthgpt_settings' );
delete_option( 'stealthgpt_webhook_secret' );

// Remove the runId -> postId reverse map options (stored as stealthgpt_run_map_*).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'stealthgpt\\_run\\_map\\_%'"
);

// Remove per-post run metadata.
$stealthgpt_meta_keys = array(
	'_stealthgpt_run_id',
	'_stealthgpt_run_status',
	'_stealthgpt_run_type',
	'_stealthgpt_run_error',
	'_stealthgpt_poll_attempts',
	'_stealthgpt_credits_spent',
	'_stealthgpt_remaining_credits',
	'_stealthgpt_billing_mode',
	'_stealthgpt_words',
	'_stealthgpt_detection_score',
);
foreach ( $stealthgpt_meta_keys as $stealthgpt_meta_key ) {
	delete_post_meta_by_key( $stealthgpt_meta_key );
}
