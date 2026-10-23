<?php
/**
 * HTTP 响应与输入参数工具
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 输出 JSON 响应并结束请求
 */
function jsonResponse(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 安全读取 POST 字符串参数（拒绝数组等非标量输入）
 */
function postString(string $field, string $default = ''): string {
    $value = $_POST[$field] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

/**
 * 读取并校验必填文本参数
 */
function requireText(string $field, string $label, int $maxLength): string {
    $value = postString($field);
    if ($value === '') {
        jsonResponse(['success' => false, 'error' => "请输入{$label}"], 400);
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        jsonResponse(['success' => false, 'error' => "{$label}包含非法字符编码"], 400);
    }
    if (mb_strlen($value) > $maxLength) {
        jsonResponse(['success' => false, 'error' => "{$label}过长（最多 {$maxLength} 字）"], 400);
    }
    return $value;
}

/**
 * 读取并校验 32 位十六进制 ID 参数（会话、任务标识）
 */
function postHexId(string $field, string $label): string {
    $value = postString($field);
    if ($value === '') {
        return '';
    }
    if (!preg_match('/^[a-f0-9]{32}$/', $value)) {
        jsonResponse(['success' => false, 'error' => $label . '不合法'], 400);
    }
    return $value;
}

/**
 * 校验模型名称，避免任意字符串注入请求体
 */
function validateModelName(?string $model): ?string {
    if ($model === null || $model === '') {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9._:\/\-]{1,80}$/', $model)) {
        jsonResponse(['success' => false, 'error' => '模型名称不合法'], 400);
    }
    return $model;
}
