/**
 * 全站扫描分批进度与忽略词操作。
 */
(function () {
	var cfg = window.WPRS_SCAN;
	if (!cfg) {
		return;
	}

	var runBtn = document.getElementById('wprs-scan-run');
	var prog = document.getElementById('wprs-scan-progress');
	var bar = document.getElementById('wprs-scan-bar');
	var status = document.getElementById('wprs-scan-status');
	var batchSize = cfg.batchSize || 10;
	var i18n = cfg.i18n || {};

	/**
	 * @param {string} action
	 * @param {Object} data
	 * @returns {Promise<object>}
	 */
	function post(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (key) {
			body.set(key, data[key]);
		});
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin',
		}).then(function (res) {
			return res.json();
		});
	}

	/**
	 * @param {string} text
	 * @param {number} pct
	 */
	function setProgress(text, pct) {
		if (status) {
			status.textContent = text;
		}
		if (bar) {
			bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
		}
	}

	/**
	 * @param {number[]} ids
	 * @param {string} runId
	 * @param {number} done
	 * @param {number} total
	 * @returns {Promise<void>}
	 */
	function processBatches(ids, runId, done, total) {
		if (!ids.length) {
			return post('wprs_scan_complete', {
				total_posts: String(total),
				scan_run_id: runId,
			}).then(function (r) {
				if (r && r.success) {
					setProgress((i18n.done || '完成') + ' — ' + (i18n.reload || ''), 100);
					window.setTimeout(function () {
						window.location.reload();
					}, 800);
				} else {
					setProgress((i18n.fail || '失败') + (r && r.data && r.data.message ? ': ' + r.data.message : ''), 100);
					if (runBtn) {
						runBtn.disabled = false;
					}
				}
			});
		}

		var batch = ids.splice(0, batchSize);
		setProgress(
			(i18n.scanning || '扫描中') + ' ' + done + ' / ' + total,
			total ? Math.round((done * 100) / total) : 0
		);

		/**
		 * @param {number} attempt
		 * @returns {Promise<void>}
		 */
		function tryBatch(attempt) {
			return post('wprs_scan_process_batch', {
				batch: JSON.stringify(batch),
				scan_run_id: runId,
				attempt: String(attempt),
			}).then(function (r) {
				if (r && r.success) {
					var next = done + batch.length;
					setProgress(
						(i18n.scanning || '扫描中') + ' ' + next + ' / ' + total,
						total ? Math.round((next * 100) / total) : 0
					);
					return processBatches(ids, runId, next, total);
				}
				if (attempt < 2) {
					return tryBatch(attempt + 1);
				}
				throw new Error((r && r.data && r.data.message) || i18n.fail || '失败');
			});
		}

		return tryBatch(1);
	}

	if (runBtn) {
		runBtn.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmRun || '开始扫描？')) {
				return;
			}
			runBtn.disabled = true;
			if (prog) {
				prog.hidden = false;
			}
			setProgress(i18n.starting || '准备中…', 0);
			post('wprs_scan_start', {})
				.then(function (r) {
					if (!r || !r.success) {
						throw new Error((r && r.data && r.data.message) || i18n.fail || '失败');
					}
					var ids = (r.data && r.data.post_ids) || [];
					var runId = (r.data && r.data.scan_run_id) || '';
					if (!ids.length) {
						setProgress(i18n.empty || '无文章', 100);
						runBtn.disabled = false;
						return null;
					}
					return processBatches(ids.slice(), runId, 0, ids.length);
				})
				.catch(function (err) {
					setProgress((i18n.fail || '失败') + ': ' + (err && err.message ? err.message : ''), 100);
					runBtn.disabled = false;
				});
		});
	}

	document.querySelectorAll('.wprs-term-action').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var action = btn.getAttribute('data-action');
			var term = btn.getAttribute('data-term');
			var resource = btn.getAttribute('data-resource') || 'post';
			if (!action || !term) {
				return;
			}
			btn.disabled = true;

			var ajaxAction;
			var payload = { term: term };
			if (resource === 'archive') {
				ajaxAction =
					action === 'unignore'
						? 'wprs_scan_unignore_archive_term'
						: 'wprs_scan_ignore_archive_term';
				payload.taxonomy = btn.getAttribute('data-taxonomy') || '';
				payload.term_id = btn.getAttribute('data-term-id') || '';
			} else {
				ajaxAction =
					action === 'unignore' ? 'wprs_scan_unignore_term' : 'wprs_scan_ignore_term';
				payload.post_id = btn.getAttribute('data-post') || '';
			}

			post(ajaxAction, payload)
				.then(function (r) {
					if (r && r.success) {
						window.location.reload();
						return;
					}
					window.alert((r && r.data && r.data.message) || i18n.fail || '失败');
					btn.disabled = false;
				})
				.catch(function () {
					window.alert(i18n.fail || '失败');
					btn.disabled = false;
				});
		});
	});

	document.querySelectorAll('.wprs-snip-toggle').forEach(function (link) {
		link.addEventListener('click', function (e) {
			e.preventDefault();
			var box = link.nextElementSibling;
			if (!box || !box.classList.contains('wprs-snippets-box')) {
				return;
			}
			var open = box.hasAttribute('hidden');
			if (open) {
				box.removeAttribute('hidden');
				link.textContent = (i18n.hideSnippets || '收起内容片段 ▴');
			} else {
				box.setAttribute('hidden', 'hidden');
				link.textContent = (i18n.showSnippets || '查看内容片段 ▾');
			}
		});
	});
})();
