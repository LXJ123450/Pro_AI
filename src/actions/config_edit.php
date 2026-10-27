<?php
/**
 * 动作：网页端读取与保存 AI 服务配置
 *
 * 安全约定：
 * 1. 仅允许本机回环地址调用，避免配置被局域网内其他机器改写；
 * 2. API Key 只写不读，前端最多看到末 4 位提示；
 * 3. 仍走统一的同源 + CSRF + 限流校验（见 registry.php）。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 仅允许本机访问配置编辑功能
 */
function requireLocalConfigAccess(): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        jsonResponse(['success' => false, 'error' => '配置编辑仅允许在本机访问'], 403);
    }
}

/**
 * 密钥掩码：只暴露末 4 位
 */
function maskApiKey(string $key): string {
    if ($key === '') {
        return '';
    }
    if (strlen($key) <= 6) {
        return '******';
    }
    return '******' . substr($key, -4);
}

function handleGetAiServiceConfig(): void {
    global $config;
    requireLocalConfigAccess();

    $serviceKey = postString('ai_service');
    if ($serviceKey === '' || !isset($config['ai_services'][$serviceKey])) {
        jsonResponse(['success' => false, 'error' => '未知的 AI 服务'], 400);
    }
    $service = $config['ai_services'][$serviceKey];
    $apiKey = (string)($service['api_key'] ?? '');

    jsonResponse([
        'success' => true,
        'service' => [
            'key' => $serviceKey,
            'name' => (string)($service['name'] ?? $serviceKey),
            'description' => (string)($service['description'] ?? ''),
            'requires_key' => serviceNeedsKey($serviceKey),
            'api_base_url' => (string)($service['api_base_url'] ?? ''),
            'default_model' => (string)($service['default_model'] ?? ''),
            'has_key' => $apiKey !== '',
            'key_hint' => maskApiKey($apiKey),
            'config_writable' => is_writable(configFilePath()),
        ],
    ]);
}

function handleSaveAiServiceConfig(): void {
    global $config;
    requireLocalConfigAccess();

    $serviceKey = postString('ai_service');
    if ($serviceKey === '' || !isset($config['ai_services'][$serviceKey])) {
        jsonResponse(['success' => false, 'error' => '未知的 AI 服务'], 400);
    }

    $baseUrl = postString('api_base_url');
    if (strlen($baseUrl) > 300) {
        jsonResponse(['success' => false, 'error' => '接口地址过长（最多 300 字符）'], 400);
    }
    if ($baseUrl !== '') {
        if (!preg_match('#^https?://[^\s]+$#i', $baseUrl)) {
            jsonResponse(['success' => false, 'error' => '接口地址必须以 http:// 或 https:// 开头'], 400);
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            jsonResponse(['success' => false, 'error' => '接口地址格式不正确'], 400);
        }
    }

    $model = postString('default_model');
    if ($model !== '' && !preg_match('/^[A-Za-z0-9._:\/\-]{1,100}$/', $model)) {
        jsonResponse(['success' => false, 'error' => '模型名称只能包含字母、数字与 . _ : / -'], 400);
    }

    $apiKey = postString('api_key');
    if ($apiKey !== '' && !preg_match('/^[\x21-\x7E]{1,500}$/', $apiKey)) {
        jsonResponse(['success' => false, 'error' => 'API Key 只能包含可见 ASCII 字符，且不超过 500 字符'], 400);
    }
    $clearKey = postString('clear_key') === '1';

    $currentKey = (string)($config['ai_services'][$serviceKey]['api_key'] ?? '');
    $fields = [
        'api_base_url' => $baseUrl,
        'default_model' => $model,
    ];
    if ($clearKey) {
        $fields['api_key'] = '';
    } elseif ($apiKey !== '') {
        $fields['api_key'] = $apiKey;
    }

    $result = saveAiServiceConfig($serviceKey, $fields);
    if (!$result['success']) {
        jsonResponse(['success' => false, 'error' => $result['error']], 500);
    }

    $effectiveKey = $clearKey ? '' : ($apiKey !== '' ? $apiKey : $currentKey);
    jsonResponse([
        'success' => true,
        'message' => '配置已保存',
        'service' => [
            'key' => $serviceKey,
            'api_base_url' => $baseUrl,
            'default_model' => $model,
            'has_key' => $effectiveKey !== '',
            'key_hint' => maskApiKey($effectiveKey),
        ],
    ]);
}
