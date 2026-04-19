<?php
/**
 * Plugin Name:       VTC Training Planner
 * Description:       Trainingsrooster en Nevobo-wedstrijden: beheer in wp-admin, weekoverzicht op de site (geen autoplanner).
 * Version:           0.2.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            VTC Woerden
 * Text Domain:       vtc-training-planner
 * Domain Path:       /languages
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VTC_TP_VERSION', '0.2.21' );
define( 'VTC_TP_FILE', __FILE__ );
define( 'VTC_TP_DIR', plugin_dir_path( __FILE__ ) );
define( 'VTC_TP_URL', plugin_dir_url( __FILE__ ) );

require_once VTC_TP_DIR . 'includes/class-vtc-tp-activator.php';
require_once VTC_TP_DIR . 'includes/class-vtc-tp-db.php';
require_once VTC_TP_DIR . 'includes/class-vtc-tp-nevobo.php';
require_once VTC_TP_DIR . 'includes/class-vtc-tp-schedule.php';
require_once VTC_TP_DIR . 'includes/class-vtc-tp-rest-admin.php';
require_once VTC_TP_DIR . 'admin/class-vtc-tp-admin.php';
require_once VTC_TP_DIR . 'public/class-vtc-tp-public.php';

register_activation_hook( __FILE__, array( 'VTC_TP_Activator', 'activate' ) );
add_action( 'plugins_loaded', array( 'VTC_TP_Activator', 'upgrade_schema' ), 5 );

/**
 * Bootstrap plugin.
 */
function vtc_tp_bootstrap() {
	load_plugin_textdomain( 'vtc-training-planner', false, dirname( plugin_basename( VTC_TP_FILE ) ) . '/languages' );

	$db       = new VTC_TP_DB();
	$nevobo   = new VTC_TP_Nevobo( $db );
	$schedule = new VTC_TP_Schedule( $db );
	new VTC_TP_Rest_Admin( $db );

	if ( is_admin() ) {
		new VTC_TP_Admin( $db, $nevobo, $schedule );
	}

	new VTC_TP_Public( $db, $nevobo, $schedule );
}
add_action( 'plugins_loaded', 'vtc_tp_bootstrap' );
