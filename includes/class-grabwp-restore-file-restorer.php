<?php
/**
 * File restoration with rename-to-.old safety strategy.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GrabWP_Restore_File_Restorer {

	/**
	 * Rename existing directory to {name}.old, create fresh empty one.
	 * Optionally preserves a subdirectory (e.g. grabwp-restore/ in plugins/).
	 *
	 * @param string $dir             Absolute path to directory.
	 * @param string $preserve_subdir Subdirectory name to preserve. Optional.
	 * @return true|WP_Error
	 */
	public static function rename_to_old( $dir, $preserve_subdir = '' ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			return true;
		}

		$old_dir = rtrim( $dir, '/' ) . '.old';

		if ( is_dir( $old_dir ) ) {
			self::remove_dir( $old_dir );
		}

		if ( $preserve_subdir ) {
			$src_sub = $dir . '/' . $preserve_subdir;
			$tmp_sub = dirname( $dir ) . '/_grabwp_restore_tmp';

			if ( is_dir( $src_sub ) ) {
				self::recurse_copy( $src_sub, $tmp_sub );
			}

			if ( ! rename( $dir, $old_dir ) ) {
				return new WP_Error( 'rename_failed', 'Could not rename ' . $dir . ' to ' . $old_dir );
			}
			wp_mkdir_p( $dir );

			if ( is_dir( $tmp_sub ) ) {
				self::recurse_copy( $tmp_sub, $dir . '/' . $preserve_subdir );
				self::remove_dir( $tmp_sub );
			}
		} else {
			if ( ! rename( $dir, $old_dir ) ) {
				return new WP_Error( 'rename_failed', 'Could not rename ' . $dir . ' to ' . $old_dir );
			}
			wp_mkdir_p( $dir );
		}

		return true;
	}

	/**
	 * Copy source directory contents into destination recursively.
	 * Skips symlinks for security.
	 *
	 * @param string $src Source directory path.
	 * @param string $dst Destination directory path.
	 * @return bool
	 */
	public static function recurse_copy( $src, $dst ) {
		if ( ! is_dir( $src ) ) {
			return false;
		}
		wp_mkdir_p( $dst );
		$dir = opendir( $src );
		if ( ! $dir ) {
			return false;
		}
		while ( false !== ( $entry = readdir( $dir ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$src_path = $src . '/' . $entry;
			$dst_path = $dst . '/' . $entry;
			if ( is_link( $src_path ) ) {
				continue;
			}
			if ( is_dir( $src_path ) ) {
				self::recurse_copy( $src_path, $dst_path );
			} else {
				copy( $src_path, $dst_path );
			}
		}
		closedir( $dir );
		return true;
	}

	/**
	 * Recursively delete a directory and all contents.
	 *
	 * @param string $dir Directory to delete.
	 */
	public static function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $entry ) {
			if ( $entry->isLink() ) {
				unlink( $entry->getPathname() );
			} elseif ( $entry->isDir() ) {
				rmdir( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
