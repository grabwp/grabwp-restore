<?php
/**
 * Step dispatcher for GrabWP Restore.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GrabWP_Restore_Step_Controller {

	public function dispatch( $step, $state ) {
		switch ( $step ) {
			case 1:
				return $this->step_validate( $state );
			case 2:
				return $this->step_extract( $state );
			case 3:
				return $this->step_import_database( $state );
			case 4:
				return $this->step_restore_uploads( $state );
			case 5:
				return $this->step_restore_plugins( $state );
			case 6:
				return $this->step_restore_themes( $state );
			case 7:
				return $this->step_cleanup( $state );
		}
		return new WP_Error( 'invalid_step', 'Invalid step.' );
	}

	private function step_validate( $state ) {
		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-validator.php';
		$validator = new GrabWP_Restore_Validator();

		$result = $validator->validate_zip( $state['zip_path'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [ 'message' => __( 'Archive validated.', 'grabwp-restore' ) ];
	}

	private function step_extract( $state ) {
		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-validator.php';
		$validator = new GrabWP_Restore_Validator();

		$extract_dir = GRABWP_RESTORE_TMP_DIR . '/' . $state['ts'];

		$result = $validator->extract_zip( $state['zip_path'], $extract_dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$meta = $validator->parse_metadata( $extract_dir );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		return [
			'message' => __( 'Archive extracted and verified.', 'grabwp-restore' ),
			'data'    => [
				'extract_dir' => $extract_dir,
				'meta'        => $meta,
				'src_prefix'  => $validator->get_source_prefix( $meta ),
			],
		];
	}

	private function step_import_database( $state ) {
		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-db-importer.php';

		$importer    = new GrabWP_Restore_Db_Importer();
		$sql_file    = $state['extract_dir'] . '/database.sql';
		$src_prefix  = $state['src_prefix'];
		$dst_prefix  = $GLOBALS['table_prefix'];
		$current_url = $state['current_url'];
		$meta        = $state['meta'] ?? [];

		$result = $importer->import( $sql_file, $src_prefix, $dst_prefix, $current_url, $meta );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->reactivate_self( $dst_prefix );

		return [
			'message' => __( 'Database imported, prefixes rewritten, and URLs updated.', 'grabwp-restore' ),
			'data'    => [ 'dst_prefix' => $dst_prefix ],
		];
	}

	private function reactivate_self( $dst_prefix ) {
		global $wpdb;

		$table   = $dst_prefix . 'options';
		$self    = 'grabwp-restore/grabwp-restore.php';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Must read freshly imported options table before WP core is aware of it.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from validated prefix, not user input.
		$row     = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s", 'active_plugins' ),
			ARRAY_A
		);
		$plugins = $row ? maybe_unserialize( $row['option_value'] ) : [];
		if ( ! is_array( $plugins ) ) {
			$plugins = [];
		}
		if ( ! in_array( $self, $plugins, true ) ) {
			$plugins[] = $self;
			$wpdb->update(
				$table,
				[ 'option_value' => maybe_serialize( $plugins ) ],
				[ 'option_name' => 'active_plugins' ],
				[ '%s' ],
				[ '%s' ]
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_delete( 'alloptions', 'options' );
	}

	private function step_restore_uploads( $state ) {
		$src = $state['extract_dir'] . '/uploads';
		if ( ! is_dir( $src ) ) {
			return [ 'message' => __( 'No uploads directory in archive, skipped.', 'grabwp-restore' ) ];
		}

		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';
		$dst    = WP_CONTENT_DIR . '/uploads';
		$result = GrabWP_Restore_File_Restorer::rename_to_old( $dst );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		GrabWP_Restore_File_Restorer::recurse_copy( $src, $dst );

		return [ 'message' => __( 'Uploads restored (old files in uploads.old/).', 'grabwp-restore' ) ];
	}

	private function step_restore_plugins( $state ) {
		$src = $state['extract_dir'] . '/plugins';
		if ( ! is_dir( $src ) ) {
			return [ 'message' => __( 'No plugins directory in archive, skipped.', 'grabwp-restore' ) ];
		}

		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';
		$dst    = WP_PLUGIN_DIR;
		$result = GrabWP_Restore_File_Restorer::rename_to_old( $dst, 'grabwp-restore' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		GrabWP_Restore_File_Restorer::recurse_copy( $src, $dst );

		return [ 'message' => __( 'Plugins restored (old plugins in plugins.old/).', 'grabwp-restore' ) ];
	}

	private function step_restore_themes( $state ) {
		$src = $state['extract_dir'] . '/themes';
		if ( ! is_dir( $src ) ) {
			return [ 'message' => __( 'No themes directory in archive, skipped.', 'grabwp-restore' ) ];
		}

		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';
		$dst    = get_theme_root();
		$result = GrabWP_Restore_File_Restorer::rename_to_old( $dst );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		GrabWP_Restore_File_Restorer::recurse_copy( $src, $dst );

		return [ 'message' => __( 'Themes restored (old themes in themes.old/).', 'grabwp-restore' ) ];
	}

	private function step_cleanup( $state ) {
		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';

		if ( ! empty( $state['extract_dir'] ) && is_dir( $state['extract_dir'] ) ) {
			GrabWP_Restore_File_Restorer::remove_dir( $state['extract_dir'] );
		}

		if ( ! empty( $state['zip_path'] ) && file_exists( $state['zip_path'] ) ) {
			wp_delete_file( $state['zip_path'] );
		}

		$upload_tmp = GRABWP_RESTORE_TMP_DIR . '/upload';
		if ( is_dir( $upload_tmp ) ) {
			GrabWP_Restore_File_Restorer::remove_dir( $upload_tmp );
		}

		flush_rewrite_rules();

		$lock_file = GRABWP_RESTORE_TMP_DIR . '/jobs/active.lock';
		if ( file_exists( $lock_file ) ) {
			wp_delete_file( $lock_file );
		}

		return [ 'message' => __( 'Restore complete.', 'grabwp-restore' ) ];
	}
}
