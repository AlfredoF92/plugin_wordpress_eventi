<?php
/**
 * Plugin Name: Gestione Eventi CRAL
 * Description: Gestione eventi, soci e prenotazioni per CRAL aziendale.
 * Version: 0.1.0
 * Author: Alfredo F.
 * Text Domain: gestione-eventi-cral
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GEC_VERSION', '0.1.0' );
define( 'GEC_PLUGIN_FILE', __FILE__ );
define( 'GEC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GEC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GEC_PLUGIN_DIR . 'includes/class-gec-activator.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-post-types.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-admin-menu.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-members.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-bookings.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-demo-data.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-brand.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-auth.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-elementor-dynamic-tags.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-query-settings.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-elementor-query.php';
require_once GEC_PLUGIN_DIR . 'includes/class-gec-elementor-widgets.php';

register_activation_hook( __FILE__, array( 'GEC_Activator', 'activate' ) );

/**
 * Bootstrap plugin.
 */
function gec_bootstrap() {
	$post_types = new GEC_Post_Types();
	$post_types->register();

	$members  = new GEC_Members();
	$bookings = new GEC_Bookings();
	$brand    = new GEC_Brand();
	$auth     = new GEC_Auth();
	$elementor_tags = new GEC_Elementor_Dynamic_Tags();
	$elementor_tags->register();
	$elementor_widgets = new GEC_Elementor_Widgets();
	$elementor_widgets->register();

	$query_settings = new GEC_Query_Settings();
	$elementor_query = new GEC_Elementor_Query( $query_settings );
	$elementor_query->register();

	$admin_menu = new GEC_Admin_Menu( $members, $bookings, $brand, $query_settings );
	$admin_menu->register();

	if ( is_admin() ) {
		GEC_Activator::maybe_upgrade();
		GEC_Demo_Data::maybe_seed();
	}
}

add_action( 'plugins_loaded', 'gec_bootstrap' );

