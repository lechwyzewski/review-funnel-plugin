<?php
/**
 * Plugin Name: Review Funnel Plugin
 * Description: Universal review funnel with star ratings, Google API integration, and modular admin tabs.
 * Version: 1.7.0
 * Author: AI Collaborator
 * Text Domain: review-funnel
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants
define( 'WPRF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPRF_PLUGIN_FILE', __FILE__ );

// Include modular classes
require_once WPRF_PLUGIN_DIR . 'includes/class-wprf-database.php';
require_once WPRF_PLUGIN_DIR . 'includes/class-wprf-admin.php';
require_once WPRF_PLUGIN_DIR . 'includes/class-wprf-frontend.php';

// Register activation / deactivation hooks
register_activation_hook( __FILE__, 'wprf_activate_plugin' );
register_deactivation_hook( __FILE__, 'wprf_deactivate_plugin' );

function wprf_activate_plugin() {
	WPRF_Database::install_table();
	if ( ! wp_next_scheduled( 'wprf_daily_sync_event' ) ) {
		wp_schedule_event( time(), 'daily', 'wprf_daily_sync_event' );
	}
}

function wprf_deactivate_plugin() {
	$timestamp = wp_next_scheduled( 'wprf_daily_sync_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wprf_daily_sync_event' );
	}
}

// Hook daily sync event
add_action( 'wprf_daily_sync_event', 'wprf_run_daily_sync' );

function wprf_run_daily_sync() {
	WPRF_Database::sync_google_reviews();
}

add_action( 'plugins_loaded', 'wprf_init_modules' );

function wprf_init_modules() {
	new WPRF_Admin();
	new WPRF_Frontend();
}