<?php
/**
 * Database importer with streaming SQL execution and prefix rewriting.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GrabWP_Restore_Db_Importer {

	/**
	 * Import database from SQL file with prefix rewriting, collation downgrade,
	 * and post-import URL search-and-replace.
	 *
	 * @param string $zip_path    Absolute path to the validated ZIP archive.
	 * @param string $src_prefix  Source table prefix from metadata.json.
	 * @param string $dst_prefix  Target table prefix ($table_prefix).
	 * @param string $current_url Current site URL captured BEFORE import.
	 * @param array  $meta        Parsed metadata.json from the archive.
	 * @return true|WP_Error
	 */
	public function import( $zip_path, $src_prefix, $dst_prefix, $current_url, $meta = [] ) {
		global $wpdb;

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'no_archive', __( 'Restore archive not found.', 'grabwp-restore' ) );
		}

		$validation = $this->validate_sql_archive( $wpdb, $zip_path, $src_prefix, $dst_prefix );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$wpdb->query( "SET SESSION sql_mode = ''" );

		$this->drop_existing_tables( $wpdb, $dst_prefix );

		$errors = $this->execute_streaming( $wpdb, $zip_path, $src_prefix, $dst_prefix );
		if ( is_wp_error( $errors ) ) {
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
			return $errors;
		}

		$this->rewrite_prefix_in_data( $wpdb, $src_prefix, $dst_prefix );

		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-url-replacer.php';
		$url_replacer = new GrabWP_Restore_Url_Replacer();
		$old_url      = $url_replacer->read_siteurl( $dst_prefix );

		$this->update_site_url( $wpdb, $dst_prefix, $current_url );

		if ( ! empty( $old_url ) && $old_url !== $current_url ) {
			$url_replacer->replace( $dst_prefix, $old_url, $current_url );
		}

		$this->replace_cdn_urls( $url_replacer, $dst_prefix, $meta );

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'sql_import_failed',
				sprintf(
					__( 'Database import failed: %s', 'grabwp-restore' ),
					implode( '; ', array_slice( $errors, 0, 3 ) )
				)
			);
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

	private function validate_sql_archive( $wpdb, $zip_path, $src_prefix, $dst_prefix ) {
		$max_packet = $this->get_max_allowed_packet( $wpdb );
		return $this->walk_sql_statements(
			$zip_path,
			function ( $statement ) use ( $wpdb, $src_prefix, $dst_prefix, $max_packet ) {
				$validation = $this->validate_sql_statement( $statement, $src_prefix );
				if ( is_wp_error( $validation ) || $this->should_skip_statement( $statement ) ) {
					return $validation;
				}
				$prepared_sql = $this->replace_prefixes( $statement, $src_prefix, $dst_prefix );
				$prepared_sql = $this->replace_collations( $wpdb, $prepared_sql );
				$prepared_sql = $this->fix_text_defaults( trim( $prepared_sql ) );
				if ( strlen( $prepared_sql ) > $max_packet ) {
					return new WP_Error( 'oversized_sql', __( 'Archive contains a database statement larger than the server limit.', 'grabwp-restore' ) );
				}
				return true;
			}
		);
	}

	private function execute_streaming( $wpdb, $zip_path, $src_prefix, $dst_prefix ) {
		$errors     = [];
		$max_packet = $this->get_max_allowed_packet( $wpdb );
		$result     = $this->walk_sql_statements(
			$zip_path,
			function ( $statement ) use ( $wpdb, $src_prefix, $dst_prefix, $max_packet, &$errors ) {
				if ( $this->should_skip_statement( $statement ) ) {
					return true;
				}
				$prepared_sql = $this->replace_prefixes( $statement, $src_prefix, $dst_prefix );
				$prepared_sql = $this->replace_collations( $wpdb, $prepared_sql );
				$prepared_sql = $this->fix_text_defaults( trim( $prepared_sql ) );
				if ( strlen( $prepared_sql ) > $max_packet ) {
					return new WP_Error( 'oversized_sql', __( 'Archive contains a database statement larger than the server limit.', 'grabwp-restore' ) );
				}
				$wpdb->query( $prepared_sql );
				if ( ! empty( $wpdb->last_error ) ) {
					$errors[] = $wpdb->last_error;
				}
				return true;
			}
		);
		return is_wp_error( $result ) ? $result : $errors;
	}

	private function walk_sql_statements( $zip_path, $callback ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open', __( 'Cannot open restore archive.', 'grabwp-restore' ) );
		}
		$stream = $zip->getStream( 'database.sql' );
		if ( false === $stream ) {
			$zip->close();
			return new WP_Error( 'missing_sql', __( 'Archive database.sql could not be read.', 'grabwp-restore' ) );
		}

		$current_statement = '';
		while ( false !== ( $line = fgets( $stream ) ) ) {
			$line    = rtrim( $line, "\r\n" );
			$trimmed = ltrim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '#' ) ) {
				continue;
			}
			$current_statement .= $line . "\n";
			if ( ';' !== substr( rtrim( $line ), -1 ) ) {
				continue;
			}
			$result = call_user_func( $callback, trim( $current_statement ) );
			if ( is_wp_error( $result ) ) {
				unset( $stream );
				$zip->close();
				return $result;
			}
			$current_statement = '';
		}

		if ( '' !== trim( $current_statement ) ) {
			$result = call_user_func( $callback, trim( $current_statement ) );
			if ( is_wp_error( $result ) ) {
				unset( $stream );
				$zip->close();
				return $result;
			}
		}
		unset( $stream );
		$zip->close();
		return true;
	}

	private function validate_sql_statement( $statement, $src_prefix ) {
		if ( $this->should_skip_statement( $statement ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:INTO\s+(?:OUTFILE|DUMPFILE)|LOAD\s+DATA|LOAD_FILE|CREATE\s+(?:FUNCTION|PROCEDURE|TRIGGER|EVENT))\b/i', $statement ) ) {
			return new WP_Error( 'unsafe_sql', __( 'Archive contains a prohibited database operation.', 'grabwp-restore' ) );
		}
		$pattern = '/^(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE)\s+`?([a-zA-Z0-9_]+)`?/i';
		if ( ! preg_match( $pattern, ltrim( $statement ), $matches ) || 0 !== strpos( $matches[1], $src_prefix ) ) {
			return new WP_Error( 'unsupported_sql', __( 'Archive contains SQL outside the exported site tables.', 'grabwp-restore' ) );
		}
		if ( 0 === stripos( ltrim( $statement ), 'ALTER TABLE' ) && preg_match( '/\bRENAME\b/i', $statement ) ) {
			return new WP_Error( 'unsafe_alter', __( 'Archive attempts to rename a database table.', 'grabwp-restore' ) );
		}
		return true;
	}

	private function should_skip_statement( $statement ) {
		$statement = ltrim( $statement );
		return (bool) preg_match( '/^(?:\/\*|SET\b|LOCK\s+TABLES\b|UNLOCK\s+TABLES\b|START\s+TRANSACTION\b|COMMIT\b)/i', $statement );
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
