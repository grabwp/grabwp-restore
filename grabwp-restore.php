<?php
/**
 * Plugin Name: GrabWP Restore
 * Description: Restore a full WordPress site from a GrabWP tenant export ZIP file.
 * Version:     1.0.2
 * Author:      taicv
 * License:     GPL-2.0-or-later
 * Text Domain: grabwp-restore
 * Requires PHP: 7.4
 * Requires at least: 6.2
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GRABWP_RESTORE_VERSION', '1.0.2' );
define( 'GRABWP_RESTORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRABWP_RESTORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GRABWP_RESTORE_TMP_DIR', wp_upload_dir()['basedir'] . '/grabwp-restore' );

add_action( 'plugins_loaded', function () {
	if ( is_admin() ) {
		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-admin.php';
		new GrabWP_Restore_Admin();
	}
} );

register_deactivation_hook( __FILE__, function () {
	if ( is_dir( GRABWP_RESTORE_TMP_DIR ) ) {
		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';
		GrabWP_Restore_File_Restorer::remove_dir( GRABWP_RESTORE_TMP_DIR );
	}
} );
