<?php
/**
 * Plugin Name: GrabWP Restore
 * Description: Restore a full WordPress site from a GrabWP tenant export ZIP file.
 * Version:     1.0.4
 * Author:      taicv
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: grabwp-restore
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.2
 * Tested up to: 7.0
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GRABWP_RESTORE_VERSION', '1.0.4' );
define( 'GRABWP_RESTORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRABWP_RESTORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Get the private working directory outside the public uploads tree.
 *
 * @return string
 */
function grabwp_restore_tmp_dir() {
	$auth_key = defined( 'AUTH_KEY' ) ? AUTH_KEY : GRABWP_RESTORE_PLUGIN_DIR;
	$site_key = hash( 'sha256', ABSPATH . $auth_key );
	return trailingslashit( sys_get_temp_dir() ) . 'grabwp-restore-' . substr( $site_key, 0, 24 );
}

add_action( 'plugins_loaded', function () {
	if ( is_admin() ) {
		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-admin.php';
		new GrabWP_Restore_Admin();
	}
} );

add_action( 'grabwp_restore_cleanup_temp', function () {
	require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';
	GrabWP_Restore_File_Restorer::cleanup_stale_dir( grabwp_restore_tmp_dir(), 21600 );
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'grabwp_restore_cleanup_temp' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'grabwp_restore_cleanup_temp' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'grabwp_restore_cleanup_temp' );
	require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';

	$tmp_dir = grabwp_restore_tmp_dir();
	if ( is_dir( $tmp_dir ) ) {
		GrabWP_Restore_File_Restorer::remove_dir( $tmp_dir );
	}

	// Older releases stored working files under uploads/grabwp-restore/.
	$uploads = wp_get_upload_dir();
	if ( ! empty( $uploads['basedir'] ) ) {
		$legacy = trailingslashit( $uploads['basedir'] ) . 'grabwp-restore';
		if ( is_dir( $legacy ) ) {
			GrabWP_Restore_File_Restorer::remove_dir( $legacy );
		}
	}
} );
