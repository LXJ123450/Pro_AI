# CLAUDE.md — Pro_AI 项目 AI 工作手册

> 适用对象：所有参与本项目的 AI 会话（Trae / Claude / 其他）。
> 本文件是项目级最高优先级工作约定。**开始任何任务前，必须先读本文件，再读 `Project_AI/memory/` 中最新的任务记忆。**

---

## 0. 会话启动协议（强制）

1. 阅读本文件。
2. 列出 `Project_AI/memory/` 下所有 `.md`（`README.md` 除外），按文件名开头的日期倒序，打开最新一份。
3. 按记忆文件中的「状态」判断本次会话类型：

| 记忆文件状态 | 本次会话应做的事 | 是否新建任务目录/文档 |
|--------------|------------------|------------------------|
| `进行中` | 继续该任务，按「任务步骤」执行未完成项 | **否**，只在原文件上更新 |
| `阻塞` | 先看「阻塞原因」和「下一步」，与用户确认解除条件后再继续 | **否** |
| `已完成` | 查看「后续任务」；用户给出新任务时按第 4 节新建 | 是（新任务） |

4. 若用户当前要求与最新记忆中的任务无关，视为**新任务**，按第 4 节新建目录与文档。
5. 会话结束前必须更新任务记忆（见 8.3）；开发管理文档中的测试与检查项也要同步填写。

---

## 1. 项目概览

- 项目名称：AI 创作平台（Pro_AI）
- 项目类型：PHP 单入口 Web 应用（无框架）
- 核心功能：
  - 多 AI 服务对话（普通 + SSE 流式）
  - 小说多步创作流水线（大纲 → 分章 → 梗概）
  - 图片生成
  - 视频生成（同步 + 异步任务轮询）
- 本地访问地址：`http://127.0.0.1:1597/`
- 页面入口：`index.php`；API 通过 `POST` + `action` 参数分发

---

## 2. 运行环境

| 项目 | 说明 |
|------|------|
| Web 服务器 | Apache 2.4.39，端口 `1597` |
| PHP | 8.3.36 NTS（`D:\software\phpstudy\phpstudy_pro\Extensions\php\php8.3.36nts`） |
| PHP 兼容目标 | **必须兼容 PHP 7.3.4**（备用环境：`...\php7.3.4nts`） |
| 数据库 | MySQL 5.7.26，库名 `pro_ai`，`utf8mb4` |
| Node.js | `D:\software\nodejs`（仅用于 JS 语法检查与调试工具） |

> 数据库账号、API 密钥等敏感信息只允许放在 `config.php`（已被 `.gitignore` 忽略）或 `管理员.txt`，**禁止写入任何文档、代码注释或前端**。

---

## 3. 目录结构与请求链路

```
Pro_AI/
├── CLAUDE.md                  # 本手册
├── index.php                  # 唯一入口：加载内核 → 分发请求 → 渲染页面
├── config.php                 # 本地敏感配置（已忽略）
├── src/
│   ├── http.php               # JSON 响应、参数校验工具
│   ├── config.php             # 配置读取辅助
│   ├── security.php           # 会话、CSRF、同源校验、限流、消息校验
│   ├── ai.php                 # cURL 调用、重试、响应解析
│   ├── providers/             # Provider 抽象层
│   │   ├── provider.php
│   │   ├── openai_compatible.php
│   │   └── factory.php
│   ├── storage/               # 数据持久化
│   │   ├── db.php             # PDO 单例（不可用时返回 null）
│   │   ├── conversation_store.php
│   │   └── video_task_store.php
│   └── actions/               # 动作处理器
│       ├── registry.php       # 动作白名单与统一分发
│       ├── services.php / status.php
│       ├── chat.php / chat_stream.php / conversation.php
│       ├── novel.php / novel_pipeline.php
│       ├── image.php
│       └── video.php / video_task.php
├── views/
│   ├── index.php              # 页面模板（引入静态资源、输出 CSRF）
│   └── index.body.php         # 页面主体结构
├── assets/
│   ├── css/app.css
│   └── js/app.js
└── Project_AI/                # 文档与记忆（已整体忽略）
    ├── memory/
    └── YYYYMMDD 主题/
```

**请求链路**

- GET：`index.php` → 加载配置与内核 → `startSecureSession()` → 生成 CSRF → `views/index.php`
- POST：`dispatchAction()` → 动作白名单 → 同源校验 → CSRF 校验 → 限流 → 处理器 → `jsonResponse()` / SSE
- SSE：仅 `chat_stream` 使用 `text/event-stream`，其余动作统一 JSON

**动作清单**

| action | 说明 | 限流（次/分钟） |
|--------|------|------------------|
| `get_ai_services` | AI 服务列表 | 30 |
| `check_config` | 配置状态 | 30 |
| `chat` | 普通对话 | 30 |
| `chat_stream` | SSE 流式对话 | 20 |
| `get_conversation` | 读取服务端会话历史 | 60 |
| `generate_novel` | 单步小说生成（兼容保留） | 5 |
| `generate_novel_pipeline` | 小说多步流水线 | 3 |
| `generate_image` | 图片生成 | 5 |
| `generate_video` | 视频生成（同步/异步） | 3 |
| `get_video_task` | 视频任务轮询 | 60 |

**数据表**（首次调用时自动创建）：`conversations`、`conversation_messages`、`video_tasks`。

---

## 4. 任务与文档规则

### 4.1 一个 AI 会话 = 一个任务

- 每次新任务必须同时创建：
  1. 开发管理文档：`Project_AI/<YYYYMMDD> <主题>/<YYYYMMDD> <主题>.md`
  2. 任务记忆：`Project_AI/memory/<YYYYMMDD> <主题>.md`
- **续做旧任务不新建任何目录或文档**，只更新原开发管理文档与记忆文件。

### 4.2 命名规范

- 日期：任务开始当天，格式 `YYYYMMDD`（如 `20261023`）
- 主题：中文短语，≤ 12 字，不含 `/ \ : * ? " < > |`
- 目录名与 md 文件名保持一致
- 同一天同一主题重复时追加 `-2`、`-3`
- 例：`Project_AI/20261023 AI工作手册与记忆机制/20261023 AI工作手册与记忆机制.md`

### 4.3 文档内禁止出现

- 任何密钥、密码、Token、数据库连接串明文
- 用户隐私数据、生产环境账号
- 大段与任务无关的代码复制

---

## 5. 开发周期管理（每个任务必须走完）

开发管理文档必须包含以下五个阶段，模板见 `Project_AI/memory/README.md`。

| 阶段 | 名称 | 必须产出 | 完成标志 |
|------|------|----------|----------|
| 0 | 需求确认 | 目标、范围、不做的事、验收标准 | 用户确认或明确无歧义 |
| 1 | 开发实现 | 方案、改动文件清单、关键决策 | 代码/文档完成 |
| 2 | BUG 调试 | 问题、复现步骤、根因、修复、回归 | 回归通过 |
| 3 | 功能测试 | 用例表（接口/浏览器/边界/安全）与结果 | 全部用例通过 |
| 4 | 上线前检查 | 检查清单逐项勾选 | 清单全绿 |
| 5 | 收尾归档 | 更新记忆状态、变更记录、提交建议 | 记忆状态为 `已完成` |

> 小任务可合并阶段 1–2，但 0、3、4、5 不可省略。

---

## 6. 强制技术约束

### 6.1 安全

- 所有 POST 动作必须登记在 `src/actions/registry.php`，禁止绕过统一分发
- 必须同时通过：同源校验 + CSRF 校验 + 按动作限流
- 所有外部输入必须白名单/长度/格式校验，禁止直接透传
- 前端渲染一律使用 `textContent` / `replaceChildren`，禁止 `innerHTML` 拼接用户或模型内容
- 必须保留 TLS 证书校验，禁止关闭 `CURLOPT_SSL_VERIFYPEER`
- 所有 cURL 请求必须设置连接超时与总超时
- 会话 ID、任务 ID 必须为 32 位十六进制并由服务端生成

### 6.2 兼容性

- 项目必须同时通过 PHP 7.3 与 PHP 8.3 语法检查
- 禁止使用 PHP 8 专属语法（`match`、`str_contains`、空安全运算符等）
- 禁止使用 PHP 7.4 属性类型声明与箭头函数

### 6.3 分层与风格

- `index.php` 只做加载、分发、渲染，不写业务逻辑
- 业务逻辑放 `src/`，视图放 `views/`，静态资源放 `assets/`
- 禁止在静态资源中写内联 PHP
- 数据库统一使用 PDO 预处理；数据库不可用时必须优雅降级
- 避免无关重构；只改与任务直接相关的代码
- 注释使用中文，风格与现有文件保持一致

---

## 7. 验证命令

```powershell
# PHP 8.3 全量语法检查
$php='D:\software\phpstudy\phpstudy_pro\Extensions\php\php8.3.36nts\php.exe'
Get-ChildItem -Path 'E:\Project\Pro_AI' -Recurse -Filter *.php |
  Where-Object { $_.FullName -notmatch '\\node_modules\\|\\.trae\\|\\Project_AI\\' } |
  ForEach-Object { & $php -l $_.FullName }

# PHP 7.3 兼容检查（把路径换成 php7.3.4nts）
$php='D:\software\phpstudy\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe'
Get-ChildItem -Path 'E:\Project\Pro_AI' -Recurse -Filter *.php |
  Where-Object { $_.FullName -notmatch '\\node_modules\\|\\.trae\\|\\Project_AI\\' } |
  ForEach-Object { & $php -l $_.FullName }

# 前端 JS 语法检查
& 'D:\software\nodejs\node.exe' --check 'E:\Project\Pro_AI\assets\js\app.js'

# 页面可用性
curl.exe -s -I 'http://127.0.0.1:1597/'
```

---

## 8. 记忆机制

### 8.1 位置与命名

- 目录：`Project_AI/memory/`
- 任务记忆文件名：`<YYYYMMDD> <主题>.md`（与开发管理文档同名）
- 机制说明：`Project_AI/memory/README.md`（不参与任务排序）
- 模板：见 `Project_AI/memory/README.md`

### 8.2 状态定义

| 状态 | 含义 |
|------|------|
| `进行中` | 正在执行，新会话应继续 |
| `阻塞` | 等待用户/外部条件，不能自行推进 |
| `已完成` | 任务结束，可查看「后续任务」 |
| `已搁置` | 用户明确暂停，暂不推进 |

### 8.3 会话结束前必须更新

1. 任务记忆：勾选完成的步骤、更新「最后更新」、写「下一步」或改为最终状态
2. 开发管理文档：补齐 BUG 记录、测试结果、上线检查、变更记录
3. 若有未完成项，记忆状态保持 `进行中` 或 `阻塞`，便于下次会话接续

### 8.4 多任务冲突

- 若存在多个 `进行中`，以**文件名日期最新**的为准
- 其余文件需在本次会话中改为 `已搁置` 并注明原因，避免新会话误判

---

## 9. 文档索引

| 文档 | 路径 | 说明 |
|------|------|------|
| AI 工作手册 | `CLAUDE.md` | 本文件 |
| 记忆机制 | `Project_AI/memory/README.md` | 记忆规则与模板 |
| 开发规范 | `Project_AI/20260529 开发规范/20260529 开发规范.md` | PHP 编码/安全/Git 规范 |
| 项目初版说明 | `Project_AI/20260529 AI网页开发初版/20260529 AI网页开发初版.md` | 初版功能与配置说明 |
| 本次任务文档 | `Project_AI/20261023 AI工作手册与记忆机制/20261023 AI工作手册与记忆机制.md` | 手册与记忆机制建设记录 |
| 在线API与本地AI接入 | `Project_AI/20261027 在线API与本地AI接入/20261027 在线API与本地AI接入.md` | 免注册 API、本地模型与浏览器实测记录 |
| 网页配置与视频接口 | `Project_AI/20261027 网页配置与视频接口/20261027 网页配置与视频接口.md` | 网页端配置编辑、智谱视频接入、小说流式生成 |

> 新增任务文档后，必须把该任务登记到本索引中（只追加，不删除历史）。

---

## 10. 禁止事项

- 未读记忆就开始新任务，导致重复建档
- 把密钥、账号、Token 写入文档或前端
- 为调试关闭 TLS 校验、跳过 CSRF 或移除限流
- 未做语法检查与功能测试就标记任务完成
- 在任务范围外进行大规模重构或格式化
- 删除历史任务文档或记忆文件
