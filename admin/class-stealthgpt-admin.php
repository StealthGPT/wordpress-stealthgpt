<?php
/**
 * Admin UI: settings page, "New content" page, editor meta box, assets.
 *
 * @package StealthGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up all admin-facing UI.
 */
class StealthGPT_Admin {

	const MENU_SLUG     = 'stealthgpt';
	const SETTINGS_SLUG = 'stealthgpt-settings';

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
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter( 'plugin_action_links_' . STEALTHGPT_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Register the top-level menu and its sub-pages.
	 */
	public function register_menus() {
		add_menu_page(
			__( 'StealthGPT', 'stealthgpt' ),
			__( 'StealthGPT', 'stealthgpt' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_new_content_page' ),
			'dashicons-edit-page',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'New content', 'stealthgpt' ),
			__( 'New content', 'stealthgpt' ),
			'edit_posts',
			self::MENU_SLUG,
			array( $this, 'render_new_content_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'stealthgpt' ),
			__( 'Settings', 'stealthgpt' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Add the editor meta box for posts and pages.
	 */
	public function register_meta_box() {
		foreach ( array( 'post', 'page' ) as $screen ) {
			add_meta_box(
				'stealthgpt_meta_box',
				__( 'StealthGPT', 'stealthgpt' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url   = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
		$links = array_merge(
			array( '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'stealthgpt' ) . '</a>' ),
			$links
		);
		return $links;
	}

	/**
	 * Enqueue admin assets on relevant screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		$is_editor   = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		$is_our_page = ( false !== strpos( $hook, self::MENU_SLUG ) );

		if ( ! $is_editor && ! $is_our_page ) {
			return;
		}

		wp_enqueue_style(
			'stealthgpt-admin',
			STEALTHGPT_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			STEALTHGPT_VERSION
		);

		wp_enqueue_script(
			'stealthgpt-admin',
			STEALTHGPT_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			STEALTHGPT_VERSION,
			true
		);

		wp_localize_script(
			'stealthgpt-admin',
			'StealthGPTData',
			array(
				'restUrl'     => esc_url_raw( rest_url( StealthGPT_Runs::REST_NAMESPACE ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'hasToken'    => '' !== $this->settings->get_api_token(),
				'currentId'   => get_the_ID() ? (int) get_the_ID() : 0,
				'currentType' => get_post_type( get_the_ID() ) ? get_post_type( get_the_ID() ) : 'post',
				'i18n'        => array(
					'starting'   => __( 'Starting…', 'stealthgpt' ),
					'generating' => __( 'Generating… this can take a few minutes. You can leave this page.', 'stealthgpt' ),
					'completed'  => __( 'Done! Your draft is ready.', 'stealthgpt' ),
					'failed'     => __( 'Run failed.', 'stealthgpt' ),
					'openDraft'  => __( 'Open draft', 'stealthgpt' ),
					'noToken'    => __( 'Add your API token in StealthGPT → Settings first.', 'stealthgpt' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require STEALTHGPT_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Render the standalone "New content" page.
	 */
	public function render_new_content_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$has_token = '' !== $this->settings->get_api_token();
		$context   = 'page';
		require STEALTHGPT_PLUGIN_DIR . 'admin/views/new-content-page.php';
	}

	/**
	 * Render the editor meta box.
	 */
	public function render_meta_box() {
		$has_token = '' !== $this->settings->get_api_token();
		$context   = 'metabox';
		require STEALTHGPT_PLUGIN_DIR . 'admin/views/meta-box.php';
	}

	/**
	 * Render queued admin notices (token validation result).
	 */
	public function render_notices() {
		$notice = $this->settings->pull_balance_notice();
		if ( ! $notice ) {
			return;
		}
		$class = 'notice notice-' . ( in_array( $notice['type'], array( 'success', 'error', 'warning' ), true ) ? $notice['type'] : 'info' );
		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}
}
