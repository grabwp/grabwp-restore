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

	public function validate_environment() {
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$uploads = wp_get_upload_dir();
		$parents = [
			dirname( $uploads['basedir'] ),
			dirname( WP_PLUGIN_DIR ),
			dirname( get_theme_root() ),
		];
		foreach ( array_unique( $parents ) as $parent ) {
			if ( ! $filesystem->is_writable( $parent ) ) {
				return new WP_Error( 'destination_not_writable', __( 'A wp-content destination is not writable.', 'grabwp-restore' ) );
			}
		}
		return true;
	}

	/**
	 * Validate that the ZIP file is a valid GrabWP export.
	 *
	 * @param string $zip_path Absolute path to uploaded ZIP.
	 * @return true|WP_Error
	 */
	public function validate_zip( $zip_path ) {
		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'no_archive', __( 'Archive file not found.', 'grabwp-restore' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'PHP ZipArchive extension is required.', 'grabwp-restore' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'bad_zip', __( 'Invalid or corrupted ZIP archive.', 'grabwp-restore' ) );
		}

		$has_sql  = false;
		$has_meta = false;
		$total_size = 0;
		$max_entries = (int) apply_filters( 'grabwp_restore_max_archive_entries', 100000 );
		$max_size    = (int) apply_filters( 'grabwp_restore_max_uncompressed_bytes', 10 * GB_IN_BYTES );
		if ( $zip->numFiles > $max_entries ) {
			$zip->close();
			return new WP_Error( 'too_many_files', __( 'Archive contains too many files.', 'grabwp-restore' ) );
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( ! $this->is_safe_archive_path( $name ) || $this->is_symlink_entry( $zip, $i ) ) {
				$zip->close();
				return new WP_Error( 'unsafe_path', __( 'Archive contains an unsafe file path.', 'grabwp-restore' ) );
			}
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				$zip->close();
				return new WP_Error( 'bad_entry', __( 'Archive contains an unreadable entry.', 'grabwp-restore' ) );
			}
			$total_size += (int) $stat['size'];
			if ( $total_size > $max_size ) {
				$zip->close();
				return new WP_Error( 'archive_too_large', __( 'Archive expands beyond the allowed size.', 'grabwp-restore' ) );
			}
			if ( $stat['size'] > 10 * MB_IN_BYTES && $stat['comp_size'] > 0 && ( $stat['size'] / $stat['comp_size'] ) > 1000 ) {
				$zip->close();
				return new WP_Error( 'unsafe_compression', __( 'Archive contains an unsafe compression ratio.', 'grabwp-restore' ) );
			}
			if ( 'database.sql' === $name ) {
				$has_sql = true;
			}
			if ( 'metadata.json' === $name ) {
				$has_meta = true;
			}
		}
		$zip->close();

		if ( ! $has_sql ) {
			return new WP_Error( 'missing_sql', __( 'Archive is missing database.sql.', 'grabwp-restore' ) );
		}
		if ( ! $has_meta ) {
			return new WP_Error( 'missing_meta', __( 'Archive is missing metadata.json.', 'grabwp-restore' ) );
		}

		return true;
	}

	/**
	 * Extract a subdirectory from the ZIP directly to a target directory.
	 *
	 * Used for plugins/, themes/, and uploads/ — files go straight to their
	 * final wp-content location, never into the uploads temp directory.
	 *
	 * @param string $zip_path   Absolute path to ZIP file.
	 * @param string $subdir     Subdirectory name inside the ZIP (e.g. 'plugins').
	 * @param string $target_dir Absolute path to the final destination directory.
	 * @return true|WP_Error
	 */
	public function extract_subdir( $zip_path, $subdir, $target_dir ) {
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open', __( 'Cannot open archive.', 'grabwp-restore' ) );
		}

		$prefix     = rtrim( $subdir, '/' ) . '/';
		$prefix_len = strlen( $prefix );

		if ( ! wp_mkdir_p( $target_dir ) ) {
			$zip->close();
			return new WP_Error( 'mkdir_fail', __( 'Cannot create the restore destination directory.', 'grabwp-restore' ) );
		}

		$real_target = realpath( $target_dir );
		if ( false === $real_target || is_link( $target_dir ) ) {
			$zip->close();
			return new WP_Error( 'unsafe_target', __( 'Restore destination is not a safe directory.', 'grabwp-restore' ) );
		}
		$target_prefix = trailingslashit( $real_target );

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );

			if ( 0 !== strpos( $name, $prefix ) ) {
				continue;
			}

			$relative = substr( $name, $prefix_len );
			if ( '' === $relative ) {
				continue;
			}
			if ( ! $this->is_safe_archive_path( $relative ) || $this->is_symlink_entry( $zip, $i ) ) {
				$zip->close();
				return new WP_Error( 'path_traversal', __( 'Archive contains an unsafe file path.', 'grabwp-restore' ) );
			}

			if ( 'uploads' === trim( $subdir, '/' ) && $this->is_forbidden_upload_file( $relative ) ) {
				continue;
			}

			$dest = $target_prefix . $relative;

			if ( '/' === substr( $name, -1 ) ) {
				if ( ! wp_mkdir_p( $dest ) ) {
					$zip->close();
					return new WP_Error( 'mkdir_fail', __( 'Cannot create an archive directory.', 'grabwp-restore' ) );
				}
				continue;
			}

			$parent = dirname( $dest );
			if ( ! wp_mkdir_p( $parent ) ) {
				$zip->close();
				return new WP_Error( 'mkdir_fail', __( 'Cannot create an archive directory.', 'grabwp-restore' ) );
			}
			$real_parent = realpath( $parent );
			if ( false === $real_parent || ( $real_parent !== $real_target && 0 !== strpos( trailingslashit( $real_parent ), $target_prefix ) ) || is_link( $dest ) ) {
				$zip->close();
				return new WP_Error( 'path_escape', __( 'Archive path escapes the restore destination.', 'grabwp-restore' ) );
			}

			$content = $zip->getFromIndex( $i );
			if ( false === $content ) {
				$zip->close();
				return new WP_Error( 'extract_fail', __( 'Cannot read an archive entry.', 'grabwp-restore' ) );
			}

			if ( ! $filesystem->put_contents( $dest, $content, FS_CHMOD_FILE ) ) {
				$zip->close();
				return new WP_Error( 'extract_fail', __( 'Cannot write an archive entry.', 'grabwp-restore' ) );
			}
		}

		$zip->close();
		return true;
	}

	private function is_safe_archive_path( $path ) {
		if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( $path, '\\' ) || '/' === $path[0] || preg_match( '/^[a-zA-Z]:/', $path ) ) {
			return false;
		}
		$segments = explode( '/', rtrim( $path, '/' ) );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	private function is_symlink_entry( $zip, $index ) {
		$operations = 0;
		$attributes = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $operations, $attributes ) ) {
			return false;
		}
		$mode = ( $attributes >> 16 ) & 0xFFFF;
		return 0120000 === ( $mode & 0170000 );
	}

	private function is_forbidden_upload_file( $path ) {
		$basename = strtolower( basename( $path ) );
		if ( in_array( $basename, [ '.htaccess', '.user.ini', 'web.config' ], true ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(?:php\d*|phtml|pht|phar|cgi|pl|py|sh)$/i', $basename );
	}

	private function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'filesystem_unavailable', __( 'WordPress could not initialize the filesystem.', 'grabwp-restore' ) );
		}
		global $wp_filesystem;
		if ( 'direct' !== $wp_filesystem->method ) {
			return new WP_Error( 'filesystem_not_direct', __( 'Restore requires direct filesystem access.', 'grabwp-restore' ) );
		}
		return $wp_filesystem;
	}

	/**
	 * Check whether a subdirectory exists inside the ZIP archive.
	 *
	 * @param string $zip_path Absolute path to ZIP file.
	 * @param string $subdir   Subdirectory name (e.g. 'plugins').
	 * @return bool
	 */
	public function has_subdir( $zip_path, $subdir ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return false;
		}

		$prefix = rtrim( $subdir, '/' ) . '/';
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			if ( 0 === strpos( $zip->getNameIndex( $i ), $prefix ) ) {
				$zip->close();
				return true;
			}
		}

		$zip->close();
		return false;
	}

	/**
	 * Parse metadata.json directly from the archive.
	 *
	 * @param string $zip_path Absolute path to ZIP file.
	 * @return array|WP_Error Parsed metadata or error.
	 */
	public function parse_metadata( $zip_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open', __( 'Cannot open archive.', 'grabwp-restore' ) );
		}
		$stat = $zip->statName( 'metadata.json' );
		if ( false === $stat || $stat['size'] > MB_IN_BYTES ) {
			$zip->close();
			return new WP_Error( 'bad_meta_size', __( 'metadata.json is missing or too large.', 'grabwp-restore' ) );
		}
		$raw = $zip->getFromName( 'metadata.json' );
		$zip->close();
		if ( false === $raw ) {
			return new WP_Error( 'missing_meta', __( 'metadata.json could not be read.', 'grabwp-restore' ) );
		}
		$meta = json_decode( $raw, true );
		if ( ! is_array( $meta ) ) {
			return new WP_Error( 'bad_meta', __( 'metadata.json is invalid JSON.', 'grabwp-restore' ) );
		}

		if ( empty( $meta['tenant_prefix'] ) ) {
			return new WP_Error( 'no_prefix', __( 'metadata.json is missing tenant_prefix.', 'grabwp-restore' ) );
		}

		if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $meta['tenant_prefix'] ) ) {
			return new WP_Error( 'bad_prefix', __( 'metadata.json contains an invalid table prefix.', 'grabwp-restore' ) );
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
