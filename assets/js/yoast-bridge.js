/**
 * 保存前将 Yoast 文案快照注入 REST meta。
 */
(function () {
	var cfg = window.WPRS_YOAST_BRIDGE;
	if (!cfg || !window.wp || !wp.data || !wp.apiFetch || !wp.domReady) {
		return;
	}

	wp.domReady(function () {
		var sel = wp.data.select;
		var yKeys = cfg.yoastKeys || [];
		var metaKey = cfg.metaKey || '_wprs_risk_yoast_snapshot';

		/**
		 * @param {string[]} parts
		 * @param {Object} seen
		 * @param {*} v
		 */
		function push(parts, seen, v) {
			if (typeof v !== 'string') {
				return;
			}
			var t = v.trim();
			if (!t || seen[t]) {
				return;
			}
			seen[t] = true;
			parts.push(t);
		}

		/**
		 * @returns {string}
		 */
		function snap() {
			var meta = sel('core/editor').getEditedPostAttribute('meta') || {};
			var parts = [];
			var seen = {};
			yKeys.forEach(function (k) {
				push(parts, seen, meta[k]);
			});
			try {
				var ys = sel('yoast-seo/editor');
				if (ys) {
					var sd =
						typeof ys.getSnippetEditorData === 'function'
							? ys.getSnippetEditorData()
							: null;
					if (sd) {
						push(parts, seen, sd.title);
						push(parts, seen, sd.description);
					}
					var fk =
						typeof ys.getFocusKeyphrase === 'function'
							? ys.getFocusKeyphrase()
							: null;
					push(parts, seen, fk);
				}
			} catch (e) {}
			return parts.join('\n');
		}

		wp.apiFetch.use(function (options, next) {
			try {
				var method = (options && options.method ? String(options.method) : 'GET').toUpperCase();
				var target =
					options && typeof options.path === 'string' && options.path
						? options.path
						: options && typeof options.url === 'string'
							? options.url
							: '';
				var isSave =
					(method === 'POST' || method === 'PUT' || method === 'PATCH') &&
					/\/wp\/v2\/[^/?]+\/\d+/.test(target) &&
					target.indexOf('/autosaves') === -1 &&
					target.indexOf('/revisions') === -1;
				if (isSave) {
					var snapshot = snap();
					if (options && options.data && typeof options.data === 'object') {
						var data = Object.assign({}, options.data);
						var mergedMeta =
							data.meta && typeof data.meta === 'object'
								? Object.assign({}, data.meta)
								: {};
						mergedMeta[metaKey] = snapshot;
						data.meta = mergedMeta;
						options.data = data;
					} else if (options && typeof options.body === 'string') {
						try {
							var payload = JSON.parse(options.body);
							if (payload && typeof payload === 'object') {
								var bodyMeta =
									payload.meta && typeof payload.meta === 'object'
										? Object.assign({}, payload.meta)
										: {};
								bodyMeta[metaKey] = snapshot;
								payload.meta = bodyMeta;
								options.body = JSON.stringify(payload);
							}
						} catch (e) {}
					}
				}
			} catch (e) {}
			return next(options);
		});
	});
})();
