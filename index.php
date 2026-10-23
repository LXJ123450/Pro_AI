<?php
/**
 * AI 创作平台 - 应用入口
 *
 * 仅负责：加载配置与内核、分发 API 请求、渲染页面模板。
 * 具体实现拆分到 src/（安全、AI 调用、动作处理器）与 views/、assets/。
 */

define('AI_CREATOR_APP', true);

// 加载配置文件（config.php 内部会校验该常量，防止被 Web 直接访问）
$config = require __DIR__ . '/config.php';

require __DIR__ . '/src/http.php';
require __DIR__ . '/src/config.php';
require __DIR__ . '/src/security.php';
require __DIR__ . '/src/ai.php';
require __DIR__ . '/src/providers/factory.php';
require __DIR__ . '/src/storage/conversation_store.php';
require __DIR__ . '/src/storage/video_task_store.php';
require __DIR__ . '/src/actions/registry.php';

// 启动会话并准备 CSRF Token
startSecureSession();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$csrfToken = getCsrfToken();

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    dispatchAction();
}

// 渲染页面
require __DIR__ . '/views/index.php';
