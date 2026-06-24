<?php
/**
 * Admin page template for GrabWP Restore.
 *
 * @package GrabWP_Restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap grabwp-restore-wrap">
	<h1><?php esc_html_e( 'GrabWP Restore', 'grabwp-restore' ); ?></h1>

	<div class="grabwp-restore-warning">
		<strong><?php esc_html_e( 'Warning: Destructive Operation', 'grabwp-restore' ); ?></strong>
		<?php esc_html_e( 'This will REPLACE your entire WordPress site including the database, uploads, plugins, and themes. Your current admin credentials will be replaced by those from the backup. Existing directories will be renamed to .old — you can remove them after verifying the restore.', 'grabwp-restore' ); ?>
	</div>

	<div class="grabwp-restore-limits">
		<strong><?php esc_html_e( 'Note:', 'grabwp-restore' ); ?></strong>
		<?php esc_html_e( 'Files are uploaded in 2MB chunks — no PHP upload limit restrictions.', 'grabwp-restore' ); ?>
	</div>

	<form id="grabwp-restore-form" enctype="multipart/form-data">
		<p>
			<input type="file" id="grabwp-restore-file" accept=".zip" />
		</p>
		<p>
			<label>
				<input type="checkbox" id="grabwp-restore-confirm" />
				<?php esc_html_e( 'I understand this is destructive and I have already backed up my website.', 'grabwp-restore' ); ?>
			</label>
		</p>
		<p>
			<button type="submit" id="grabwp-restore-submit" class="button button-primary" disabled>
				<?php esc_html_e( 'Upload & Restore', 'grabwp-restore' ); ?>
			</button>
		</p>
	</form>

	<div id="grabwp-restore-progress" class="grabwp-restore-progress">
		<div class="grabwp-restore-bar-wrap">
			<div id="grabwp-restore-bar" class="grabwp-restore-bar">0%</div>
		</div>
		<div id="grabwp-restore-status" class="grabwp-restore-status"></div>
	</div>

	<div id="grabwp-restore-result" style="display:none;"></div>
</div>
