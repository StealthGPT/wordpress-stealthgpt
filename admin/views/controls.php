<?php
/**
 * Shared StealthGPT controls (mode toggle + generate/humanize fields).
 *
 * Expects in scope:
 * - string $context  'metabox' or 'page'.
 * - bool   $has_token Whether an API token is configured.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stealthgpt_uid = 'sg-' . wp_generate_password( 6, false );
?>
<div class="stealthgpt-app" data-context="<?php echo esc_attr( $context ); ?>" data-uid="<?php echo esc_attr( $stealthgpt_uid ); ?>">

	<?php if ( ! $has_token ) : ?>
		<div class="stealthgpt-warning">
			<?php
			printf(
				/* translators: %s: settings page link. */
				esc_html__( 'Add your API token in %s first.', 'stealthgpt' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . StealthGPT_Admin::SETTINGS_SLUG ) ) . '">' . esc_html__( 'StealthGPT → Settings', 'stealthgpt' ) . '</a>'
			);
			?>
		</div>
	<?php endif; ?>

	<div class="stealthgpt-modes">
		<label><input type="radio" name="stealthgpt_mode_<?php echo esc_attr( $stealthgpt_uid ); ?>" value="generate" checked /> <?php esc_html_e( 'Generate', 'stealthgpt' ); ?></label>
		<label><input type="radio" name="stealthgpt_mode_<?php echo esc_attr( $stealthgpt_uid ); ?>" value="humanize" /> <?php esc_html_e( 'Humanize', 'stealthgpt' ); ?></label>
	</div>

	<div class="stealthgpt-fields stealthgpt-fields-generate">
		<p>
			<label><?php esc_html_e( 'Preset', 'stealthgpt' ); ?></label>
			<select class="stealthgpt-preset widefat">
				<option value="seo"><?php esc_html_e( 'SEO article', 'stealthgpt' ); ?></option>
				<option value="academic"><?php esc_html_e( 'Academic', 'stealthgpt' ); ?></option>
				<option value="social"><?php esc_html_e( 'Social', 'stealthgpt' ); ?></option>
			</select>
		</p>
		<p class="stealthgpt-platform-row" style="display:none;">
			<label><?php esc_html_e( 'Platform', 'stealthgpt' ); ?></label>
			<select class="stealthgpt-platform widefat">
				<option value="linkedin"><?php esc_html_e( 'LinkedIn', 'stealthgpt' ); ?></option>
				<option value="medium"><?php esc_html_e( 'Medium', 'stealthgpt' ); ?></option>
			</select>
		</p>
		<p>
			<label><?php esc_html_e( 'Prompt', 'stealthgpt' ); ?></label>
			<textarea class="stealthgpt-prompt widefat" rows="4" placeholder="<?php esc_attr_e( 'Describe what to write about…', 'stealthgpt' ); ?>"></textarea>
		</p>
		<p>
			<label><input type="checkbox" class="stealthgpt-factcheck" /> <?php esc_html_e( 'Enable fact-check', 'stealthgpt' ); ?></label><br />
			<label><input type="checkbox" class="stealthgpt-images" /> <?php esc_html_e( 'Generate images', 'stealthgpt' ); ?></label>
		</p>
	</div>

	<div class="stealthgpt-fields stealthgpt-fields-humanize" style="display:none;">
		<?php if ( 'metabox' === $context ) : ?>
			<p>
				<label><input type="checkbox" class="stealthgpt-use-current" checked /> <?php esc_html_e( 'Use current post content', 'stealthgpt' ); ?></label>
			</p>
		<?php endif; ?>
		<p>
			<label><?php esc_html_e( 'Text to humanize', 'stealthgpt' ); ?></label>
			<textarea class="stealthgpt-text widefat" rows="6" placeholder="<?php esc_attr_e( 'Paste text to humanize…', 'stealthgpt' ); ?>"></textarea>
		</p>
		<p>
			<label><?php esc_html_e( 'Quality', 'stealthgpt' ); ?></label>
			<select class="stealthgpt-quality widefat">
				<option value="quality"><?php esc_html_e( 'Quality (recommended)', 'stealthgpt' ); ?></option>
				<option value="fast"><?php esc_html_e( 'Fast', 'stealthgpt' ); ?></option>
			</select>
		</p>
		<p>
			<label><?php esc_html_e( 'Model', 'stealthgpt' ); ?></label>
			<select class="stealthgpt-model widefat">
				<option value="heavy"><?php esc_html_e( 'Heavy (recommended)', 'stealthgpt' ); ?></option>
				<option value="lite"><?php esc_html_e( 'Lite', 'stealthgpt' ); ?></option>
			</select>
		</p>
	</div>

	<p>
		<button type="button" class="button button-primary stealthgpt-start" <?php disabled( ! $has_token ); ?>>
			<?php esc_html_e( 'Start', 'stealthgpt' ); ?>
		</button>
	</p>

	<div class="stealthgpt-status" aria-live="polite"></div>
</div>
