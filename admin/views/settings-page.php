<?php
/**
 * Settings page.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap stealthgpt-wrap">
	<h1><?php esc_html_e( 'StealthGPT Settings', 'stealthgpt' ); ?></h1>
	<form action="options.php" method="post">
		<?php
		settings_fields( StealthGPT_Settings::OPTION_GROUP );
		do_settings_sections( 'stealthgpt' );
		submit_button( __( 'Save & validate token', 'stealthgpt' ) );
		?>
	</form>

	<hr />
	<h2><?php esc_html_e( 'About this plugin', 'stealthgpt' ); ?></h2>
	<p>
		<?php esc_html_e( 'This plugin sends the prompts and text you provide to StealthGPT (stealthgpt.ai) to generate or humanize content. Generated content is always saved as a draft.', 'stealthgpt' ); ?>
	</p>
	<p>
		<a href="https://www.stealthgpt.ai/terms" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Terms of Service', 'stealthgpt' ); ?></a>
		&middot;
		<a href="https://www.stealthgpt.ai/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy Policy', 'stealthgpt' ); ?></a>
	</p>
</div>
