<?php
/**
 * Admin page controller for GrabWP Restore.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GrabWP_Restore_Admin {

	const JOB_TTL     = 21600;
	const TOTAL_STEPS = 7;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_ajax_grabwp_restore_upload', [ $this, 'ajax_upload' ] );
		add_action( 'wp_ajax_grabwp_restore_step', [ $this, 'ajax_step' ] );
		// The database import can replace the authenticated session.
		// A high-entropy, single-job token authenticates subsequent steps.
		add_action( 'wp_ajax_nopriv_grabwp_restore_step', [ $this, 'ajax_step' ] );
	}

	public function add_menu_page() {
		if ( is_multisite() ) {
			return;
		}
		add_management_page(
			__( 'GrabWP Restore', 'grabwp-restore' ),
			__( 'GrabWP Restore', 'grabwp-restore' ),
			'manage_options',
			'grabwp-restore',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( 'tools_page_grabwp-restore' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'grabwp-restore-admin',
			GRABWP_RESTORE_PLUGIN_URL . 'assets/css/admin-restore.css',
			[],
			GRABWP_RESTORE_VERSION
		);
		wp_enqueue_script(
			'grabwp-restore-admin',
			GRABWP_RESTORE_PLUGIN_URL . 'assets/js/admin-restore.js',
			[ 'jquery' ],
			GRABWP_RESTORE_VERSION,
			true
		);
		wp_localize_script( 'grabwp-restore-admin', 'grabwpRestore', [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'uploadNonce' => wp_create_nonce( 'grabwp_restore_upload' ),
			'stepNonce'   => wp_create_nonce( 'grabwp_restore_step' ),
			'i18n'        => [
				'confirmStart' => __( 'This will permanently replace your entire site. Continue?', 'grabwp-restore' ),
				'uploading'    => __( 'Uploading...', 'grabwp-restore' ),
				'restoring'    => __( 'Restoring...', 'grabwp-restore' ),
				'complete'     => __( 'Restore complete! Log in with the exported site credentials. You can safely remove the .old directories in wp-content/.', 'grabwp-restore' ),
				'error'        => __( 'Error:', 'grabwp-restore' ),
				'selectZip'    => __( 'Please select a ZIP file.', 'grabwp-restore' ),
				'uploadFailed' => __( 'Upload failed. Check the PHP upload limit and your connection.', 'grabwp-restore' ),
				'stepFailed'   => __( 'Restore step failed.', 'grabwp-restore' ),
				'stepRequest'  => __( 'Step request failed. The server may have timed out.', 'grabwp-restore' ),
				'stepLabels'   => [
					'',
					__( 'Validating archive...', 'grabwp-restore' ),
					__( 'Reading archive metadata...', 'grabwp-restore' ),
					__( 'Importing database...', 'grabwp-restore' ),
					__( 'Restoring uploads...', 'grabwp-restore' ),
					__( 'Restoring plugins...', 'grabwp-restore' ),
					__( 'Restoring themes...', 'grabwp-restore' ),
					__( 'Cleaning up...', 'grabwp-restore' ),
				],
			],
		] );
	}

	public function render_page() {
		require GRABWP_RESTORE_PLUGIN_DIR . 'views/admin-page.php';
	}

	public function ajax_upload() {
		check_ajax_referer( 'grabwp_restore_upload', 'nonce' );
		if ( is_multisite() || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'grabwp-restore' ) ], 403 );
		}
		$prepared = $this->prepare_private_directory( grabwp_restore_tmp_dir() );
		if ( is_wp_error( $prepared ) ) {
			wp_send_json_error( [ 'message' => $prepared->get_error_message() ] );
		}
		if ( $this->load_active_job() ) {
			wp_send_json_error( [ 'message' => __( 'A restore is already in progress.', 'grabwp-restore' ) ] );
		}
		if ( empty( $_FILES['archive'] ) || ! isset( $_FILES['archive']['name'], $_FILES['archive']['type'], $_FILES['archive']['tmp_name'], $_FILES['archive']['error'], $_FILES['archive']['size'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid archive upload.', 'grabwp-restore' ) ] );
		}
		$archive = [
			'name'     => sanitize_file_name( wp_unslash( $_FILES['archive']['name'] ) ),
			'type'     => sanitize_mime_type( wp_unslash( $_FILES['archive']['type'] ) ),
			'tmp_name' => sanitize_text_field( wp_unslash( $_FILES['archive']['tmp_name'] ) ),
			'error'    => absint( wp_unslash( $_FILES['archive']['error'] ) ),
			'size'     => absint( wp_unslash( $_FILES['archive']['size'] ) ),
		];
		if ( UPLOAD_ERR_OK !== $archive['error'] || ! is_uploaded_file( $archive['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid archive upload.', 'grabwp-restore' ) ] );
		}

		$original_name = $archive['name'];
		if ( 'zip' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			wp_send_json_error( [ 'message' => __( 'Only ZIP archives are accepted.', 'grabwp-restore' ) ] );
		}

		$upload = $this->handle_private_upload( $archive );
		if ( is_wp_error( $upload ) ) {
			wp_send_json_error( [ 'message' => $upload->get_error_message() ] );
		}

		$job_id    = bin2hex( random_bytes( 16 ) );
		$job_token = bin2hex( random_bytes( 32 ) );
		$state     = [
			'step'        => 0,
			'total'       => self::TOTAL_STEPS,
			'zip_path'    => $upload['file'],
			'current_url' => site_url(),
			'ts'          => time(),
			'token_hash'  => hash( 'sha256', $job_token ),
			'failures'    => 0,
		];
		if ( ! $this->save_job( $job_id, $state ) || ! $this->save_active_job( $job_id ) ) {
			wp_delete_file( $upload['file'] );
			wp_send_json_error( [ 'message' => __( 'Could not create restore job.', 'grabwp-restore' ) ] );
		}

		wp_send_json_success( [
			'job_id'    => $job_id,
			'job_token' => $job_token,
		] );
	}

	private function handle_private_upload( $archive ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$filesystem = $this->filesystem();
		if ( ! $filesystem ) {
			return new WP_Error( 'filesystem_unavailable', __( 'WordPress could not initialize direct filesystem access.', 'grabwp-restore' ) );
		}

		add_filter( 'upload_dir', [ $this, 'private_upload_dir' ] );
		add_filter( 'upload_mimes', [ $this, 'allow_zip_mime' ] );
		$upload = wp_handle_upload(
			$archive,
			[
				'test_form' => false,
				'mimes'     => [ 'zip' => 'application/zip' ],
			]
		);
		remove_filter( 'upload_dir', [ $this, 'private_upload_dir' ] );
		remove_filter( 'upload_mimes', [ $this, 'allow_zip_mime' ] );

		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'upload_error', $upload['error'] );
		}

		$secured = $this->secure_uploaded_archive( $upload['file'] );
		if ( is_wp_error( $secured ) ) {
			wp_delete_file( $upload['file'] );
			return $secured;
		}

		return $upload;
	}

	/**
	 * Ensure the archive is a real ZIP and lives outside the public web tree.
	 *
	 * @param string $file Absolute path to the uploaded archive.
	 * @return true|WP_Error
	 */
	private function secure_uploaded_archive( $file ) {
		$filesystem = $this->filesystem();
		if ( ! $filesystem || ! $filesystem->chmod( $file, 0600 ) ) {
			return new WP_Error( 'upload_permissions', __( 'Could not secure the uploaded archive.', 'grabwp-restore' ) );
		}

		$real_file = realpath( $file );
		if ( false === $real_file ) {
			return new WP_Error( 'upload_path', __( 'Could not resolve the uploaded archive path.', 'grabwp-restore' ) );
		}
		$normalized = wp_normalize_path( $real_file );
		$web_roots  = [ trailingslashit( wp_normalize_path( ABSPATH ) ) ];
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$web_roots[] = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		}
		foreach ( $web_roots as $web_root ) {
			$real_root = realpath( $web_root );
			if ( false !== $real_root && 0 === strpos( $normalized, trailingslashit( wp_normalize_path( $real_root ) ) ) ) {
				return new WP_Error( 'unsafe_upload_path', __( 'Uploaded archives must not be stored inside the web root.', 'grabwp-restore' ) );
			}
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_ziparchive', __( 'PHP ZipArchive extension is required.', 'grabwp-restore' ) );
		}
		$zip    = new ZipArchive();
		$opened = $zip->open( $real_file, ZipArchive::CHECKCONS );
		if ( true !== $opened ) {
			return new WP_Error( 'bad_zip', __( 'Invalid or corrupted ZIP archive.', 'grabwp-restore' ) );
		}
		$zip->close();

		return true;
	}

	public function private_upload_dir( $uploads ) {
		$uploads['path']    = grabwp_restore_tmp_dir();
		$uploads['basedir'] = grabwp_restore_tmp_dir();
		$uploads['url']     = '';
		$uploads['baseurl'] = '';
		$uploads['subdir']  = '';
		return $uploads;
	}

	public function allow_zip_mime( $mimes ) {
		$mimes['zip'] = 'application/zip';
		return $mimes;
	}

	public function ajax_step() {
		// The nonce protects early steps while the original session exists. The
		// job token remains authoritative after the imported DB replaces it.
		check_ajax_referer( 'grabwp_restore_step', 'nonce', false );
		if ( is_multisite() ) {
			wp_send_json_error( [ 'message' => __( 'Multisite restore is not supported.', 'grabwp-restore' ) ], 403 );
		}
		$prepared = $this->prepare_private_directory( grabwp_restore_tmp_dir() );
		if ( is_wp_error( $prepared ) ) {
			wp_send_json_error( [ 'message' => $prepared->get_error_message() ] );
		}
		$job_id    = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job_token = isset( $_POST['job_token'] ) ? sanitize_text_field( wp_unslash( $_POST['job_token'] ) ) : '';

		$state = $this->load_job( $job_id );
		if ( ! $state ) {
			wp_send_json_error( [ 'message' => __( 'Job not found or expired.', 'grabwp-restore' ) ] );
		}

		$expected_hash = $state['token_hash'] ?? '';
		if ( ! $expected_hash || ! hash_equals( $expected_hash, hash( 'sha256', $job_token ) ) ) {
			$state['failures'] = (int) ( $state['failures'] ?? 0 ) + 1;
			if ( $state['failures'] >= 5 ) {
				$this->abort_job( $job_id, $state );
			} else {
				$this->save_job( $job_id, $state );
			}
			wp_send_json_error( [ 'message' => __( 'Invalid job token.', 'grabwp-restore' ) ], 403 );
		}

		$next = (int) $state['step'] + 1;
		if ( $next > self::TOTAL_STEPS ) {
			wp_send_json_error( [ 'message' => __( 'Restore is already complete.', 'grabwp-restore' ) ] );
		}

		$state['heartbeat'] = time();
		$this->save_job( $job_id, $state );

		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-step-controller.php';
		$controller = new GrabWP_Restore_Step_Controller();
		$result     = $controller->dispatch( $next, $state );
		if ( is_wp_error( $result ) ) {
			$this->abort_job( $job_id, $state );
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$state['step'] = $next;
		if ( ! empty( $result['data'] ) ) {
			$state = array_merge( $state, $result['data'] );
		}
		if ( $next === self::TOTAL_STEPS ) {
			$this->delete_job( $job_id );
			$this->clear_active_job();
		} else {
			$this->save_job( $job_id, $state );
		}

		wp_send_json_success( [
			'step'    => $next,
			'total'   => self::TOTAL_STEPS,
			'message' => $result['message'],
			'done'    => ( $next === self::TOTAL_STEPS ),
		] );
	}

	private function job_dir() {
		$dir = grabwp_restore_tmp_dir() . '/jobs';
		wp_mkdir_p( $dir );
		$filesystem = $this->filesystem();
		if ( $filesystem ) {
			$filesystem->chmod( $dir, 0700 );
		}
		return $dir;
	}

	private function save_job( $job_id, $state ) {
		$filesystem = $this->filesystem();
		if ( ! $filesystem ) {
			return false;
		}
		$file = $this->job_dir() . '/' . $job_id . '.json';
		$temp = $file . '.tmp';
		$saved = $filesystem->put_contents( $temp, wp_json_encode( $state ), 0600 )
			&& $filesystem->move( $temp, $file, true );
		if ( $saved ) {
			$filesystem->chmod( $file, 0600 );
		}
		return $saved;
	}

	private function load_job( $job_id ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $job_id ) ) {
			return false;
		}
		$file = $this->job_dir() . '/' . $job_id . '.json';
		if ( ! file_exists( $file ) ) {
			return false;
		}
		$filesystem = $this->filesystem();
		if ( ! $filesystem ) {
			return false;
		}
		$state = json_decode( $filesystem->get_contents( $file ), true );
		if ( ( time() - filemtime( $file ) ) > self::JOB_TTL ) {
			if ( is_array( $state ) && ! empty( $state['zip_path'] ) && file_exists( $state['zip_path'] ) ) {
				wp_delete_file( $state['zip_path'] );
			}
			wp_delete_file( $file );
			return false;
		}
		return is_array( $state ) && ! empty( $state['token_hash'] ) ? $state : false;
	}

	private function save_active_job( $job_id ) {
		$filesystem = $this->filesystem();
		return $filesystem && $filesystem->put_contents( $this->job_dir() . '/active.lock', $job_id, 0600 );
	}

	private function load_active_job() {
		$file = $this->job_dir() . '/active.lock';
		if ( ! file_exists( $file ) ) {
			return false;
		}
		$filesystem = $this->filesystem();
		if ( ! $filesystem ) {
			return false;
		}
		$active_id = trim( $filesystem->get_contents( $file ) );
		if ( ! $active_id ) {
			return false;
		}
		$state = $this->load_job( $active_id );
		if ( ! $state ) {
			wp_delete_file( $file );
			return false;
		}
		return $active_id;
	}

	private function clear_active_job() {
		$file = $this->job_dir() . '/active.lock';
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	private function delete_job( $job_id ) {
		$file = $this->job_dir() . '/' . $job_id . '.json';
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	private function abort_job( $job_id, $state ) {
		// Always remove the archive: it contains the full database dump.
		if ( ! empty( $state['zip_path'] ) && file_exists( $state['zip_path'] ) ) {
			wp_delete_file( $state['zip_path'] );
		}
		$this->delete_job( $job_id );
		$this->clear_active_job();
	}

	private function filesystem() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return false;
		}
		global $wp_filesystem;
		if ( 'direct' !== $wp_filesystem->method ) {
			return false;
		}
		return $wp_filesystem;
	}

	private function prepare_private_directory( $dir ) {
		$normalized_dir = trailingslashit( wp_normalize_path( $dir ) );
		$web_roots      = [ trailingslashit( wp_normalize_path( ABSPATH ) ) ];
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$web_roots[] = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		}
		foreach ( $web_roots as $web_root ) {
			if ( 0 === strpos( $normalized_dir, $web_root ) ) {
				return new WP_Error( 'unsafe_temp_dir', __( 'A private system temporary directory is required.', 'grabwp-restore' ) );
			}
		}

		$filesystem = $this->filesystem();
		if ( ! $filesystem || is_link( $dir ) || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) || ! $filesystem->chmod( $dir, 0700 ) ) {
			return new WP_Error( 'temp_dir_unavailable', __( 'Could not create a private temporary directory.', 'grabwp-restore' ) );
		}
		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return new WP_Error( 'temp_dir_unavailable', __( 'Could not resolve the private temporary directory.', 'grabwp-restore' ) );
		}
		$real_dir = trailingslashit( wp_normalize_path( $real_dir ) );
		foreach ( $web_roots as $web_root ) {
			$real_root = realpath( $web_root );
			if ( false !== $real_root && 0 === strpos( $real_dir, trailingslashit( wp_normalize_path( $real_root ) ) ) ) {
				return new WP_Error( 'unsafe_temp_dir', __( 'A private system temporary directory is required.', 'grabwp-restore' ) );
			}
		}
		return true;
	}
}
