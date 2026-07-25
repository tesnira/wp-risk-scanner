# WP Risk Scanner

通用 WordPress 风险词管理插件：违禁词表、全站/定时扫描、保存拦截与通知（通用 Webhook / 飞书预设）。

> 当前进度：词表、文章扫描、**定时 Cron、通知、保存拦截** 已可用；归档页扫描仍为默认关闭待完善。

## 基本信息

- 作者：`biancaplus`
- 当前版本：`1.0.0`
- WordPress：`6.0+`
- PHP：`7.4+`
- 许可证：[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
- 前缀：`wprs_`（option / 表 / AJAX / meta）

## 后台菜单

```
风险词管理
├── 风险扫描
├── 违禁词表
└── 设置
```

- 日常操作：`edit_others_posts`
- 敏感操作（通知密钥、卸载删数据等）：`manage_options`

## 安全默认

| 项 | 默认 |
|----|------|
| 保存拦截 | 关 |
| 归档页扫描 | 关 |
| 通知 | 关 |
| 扫描文章类型 | `post`、`page` |
| 定时模式 | WP-Cron |
| 卸载删数据 | 关（保留） |

## 违禁词表（已实现）

- 新增 / 编辑 / 删除 / 批量删除
- 搜索与分页
- CSV 导出（UTF-8 BOM）
- CSV 导入：
  - **合并（默认）**：同词更新，新词追加
  - **替换全部**：需勾选确认 + 二次弹窗确认后清空再导入
- 词表变更后提示需全量重扫

CSV 列：`risk_term, recommended_term, guidelines`（首行可为表头）。

## 风险扫描（已实现 · 文章）

- 全站重新扫描（AJAX 分批，进度条）
- 扫描范围：设置中的文章类型 + `publish`；文本含标题/正文/摘要、Elementor 数据、Yoast meta（软依赖）
- 风险列表：按风险状态/类型/标题筛选；来源分组；忽略 / 取消忽略
- 扫描日志（保留约 15 天）；管理员可清空结果
- 词表变更后提示需全量重扫；扫描成功后清除标记

### 定时 / 通知 / 拦截（已实现）

- 定时：默认 WP-Cron（每日多时间点）；可选系统/外部 Cron（密钥 URL + 下载安装脚本，写入 `uploads/wp-risk-scanner/`）
- 通知：通用 Webhook（固定 JSON）/ 飞书预设（可选签名）；默认关；支持管理员测试发送
- 保存拦截：设置中开启后生效；Gutenberg REST + Elementor + Yoast 快照软依赖

尚未完善：归档页扫描执行链路（设置开关已预留）。

## 数据表

激活时通过 `dbDelta` 创建：

- `{prefix}wprs_risk_terms`
- `{prefix}wprs_risk_scan_results`
- `{prefix}wprs_risk_scan_archives`
- `{prefix}wprs_risk_scan_log`

## 通用 Webhook 契约（拟定）

扫描通知 POST JSON 最小字段（实现阶段固化）：

```json
{
  "event": "risk_scan_completed",
  "site": "https://example.com",
  "scanned": 120,
  "violations": 3,
  "time": "2026-07-25T05:00:00+00:00",
  "items": []
}
```

## Cron 指引（摘要）

1. **默认 WP-Cron**：在后台配置时间点即可，一般无需 SSH。
2. **系统 / 外部 Cron**：通过带密钥的 URL 触发；安装脚本优先写入 `uploads/wp-risk-scanner/`，并始终可下载。
3. 修改或清空定时时间后，若使用系统 crontab，需按指引**重新安装或清理**服务器任务，否则仍按旧配置执行。

## 安装

1. 将 `wp-risk-scanner` 目录放到 `wp-content/plugins/`。
2. 后台启用 **WP Risk Scanner**。
3. 打开「风险词管理 → 设置」按需配置。

## 更新日志

### 1.0.0

- 脚手架：激活建表、三子菜单、设置页（安全默认）、模块占位、可选卸载清数据。
- 违禁词表：CRUD、搜索分页、CSV 合并/替换导入与导出。
- 文章扫描：全站分批扫、风险列表、忽略词、扫描日志、清空结果。
- 定时：WP-Cron / 系统 URL Cron、安装脚本下载。
- 通知：Webhook + 飞书；保存拦截（默认关）。
