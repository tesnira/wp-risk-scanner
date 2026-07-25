/**
 * 古腾堡文档设置侧栏：本文违禁词白名单。
 */
(function (wp) {
	if (!wp || !wp.plugins || !wp.editPost || !wp.element || !wp.components || !wp.data) {
		return;
	}

	var cfg = window.WPRS_WHITELIST_PANEL || {};
	var metaKey = cfg.metaKey || '_wprs_risk_whitelist';
	var title = cfg.title || '本文违禁词白名单';
	var help = cfg.help || '';

	var el = wp.element.createElement;
	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var TextareaControl = wp.components.TextareaControl;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;

	/**
	 * 侧栏面板组件。
	 *
	 * @returns {*}
	 */
	function WhitelistPanel() {
		var whitelist = useSelect(function (select) {
			var meta = select('core/editor').getEditedPostAttribute('meta') || {};
			return typeof meta[metaKey] === 'string' ? meta[metaKey] : '';
		}, []);

		var editPost = useDispatch('core/editor').editPost;

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'wprs-risk-whitelist',
				title: title,
				icon: 'shield',
			},
			el(TextareaControl, {
				label: help,
				value: whitelist,
				onChange: function (val) {
					var meta = {};
					meta[metaKey] = val;
					editPost({ meta: meta });
				},
			})
		);
	}

	registerPlugin('wprs-risk-whitelist-panel', {
		render: WhitelistPanel,
	});
})(window.wp);
