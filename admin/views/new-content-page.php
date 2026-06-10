<?php
/**
 * Standalone "New content" admin page.
 *
 * Expects in scope: bool $has_token, string $context.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap stealthgpt-wrap">
	<h1><?php esc_html_e( 'StealthGPT — New content', 'stealthgpt' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Generate or humanize content. The result is saved to a new draft for your review.', 'stealthgpt' ); ?>
	</p>
	<div class="stealthgpt-card">
		<?php require STEALTHGPT_PLUGIN_DIR . 'admin/views/controls.php'; ?>
	</div>
</div>
