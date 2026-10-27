<?php
/**
 * 会话、CSRF、同源校验与限流
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 启动安全会话（HttpOnly + SameSite）
 */
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // 会话目录不可写时回退到系统临时目录，避免直接 500
    $savePath = session_save_path();
    if ($savePath !== '') {
        $parts = explode(';', $savePath);
        $savePath = end($parts);
    }
    if ($savePath === '' || !is_dir($savePath) || !is_writable($savePath)) {
        $fallback = rtrim(sys_get_temp_dir(), '/\\');
        if (is_dir($fallback) && is_writable($fallback)) {
            session_save_path($fallback);
        }
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
    ]);
    if (!session_start()) {
        http_response_code(500);
        exit('会话初始化失败，请检查服务器 session 配置');
    }
}

/**
 * 获取（或创建）当前会话的 CSRF Token
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 校验 CSRF Token
 */
function verifyCsrfToken(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonResponse(['success' => false, 'error' => '会话已失效，请刷新页面后重试'], 403);
    }
}

/**
 * 校验请求来源，防止跨站请求
 */
function verifySameOrigin(): void {
    $hostName = explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0];
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $candidate = parse_url($_SERVER[$key], PHP_URL_HOST);
        if ($candidate === false || $candidate === null || strcasecmp((string)$candidate, $hostName) !== 0) {
            jsonResponse(['success' => false, 'error' => '非法请求来源'], 403);
        }
        return;
    }
}

/**
 * 按客户端 IP + 动作做滑动窗口限流
 */
function enforceRateLimit(string $action): void {
    $limits = [
        'chat' => 30,
        'chat_stream' => 20,
        'get_conversation' => 60,
        'get_ai_service_config' => 60,
        'save_ai_service_config' => 10,
        'generate_novel_stage' => 30,
        'generate_novel_stream' => 30,
        'generate_novel' => 5,
        'generate_image' => 5,
        'generate_video' => 3,
        'get_video_task' => 60,
    ];
    $limit = $limits[$action] ?? 30;
    $window = 60;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ai_creator_rate';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return; // 无法写入限流文件时降级放行
    }
    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $ip . '|' . $action) . '.json';
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        return;
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return;
    }
    $now = time();
    $state = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($state) || ($now - (int)($state['start'] ?? 0)) >= $window) {
        $state = ['start' => $now, 'count' => 0];
    }
    if ((int)$state['count'] >= $limit) {
        $retryAfter = max(1, $window - ($now - (int)$state['start']));
        flock($handle, LOCK_UN);
        fclose($handle);
        header('Retry-After: ' . $retryAfter);
        jsonResponse([
            'success' => false,
            'error' => '请求过于频繁，请稍后再试',
            'retry_after' => $retryAfter,
        ], 429);
    }
    $state['count'] = (int)$state['count'] + 1;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * 校验并清洗对话消息，禁止客户端注入 system 角色
 */
function validateMessages($raw): array {
    if (!is_string($raw) || $raw === '') {
        jsonResponse(['success' => false, 'error' => '消息格式不正确'], 400);
    }
    $messages = json_decode($raw, true);
    if (!is_array($messages) || json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'error' => '消息格式不正确'], 400);
    }
    if (count($messages) > 40) {
        jsonResponse(['success' => false, 'error' => '对话轮次过多，请刷新页面后重试'], 400);
    }
    $clean = [];
    $totalLength = 0;
    foreach ($messages as $message) {
        if (!is_array($message) || !isset($message['role'], $message['content'])) {
            jsonResponse(['success' => false, 'error' => '消息格式不正确'], 400);
        }
        $role = is_string($message['role']) ? $message['role'] : '';
        $content = is_string($message['content']) ? trim($message['content']) : '';
        if (!in_array($role, ['user', 'assistant'], true)) {
            jsonResponse(['success' => false, 'error' => '消息角色不合法'], 400);
        }
        if ($content === '') {
            continue;
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            jsonResponse(['success' => false, 'error' => '消息包含非法字符编码'], 400);
        }
        $length = mb_strlen($content);
        if ($length > 8000) {
            jsonResponse(['success' => false, 'error' => '单条消息过长（最多 8000 字）'], 400);
        }
        $totalLength += $length;
        if ($totalLength > 32000) {
            jsonResponse(['success' => false, 'error' => '对话内容过长，请刷新页面后重试'], 400);
        }
        $clean[] = ['role' => $role, 'content' => $content];
    }
    $last = end($clean);
    if ($last === false || $last['role'] !== 'user') {
        jsonResponse(['success' => false, 'error' => '请输入内容后再发送'], 400);
    }
    return $clean;
}
