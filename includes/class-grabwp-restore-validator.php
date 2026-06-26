<?php
/**
 * ZIP archive validator for GrabWP Restore.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GrabWP_Restore_Validator {

	/**
	 * Validate that the ZIP file is a valid GrabWP export.
	 *
	 * @param string $zip_path Absolute path to uploaded ZIP.
	 * @return true|WP_Error
	 */
	public function validate_zip( $zip_path ) {
		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'no_archive', 'Archive file not found.' );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', 'PHP ZipArchive extension is required.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'bad_zip', 'Invalid or corrupted ZIP archive.' );
		}

		$has_sql  = false;
		$has_meta = false;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( 'database.sql' === $name ) {
				$has_sql = true;
			}
			if ( 'metadata.json' === $name ) {
				$has_meta = true;
			}
		}
		$zip->close();

		if ( ! $has_sql ) {
			return new WP_Error( 'missing_sql', 'Archive is missing database.sql.' );
		}
		if ( ! $has_meta ) {
			return new WP_Error( 'missing_meta', 'Archive is missing metadata.json.' );
		}

		return true;
	}

	/**
	 * Extract ZIP to temp directory with path traversal protection.
	 *
	 * @param string $zip_path    Absolute path to ZIP file.
	 * @param string $extract_dir Target extraction directory.
	 * @return true|WP_Error
	 */
	public function extract_zip( $zip_path, $extract_dir ) {
		if ( ! wp_mkdir_p( $extract_dir ) ) {
			return new WP_Error( 'mkdir_fail', 'Cannot create temp directory.' );
		}

		file_put_contents( $extract_dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $extract_dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open', 'Cannot open archive for extraction.' );
		}

		$real_extract = realpath( $extract_dir );

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== strpos( $name, '..' ) || 0 === strpos( $name, '/' ) ) {
				$zip->close();
				return new WP_Error( 'path_traversal', 'Archive contains unsafe file paths.' );
			}

			$dest = $real_extract . '/' . $name;
			if ( '/' === substr( $name, -1 ) ) {
				wp_mkdir_p( $dest );
				continue;
			}

			wp_mkdir_p( dirname( $dest ) );

			$content = $zip->getFromIndex( $i );
			if ( false === $content ) {
				$zip->close();
				require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';
				GrabWP_Restore_File_Restorer::remove_dir( $extract_dir );
				return new WP_Error( 'extract_fail', 'Cannot read archive entry: ' . $name );
			}

			file_put_contents( $dest, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing extracted archive entry to validated temp path.

			$real_dest = realpath( $dest );
			if ( false === $real_dest || 0 !== strpos( $real_dest, $real_extract ) ) {
				$zip->close();
				require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-file-restorer.php';
				GrabWP_Restore_File_Restorer::remove_dir( $extract_dir );
				return new WP_Error( 'path_escape', 'Extracted path escapes target directory.' );
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Parse metadata.json from extracted archive.
	 *
	 * @param string $extract_dir Extraction directory.
	 * @return array|WP_Error Parsed metadata or error.
	 */
	public function parse_metadata( $extract_dir ) {
		$meta_file = $extract_dir . '/metadata.json';
		if ( ! file_exists( $meta_file ) ) {
			return new WP_Error( 'missing_meta', 'metadata.json not found in extracted archive.' );
		}

		$raw  = file_get_contents( $meta_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local extracted metadata file.
		$meta = json_decode( $raw, true );
		if ( ! is_array( $meta ) ) {
			return new WP_Error( 'bad_meta', 'metadata.json is invalid JSON.' );
		}

		if ( empty( $meta['tenant_prefix'] ) ) {
			return new WP_Error( 'no_prefix', 'metadata.json is missing tenant_prefix field.' );
		}

		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $meta['tenant_prefix'] ) ) {
			return new WP_Error( 'bad_prefix', 'metadata.json tenant_prefix contains invalid characters.' );
		}

		return $meta;
	}

	/**
	 * Get the source table prefix from parsed metadata.
	 *
	 * @param array $meta Parsed metadata array.
	 * @return string Source prefix.
	 */
	public function get_source_prefix( $meta ) {
		return $meta['tenant_prefix'] ?? 'wp_';
	}
}
