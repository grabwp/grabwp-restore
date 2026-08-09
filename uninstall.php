<?php
/**
 * Uninstall cleanup for GrabWP Restore.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'grabwp_restore_cleanup_temp' );

$auth_key = defined( 'AUTH_KEY' ) ? AUTH_KEY : __DIR__;
$tmp_dir  = trailingslashit( sys_get_temp_dir() ) . 'grabwp-restore-' . substr( hash( 'sha256', ABSPATH . $auth_key ), 0, 24 );

if ( is_dir( $tmp_dir ) ) {
	require_once __DIR__ . '/includes/class-grabwp-restore-file-restorer.php';
	GrabWP_Restore_File_Restorer::remove_dir( $tmp_dir );
}

// Remove any leftover directory from older releases that stored temp files under uploads.
$uploads = wp_get_upload_dir();
if ( ! empty( $uploads['basedir'] ) ) {
	$legacy = trailingslashit( $uploads['basedir'] ) . 'grabwp-restore';
	if ( is_dir( $legacy ) ) {
		require_once __DIR__ . '/includes/class-grabwp-restore-file-restorer.php';
		GrabWP_Restore_File_Restorer::remove_dir( $legacy );
	}
}
