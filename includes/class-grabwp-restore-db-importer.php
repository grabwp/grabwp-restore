<?php
/**
 * Database importer with streaming SQL execution and prefix rewriting.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Entire class executes raw SQL for database restoration.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL statements are read from export dump files, not user input.
class GrabWP_Restore_Db_Importer {

	/**
	 * Import database from SQL file with prefix rewriting, collation downgrade,
	 * and post-import URL search-and-replace.
	 *
	 * @param string $sql_file    Absolute path to database.sql.
	 * @param string $src_prefix  Source table prefix from metadata.json.
	 * @param string $dst_prefix  Target table prefix ($table_prefix).
	 * @param string $current_url Current site URL captured BEFORE import.
	 * @param array  $meta        Parsed metadata.json from the archive.
	 * @return true|WP_Error
	 */
	public function import( $sql_file, $src_prefix, $dst_prefix, $current_url, $meta = [] ) {
		global $wpdb;

		if ( ! file_exists( $sql_file ) ) {
			return new WP_Error( 'no_sql', 'database.sql not found.' );
		}

		@set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large SQL imports need extended execution time.

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$wpdb->query( "SET SESSION sql_mode = ''" );

		$this->drop_existing_tables( $wpdb, $dst_prefix );

		$errors = $this->execute_streaming( $wpdb, $sql_file, $src_prefix, $dst_prefix );

		$this->rewrite_prefix_in_data( $wpdb, $src_prefix, $dst_prefix );

		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-url-replacer.php';
		$url_replacer = new GrabWP_Restore_Url_Replacer();
		$old_url      = $url_replacer->read_siteurl( $dst_prefix );

		$this->update_site_url( $wpdb, $dst_prefix, $current_url );

		if ( ! empty( $old_url ) && $old_url !== $current_url ) {
			@set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- URL replacement on large databases needs extended execution time.
			$url_replacer->replace( $dst_prefix, $old_url, $current_url );
		}

		$this->replace_cdn_urls( $url_replacer, $dst_prefix, $meta );

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		if ( ! empty( $errors ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[GrabWP Restore] SQL errors: ' . implode( '; ', array_slice( $errors, 0, 10 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging gated behind WP_DEBUG.
		}

		return true;
	}

	/**
	 * Replace CDN/S3 media URLs with local upload URLs after restore.
	 *
	 * When the source site used S3 storage with a CDN URL, media references in
	 * post_content point to {cdn_url}/{tenant_id}/... which won't work on the
	 * target site. Replace them with the local uploads base URL.
	 *
	 * @param GrabWP_Restore_Url_Replacer $replacer   URL replacer instance.
	 * @param string                      $dst_prefix Target table prefix.
	 * @param array                       $meta       Parsed metadata.json.
	 */
	private function replace_cdn_urls( $replacer, $dst_prefix, $meta ) {
		$cdn_url   = $meta['cdn_url'] ?? '';
		$tenant_id = $meta['tenant_id'] ?? '';

		if ( empty( $cdn_url ) || empty( $tenant_id ) ) {
			return;
		}

		$old_cdn_base = rtrim( $cdn_url, '/' ) . '/' . $tenant_id;
		$upload_dir   = wp_upload_dir();
		$new_base     = $upload_dir['baseurl'];

		if ( ! empty( $new_base ) && $old_cdn_base !== $new_base ) {
			@set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			$replacer->replace( $dst_prefix, $old_cdn_base, $new_base );
		}
	}

	private function drop_existing_tables( $wpdb, $prefix ) {
		$tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);
		foreach ( $tables as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	private function execute_streaming( $wpdb, $sql_file, $src_prefix, $dst_prefix ) {
		$handle = fopen( $sql_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming line-by-line reads; WP_Filesystem has no line iterator.
		if ( ! $handle ) {
			return [ 'Could not open SQL file: ' . $sql_file ];
		}

		$errors            = [];
		$current_statement = '';
		$max_packet        = $this->get_max_allowed_packet( $wpdb );

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line || 0 === strpos( ltrim( $line ), '--' ) ) {
				continue;
			}
			$current_statement .= $line . "\n";

			if ( ';' === substr( rtrim( $line ), -1 ) ) {
				$statement = $this->replace_prefixes( $current_statement, $src_prefix, $dst_prefix );
				$statement = $this->replace_collations( $wpdb, $statement );
				$statement = trim( $statement );

				if ( '' !== $statement ) {
					if ( strlen( $statement ) > $max_packet ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( '[GrabWP Restore] Skipping oversized statement (' . strlen( $statement ) . ' bytes)' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					} else {
						$statement = $this->fix_text_defaults( $statement );
						$wpdb->query( $statement );
						if ( ! empty( $wpdb->last_error ) ) {
							$errors[] = $wpdb->last_error . ' [SQL: ' . substr( $statement, 0, 120 ) . ']';
						}
					}
				}
				$current_statement = '';
			}
		}

		if ( '' !== trim( $current_statement ) ) {
			$statement = $this->replace_prefixes( $current_statement, $src_prefix, $dst_prefix );
			$statement = $this->replace_collations( $wpdb, $statement );
			$statement = trim( $statement );
			if ( '' !== $statement && strlen( $statement ) <= $max_packet ) {
				$statement = $this->fix_text_defaults( $statement );
				$wpdb->query( $statement );
				if ( ! empty( $wpdb->last_error ) ) {
					$errors[] = $wpdb->last_error;
				}
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $errors;
	}

	private function replace_prefixes( $sql, $src_prefix, $dst_prefix ) {
		if ( $src_prefix === $dst_prefix ) {
			return $sql;
		}
		return preg_replace_callback(
			'/`' . preg_quote( $src_prefix, '/' ) . '/',
			function () use ( $dst_prefix ) {
				return '`' . $dst_prefix;
			},
			$sql
		);
	}

	private function replace_collations( $wpdb, $sql ) {
		if ( $wpdb->has_cap( 'utf8mb4_520' ) ) {
			return str_replace( 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_ci', $sql );
		}
		if ( $wpdb->has_cap( 'utf8mb4' ) ) {
			return str_replace(
				[ 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_ci' ],
				[ 'utf8mb4_unicode_ci', 'utf8mb4_unicode_ci' ],
				$sql
			);
		}
		return str_replace(
			[ 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_ci', 'utf8mb4' ],
			[ 'utf8_unicode_ci', 'utf8_unicode_ci', 'utf8' ],
			$sql
		);
	}

	private function rewrite_prefix_in_data( $wpdb, $src_prefix, $dst_prefix ) {
		if ( $src_prefix === $dst_prefix ) {
			return;
		}

		$prefix_columns = [
			$dst_prefix . 'options'  => [ 'option_name' ],
			$dst_prefix . 'usermeta' => [ 'meta_key' ],
		];

		foreach ( $prefix_columns as $table => $columns ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! $exists ) {
				continue;
			}
			foreach ( $columns as $col_name ) {
				$wpdb->query( $wpdb->prepare(
					'UPDATE %i SET %i = REPLACE(%i, %s, %s) WHERE %i LIKE %s',
					$table,
					$col_name,
					$col_name,
					$src_prefix,
					$dst_prefix,
					$col_name,
					$wpdb->esc_like( $src_prefix ) . '%'
				) );
			}
		}
	}

	private function update_site_url( $wpdb, $dst_prefix, $current_url ) {
		$table  = $dst_prefix . 'options';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! $exists ) {
			return;
		}

		foreach ( [ 'siteurl', 'home' ] as $option ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s',
				$table,
				$current_url,
				$option
			) );
		}
	}

	private function fix_text_defaults( $sql ) {
		if ( stripos( $sql, 'CREATE TABLE' ) === false ) {
			return $sql;
		}
		return preg_replace(
			'/(`\w+`\s+(?:(?:tiny|medium|long)?(?:text|blob)|json|geometry))\s+(NOT\s+NULL\s+)?DEFAULT\s+(?:\'[^\']*\'|"[^"]*"|NULL|\d+)/i',
			'$1 $2',
			$sql
		);
	}

	private function get_max_allowed_packet( $wpdb ) {
		$row = $wpdb->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_A );
		return isset( $row['Value'] ) ? (int) $row['Value'] : PHP_INT_MAX;
	}
}
