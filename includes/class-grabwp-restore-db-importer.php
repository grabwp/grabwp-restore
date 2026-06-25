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
	 * Import database from SQL file with prefix rewriting and collation downgrade.
	 *
	 * @param string $sql_file    Absolute path to database.sql.
	 * @param string $src_prefix  Source table prefix from metadata.json.
	 * @param string $dst_prefix  Target table prefix ($table_prefix).
	 * @param string $current_url Current site URL captured BEFORE import.
	 * @return true|WP_Error
	 */
	public function import( $sql_file, $src_prefix, $dst_prefix, $current_url ) {
		global $wpdb;

		if ( ! file_exists( $sql_file ) ) {
			return new WP_Error( 'no_sql', 'database.sql not found.' );
		}

		@set_time_limit( 300 );

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$wpdb->query( "SET SESSION sql_mode = ''" );

		$this->drop_existing_tables( $wpdb, $dst_prefix );

		$errors = $this->execute_streaming( $wpdb, $sql_file, $src_prefix, $dst_prefix );

		$this->rewrite_prefix_in_data( $wpdb, $src_prefix, $dst_prefix );

		$this->update_site_url( $wpdb, $dst_prefix, $current_url );

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

		if ( ! empty( $errors ) ) {
			error_log( '[GrabWP Restore] SQL errors: ' . implode( '; ', array_slice( $errors, 0, 10 ) ) );
		}

		return true;
	}

	private function drop_existing_tables( $wpdb, $prefix ) {
		$tables = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	private function execute_streaming( $wpdb, $sql_file, $src_prefix, $dst_prefix ) {
		$handle = fopen( $sql_file, 'r' );
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
						error_log( '[GrabWP Restore] Skipping oversized statement (' . strlen( $statement ) . ' bytes)' );
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

		fclose( $handle );
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
					"UPDATE `{$table}` SET `{$col_name}` = REPLACE(`{$col_name}`, %s, %s) WHERE `{$col_name}` LIKE %s",
					$src_prefix,
					$dst_prefix,
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
			$wpdb->update(
				$table,
				[ 'option_value' => $current_url ],
				[ 'option_name' => $option ],
				[ '%s' ],
				[ '%s' ]
			);
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
