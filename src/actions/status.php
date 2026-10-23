<?php
/**
 * 动作：配置状态检查
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleCheckConfig(): void {
    global $config;

    $configuredServices = [];
    foreach ($config['ai_services'] as $key => $service) {
        if (!serviceNeedsKey($key) || !empty($service['api_key'])) {
            $configuredServices[] = $service['name'];
        }
    }
    jsonResponse([
        'success' => true,
        'configured' => $configuredServices !== [],
        'message' => $configuredServices !== []
            ? '已配置: ' . implode(', ', $configuredServices)
            : '未配置任何AI服务',
    ]);
}
