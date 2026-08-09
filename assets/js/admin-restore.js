(function ($) {
	'use strict';

	var $form, $progress, $bar, $status, $result;
	var stepLabels = grabwpRestore.i18n.stepLabels;

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
				showError(grabwpRestore.i18n.selectZip);
				return;
			}

			$form.hide();
			$progress.show();
			$status.text(grabwpRestore.i18n.uploading);
			uploadArchive(fileInput.files[0]);
		});
	});

	function uploadArchive(file) {
		var fd = new FormData();
		fd.append('action', 'grabwp_restore_upload');
		fd.append('nonce', grabwpRestore.uploadNonce);
		fd.append('archive', file, file.name);

		$.ajax({
			url: grabwpRestore.ajaxUrl,
			type: 'POST',
			data: fd,
			processData: false,
			contentType: false,
			xhr: function () {
				var xhr = $.ajaxSettings.xhr();
				xhr.upload.addEventListener('progress', function (event) {
					if (!event.lengthComputable) {
						return;
					}
					var pct = Math.round((event.loaded / event.total) * 50);
					$bar.css('width', pct + '%').text(pct + '%');
				});
				return xhr;
			},
			success: function (res) {
				if (!res.success) {
					showError(res.data.message || grabwpRestore.i18n.uploadFailed);
					return;
				}
				runStep(res.data.job_id, res.data.job_token);
			},
			error: function () {
				showError(grabwpRestore.i18n.uploadFailed);
			}
		});
	}

	function runStep(jobId, jobToken) {
		$.post(grabwpRestore.ajaxUrl, {
			action: 'grabwp_restore_step',
			nonce: grabwpRestore.stepNonce,
			job_id: jobId,
			job_token: jobToken
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
					setTimeout(function () { runStep(jobId, jobToken); }, 500);
				}
			} else {
				showError(res.data ? res.data.message : grabwpRestore.i18n.stepFailed);
			}
		}).fail(function () {
			showError(grabwpRestore.i18n.stepRequest);
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
		$result.empty().append(
			$('<div class="grabwp-restore-success"></div>').text(grabwpRestore.i18n.complete)
		).show();
	}

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

})(jQuery);
