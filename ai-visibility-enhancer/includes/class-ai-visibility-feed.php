<?php
/**
 * Generates a static manifest for AI crawlers.
 *
 * @package AI_Visibility_Enhancer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles generation of an aggregated JSON file for AI agents.
 */
class AI_Visibility_Feed {
	/** @var AI_Visibility_Settings */
	private $settings;

	/** @var AI_Visibility_REST_Controller */
	private $rest_controller;

	/** @var string Absolute path to the generated file */
	private $file_path = '';

	/** @var string Public URL to the generated file */
	private $file_url = '';

	public function __construct( AI_Visibility_Settings $settings, AI_Visibility_REST_Controller $rest_controller ) {
		$this->settings        = $settings;
		$this->rest_controller = $rest_controller;
	}

	public function register() {
		add_action( 'admin_init', array( $this, 'ensure_manifest_exists' ) );
		add_action( 'save_post', array( $this, 'maybe_regenerate_on_save' ), 20, 2 );
		add_action( 'trashed_post', array( $this, 'regenerate_manifest' ) );
		add_action( 'untrashed_post', array( $this, 'regenerate_manifest' ) );
		add_action( 'deleted_post', array( $this, 'regenerate_manifest' ) );
		add_action( 'update_option_' . AI_Visibility_Settings::OPTION_KEY, array( $this, 'regenerate_manifest' ), 10, 0 );
	}

	public function ensure_manifest_exists() {
		if ( ! $this->locate_paths() ) {
			return;
		}
		if ( ! file_exists( $this->file_path ) ) {
			$this->generate_manifest();
		}
	}

	public function regenerate_manifest( $unused = null ) {
		if ( ! $this->locate_paths() ) {
			return;
		}
		$this->generate_manifest();
	}

	public function maybe_regenerate_on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$status = get_post_status( $post ) ?: get_post_status( $post_id );
		if ( 'publish' !== $status ) {
			return;
		}
		$this->regenerate_manifest();
	}

	public function get_manifest_url() {
		if ( ! $this->file_url ) {
			$this->locate_paths();
		}
		return $this->file_url;
	}

	public function get_manifest_path() {
		if ( ! $this->file_path ) {
			$this->locate_paths();
		}
		return $this->file_path;
	}

	/**
	 * Use uploads root (no month/year subdirs).
	 */
	private function locate_paths() {
		$uploads = wp_upload_dir( null, false ); // no YM subdirs
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return false;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'ai-visibility';
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$this->file_path = trailingslashit( $dir ) . 'aive-manifest.json';
		$this->file_url  = trailingslashit( $uploads['baseurl'] ) . 'ai-visibility/aive-manifest.json';

		return is_writable( $dir ) || ( file_exists( $this->file_path ) && is_writable( $this->file_path ) );
	}

	private function generate_manifest() {
		$posts = get_posts(
			apply_filters(
				'aive_manifest_query_args',
				array(
					'post_type'      => get_post_types( array( 'public' => true ) ),
					'post_status'    => 'publish',
					'posts_per_page' => 25,
					'orderby'        => 'modified',
					'order'          => 'DESC',
				)
			)
		);

		$request = class_exists( 'WP_REST_Request' ) ? new WP_REST_Request( 'GET', '' ) : null;
		$items   = array();

		foreach ( $posts as $post ) {
			$item    = $this->rest_controller->prepare_item_for_response( $post, $request );
			$items[] = $this->filter_manifest_item_fields( $item );
		}

		$manifest = apply_filters(
			'aive_manifest_payload',
			array(
				'generated_at' => current_time( 'c' ),
				'site'         => array(
					'name'     => get_bloginfo( 'name' ),
					'url'      => home_url( '/' ),
					'language' => get_bloginfo( 'language' ),
				),
				'endpoint'     => rest_url( trailingslashit( $this->rest_controller->get_namespace() ) . $this->rest_controller->get_rest_base() ),
				'items'        => $items,
			)
		);

		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return;
		}

		// Best-effort write.
		@file_put_contents( $this->file_pa_
