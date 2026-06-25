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

	const JOB_TTL     = 1800;
	const TOTAL_STEPS = 7;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_ajax_grabwp_restore_upload_chunk', [ $this, 'ajax_upload_chunk' ] );
		add_action( 'wp_ajax_grabwp_restore_step', [ $this, 'ajax_step' ] );
		add_action( 'wp_ajax_nopriv_grabwp_restore_step', [ $this, 'ajax_step' ] );
	}

	public function add_menu_page() {
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
			'i18n'        => [
				'confirmStart' => __( 'This will permanently replace your entire site. Continue?', 'grabwp-restore' ),
				'uploading'    => __( 'Uploading...', 'grabwp-restore' ),
				'restoring'    => __( 'Restoring...', 'grabwp-restore' ),
				'complete'     => __( 'Restore complete! Log in with the exported site credentials. You can safely remove the .old directories in wp-content/.', 'grabwp-restore' ),
				'error'        => __( 'Error:', 'grabwp-restore' ),
			],
		] );
	}

	public function render_page() {
		require GRABWP_RESTORE_PLUGIN_DIR . 'views/admin-page.php';
	}

	public function ajax_upload_chunk() {
		check_ajax_referer( 'grabwp_restore_upload', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$chunk_index  = (int) ( $_POST['chunk_index'] ?? -1 );
		$total_chunks = (int) ( $_POST['total_chunks'] ?? 0 );
		$filename     = sanitize_file_name( $_POST['filename'] ?? 'restore.zip' );

		if ( $chunk_index < 0 || $total_chunks < 1 || empty( $_FILES['chunk'] ) ) {
			wp_send_json_error( [ 'message' => 'Invalid chunk data.' ] );
		}

		$tmp_dir = GRABWP_RESTORE_TMP_DIR . '/upload';
		wp_mkdir_p( $tmp_dir );

		$htaccess = $tmp_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$target     = $tmp_dir . '/' . $filename;
		$chunk_data = file_get_contents( $_FILES['chunk']['tmp_name'] );
		if ( false === $chunk_data ) {
			wp_send_json_error( [ 'message' => 'Cannot read chunk.' ] );
		}

		$mode   = ( 0 === $chunk_index ) ? 'wb' : 'ab';
		$handle = fopen( $target, $mode );
		if ( ! $handle ) {
			wp_send_json_error( [ 'message' => 'Cannot write to temp file.' ] );
		}
		fwrite( $handle, $chunk_data );
		fclose( $handle );

		if ( $chunk_index < $total_chunks - 1 ) {
			wp_send_json_success( [ 'received' => $chunk_index + 1 ] );
			return;
		}

		if ( $this->load_active_job() ) {
			wp_send_json_error( [ 'message' => 'A restore is already in progress.' ] );
		}

		$job_id     = bin2hex( random_bytes( 16 ) );
		$job_secret = bin2hex( random_bytes( 32 ) );
		$job_token  = hash_hmac( 'sha256', $job_id, $job_secret );
		$state      = [
			'step'        => 0,
			'total'       => self::TOTAL_STEPS,
			'zip_path'    => $target,
			'current_url' => site_url(),
			'ts'          => time(),
			'secret'      => $job_secret,
		];
		$this->save_job( $job_id, $state );
		$this->save_active_job( $job_id );

		wp_send_json_success( [
			'job_id'    => $job_id,
			'job_token' => $job_token,
		] );
	}

	public function ajax_step() {
		$job_id    = sanitize_text_field( $_POST['job_id'] ?? '' );
		$job_token = sanitize_text_field( $_POST['job_token'] ?? '' );

		$state = $this->load_job( $job_id );
		if ( ! $state ) {
			wp_send_json_error( [ 'message' => 'Job not found or expired.' ] );
		}

		$expected_token = hash_hmac( 'sha256', $job_id, $state['secret'] ?? '' );
		if ( ! hash_equals( $expected_token, $job_token ) ) {
			wp_send_json_error( [ 'message' => 'Invalid job token.' ], 403 );
		}

		$next = (int) $state['step'] + 1;
		if ( $next > self::TOTAL_STEPS ) {
			wp_send_json_error( [ 'message' => 'Already completed.' ] );
		}

		require_once GRABWP_RESTORE_PLUGIN_DIR . 'includes/class-grabwp-restore-step-controller.php';
		$controller = new GrabWP_Restore_Step_Controller();
		$result     = $controller->dispatch( $next, $state );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			return;
		}

		$state['step'] = $next;
		if ( ! empty( $result['data'] ) ) {
			$state = array_merge( $state, $result['data'] );
		}
		$this->save_job( $job_id, $state );

		wp_send_json_success( [
			'step'    => $next,
			'total'   => self::TOTAL_STEPS,
			'message' => $result['message'],
			'done'    => ( $next === self::TOTAL_STEPS ),
		] );
	}

	private function job_dir() {
		$dir = GRABWP_RESTORE_TMP_DIR . '/jobs';
		wp_mkdir_p( $dir );
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
		return $dir;
	}

	private function save_job( $job_id, $state ) {
		$file = $this->job_dir() . '/' . $job_id . '.json';
		file_put_contents( $file, wp_json_encode( $state ), LOCK_EX );
	}

	private function load_job( $job_id ) {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $job_id ) ) {
			return false;
		}
		$file = $this->job_dir() . '/' . $job_id . '.json';
		if ( ! file_exists( $file ) ) {
			return false;
		}
		if ( ( time() - filemtime( $file ) ) > self::JOB_TTL ) {
			unlink( $file );
			return false;
		}
		return json_decode( file_get_contents( $file ), true );
	}

	private function save_active_job( $job_id ) {
		file_put_contents( $this->job_dir() . '/active.lock', $job_id, LOCK_EX );
	}

	private function load_active_job() {
		$file = $this->job_dir() . '/active.lock';
		if ( ! file_exists( $file ) ) {
			return false;
		}
		$active_id = trim( file_get_contents( $file ) );
		if ( ! $active_id ) {
			return false;
		}
		$state = $this->load_job( $active_id );
		if ( ! $state ) {
			unlink( $file );
			return false;
		}
		return $active_id;
	}

	private function clear_active_job() {
		$file = $this->job_dir() . '/active.lock';
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
}
