<?php
/**
 * Settings manager for the AI Visibility Enhancer plugin.
 *
 * @package AI_Visibility_Enhancer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and exposes plugin settings.
 */
class AI_Visibility_Settings {

	/**
	 * Option key used to store settings.
	 */
	const OPTION_KEY = 'aive_settings';

	/**
	 * Feed generator instance.
	 *
	 * @var AI_Visibility_Feed|null
	 */
	private $feed = null;

	/**
	 * Default settings.
	 *
	 * @var array
	 */
        private $defaults = array(
                'default_summary_length' => 120,
                'expose_keywords'       => true,
                'enable_cache'          => true,
                'cache_ttl'             => 5 * MINUTE_IN_SECONDS,
                'allow_public_endpoint' => true,
                'summary_strategy'      => 'fallback',
                'user_agent_whitelist'  => array(),
                'manifest_fields'       => array(
                        'summary',
                        'keywords',
                        'audience',
                        'language',
                        'updated_at',
                        'published_at',
                        'author',
                        'categories',
                        'tags',
                        'content_hash',
                ),
        );

	/**
	 * Hook registrations.
	 *
	 * @return void
	 */
        public function register() {
                add_action( 'admin_init', array( $this, 'register_settings' ) );
                add_action( 'admin_menu', array( $this, 'register_menu' ) );
                add_filter(
                        'plugin_action_links_' . plugin_basename( AIVE_PLUGIN_DIR . 'ai-visibility-enhancer.php' ),
                        array( $this, 'register_action_links' )
                );
                add_filter( 'aive_user_agent_whitelist', array( $this, 'filter_user_agent_whitelist' ) );
        }

	/**
	 * Injects dependencies for rendering context.
	 *
	 * @param AI_Visibility_Feed $feed Feed generator instance.
	 * @return void
	 */
	public function set_feed( AI_Visibility_Feed $feed ) {
		$this->feed = $feed;
	}

	/**
	 * Retrieves the merged settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $settings, $this->defaults );
	}

	/**
	 * Returns a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Registers settings fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'aive_settings_group',
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

                add_settings_section(
                        'aive_general_section',
                        __( 'General AI Visibility Settings', 'ai-visibility-enhancer' ),
                        '__return_false',
                        'ai-visibility-enhancer'
                );

                add_settings_section(
                        'aive_manifest_section',
                        __( 'Manifest Output Settings', 'ai-visibility-enhancer' ),
                        '__return_false',
                        'ai-visibility-enhancer'
                );

		add_settings_field(
			'default_summary_length',
			__( 'Default AI Summary Length (words)', 'ai-visibility-enhancer' ),
			array( $this, 'render_summary_length_field' ),
			'ai-visibility-enhancer',
			'aive_general_section'
		);

		add_settings_field(
			'expose_keywords',
			__( 'Expose custom AI keywords', 'ai-visibility-enhancer' ),
			array( $this, 'render_checkbox_field' ),
			'ai-visibility-enhancer',
			'aive_general_section',
			array( 'label_for' => 'expose_keywords' )
		);

                add_settings_field(
                        'allow_public_endpoint',
                        __( 'Allow public AI content endpoint', 'ai-visibility-enhancer' ),
                        array( $this, 'render_checkbox_field' ),
                        'ai-visibility-enhancer',
                        'aive_general_section',
			array( 'label_for' => 'allow_public_endpoint' )
		);

                add_settings_field(
                        'enable_cache',
                        __( 'Enable response caching', 'ai-visibility-enhancer' ),
                        array( $this, 'render_checkbox_field' ),
                        'ai-visibility-enhancer',
			'aive_general_section',
			array( 'label_for' => 'enable_cache' )
		);

                add_settings_field(
                        'cache_ttl',
                        __( 'Cache lifetime (seconds)', 'ai-visibility-enhancer' ),
                        array( $this, 'render_cache_ttl_field' ),
                        'ai-visibility-enhancer',
                        'aive_general_section'
                );

                add_settings_field(
                        'summary_strategy',
                        __( 'Summary generation strategy', 'ai-visibility-enhancer' ),
                        array( $this, 'render_summary_strategy_field' ),
                        'ai-visibility-enhancer',
                        'aive_general_section'
                );

                add_settings_field(
                        'user_agent_whitelist',
                        __( 'Allowed AI User-Agents', 'ai-visibility-enhancer' ),
                        array( $this, 'render_user_agent_whitelist_field' ),
                        'ai-visibility-enhancer',
                        'aive_general_section'
                );

                add_settings_field(
                        'manifest_fields',
                        __( 'Manifest data fields', 'ai-visibility-enhancer' ),
                        array( $this, 'render_manifest_fields_field' ),
                        'ai-visibility-enhancer',
                        'aive_manifest_section'
                );
	}

	/**
	 * Registers the settings page menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'AI Visibility Enhancer', 'ai-visibility-enhancer' ),
			__( 'AI Visibility Enhancer', 'ai-visibility-enhancer' ),
			'manage_options',
			'ai-visibility-enhancer',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Adds the settings link to the plugins list.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
        public function register_action_links( $links ) {
                $links[] = sprintf(
                        '<a href="%s">%s</a>',
                        esc_url( admin_url( 'options-general.php?page=ai-visibility-enhancer' ) ),
                        esc_html__( 'Settings', 'ai-visibility-enhancer' )
                );
                return $links;
        }

        /**
         * Provides the User-Agent whitelist for API filtering.
         *
         * @param array $whitelist Existing patterns.
         * @return array
         */
        public function filter_user_agent_whitelist( $whitelist ) {
                $settings = $this->get_settings();
                $custom   = isset( $settings['user_agent_whitelist'] ) ? (array) $settings['user_agent_whitelist'] : array();

                if ( empty( $custom ) ) {
                        return $whitelist;
                }

                return array_unique( array_merge( $whitelist, $custom ) );
        }

	/**
	 * Renders the summary length input.
	 *
	 * @return void
	 */
	public function render_summary_length_field() {
		$settings = $this->get_settings();
		$length   = (int) $settings['default_summary_length'];

		printf(
			'<input type="number" id="default_summary_length" name="%1$s[default_summary_length]" value="%2$d" min="30" max="400" step="5" class="small-text" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $length )
		);
		echo '<p class="description">' . esc_html__( 'Controls fallback AI summary length when no manual summary is provided.', 'ai-visibility-enhancer' ) . '</p>';
	}

	/**
	 * Renders a checkbox input.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_checkbox_field( $args ) {
		$settings = $this->get_settings();
		$key      = $args['label_for'];
		$checked  = ! empty( $settings[ $key ] );

		printf(
			'<label><input type="checkbox" id="%1$s" name="%2$s[%1$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( $key ),
			esc_attr( self::OPTION_KEY ),
			checked( $checked, true, false ),
			$this->get_checkbox_label( $key )
		);
	}

	/**
	 * Provides checkbox descriptions.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
        private function get_checkbox_label( $key ) {
                switch ( $key ) {
                        case 'expose_keywords':
                                return esc_html__( 'Expose manually curated keywords in schema and API responses.', 'ai-visibility-enhancer' );
                        case 'allow_public_endpoint':
                                return esc_html__( 'Allow unauthenticated access to AI summaries via the REST endpoint.', 'ai-visibility-enhancer' );
                        case 'enable_cache':
                                return esc_html__( 'Cache schema and REST responses for faster AI crawler access.', 'ai-visibility-enhancer' );
                        default:
                                return '';
                }
        }

        /**
         * Renders checkboxes to select manifest fields.
         *
         * @return void
         */
        public function render_manifest_fields_field() {
                $settings = $this->get_settings();
                $selected = isset( $settings['manifest_fields'] ) && is_array( $settings['manifest_fields'] )
                        ? $settings['manifest_fields']
                        : $this->defaults['manifest_fields'];

                $options = $this->get_manifest_field_options();

                echo '<fieldset>';
                echo '<legend class="screen-reader-text">' . esc_html__( 'Select manifest data fields', 'ai-visibility-enhancer' ) . '</legend>';

                foreach ( $options as $key => $label ) {
                        printf(
                                '<label><input type="checkbox" name="%1$s[manifest_fields][]" value="%2$s" %3$s /> %4$s</label><br />',
                                esc_attr( self::OPTION_KEY ),
                                esc_attr( $key ),
                                checked( in_array( $key, $selected, true ), true, false ),
                                esc_html( $label )
                        );
                }

                echo '<p class="description">' . esc_html__( 'Choose which optional data points should be written to the AI manifest alongside the required identifiers.', 'ai-visibility-enhancer' ) . '</p>';
                echo '</fieldset>';
        }

        /**
         * Renders summary strategy options.
         *
         * @return void
         */
        public function render_summary_strategy_field() {
                $settings = $this->get_settings();
                $current  = isset( $settings['summary_strategy'] ) ? $settings['summary_strategy'] : $this->defaults['summary_strategy'];
                $options  = array(
                        'manual'          => __( 'Prefer manual summaries', 'ai-visibility-enhancer' ),
                        'excerpt'         => __( 'Use the excerpt when available', 'ai-visibility-enhancer' ),
                        'first_paragraph' => __( 'Use the first paragraph of content', 'ai-visibility-enhancer' ),
                        'fallback'        => __( 'Automatic fallback to generated summaries', 'ai-visibility-enhancer' ),
                );

                echo '<fieldset>';
                foreach ( $options as $key => $label ) {
                        printf(
                                '<label><input type="radio" name="%1$s[summary_strategy]" value="%2$s" %3$s /> %4$s</label><br />',
                                esc_attr( self::OPTION_KEY ),
                                esc_attr( $key ),
                                checked( $current, $key, false ),
                                esc_html( $label )
                        );
                }
                echo '<p class="description">' . esc_html__( 'Control how the plugin derives summaries when no manual value is supplied.', 'ai-visibility-enhancer' ) . '</p>';
                echo '</fieldset>';
        }

        /**
         * Renders the user agent whitelist textarea.
         *
         * @return void
         */
        public function render_user_agent_whitelist_field() {
                $settings = $this->get_settings();
                $value    = isset( $settings['user_agent_whitelist'] ) ? implode( "\n", (array) $settings['user_agent_whitelist'] ) : '';

                printf(
                        '<textarea id="user_agent_whitelist" name="%1$s[user_agent_whitelist]" rows="4" style="width: 25em;">%2$s</textarea>',
                        esc_attr( self::OPTION_KEY ),
                        esc_textarea( $value )
                );

                echo '<p class="description">' . esc_html__( 'One regular expression per line. Only matching AI User-Agents will be served the API endpoints when a whitelist is configured.', 'ai-visibility-enhancer' ) . '</p>';
        }

        /**
         * Returns manifest field options.
         *
         * @return array
         */
        private function get_manifest_field_options() {
                return array(
                        'summary'      => __( 'AI summary', 'ai-visibility-enhancer' ),
                        'keywords'     => __( 'Keywords', 'ai-visibility-enhancer' ),
                        'audience'     => __( 'Audience descriptors', 'ai-visibility-enhancer' ),
                        'language'     => __( 'Content language', 'ai-visibility-enhancer' ),
                        'updated_at'   => __( 'Last modified date', 'ai-visibility-enhancer' ),
                        'published_at' => __( 'Publication date', 'ai-visibility-enhancer' ),
                        'author'       => __( 'Author details', 'ai-visibility-enhancer' ),
                        'categories'   => __( 'Categories', 'ai-visibility-enhancer' ),
                        'tags'         => __( 'Tags', 'ai-visibility-enhancer' ),
                        'content_hash' => __( 'Content hash', 'ai-visibility-enhancer' ),
                );
        }

	/**
	 * Renders the cache TTL field.
	 *
	 * @return void
	 */
	public function render_cache_ttl_field() {
		$settings = $this->get_settings();
		$ttl      = (int) $settings['cache_ttl'];

		printf(
			'<input type="number" id="cache_ttl" name="%1$s[cache_ttl]" value="%2$d" min="60" max="86400" step="30" class="small-text" />',
			esc_attr( self::OPTION_KEY ),
			esc_attr( $ttl )
		);
		echo '<p class="description">' . esc_html__( 'Duration for cached schema and endpoint payloads in seconds.', 'ai-visibility-enhancer' ) . '</p>';
	}

	/**
	 * Renders the settings page content.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Visibility Enhancer', 'ai-visibility-enhancer' ); ?></h1>

			<?php if ( $this->feed ) :
				$manifest_url  = $this->feed->get_manifest_url();
				$manifest_path = $this->feed->get_manifest_path();
				if ( $manifest_url && file_exists( $manifest_path ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'Download the latest AI agent manifest generated by the plugin:', 'ai-visibility-enhancer' ); ?>
						<a href="<?php echo esc_url( $manifest_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View AI manifest', 'ai-visibility-enhancer' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'The AI manifest will be generated after publishing public content.', 'ai-visibility-enhancer' ); ?></p>
				<?php endif;
			endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'aive_settings_group' );
				do_settings_sections( 'ai-visibility-enhancer' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitizes the settings payload.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public function sanitize_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		$settings['default_summary_length'] = max(
			30,
			min( 400, isset( $settings['default_summary_length'] ) ? (int) $settings['default_summary_length'] : $this->defaults['default_summary_length'] )
		);
		$settings['cache_ttl'] = max(
			60,
			min( DAY_IN_SECONDS, isset( $settings['cache_ttl'] ) ? (int) $settings['cache_ttl'] : $this->defaults['cache_ttl'] )
		);

                $settings['expose_keywords']       = ! empty( $settings['expose_keywords'] );
                $settings['enable_cache']          = ! empty( $settings['enable_cache'] );
                $settings['allow_public_endpoint'] = ! empty( $settings['allow_public_endpoint'] );

                $allowed_strategies          = array( 'manual', 'excerpt', 'first_paragraph', 'fallback' );
                $settings['summary_strategy'] = in_array( isset( $settings['summary_strategy'] ) ? $settings['summary_strategy'] : '', $allowed_strategies, true )
                        ? $settings['summary_strategy']
                        : $this->defaults['summary_strategy'];

                $whitelist = array();
                if ( isset( $settings['user_agent_whitelist'] ) ) {
                        $lines = explode( "\n", $settings['user_agent_whitelist'] );
                        foreach ( $lines as $line ) {
                                $line = trim( wp_strip_all_tags( $line ) );
                                if ( '' !== $line ) {
                                        $whitelist[] = $line;
                                }
                        }
                }
                $settings['user_agent_whitelist'] = $whitelist;

                $options = array_keys( $this->get_manifest_field_options() );
                $fields  = array();

                if ( isset( $settings['manifest_fields'] ) && is_array( $settings['manifest_fields'] ) ) {
                        $fields = array_values( array_intersect( $options, array_map( 'sanitize_key', $settings['manifest_fields'] ) ) );
                }

                if ( empty( $fields ) ) {
                        $fields = $this->defaults['manifest_fields'];
                }

                $settings['manifest_fields'] = $fields;

                return $settings;
        }
}
