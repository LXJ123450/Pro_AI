<?php
/**
 * 动作：返回可用的 AI 服务列表
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleGetAiServices(): void {
    global $config;

    $services = [];
    foreach ($config['ai_services'] as $key => $service) {
        $services[] = [
            'key' => $key,
            'name' => $service['name'],
            'description' => $service['description'],
            'configured' => !serviceNeedsKey($key) || !empty($service['api_key']),
            'default_model' => $service['default_model'],
        ];
    }
    jsonResponse([
        'success' => true,
        'services' => $services,
        'default_ai' => $config['default_ai'],
    ]);
}
