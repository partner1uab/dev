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
        /**
         * Settings handler.
         *
         * @var AI_Visibility_Settings
         */
        private $settings;

        /**
         * REST controller instance.
         *
         * @var AI_Visibility_REST_Controller
         */
        private $rest_controller;

        /**
         * Absolute path to the generated file.
         *
         * @var string
         */
        private $file_path = '';

        /**
         * Public URL to the generated file.
         *
         * @var string
         */
        private $file_url = '';

        /**
         * Constructor.
         *
         * @param AI_Visibility_Settings        $settings        Settings handler.
         * @param AI_Visibility_REST_Controller $rest_controller REST controller instance.
         */
        public function __construct( AI_Visibility_Settings $settings, AI_Visibility_REST_Controller $rest_controller ) {
                $this->settings        = $settings;
                $this->rest_controller = $rest_controller;
        }

        /**
         * Hooks into WordPress actions.
         *
         * @return void
         */
        public function register() {
                add_action( 'admin_init', array( $this, 'ensure_manifest_exists' ) );
                add_action( 'save_post', array( $this, 'maybe_regenerate_on_save' ), 20, 2 );
                add_action( 'trashed_post', array( $this, 'regenerate_manifest' ) );
                add_action( 'untrashed_post', array( $this, 'regenerate_manifest' ) );
                add_action( 'deleted_post', array( $this, 'regenerate_manifest' ) );
                add_action( 'update_option_' . AI_Visibility_Settings::OPTION_KEY, array( $this, 'regenerate_manifest' ), 10, 0 );
        }

        /**
         * Ensures the manifest exists whenever the admin loads.
         *
         * @return void
         */
        public function ensure_manifest_exists() {
                if ( ! $this->locate_paths() ) {
                        return;
                }

                if ( ! file_exists( $this->file_path ) ) {
                        $this->generate_manifest();
                }
        }

        /**
         * Regenerates the manifest file when settings are updated or posts removed.
         *
         * @return void
         */
        public function regenerate_manifest( $unused = null ) {
                if ( ! $this->locate_paths() ) {
                        return;
                }

                $this->generate_manifest();
        }

        /**
         * Regenerates the manifest when content is saved.
         *
         * @param int     $post_id Post ID.
         * @param WP_Post $post    Post object.
         *
         * @return void
         */
        public function maybe_regenerate_on_save( $post_id, $post ) {
                if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                        return;
                }

                if ( wp_is_post_revision( $post_id ) ) {
                        return;
                }

                $status = get_post_status( $post );

                if ( 'publish' !== $status ) {
                        $status = get_post_status( $post_id );
                }

                if ( 'publish' !== $status ) {
                        return;
                }

                $this->regenerate_manifest();
        }

        /**
         * Returns the manifest URL.
         *
         * @return string
         */
        public function get_manifest_url() {
                if ( ! $this->file_url ) {
                        $this->locate_paths();
                }

                return $this->file_url;
        }

        /**
         * Returns the manifest path.
         *
         * @return string
         */
        public function get_manifest_path() {
                if ( ! $this->file_path ) {
                        $this->locate_paths();
                }

                return $this->file_path;
        }

        /**
         * Calculates filesystem and URL targets.
         *
         * @return bool
         */
        private function locate_paths() {
               $directory = trailingslashit( ABSPATH ) . 'ai-visibility';

               if ( ! wp_mkdir_p( $directory ) ) {
                       return false;
               }

               $this->file_path = trailingslashit( $directory ) . 'aive-manifest.json';
               $this->file_url  = trailingslashit( home_url( 'ai-visibility' ) ) . 'aive-manifest.json';

               return true;
       }

        /**
         * Generates the manifest payload and writes it to disk.
         *
         * @return void
         */
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
                        $item     = $this->rest_controller->prepare_item_for_response( $post, $request );
                        $items[]  = $this->filter_manifest_item_fields( $item );
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

                file_put_contents( $this->file_path, $json );
        }

        /**
         * Filters manifest item data according to settings.
         *
         * @param array $item Item payload.
         * @return array
         */
        private function filter_manifest_item_fields( $item ) {
                if ( ! is_array( $item ) ) {
                        return array();
                }

                $fields   = $this->settings->get_setting( 'manifest_fields' );
                $fields   = is_array( $fields ) ? $fields : array();
                $required = array( 'id', 'url', 'title' );
                $allowed  = array_unique( array_merge( $required, $fields ) );

                return array_intersect_key( $item, array_flip( $allowed ) );
        }
}
