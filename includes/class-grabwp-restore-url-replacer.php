<?php
/**
 * URL replacer for post-restore domain migration.
 *
 * Iterates all restored tables, replacing old domain URLs with new domain
 * in plain text, PHP-serialized data, and BeTheme base64-encoded serialized data.
 *
 * Adapted from GrabWP_Tenancy_Clone_Url_Replacer for standalone use.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Must iterate all restored tables for URL replacement.
class GrabWP_Restore_Url_Replacer {

	/**
	 * Validate that a name is a safe SQL identifier (table or column name).
	 *
	 * @param string $name Identifier to validate.
	 * @return bool
	 */
	private function is_valid_identifier( $name ) {
		return (bool) preg_match( '/^[a-zA-Z0-9_]+$/', $name );
	}

	/**
	 * Replace old URL with new URL across all tables matching $prefix.
	 *
	 * @param string $prefix     Table prefix to match.
	 * @param string $old_url    Source site URL (e.g. https://old.example.com).
	 * @param string $new_url    Target site URL (e.g. https://new.example.com).
	 */
	public function replace( $prefix, $old_url, $new_url ) {
		if ( $old_url === $new_url || empty( $old_url ) ) {
			return;
		}

		global $wpdb;
		$old_domain = preg_replace( '#^https?://#', '', rtrim( $old_url, '/' ) );
		$new_domain = preg_replace( '#^https?://#', '', rtrim( $new_url, '/' ) );

		if ( $old_domain === $new_domain ) {
			return;
		}

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );

		foreach ( $tables as $table ) {
			if ( ! $this->is_valid_identifier( $table ) ) {
				continue;
			}

			$columns   = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
			$pk        = null;
			$text_cols = [];

			foreach ( $columns as $col ) {
				if ( 'PRI' === $col['Key'] ) {
					$pk = $col['Field'];
				}
				if ( preg_match( '/^(varchar|text|longtext|mediumtext|tinytext|char)/i', $col['Type'] ) ) {
					$text_cols[] = $col['Field'];
				}
			}
			if ( ! $pk || empty( $text_cols ) || ! $this->is_valid_identifier( $pk ) ) {
				continue;
			}

			foreach ( $text_cols as $col_name ) {
				if ( ! $this->is_valid_identifier( $col_name ) ) {
					continue;
				}

				$rows = $wpdb->get_results( $wpdb->prepare(
					'SELECT %i, %i FROM %i WHERE %i LIKE %s',
					$pk,
					$col_name,
					$table,
					$col_name,
					'%' . $wpdb->esc_like( $old_domain ) . '%'
				) );
				if ( empty( $rows ) ) {
					continue;
				}
				foreach ( $rows as $row ) {
					$original = $row->$col_name;
					$replaced = $this->replace_value( $original, $old_url, $new_url, $old_domain, $new_domain );
					if ( $replaced !== $original ) {
						$wpdb->update( $table, [ $col_name => $replaced ], [ $pk => $row->$pk ] );
					}
				}
			}

			if ( 'postmeta' === substr( $table, -8 ) ) {
				$this->replace_muffin_builder( $wpdb, $table, $old_url, $new_url, $old_domain, $new_domain );
			}
		}
	}

	/**
	 * Read siteurl from the given options table.
	 *
	 * @param string $prefix Table prefix.
	 * @return string Current siteurl value or empty string.
	 */
	public function read_siteurl( $prefix ) {
		global $wpdb;
		$table = $prefix . 'options';
		if ( ! $this->is_valid_identifier( $table ) ) {
			return '';
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! $exists ) {
			return '';
		}
		return $wpdb->get_var( $wpdb->prepare(
			'SELECT option_value FROM %i WHERE option_name = %s',
			$table,
			'siteurl'
		) ) ?: '';
	}

	private function replace_muffin_builder( $wpdb, $table, $old_url, $new_url, $old_domain, $new_domain ) {
		$results = $wpdb->get_results( $wpdb->prepare(
			'SELECT meta_id, post_id, meta_value FROM %i WHERE meta_key = %s',
			$table,
			'mfn-page-items'
		) );
		if ( empty( $results ) ) {
			return;
		}

		$skip_url = ( false !== strpos( $new_domain, $old_domain ) );

		foreach ( $results as $row ) {
			try {
				$raw        = $row->meta_value;
				$data       = @unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				$was_base64 = false;

				if ( false === $data ) {
					$decoded = base64_decode( $raw, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					if ( false !== $decoded ) {
						$data       = @unserialize( $decoded ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
						$was_base64 = ( false !== $data );
					}
				}
				if ( false === $data || ! is_array( $data ) ) {
					continue;
				}

				$data = $this->recursive_replace( $data, $old_domain, $new_domain );
				if ( ! $skip_url ) {
					$data = $this->recursive_replace( $data, $old_url, $new_url );
				}

				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$new_meta = $was_base64 ? base64_encode( serialize( $data ) ) : serialize( $data );
				if ( $new_meta !== $raw ) {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- SET clause, not WHERE; keyed on meta_id PK.
					$wpdb->update( $table, [ 'meta_value' => $new_meta ], [ 'meta_id' => $row->meta_id ] );
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Delete uses indexed (post_id, meta_key) pair.
					$wpdb->delete( $table, [ 'post_id' => $row->post_id, 'meta_key' => 'mfn-page-object' ] );
				}
			} catch ( \Error $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[GrabWP Restore] Muffin Builder replace error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}

	private function replace_value( $value, $old_url, $new_url, $old_domain, $new_domain ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return $value;
		}

		$skip_url_replace = ( false !== strpos( $new_domain, $old_domain ) );

		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( false !== $data ) {
				$data = $this->recursive_replace( $data, $old_domain, $new_domain );
				if ( ! $skip_url_replace ) {
					$data = $this->recursive_replace( $data, $old_url, $new_url );
				}
				return serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
		}

		$value = str_replace( $old_domain, $new_domain, $value );
		if ( ! $skip_url_replace ) {
			$value = str_replace( $old_url, $new_url, $value );
		}
		return $value;
	}

	private function recursive_replace( $data, $search, $replace ) {
		if ( is_string( $data ) ) {
			return str_replace( $search, $replace, $data );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->recursive_replace( $value, $search, $replace );
			}
		} elseif ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = $this->recursive_replace( $value, $search, $replace );
			}
		}
		return $data;
	}
}
