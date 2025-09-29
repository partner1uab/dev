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

	const DEFAULT_PER_PAGE = 10;
	const MAX_PER_PAGE     = 100;

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
	 * REST base for content.
	 *
	 * @var string
	 */
	protected $rest_base = 'content';

	/**
	 * Schema cache.
	 *
	 * @var array
	 */
	protected $schema;

	/**
	 * Rate limit context.
	 *
	 * @var array
	 */
	private $rate_limit_context = array();

	/**
	 * Constructor.
	 *
	 * @param AI_Visibility_Settings $settings Settings handler.
	 */
	public function __construct( AI_Visibility_Settings $settings ) {
		$this->settings = $settings;
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
	 * Returns the namespace used for routes.
	 *
	 * @return string
	 */
	public function get_namespace() {
		return $this->namespace;
	}

	/**
	 * Returns the REST base for content.
	 *
	 * @return string
	 */
	public function get_rest_base() {
		return $this->rest_base;
	}

	/**
	 * Registers endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		$collection_args = array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_items' ),
			'permission_callback' => array( $this, 'permissions_check' ),
			'args'                => array(
				'page'          => array(
					'description' => __( 'Results page to retrieve.', 'ai-visibility-enhancer' ),
					'type'        => 'integer',
					'default'     => 1,
				),
				'per_page'      => array(
					'description' => __( 'Number of items per page.', 'ai-visibility-enhancer' ),
					'type'        => 'integer',
					'default'     => self::DEFAULT_PER_PAGE,
				),
				'type'          => array(
					'description' => __( 'Limit to a single post type.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'status'        => array(
					'description' => __( 'Post status filter.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
					'default'     => 'publish',
				),
				'changed_since' => array(
					'description' => __( 'Return items changed since the provided ISO8601 datetime.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
			),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				$collection_args,
				array(
					'methods'             => 'HEAD',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $collection_args['permission_callback'],
					'args'                => $collection_args['args'],
				),
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( $this, 'get_options_response' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		$single_args = array(
			'args'                => array(
				'id' => array(
					'description' => __( 'Post ID.', 'ai-visibility-enhancer' ),
					'type'        => 'integer',
				),
			),
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_item' ),
			'permission_callback' => array( $this, 'permissions_check' ),
			'schema'              => array( $this, 'get_public_item_schema' ),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				$single_args,
				array(
					'methods'             => 'HEAD',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $single_args['permission_callback'],
					'args'                => $single_args['args'],
				),
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( $this, 'get_options_response' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_batch_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Array of content IDs to fetch.', 'ai-visibility-enhancer' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => 'OPTIONS',
					'callback'            => array( $this, 'get_options_response' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permissions callback.
	 *
	 * @param WP_REST_Request $request Current request.
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
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$enforcement = $this->enforce_rate_limit( $request );
		if ( is_wp_error( $enforcement ) || $enforcement instanceof WP_REST_Response ) {
			return $enforcement;
		}

		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return $this->prepare_error( 'aive_not_found', __( 'Content not found or not public.', 'ai-visibility-enhancer' ), 404 );
		}

		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post_id ) ) {
			return $this->prepare_error( 'aive_forbidden', __( 'You are not allowed to access this content.', 'ai-visibility-enhancer' ), 403 );
		}

		$response = $this->prepare_single_response( $post, $request );

		if ( 'HEAD' === $request->get_method() ) {
			$response->set_data( null );
		}

		return $response;
	}

	/**
	 * Retrieves a paginated list of content IDs with metadata.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$enforcement = $this->enforce_rate_limit( $request );
		if ( is_wp_error( $enforcement ) || $enforcement instanceof WP_REST_Response ) {
			return $enforcement;
		}

		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = min( max( 1, $per_page ), self::MAX_PER_PAGE );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$status   = $request->get_param( 'status' );
		$type     = $request->get_param( 'type' );

		$args = array(
			'post_status'    => $status ? sanitize_key( $status ) : 'publish',
			'post_type'      => $type ? sanitize_key( $type ) : get_post_types( array( 'public' => true ) ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);

		$changed_since = $this->get_changed_since( $request );
		if ( $changed_since ) {
			$args['date_query'] = array(
				array(
					'column' => 'post_modified_gmt',
					'after'  => gmdate( 'Y-m-d H:i:s', $changed_since ),
				),
			);
		}

		$args  = apply_filters( 'aive_collection_query_args', $args, $request );
		$query = new WP_Query( $args );

		$ids    = $query->posts;
		$items  = array();
		$latest = null;

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$latest  = $this->hydrate_latest_timestamp( $latest, $post );
			$items[] = $this->prepare_item_for_response( $post, $request );
		}

		if ( empty( $items ) && $this->is_not_modified( $request, $latest ) ) {
			$response = rest_ensure_response( null );
			$response->set_status( 304 );
			$this->apply_response_headers( $response, $latest, array() );
			return $response;
		}

		$payload = array(
			'ids'         => array_map( 'intval', $ids ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) max( 1, $query->max_num_pages ),
			'items'       => $items,
		);

		$response = rest_ensure_response( $payload );
		$this->apply_response_headers( $response, $latest, $items );

		if ( 'HEAD' === $request->get_method() ) {
			$response->set_data( null );
		}

		return $response;
	}

	/**
	 * Returns a set of content items for the provided IDs.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_batch_items( $request ) {
		$enforcement = $this->enforce_rate_limit( $request );
		if ( is_wp_error( $enforcement ) || $enforcement instanceof WP_REST_Response ) {
			return $enforcement;
		}

		$ids = $request->get_param( 'ids' );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return $this->prepare_error( 'aive_invalid_batch', __( 'Provide one or more IDs.', 'ai-visibility-enhancer' ), 400 );
		}

		$ids    = array_unique( array_filter( array_map( 'intval', $ids ) ) );
		$items  = array();
		$latest = null;

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$latest  = $this->hydrate_latest_timestamp( $latest, $post );
			$items[] = $this->prepare_item_for_response( $post, $request );
		}

		if ( empty( $items ) ) {
			return $this->prepare_error( 'aive_not_found', __( 'No matching content found.', 'ai-visibility-enhancer' ), 404 );
		}

		$response = rest_ensure_response( array( 'items' => $items ) );
		$this->apply_response_headers( $response, $latest, $items );

		return $response;
	}

	/**
	 * Provides OPTIONS responses for collection endpoints.
	 *
	 * @return WP_REST_Response
	 */
	public function get_options_response( $request = null ) {
		$response = rest_ensure_response( null );
		$response->header( 'Allow', 'GET,HEAD,OPTIONS' );
		return $response;
	}

	/**
	 * Builds a response for a single post including cache headers.
	 *
	 * @param WP_Post         $post    Post instance.
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	private function prepare_single_response( $post, $request ) {
		$settings  = $this->settings->get_settings();
		$cache     = ! empty( $settings['enable_cache'] );
		$cache_ttl = isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : MINUTE_IN_SECONDS;
		$cache_key = 'aive_rest_' . $post->ID;
		$data      = false;

		if ( $cache ) {
			$data = wp_cache_get( $cache_key, 'aive-rest' );
		}

		if ( false === $data ) {
			$data = $this->prepare_item_for_response( $post, $request );
			if ( $cache ) {
				wp_cache_set( $cache_key, $data, 'aive-rest', $cache_ttl );
			}
		}

		$latest   = $post->post_modified_gmt ? strtotime( $post->post_modified_gmt . ' GMT' ) : time();
		$response = rest_ensure_response( $data );
		$this->apply_response_headers( $response, $latest, array( $data ) );

		if ( $this->is_not_modified( $request, $latest, $data ) ) {
			$response->set_status( 304 );
			$response->set_data( null );
		}

		return $response;
	}

	/**
	 * Extracts the changed_since timestamp from parameters or headers.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return int|null
	 */
	private function get_changed_since( $request ) {
		$param = $request->get_param( 'changed_since' );
		if ( $param ) {
			$timestamp = strtotime( $param );
			if ( $timestamp ) {
				return $timestamp;
			}
		}

		$header = $request->get_header( 'if-modified-since' );
		if ( $header ) {
			$timestamp = strtotime( $header );
			if ( $timestamp ) {
				return $timestamp;
			}
		}

		return null;
	}

	/**
	 * Determines whether the response should be treated as not modified.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @param int|null        $latest  Latest modification timestamp.
	 * @param array|null      $data    Response data for ETag checks.
	 * @return bool
	 */
	private function is_not_modified( $request, $latest, $data = null ) {
		if ( ! $latest ) {
			return false;
		}

		$etag        = $this->generate_etag( $data );
		$header_etag = $request->get_header( 'if-none-match' );
		if ( $header_etag && $etag && trim( $header_etag ) === $etag ) {
			return true;
		}

		$changed_since = $this->get_changed_since( $request );
		if ( $changed_since && $latest <= $changed_since ) {
			return true;
		}

		return false;
	}

	/**
	 * Applies cache and rate limit headers to a response.
	 *
	 * @param WP_REST_Response $response Response instance.
	 * @param int|null         $latest   Latest modification timestamp.
	 * @param array            $data     Response payload.
	 * @return void
	 */
	private function apply_response_headers( WP_REST_Response $response, $latest, $data ) {
		$settings  = $this->settings->get_settings();
		$cache_ttl = isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : MINUTE_IN_SECONDS;
		$max_age   = max( 30, (int) apply_filters( 'aive_cache_max_age', $cache_ttl ) );
		$etag      = $this->generate_etag( $data );
		$latest    = $latest ? $latest : time();

		$response->header( 'Cache-Control', 'public, max-age=' . $max_age );
		$response->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s', $latest ) . ' GMT' );

		if ( $etag ) {
			$response->header( 'ETag', $etag );
		}

		foreach ( $this->get_rate_limit_headers() as $name => $value ) {
			$response->header( $name, $value );
		}
	}

	/**
	 * Generates an ETag for the payload.
	 *
	 * @param array|null $data Payload data.
	 * @return string
	 */
	private function generate_etag( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		$normalized = wp_json_encode( $data );
		if ( ! $normalized ) {
			return '';
		}

		return '"' . md5( $normalized ) . '"';
	}

	/**
	 * Returns rate limit headers for the active request.
	 *
	 * @return array
	 */
	private function get_rate_limit_headers() {
		if ( empty( $this->rate_limit_context ) ) {
			return array();
		}

		return array(
			'X-RateLimit-Limit'     => (string) $this->rate_limit_context['limit'],
			'X-RateLimit-Remaining' => (string) max( 0, $this->rate_limit_context['remaining'] ),
			'X-RateLimit-Reset'     => (string) $this->rate_limit_context['reset'],
		);
	}

	/**
	 * Enforces a simple User-Agent based rate limit.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return true|WP_Error|WP_REST_Response
	 */
	private function enforce_rate_limit( $request ) {
		$user_agent = $request->get_header( 'user-agent' );
		$allow      = apply_filters( 'aive_user_agent_whitelist', array(), $request );

		if ( ! empty( $allow ) && $user_agent ) {
			$allowed = false;
			foreach ( $allow as $pattern ) {
				if ( preg_match( '/' . $pattern . '/i', $user_agent ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				return $this->prepare_error( 'aive_not_allowed', __( 'User agent is not permitted.', 'ai-visibility-enhancer' ), 403 );
			}
		}

		if ( ! $user_agent ) {
			return true;
		}

		$key      = 'aive_rate_' . md5( $user_agent );
		$limit    = (int) apply_filters( 'aive_rate_limit', 60, $request );
		$interval = (int) apply_filters( 'aive_rate_interval', MINUTE_IN_SECONDS, $request );
		$hits     = wp_cache_get( $key, 'aive-rest' );

		if ( false === $hits ) {
			$hits = 0;
		}

		$hits++;
		wp_cache_set( $key, $hits, 'aive-rest', $interval );

		$this->rate_limit_context = array(
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $hits ),
			'reset'     => time() + $interval,
		);

		if ( $hits > $limit ) {
			$payload = array(
				'code'    => 'aive_rate_limited',
				'message' => __( 'Too many requests. Slow down.', 'ai-visibility-enhancer' ),
				'data'    => array(
					'status'  => 429,
					'details' => array(
						'retry_after' => $interval,
					),
				),
			);

			$response = new WP_REST_Response( $payload, 429 );
			$response->header( 'Retry-After', $interval );

			return $response;
		}

		return true;
	}

	/**
	 * Prepares a WP_Error with the plugin error schema.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 * @param array  $details Additional details.
	 * @return WP_Error
	 */
	private function prepare_error( $code, $message, $status = 500, $details = array() ) {
		$error = new WP_Error( $code, $message );
		$error->add_data(
			array(
				'status'  => $status,
				'details' => $details,
			),
			$code
		);
		return $error;
	}

	/**
	 * Hydrates latest timestamp helper.
	 *
	 * @param int|null $current Current timestamp.
	 * @param WP_Post  $post    Post instance.
	 * @return int|null
	 */
	private function hydrate_latest_timestamp( $current, $post ) {
		$timestamp = $post->post_modified_gmt ? strtotime( $post->post_modified_gmt . ' GMT' ) : null;
		if ( ! $timestamp ) {
			return $current;
		}
		if ( null === $current || $timestamp > $current ) {
			return $timestamp;
		}
		return $current;
	}

	/**
	 * Builds the data payload for the REST response.
	 *
	 * @param WP_Post         $post    Post instance.
	 * @param WP_REST_Request $request Request object.
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
		$canonical  = get_permalink( $post );
		$schema     = $this->generate_schema_payload( $post, $summary, $keywords, $audience );
		$media      = $this->prepare_media_payload( $post );
		$taxonomies = $this->prepare_taxonomy_payload( $post );

		$data = array(
			'id'               => $post->ID,
			'url'              => $canonical,
			'canonical_url'    => $canonical,
			'title'            => get_the_title( $post ),
			'summary'          => $summary,
			'summary_strategy' => $this->get_summary_strategy_key( $settings ),
			'summary_source'   => $this->get_summary_strategy_label( $settings ),
			'keywords'         => $keywords,
			'keyword_strategy' => ! empty( $settings['expose_keywords'] ) ? 'automatic' : 'disabled',
			'audience'         => $audience,
			'language'         => get_bloginfo( 'language' ),
			'updated_at'       => get_post_modified_time( 'c', true, $post ),
			'published_at'     => get_post_time( 'c', true, $post ),
			'author'           => array(
				'name' => get_the_author_meta( 'display_name', $post->post_author ),
				'url'  => get_author_posts_url( $post->post_author ),
			),
			'categories'       => is_wp_error( $categories ) ? array() : $categories,
			'tags'             => is_wp_error( $tags ) ? array() : $tags,
			'taxonomies'       => $taxonomies,
			'images'           => $media,
			'alternates'       => $this->get_hreflang_links( $post ),
			'schema'           => $schema,
			'content_hash'     => $this->generate_content_hash( $post, $summary ),
			'ai_indexable'     => apply_filters( 'aive_is_indexable', true, $post ),
		);

		return apply_filters( 'aive_prepare_item', $data, $post, $settings, $request );
	}

	/**
	 * Generates a summary string for the REST response.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
	 * @return string
	 */
	protected function generate_summary( $post, $settings ) {
		$strategy = $this->get_summary_strategy_key( $settings );
		$summary  = get_post_meta( $post->ID, 'aive_ai_summary', true );

		if ( 'manual' === $strategy && $summary ) {
			return $summary;
		}

		if ( 'excerpt' === $strategy && has_excerpt( $post ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		$content = wp_strip_all_tags( $post->post_content );

		if ( 'first_paragraph' === $strategy ) {
			$paragraphs = preg_split( '/\r?\n\r?\n/', trim( $content ) );
			$paragraph  = isset( $paragraphs[0] ) ? $paragraphs[0] : $content;
			return wp_trim_words( $paragraph, 80, '…' );
		}

		if ( $summary ) {
			return $summary;
		}

		$length = isset( $settings['default_summary_length'] ) ? (int) $settings['default_summary_length'] : 120;

		return wp_trim_words( $content, max( 30, $length ), '…' );
	}

	/**
	 * Generates keywords for the REST response.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
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

		return array_slice( array_unique( array_filter( array_map( 'trim', $terms ) ) ), 0, 10 );
	}

	/**
	 * Builds taxonomy payload keyed by taxonomy slug.
	 *
	 * @param WP_Post $post Post instance.
	 * @return array
	 */
	private function prepare_taxonomy_payload( $post ) {
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy => $object ) {
			if ( ! $object->public ) {
				continue;
			}

			$terms = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$taxonomies[] = array(
				'taxonomy' => $taxonomy,
				'terms'    => array_values( array_unique( $terms ) ),
			);
		}

		return $taxonomies;
	}

	/**
	 * Generates the JSON-LD payload exposed to the API.
	 *
	 * @param WP_Post $post     Post object.
	 * @param string  $summary  Summary text.
	 * @param array   $keywords Keywords array.
	 * @param array   $audience Audience descriptors.
	 * @return array
	 */
	private function generate_schema_payload( $post, $summary, $keywords, $audience ) {
		$schema_type = apply_filters( 'aive_schema_type', 'Article', $post );

		$payload = array(
			'@context'      => 'https://schema.org',
			'@type'         => $schema_type,
			'@id'           => get_permalink( $post->ID ) . '#aive',
			'url'           => get_permalink( $post->ID ),
			'headline'      => get_the_title( $post ),
			'description'   => $summary,
			'keywords'      => $keywords ? implode( ', ', (array) $keywords ) : '',
			'datePublished' => get_post_time( 'c', true, $post ),
			'dateModified'  => get_post_modified_time( 'c', true, $post ),
			'inLanguage'    => get_bloginfo( 'language' ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url(),
			),
		);

		if ( ! empty( $audience ) ) {
			$payload['audience'] = array();
			foreach ( $audience as $audience_type ) {
				$payload['audience'][] = array(
					'@type'        => 'Audience',
					'audienceType' => $audience_type,
				);
			}
		}

		return apply_filters( 'aive_schema_payload_rest', $payload, $post );
	}

	/**
	 * Returns media metadata for the post.
	 *
	 * @param WP_Post $post Post instance.
	 * @return array
	 */
	private function prepare_media_payload( $post ) {
		$media = array();

		$featured = get_post_thumbnail_id( $post );
		if ( $featured ) {
			$media[] = $this->build_media_entry( $featured );
		}

		$attachments = get_attached_media( 'image', $post );
		foreach ( $attachments as $attachment ) {
			if ( $featured && $attachment->ID === $featured ) {
				continue;
			}
			$media[] = $this->build_media_entry( $attachment->ID );
		}

		return array_values( array_filter( $media ) );
	}

	/**
	 * Builds a media entry from an attachment ID.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	private function build_media_entry( $attachment_id ) {
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! $src ) {
			return null;
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return array(
			'id'     => $attachment_id,
			'url'    => $src[0],
			'width'  => isset( $src[1] ) ? (int) $src[1] : null,
			'height' => isset( $src[2] ) ? (int) $src[2] : null,
			'alt'    => $alt,
		);
	}

	/**
	 * Retrieves hreflang alternatives for the content.
	 *
	 * @param WP_Post $post Post instance.
	 * @return array
	 */
	private function get_hreflang_links( $post ) {
		$alternates = array();
		$languages  = apply_filters( 'aive_hreflang_languages', array(), $post );

		if ( empty( $languages ) ) {
			return $alternates;
		}

		foreach ( $languages as $code => $url ) {
			$alternates[] = array(
				'hreflang' => $code,
				'href'     => $url,
			);
		}

		return $alternates;
	}

	/**
	 * Generates a stable content hash from canonical data.
	 *
	 * @param WP_Post $post    Post instance.
	 * @param string  $summary Summary string.
	 * @return string
	 */
	private function generate_content_hash( $post, $summary ) {
		$content = apply_filters( 'aive_canonical_content', wp_strip_all_tags( $post->post_content ), $post );
		$payload = implode( '|', array( $post->post_modified_gmt, $summary, $content ) );
		return hash( 'sha256', $payload );
	}

	/**
	 * Returns the configured summary strategy.
	 *
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function get_summary_strategy_key( $settings ) {
		$strategy = isset( $settings['summary_strategy'] ) ? $settings['summary_strategy'] : 'manual';
		if ( ! in_array( $strategy, array( 'manual', 'excerpt', 'first_paragraph', 'fallback' ), true ) ) {
			$strategy = 'fallback';
		}
		return $strategy;
	}

	/**
	 * Returns a human readable summary strategy label.
	 *
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function get_summary_strategy_label( $settings ) {
		$strategy = $this->get_summary_strategy_key( $settings );

		$labels = array(
			'manual'          => __( 'Manual', 'ai-visibility-enhancer' ),
			'excerpt'         => __( 'Excerpt', 'ai-visibility-enhancer' ),
			'first_paragraph' => __( 'First paragraph', 'ai-visibility-enhancer' ),
			'fallback'        => __( 'Automatic fallback', 'ai-visibility-enhancer' ),
		);

		return isset( $labels[ $strategy ] ) ? $labels[ $strategy ] : $labels['fallback'];
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
				'id'               => array(
					'description' => __( 'Unique post identifier.', 'ai-visibility-enhancer' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'embed' ),
				),
				'url'              => array(
					'description' => __( 'Canonical URL for the content.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
					'format'      => 'uri',
				),
				'title'            => array(
					'description' => __( 'Title of the content item.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'summary'          => array(
					'description' => __( 'AI-optimized summary text.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'keywords'         => array(
					'description' => __( 'Keyword collection used for classification.', 'ai-visibility-enhancer' ),
					'type'        => array( 'array', 'null' ),
				),
				'audience'         => array(
					'description' => __( 'Intended audience descriptors.', 'ai-visibility-enhancer' ),
					'type'        => 'array',
				),
				'language'         => array(
					'description' => __( 'Locale of the content.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'updated_at'       => array(
					'description' => __( 'Last modification date.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'published_at'     => array(
					'description' => __( 'Publication date.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'author'           => array(
					'description' => __( 'Author information.', 'ai-visibility-enhancer' ),
					'type'        => 'object',
				),
				'categories'       => array(
					'description' => __( 'Post categories.', 'ai-visibility-enhancer' ),
					'type'        => 'array',
				),
				'tags'             => array(
					'description' => __( 'Post tags.', 'ai-visibility-enhancer' ),
					'type'        => 'array',
				),
				'taxonomies'       => array(
					'description' => __( 'Taxonomy term breakdown.', 'ai-visibility-enhancer' ),
					'type'        => 'array',
				),
				'images'           => array(
					'description' => __( 'Attached image metadata.', 'ai-visibility-enhancer' ),
					'type'        => 'array',
				),
				'canonical_url'    => array(
					'description' => __( 'Canonical URL for the content.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'alternates'       => array(
					'description' => __( 'Hreflang alternatives.', 'ai-visibility-enhancer' ),
					'type'        => 'array',
				),
				'schema'           => array(
					'description' => __( 'JSON-LD schema payload.', 'ai-visibility-enhancer' ),
					'type'        => 'object',
				),
				'summary_strategy' => array(
					'description' => __( 'Summary generation strategy.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'summary_source'   => array(
					'description' => __( 'Human readable summary source.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'keyword_strategy' => array(
					'description' => __( 'Keyword generation strategy.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'content_hash'     => array(
					'description' => __( 'Hash of content and summary for change detection.', 'ai-visibility-enhancer' ),
					'type'        => 'string',
				),
				'ai_indexable'     => array(
					'description' => __( 'Whether the content should be indexed by AI crawlers.', 'ai-visibility-enhancer' ),
					'type'        => 'boolean',
				),
			),
		);

		return $this->schema;
	}
}
