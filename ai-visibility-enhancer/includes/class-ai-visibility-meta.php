<?php
/**
 * Handles editor meta fields and structured data output.
 *
 * @package AI_Visibility_Enhancer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers post meta, meta boxes, and schema output.
 */
class AI_Visibility_Meta {
	/**
	 * Settings instance.
	 *
	 * @var AI_Visibility_Settings
	 */
	private $settings;

	/**
	 * Meta field keys managed by the plugin.
	 *
	 * @var array
	 */
	private $meta_fields = array(
		'aive_ai_summary'  => 'AI Summary',
		'aive_ai_keywords' => 'AI Keywords',
		'aive_ai_audience' => 'AI Target Audience',
	);

	/**
	 * Constructor.
	 *
	 * @param AI_Visibility_Settings $settings Settings manager instance.
	 */
	public function __construct( AI_Visibility_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks into WordPress actions.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'inject_structured_data' ), 5 );
	}

	/**
	 * Registers the meta box.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			add_meta_box(
				'ai-visibility-meta',
				__( 'AI Visibility', 'ai-visibility-enhancer' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Outputs the meta box markup.
	 *
	 * @param WP_Post $post Current post object.
	 *
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'aive_meta_box', 'aive_meta_box_nonce' );

		echo '<p>' . esc_html__( 'Provide AI-optimized context. These values power schema markup, dedicated feeds, and AI summaries.', 'ai-visibility-enhancer' ) . '</p>';

		foreach ( $this->meta_fields as $key => $label ) {
			$value = get_post_meta( $post->ID, $key, true );

			echo '<p class="aive-field">';
			echo '<label for="' . esc_attr( $key ) . '" style="font-weight:600; display:block;">' . esc_html( $label ) . '</label>';

			if ( 'aive_ai_summary' === $key ) {
				echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="4" style="width:100%;">' . esc_textarea( $value ) . '</textarea>';
				echo '<span class="description">' . esc_html__( 'Short 2-3 sentence executive summary optimized for AI crawlers.', 'ai-visibility-enhancer' ) . '</span>';
			} else {
				echo '<input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width:100%;" />';
				echo '<span class="description">' . esc_html__( 'Comma-separated keywords or audience descriptors.', 'ai-visibility-enhancer' ) . '</span>';
			}

			echo '</p>';
		}
	}

	/**
	 * Persists meta box values.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post instance.
	 *
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['aive_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aive_meta_box_nonce'] ) ), 'aive_meta_box' ) ) {
		return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
		return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
		}

		foreach ( array_keys( $this->meta_fields ) as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			update_post_meta( $post_id, $key, $value );
		} else {
			delete_post_meta( $post_id, $key );
		}
		}

		$this->purge_cache( $post_id );
	}

	/**
	 * Injects JSON-LD schema and AI-specific meta tags.
	 *
	 * @return void
	 */
	public function inject_structured_data() {
		if ( ! is_singular() ) {
		return;
		}

		global $post;

		if ( ! $post instanceof WP_Post ) {
		return;
		}

		$settings  = $this->settings->get_settings();
		$cache     = $settings['enable_cache'];
		$cache_ttl = (int) $settings['cache_ttl'];
		$cache_key = 'aive_schema_' . $post->ID;

		$payload = false;
		if ( $cache ) {
		$payload = get_transient( $cache_key );
		}

		if ( false === $payload ) {
		$payload = $this->build_schema_payload( $post, $settings );

		if ( $cache ) {
		set_transient( $cache_key, $payload, $cache_ttl );
		}
		}

		if ( empty( $payload ) ) {
		return;
		}

		echo '<meta name="ai-summary" content="' . esc_attr( $payload['description'] ) . '" />';

		if ( ! empty( $payload['keywords'] ) && is_string( $payload['keywords'] ) ) {
		echo '<meta name="ai-keywords" content="' . esc_attr( $payload['keywords'] ) . '" />';
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $payload ) . '</script>';
	}

	/**
	 * Builds schema payload for the current post.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
	 *
	 * @return array
	 */
	private function build_schema_payload( WP_Post $post, $settings ) {
		$summary  = $this->get_summary( $post, $settings );
		$keywords = $this->get_keywords( $post, $settings );
		$audience = get_post_meta( $post->ID, 'aive_ai_audience', true );
		$audience = $audience ? explode( ',', $audience ) : array();
		$audience = array_filter( array_map( 'trim', $audience ) );

		$payload = array(
			'@context'        => 'https://schema.org',
			'@type'           => apply_filters( 'aive_schema_type', 'Article', $post ),
			'@id'             => get_permalink( $post->ID ) . '#aive',
			'url'             => get_permalink( $post->ID ),
			'headline'        => get_the_title( $post ),
			'description'     => $summary,
			'abstract'        => $summary,
			'keywords'        => $keywords,
			'author'          => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'datePublished'   => get_post_time( 'c', true, $post ),
			'dateModified'    => get_post_modified_time( 'c', true, $post ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'publisher'       => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url(),
			),
			'potentialAction' => array(
				'@type'       => 'ConsumeAction',
				'target'      => get_permalink( $post->ID ),
				'description' => __( 'AI-optimized summary available via API.', 'ai-visibility-enhancer' ),
			),
		);

		if ( ! empty( $audience ) ) {
			$payload['audience'] = array();

			foreach ( $audience as $type ) {
				$payload['audience'][] = array(
					'@type'        => 'Audience',
					'audienceType' => $type,
				);
			}
		}

		return apply_filters( 'aive_schema_payload', $payload, $post, $settings );
	}

	/**
	 * Generates a summary string.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
	 *
	 * @return string
	 */
	private function get_summary( WP_Post $post, $settings ) {
		$summary = get_post_meta( $post->ID, 'aive_ai_summary', true );

		if ( $summary ) {
		return $summary;
		}

		$content = has_excerpt( $post ) ? $post->post_excerpt : wp_strip_all_tags( $post->post_content );
		$length  = isset( $settings['default_summary_length'] ) ? (int) $settings['default_summary_length'] : 120;

		return wp_trim_words( $content, max( 30, $length ), '…' );
	}

	/**
	 * Retrieves keywords as comma-separated string.
	 *
	 * @param WP_Post $post     Post object.
	 * @param array   $settings Plugin settings.
	 *
	 * @return string|null
	 */
	private function get_keywords( WP_Post $post, $settings ) {
		if ( empty( $settings['expose_keywords'] ) ) {
		return null;
		}

		$keywords = get_post_meta( $post->ID, 'aive_ai_keywords', true );

		if ( $keywords ) {
		return $keywords;
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

		return implode( ', ', $terms );
	}

	/**
	 * Clears cached payloads.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	private function purge_cache( $post_id ) {
		if ( ! $this->settings->get_setting( 'enable_cache' ) ) {
		return;
		}

		delete_transient( 'aive_schema_' . $post_id );
	}
}
