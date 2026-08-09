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
	 * Optionally preserves a subdirectory (e.g. this plugin under plugins/).
	 *
	 * @param string $dir             Absolute path to directory.
	 * @param string $preserve_subdir Subdirectory name to preserve. Optional.
	 * @return true|WP_Error
	 */
	public static function rename_to_old( $dir, $preserve_subdir = '' ) {
		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
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
			// Stage outside the web root — never under wp-content/.
			$tmp_sub = trailingslashit( grabwp_restore_tmp_dir() ) . '_preserve_' . bin2hex( random_bytes( 8 ) );

			if ( is_dir( $src_sub ) ) {
				if ( ! wp_mkdir_p( grabwp_restore_tmp_dir() ) || ! self::recurse_copy( $src_sub, $tmp_sub ) ) {
					return new WP_Error( 'preserve_failed', __( 'Could not stage the plugin directory outside the web root.', 'grabwp-restore' ) );
				}
			}

			if ( ! $filesystem->move( $dir, $old_dir, true ) ) {
				if ( is_dir( $tmp_sub ) ) {
					self::remove_dir( $tmp_sub );
				}
				return new WP_Error( 'rename_failed', __( 'Could not preserve the existing directory.', 'grabwp-restore' ) );
			}
			wp_mkdir_p( $dir );

			if ( is_dir( $tmp_sub ) ) {
				self::recurse_copy( $tmp_sub, $dir . '/' . $preserve_subdir );
				self::remove_dir( $tmp_sub );
			}
		} else {
			if ( ! $filesystem->move( $dir, $old_dir, true ) ) {
				return new WP_Error( 'rename_failed', __( 'Could not preserve the existing directory.', 'grabwp-restore' ) );
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
		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return false;
		}
		if ( ! is_dir( $src ) ) {
			return false;
		}
		wp_mkdir_p( $dst );
		$entries = $filesystem->dirlist( $src, true, false );
		if ( false === $entries ) {
			return false;
		}
		foreach ( $entries as $name => $entry ) {
			$src_path = $src . '/' . $name;
			$dst_path = $dst . '/' . $name;
			if ( is_link( $src_path ) ) {
				continue;
			}
			if ( 'd' === $entry['type'] ) {
				if ( ! self::recurse_copy( $src_path, $dst_path ) ) {
					return false;
				}
			} elseif ( ! $filesystem->copy( $src_path, $dst_path, true, FS_CHMOD_FILE ) ) {
				return false;
			}
		}
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
		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return;
		}
		self::delete_tree( $filesystem, $dir );
	}

	public static function cleanup_stale_dir( $dir, $ttl ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$filesystem = self::filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return;
		}
		$newest = self::newest_mtime( $filesystem, $dir );
		if ( $newest && ( time() - $newest ) > $ttl ) {
			self::delete_tree( $filesystem, $dir );
		}
	}

	private static function delete_tree( $filesystem, $path ) {
		if ( is_link( $path ) ) {
			return $filesystem->delete( $path, false, 'f' );
		}
		$entries = $filesystem->dirlist( $path, true, false );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $name => $entry ) {
				$child = $path . '/' . $name;
				if ( is_link( $child ) ) {
					$filesystem->delete( $child, false, 'f' );
				} elseif ( 'd' === $entry['type'] ) {
					self::delete_tree( $filesystem, $child );
				} else {
					$filesystem->delete( $child, false, 'f' );
				}
			}
		}
		return $filesystem->delete( $path, false, 'd' );
	}

	private static function newest_mtime( $filesystem, $dir ) {
		$newest  = 0;
		$entries = $filesystem->dirlist( $dir, true, false );
		if ( ! is_array( $entries ) ) {
			return $newest;
		}
		foreach ( $entries as $name => $entry ) {
			$newest = max( $newest, (int) ( $entry['lastmodunix'] ?? 0 ) );
			if ( 'd' === $entry['type'] ) {
				$newest = max( $newest, self::newest_mtime( $filesystem, $dir . '/' . $name ) );
			}
		}
		return $newest;
	}

	private static function filesystem() {
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
}
