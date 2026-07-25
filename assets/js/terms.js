/**
 * 违禁词表：弹窗、全选、批量删除、导入确认。
 */
(function () {
	/**
	 * @param {string} id
	 */
	function openModal(id) {
		var el = document.getElementById(id);
		if (el) {
			el.hidden = false;
		}
	}

	/**
	 * 关闭全部弹窗。
	 */
	function closeModals() {
		document.querySelectorAll('.wprs-modal').forEach(function (el) {
			el.hidden = true;
		});
	}

	document.querySelectorAll('[data-wprs-open]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			openModal(btn.getAttribute('data-wprs-open'));
		});
	});

	document.querySelectorAll('[data-wprs-close]').forEach(function (btn) {
		btn.addEventListener('click', closeModals);
	});

	document.querySelectorAll('.wprs-modal').forEach(function (modal) {
		modal.addEventListener('click', function (e) {
			if (e.target === modal) {
				closeModals();
			}
		});
	});

	document.querySelectorAll('.wprs-edit-term').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var id = document.getElementById('wprs-edit-id');
			var risk = document.getElementById('wprs-edit-risk');
			var rec = document.getElementById('wprs-edit-rec');
			var guide = document.getElementById('wprs-edit-guide');
			if (!id || !risk || !rec || !guide) {
				return;
			}
			id.value = btn.getAttribute('data-id') || '';
			risk.value = btn.getAttribute('data-risk') || '';
			rec.value = btn.getAttribute('data-recommended') || '';
			guide.value = btn.getAttribute('data-guidelines') || '';
			openModal('wprs-modal-edit');
		});
	});

	var checkAll = document.getElementById('wprs-check-all');
	if (checkAll) {
		checkAll.addEventListener('change', function () {
			document.querySelectorAll('.wprs-term-cb').forEach(function (cb) {
				cb.checked = checkAll.checked;
			});
		});
	}

	var batchBtn = document.getElementById('wprs-batch-delete');
	var listForm = document.getElementById('wprs-terms-list-form');
	var listAction = document.getElementById('wprs-list-action');
	if (batchBtn && listForm && listAction) {
		batchBtn.addEventListener('click', function () {
			var checked = listForm.querySelectorAll('.wprs-term-cb:checked');
			if (!checked.length) {
				window.alert((window.WPRS_TERMS && WPRS_TERMS.selectOne) || '请先选择要删除的项。');
				return;
			}
			var msg = (window.WPRS_TERMS && WPRS_TERMS.confirmBatch) || '确定删除？';
			if (!window.confirm(msg)) {
				return;
			}
			listAction.value = 'batch_delete';
			listForm.submit();
		});
	}

	document.querySelectorAll('.wprs-delete-term').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!window.confirm(btn.getAttribute('data-confirm') || '确定删除？')) {
				return;
			}
			var form = document.getElementById('wprs-single-delete-form');
			var idInput = document.getElementById('wprs-single-delete-id');
			if (!form || !idInput) {
				return;
			}
			idInput.value = btn.getAttribute('data-id') || '';
			form.submit();
		});
	});

	var replaceRadio = document.getElementById('wprs-import-replace');
	var confirmWrap = document.getElementById('wprs-replace-confirm-wrap');
	var confirmCb = document.getElementById('wprs-confirm-replace');
	var importForm = document.getElementById('wprs-import-form');

	/**
	 * 切换替换确认区显示。
	 */
	function syncReplaceUi() {
		var isReplace = replaceRadio && replaceRadio.checked;
		if (confirmWrap) {
			confirmWrap.hidden = !isReplace;
		}
		if (!isReplace && confirmCb) {
			confirmCb.checked = false;
		}
	}

	document.querySelectorAll('input[name="import_mode"]').forEach(function (radio) {
		radio.addEventListener('change', syncReplaceUi);
	});
	syncReplaceUi();

	if (importForm) {
		importForm.addEventListener('submit', function (e) {
			if (replaceRadio && replaceRadio.checked) {
				if (!confirmCb || !confirmCb.checked) {
					e.preventDefault();
					window.alert(
						(window.WPRS_TERMS && WPRS_TERMS.needReplaceConfirm) ||
							'请勾选确认后再导入。'
					);
					return;
				}
				var msg =
					(window.WPRS_TERMS && WPRS_TERMS.confirmReplace) ||
					'确定替换全部词表吗？';
				if (!window.confirm(msg)) {
					e.preventDefault();
				}
			}
		});
	}
})();
