/**
 * 设置页：测试通知。
 */
(function () {
	var cfg = window.WPRS_SETTINGS;
	var btn = document.getElementById('wprs-notify-test');
	var msg = document.getElementById('wprs-notify-test-msg');
	if (!cfg || !btn) {
		return;
	}

	btn.addEventListener('click', function () {
		btn.disabled = true;
		if (msg) {
			msg.textContent = '发送中…';
		}
		var body = new URLSearchParams();
		body.set('action', 'wprs_notify_test');
		body.set('nonce', cfg.nonce);
		fetch(cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin',
		})
			.then(function (res) {
				return res.json();
			})
			.then(function (r) {
				var text =
					r && r.success
						? (r.data && r.data.message) || '已发送'
						: (r && r.data && r.data.message) || '失败';
				if (msg) {
					msg.textContent = text;
				}
			})
			.catch(function () {
				if (msg) {
					msg.textContent = '请求失败';
				}
			})
			.finally(function () {
				btn.disabled = false;
			});
	});
})();
