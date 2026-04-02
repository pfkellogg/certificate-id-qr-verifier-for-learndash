/**
 * Certificate Photo Verifier — Frontend JS
 * Handles the photo source dropdown and AJAX upload.
 */
(function () {
	'use strict';

	var wrap = document.getElementById('cp-ld-upload-wrap');
	if (!wrap) return;

	var fileInput     = document.getElementById('cp-ld-file-input');
	var previewImg    = document.getElementById('cp-ld-preview-img');
	var placeholder   = document.getElementById('cp-ld-preview-placeholder');
	var status        = document.getElementById('cp-ld-upload-status');
	var sourceSelect  = document.getElementById('cp-ld-source');
	var uploadRow     = document.getElementById('cp-ld-upload-row');
	var hint          = document.getElementById('cp-ld-hint');
	var continueWrap  = document.getElementById('cp-ld-continue-wrap');
	var continueBtn   = document.getElementById('cp-ld-continue-btn');
	var fadeTimer     = null;
	var hadNoPhoto    = !cpLd.hasPhoto && !cpLd.hasUpload;

	function showStatus(msg, type, autoFade) {
		if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }
		status.classList.remove('cp-ld-status--fading');
		status.textContent = msg;
		status.className = 'cp-ld-status cp-ld-status--' + type;
		if (autoFade) {
			fadeTimer = setTimeout(function () {
				status.classList.add('cp-ld-status--fading');
				fadeTimer = setTimeout(hideStatus, 400);
			}, 3000);
		}
	}

	function hideStatus() {
		if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }
		status.className = 'cp-ld-status';
		status.textContent = '';
	}

	function showPhoto(url) {
		if (placeholder) placeholder.style.display = 'none';
		previewImg.src = url;
		previewImg.style.display = '';
	}

	function showContinueButton() {
		if (!continueWrap || !continueBtn) return;
		if (cpLd.returnUrl) {
			continueBtn.href = cpLd.returnUrl;
		} else {
			continueBtn.href = document.referrer || '/';
		}
		continueWrap.style.display = '';
		// Trigger reflow so the CSS animation plays.
		void continueWrap.offsetWidth;
		continueWrap.classList.add('cp-ld-continue--visible');
	}

	// ----- Source dropdown -----
	if (sourceSelect) {
		sourceSelect.addEventListener('change', function () {
			if (this.value === 'gravatar') {
				if (uploadRow) uploadRow.style.display = 'none';
				if (hint) hint.style.display = 'none';
				showStatus(cpLd.i18n.switching, 'info');

				var body = new FormData();
				body.append('action', 'cp_ld_delete_photo');
				body.append('nonce', cpLd.nonce);

				fetch(cpLd.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (res.success) {
							showPhoto(res.data.photo_url || cpLd.gravatarUrl);
							showStatus(res.data.message, 'ok', true);
						} else {
							showStatus(res.data.message, 'error', true);
						}
					})
					.catch(function () {
						showStatus('Request failed.', 'error', true);
					});
			} else {
				if (uploadRow) uploadRow.style.display = '';
				if (hint) hint.style.display = '';
				hideStatus();
			}
		});
	}

	// ----- Upload -----
	if (fileInput) {
		fileInput.addEventListener('change', function () {
			var file = this.files[0];
			if (!file) return;

			// Local preview.
			var reader = new FileReader();
			reader.onload = function (e) { showPhoto(e.target.result); };
			reader.readAsDataURL(file);

			var data = new FormData();
			data.append('action', 'cp_ld_upload_photo');
			data.append('nonce', cpLd.nonce);
			data.append('cp_ld_photo', file);

			showStatus(cpLd.i18n.uploading, 'info');

			fetch(cpLd.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res.success) {
						showStatus(res.data.message, 'ok', true);
						if (res.data.photo_url) {
							showPhoto(res.data.photo_url);
						}
						if (sourceSelect) {
							sourceSelect.value = 'custom';
						}
						if (hadNoPhoto) {
							hadNoPhoto = false;
							showContinueButton();
						}
					} else {
						showStatus(res.data.message, 'error', true);
					}
				})
				.catch(function () {
					showStatus('Upload failed. Please try again.', 'error', true);
				});
		});
	}

})();
