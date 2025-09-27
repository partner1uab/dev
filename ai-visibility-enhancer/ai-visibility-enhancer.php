<?php
/**
 * Plugin Name: AI Visibility Enhancer
 * Plugin URI:  https://example.com/ai-visibility-enhancer
 * Description: Enhances WordPress content visibility for AI crawlers by exposing structured data, custom summaries, and dedicated endpoints.
 * Version:     1.0.0
 * Author:      OpenAI Assistant
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-visibility-enhancer
 */

defined( 'ABSPATH' ) || exit;

define( 'AIVE_PLUGIN_VERSION', '1.0.0' );
define( 'AIVE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIVE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIVE_PLUGIN_DIR . 'includes/class-ai-visibility-settings.php';
require_once AIVE_PLUGIN_DIR . 'includes/class-ai-visibility-meta.php';
require_once AIVE_PLUGIN_DIR . 'includes/class-ai-visibility-rest-controller.php';

/**
 * Bootstraps the plugin.
 *
 * @return void
 */
function aive_bootstrap() {
	$settings        = new AI_Visibility_Settings();
	$meta            = new AI_Visibility_Meta( $settings );
	$rest_controller = new AI_Visibility_REST_Controller( $settings );

	$settings->register();
	$meta->register();
	$rest_controller->register();
}

aive_bootstrap();
