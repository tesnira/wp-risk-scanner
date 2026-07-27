# WP Risk Scanner

通用 WordPress 风险词管理插件：违禁词表、全站/定时扫描、归档页扫描、保存拦截与通知（通用 Webhook / 飞书预设）。

> 当前进度：词表、文章扫描、**归档页扫描**、定时 Cron、通知、保存拦截均已可用。

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
| 归档 taxonomy | 空（需勾选） |
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

## 风险扫描（已实现）

### 文章

- 全站重新扫描（AJAX 分批，进度条）
- 扫描范围：设置中的文章类型 + `publish`；文本含标题/正文/摘要、Elementor 数据、Yoast meta（软依赖）
- 保存成功后 **shutdown 延迟单篇重扫**（仅已发布）
- 风险列表：资源类型 / 风险状态 / 类型 / 标题或违禁词搜索；来源分组；忽略 / 取消忽略
- 扫描日志（保留约 15 天）；可分「文章 / 归档 / 统一」清空结果

### 归档页（默认关）

- 设置中开启「启用归档页扫描」并勾选 taxonomy
- 采集：term 名称 / 描述 / 字符串 meta + 前台归档页整页可见文本（`wp_remote_get`）+ Yoast taxonomy SEO（软依赖）
- 与文章扫描分离（独立表 `wprs_risk_scan_archives`、独立忽略：term meta `_wprs_risk_whitelist`）
- 全站扫完成时与定时扫一并执行；列表可筛「归档页扫描」

### 定时 / 通知 / 拦截

- 定时：默认 WP-Cron（每日多时间点）；可选系统/外部 Cron（密钥 URL + 下载安装脚本，写入 `uploads/wp-risk-scanner/`）
- 通知：通用 Webhook（固定 JSON）/ 飞书预设（可选签名）；默认关；支持管理员测试发送
- 保存拦截：设置中开启后生效；Gutenberg REST + Elementor + Yoast 快照软依赖

## 数据表

激活时通过 `dbDelta` 创建：

- `{prefix}wprs_risk_terms`
- `{prefix}wprs_risk_scan_results`
- `{prefix}wprs_risk_scan_archives`
- `{prefix}wprs_risk_scan_log`

## 通用 Webhook 契约

扫描通知 POST JSON 字段：

```json
{
  "event": "risk_scan_completed",
  "site": "https://example.com",
  "scanned": 120,
  "violations": 5,
  "posts_violations": 3,
  "archives_violations": 2,
  "time": "2026-07-25T05:00:00+00:00",
  "admin_url": "https://example.com/wp-admin/admin.php?page=wprs-risk-scanner",
  "items": [
    {
      "type": "post",
      "id": 12,
      "title": "示例文章",
      "url": "https://example.com/?p=12",
      "terms": ["违禁词A"]
    },
    {
      "type": "archive",
      "id": 3,
      "taxonomy": "category",
      "title": "示例分类",
      "url": "https://example.com/category/demo/",
      "terms": ["违禁词B"]
    }
  ]
}
```

- `violations` / `scanned`：文章 + 归档合计  
- `items`：最多 20 条违规摘要（按扫描时间倒序）

## Cron 指引（摘要）

1. **默认 WP-Cron**：在后台配置时间点即可，一般无需 SSH。
2. **系统 / 外部 Cron**：通过带密钥的 URL 触发；安装脚本优先写入 `uploads/wp-risk-scanner/`，并始终可下载。
3. 修改或清空定时时间后，若使用系统 crontab，需按指引**重新安装或清理**服务器任务，否则仍按旧配置执行。

## 回归清单

### 默认安全

- [ ] 启用插件后：不拦截保存、不发通知、不扫归档（各开关默认关）
- [ ] 无 Yoast / 无 Elementor 时，核心扫描与词表仍可用

### 违禁词表

- [ ] 增删改、搜索分页
- [ ] CSV 合并导入；替换全部需勾选 + 二次确认
- [ ] 词表变更后出现全站重扫提示；全站扫成功后提示消失

### 文章扫描 / 列表

- [ ] 全站重新扫描进度条完成，风险列表有数据
- [ ] 忽略 / 取消忽略后列表与白名单同步
- [ ] 发布文章后（无需开拦截）风险列表可自动更新该篇
- [ ] 清空：文章 / 归档 / 统一 分别生效

### 归档页扫描

- [ ] 设置开启并勾选 taxonomy 后，全站扫会写入归档结果
- [ ] 列表可筛「归档页扫描」；忽略写入 term meta
- [ ] 关闭开关后不再新扫归档（历史结果仍可查看）

### 保存拦截（需手动开启）

- [ ] Gutenberg：正文 / Yoast 含违禁词应拦截；白名单清空后应再拦
- [ ] Elementor：画布含违禁词应拦截；清空白名单后应再拦；控制台无多余 unhandledrejection
- [ ] 关闭拦截后编辑器保存无感

### 定时 / 通知

- [ ] WP-Cron：配置时间点后可记录「上次定时成功」
- [ ] 系统 Cron：URL + 下载脚本可用；改密钥后旧 URL 失效
- [ ] 开启通知后，有违规时 Webhook / 飞书可收到（含 `items`）；测试按钮可用

## 安装

1. 将 `wp-risk-scanner` 目录放到 `wp-content/plugins/`。
2. 后台启用 **WP Risk Scanner**。
3. 打开「风险词管理 → 设置」按需配置。

## 更新日志

### 1.0.0

- 脚手架：激活建表、三子菜单、设置页（安全默认）、模块占位、可选卸载清数据。
- 违禁词表：CRUD、搜索分页、CSV 合并/替换导入与导出。
- 文章扫描：全站分批扫、风险列表、忽略词、扫描日志、清空结果、保存后单篇重扫。
- 归档页扫描：taxonomy 配置、采集/入库、列表与忽略、定时/全站接入。
- 定时：WP-Cron / 系统 URL Cron、安装脚本下载。
- 通知：Webhook + 飞书（含 `items` 摘要）；保存拦截（默认关）；Elementor 已知拦截控制台噪音压制。
