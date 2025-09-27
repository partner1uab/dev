<?php
/**
 * REST API controller for AI-friendly endpoints.
 *
 * @package AI_Visibility_Enhancer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_REST_Controller' ) ) {
return;
}

/**
 * Exposes structured content to AI crawlers.
 */
class AI_Visibility_REST_Controller extends WP_REST_Controller {
	/**
	 * Plugin settings instance.
	 *
	 * @var AI_Visibility_Settings
	 */
	protected $settings;

	/**
	 * Namespace for the REST route.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-visibility/v1';

	/**
	 * Constructor.
	 *
	 * @param AI_Visibility_Settings $settings Settings handler.
	 */
	public function __construct( AI_Visibility_Settings $settings ) {
$this->settings = $settings;
$this->rest_base = 'content';
}

	/**
	 * Registers routes with the REST API.
	 *
	 * @return void
	 */
	public function register() {
add_action( 'rest_api_init', array( $this, 'register_routes' ) );
}

	/**
	 * Registers endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
register_rest_route(
$this->namespace,
'/' . $this->rest_base . '/(?P<id>\d+)',
array(
'args'                => array(
'id' => array(
'description' => __( 'Post ID.', 'ai-visibility-enhancer' ),
'type'        => 'integer',
),
),
'callback'            => array( $this, 'get_item' ),
'permission_callback' => array( $this, 'permissions_check' ),
'schema'              => array( $this, 'get_public_item_schema' ),
),
false
);
}

/**
 * Permissions callback.
 *
 * @param WP_REST_Request $request Current request.
 *
 * @return bool
 */
public function permissions_check( $request ) {
$settings = $this->settings->get_settings();

if ( ! empty( $settings['allow_public_endpoint'] ) ) {
return true;
}

return current_user_can( 'read' );
}

/**
 * Retrieves a single post payload.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response|WP_Error
 */
public function get_item( $request ) {
$post_id = (int) $request['id'];
$post    = get_post( $post_id );

if ( ! $post || 'publish' !== $post->post_status ) {
return new WP_Error( 'aive_not_found', __( 'Content not found or not public.', 'ai-visibility-enhancer' ), array( 'status' => 404 ) );
}

$settings  = $this->settings->get_settings();
$cache_key = 'aive_rest_' . $post_id;
$use_cache = ! empty( $settings['enable_cache'] );
$response  = false;

if ( $use_cache ) {
$response = wp_cache_get( $cache_key, 'aive-rest' );
}

if ( false === $response ) {
$data     = $this->prepare_item_for_response( $post, $request );
$response = rest_ensure_response( $data );

if ( $use_cache ) {
wp_cache_set( $cache_key, $response, 'aive-rest', isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : MINUTE_IN_SECONDS );
}
}

return $response;
}

/**
 * Builds the data payload for the REST response.
 *
 * @param WP_Post         $post    Post instance.
 * @param WP_REST_Request $request Request object.
 *
 * @return array
 */
public function prepare_item_for_response( $post, $request ) {
$settings   = $this->settings->get_settings();
$summary    = $this->generate_summary( $post, $settings );
$keywords   = $this->generate_keywords( $post, $settings );
$audience   = get_post_meta( $post->ID, 'aive_ai_audience', true );
$audience   = $audience ? array_filter( array_map( 'trim', explode( ',', $audience ) ) ) : array();
$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
$tags       = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

return array(
'id'           => $post->ID,
'url'          => get_permalink( $post ),
'title'        => get_the_title( $post ),
'summary'      => $summary,
'keywords'     => $keywords,
'audience'     => $audience,
'language'     => get_bloginfo( 'language' ),
'updated_at'   => get_post_modified_time( 'c', true, $post ),
'published_at' => get_post_time( 'c', true, $post ),
'author'       => array(
'name'  => get_the_author_meta( 'display_name', $post->post_author ),
'email' => '',
),
'categories'   => is_wp_error( $categories ) ? array() : $categories,
'tags'         => is_wp_error( $tags ) ? array() : $tags,
'content_hash' => wp_hash( $post->post_modified_gmt . $summary ),
);
}

/**
 * Generates a summary string for the REST response.
 *
 * @param WP_Post $post     Post object.
 * @param array   $settings Plugin settings.
 *
 * @return string
 */
protected function generate_summary( $post, $settings ) {
$summary = get_post_meta( $post->ID, 'aive_ai_summary', true );

if ( $summary ) {
return $summary;
}

$content = has_excerpt( $post ) ? $post->post_excerpt : wp_strip_all_tags( $post->post_content );
$length  = isset( $settings['default_summary_length'] ) ? (int) $settings['default_summary_length'] : 120;

return wp_trim_words( $content, max( 30, $length ), '…' );
}

/**
 * Generates keywords for the REST response.
 *
 * @param WP_Post $post     Post object.
 * @param array   $settings Plugin settings.
 *
 * @return array|null
 */
protected function generate_keywords( $post, $settings ) {
if ( empty( $settings['expose_keywords'] ) ) {
return null;
}

$manual = get_post_meta( $post->ID, 'aive_ai_keywords', true );

if ( $manual ) {
return array_filter( array_map( 'trim', explode( ',', $manual ) ) );
}

$terms = array();
foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $object ) {
if ( ! $object->public ) {
continue;
}

$term_list = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
if ( ! is_wp_error( $term_list ) ) {
$terms = array_merge( $terms, $term_list );
}
}

$terms = array_slice( array_unique( array_filter( array_map( 'trim', $terms ) ) ), 0, 10 );

return $terms;
}

/**
 * Retrieves the publicly accessible schema.
 *
 * @return array
 */
public function get_public_item_schema() {
return $this->get_item_schema();
}

/**
 * JSON schema for the endpoint.
 *
 * @return array
 */
public function get_item_schema() {
if ( $this->schema ) {
return $this->schema;
}

$this->schema = array(
'$schema'    => 'http://json-schema.org/draft-04/schema#',
'title'      => 'ai_visibility_content',
'type'       => 'object',
'properties' => array(
'id'           => array(
'description' => __( 'Unique post identifier.', 'ai-visibility-enhancer' ),
'type'        => 'integer',
'context'     => array( 'view', 'embed' ),
),
'url'          => array(
'description' => __( 'Canonical URL for the content.', 'ai-visibility-enhancer' ),
'type'        => 'string',
'format'      => 'uri',
),
'title'        => array(
'description' => __( 'Title of the content item.', 'ai-visibility-enhancer' ),
'type'        => 'string',
),
'summary'      => array(
'description' => __( 'AI-optimized summary text.', 'ai-visibility-enhancer' ),
'type'        => 'string',
),
'keywords'     => array(
'description' => __( 'Keyword collection used for classification.', 'ai-visibility-enhancer' ),
'type'        => array( 'array', 'null' ),
),
'audience'     => array(
'description' => __( 'Intended audience descriptors.', 'ai-visibility-enhancer' ),
'type'        => 'array',
),
'language'     => array(
'description' => __( 'Locale of the content.', 'ai-visibility-enhancer' ),
'type'        => 'string',
),
'updated_at'   => array(
'description' => __( 'Last modification date.', 'ai-visibility-enhancer' ),
'type'        => 'string',
'format'      => 'date-time',
),
'published_at' => array(
'description' => __( 'Publication date.', 'ai-visibility-enhancer' ),
'type'        => 'string',
'format'      => 'date-time',
),
'author'       => array(
'description' => __( 'Author information.', 'ai-visibility-enhancer' ),
'type'        => 'object',
),
'categories'   => array(
'description' => __( 'Post categories.', 'ai-visibility-enhancer' ),
'type'        => 'array',
),
'tags'         => array(
'description' => __( 'Post tags.', 'ai-visibility-enhancer' ),
'type'        => 'array',
),
'content_hash' => array(
'description' => __( 'Hash of content and summary for change detection.', 'ai-visibility-enhancer' ),
'type'        => 'string',
),
),
);

return $this->schema;
}
}
