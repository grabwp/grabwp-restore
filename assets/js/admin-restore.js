(function ($) {
	'use strict';

	var CHUNK_SIZE = 2 * 1024 * 1024; // 2MB
	var $form, $progress, $bar, $status, $result;
	var stepLabels = [
		'',
		'Validating archive...',
		'Extracting files...',
		'Importing database...',
		'Restoring uploads...',
		'Restoring plugins...',
		'Restoring themes...',
		'Cleaning up...'
	];

	$(function () {
		$form     = $('#grabwp-restore-form');
		$progress = $('#grabwp-restore-progress');
		$bar      = $('#grabwp-restore-bar');
		$status   = $('#grabwp-restore-status');
		$result   = $('#grabwp-restore-result');

		$('#grabwp-restore-confirm').on('change', function () {
			$('#grabwp-restore-submit').prop('disabled', !this.checked);
		});

		$form.on('submit', function (e) {
			e.preventDefault();
			if (!confirm(grabwpRestore.i18n.confirmStart)) {
				return;
			}

			var fileInput = $('#grabwp-restore-file')[0];
			if (!fileInput.files.length) {
				showError('Please select a ZIP file.');
				return;
			}

			$form.hide();
			$progress.show();
			$status.text(grabwpRestore.i18n.uploading);
			uploadChunked(fileInput.files[0]);
		});
	});

	function uploadChunked(file) {
		var totalChunks = Math.ceil(file.size / CHUNK_SIZE);
		var chunkIndex  = 0;

		function sendChunk() {
			var start = chunkIndex * CHUNK_SIZE;
			var end   = Math.min(start + CHUNK_SIZE, file.size);
			var blob  = file.slice(start, end);

			var fd = new FormData();
			fd.append('action', 'grabwp_restore_upload_chunk');
			fd.append('nonce', grabwpRestore.uploadNonce);
			fd.append('chunk', blob, file.name);
			fd.append('chunk_index', chunkIndex);
			fd.append('total_chunks', totalChunks);
			fd.append('filename', file.name);

			var pct = Math.round(((chunkIndex + 1) / totalChunks) * 50);
			$bar.css('width', pct + '%').text(pct + '%');
			$status.text('Uploading chunk ' + (chunkIndex + 1) + '/' + totalChunks + '...');

			$.ajax({
				url: grabwpRestore.ajaxUrl,
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				success: function (res) {
					if (!res.success) {
						showError(res.data.message || 'Chunk upload failed.');
						return;
					}
					chunkIndex++;
					if (chunkIndex < totalChunks) {
						sendChunk();
					} else {
						runStep(res.data.job_id);
					}
				},
				error: function () {
					showError('Upload request failed. Check your connection.');
				}
			});
		}
		sendChunk();
	}

	function runStep(jobId) {
		$.post(grabwpRestore.ajaxUrl, {
			action: 'grabwp_restore_step',
			nonce: grabwpRestore.stepNonce,
			job_id: jobId
		}, function (res) {
			if (res.success) {
				var step  = res.data.step;
				var total = res.data.total;
				var pct   = 50 + Math.round((step / total) * 50);
				$bar.css('width', pct + '%').text(pct + '%');
				$status.text(stepLabels[step] || res.data.message);

				if (res.data.done) {
					showSuccess();
				} else {
					setTimeout(function () { runStep(jobId); }, 500);
				}
			} else {
				showError(res.data.message || 'Restore step failed.');
			}
		}).fail(function () {
			showError('Step request failed. The server may have timed out.');
		});
	}

	function showError(msg) {
		$progress.hide();
		$result.html(
			'<div class="grabwp-restore-error"><strong>' +
			grabwpRestore.i18n.error + '</strong> ' + escHtml(msg) + '</div>'
		).show();
	}

	function showSuccess() {
		$progress.hide();
		$result.html(
			'<div class="grabwp-restore-success">' +
			grabwpRestore.i18n.complete + '</div>'
		).show();
	}

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

})(jQuery);
