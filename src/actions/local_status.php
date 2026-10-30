<?php
/**
 * 动作：本地 AI 服务连通性检测（Ollama / ComfyUI）
 *
 * 前端在本地服务调用失败，或切换到本地服务时调用本动作，
 * 根据返回结果弹出对应的启动指引窗口。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 探测本地服务是否可访问（1 秒连接超时、2 秒总超时，避免阻塞页面）
 */
function probeLocalService(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'online' => $body !== false && $httpCode === 200,
        'body' => $body === false ? '' : (string)$body,
    ];
}

function handleGetLocalAiStatus(): void {
    global $config;

    // 从配置中找出指向本机的服务，供前端判断当前选择是否需要启动指引
    $localServiceKeys = [];
    $ollamaUrl = '';
    foreach ($config['ai_services'] as $key => $service) {
        $base = rtrim((string)($service['api_base_url'] ?? ''), '/');
        if ($base === '' || !preg_match('#^https?://(?:localhost|127\.0\.0\.1)(?::\d+)?#i', $base)) {
            continue;
        }
        $localServiceKeys[] = $key;
        if ($ollamaUrl === '' && strpos($base, ':11434') !== false) {
            $ollamaUrl = preg_replace('#/v1$#i', '', $base);
        }
    }
    if ($ollamaUrl === '') {
        $ollamaUrl = 'http://127.0.0.1:11434';
    }

    $ollamaProbe = probeLocalService($ollamaUrl . '/api/tags');
    $models = [];
    if ($ollamaProbe['online']) {
        $decoded = json_decode($ollamaProbe['body'], true);
        if (is_array($decoded) && is_array($decoded['models'] ?? null)) {
            foreach ($decoded['models'] as $model) {
                if (is_array($model) && isset($model['name']) && is_string($model['name'])) {
                    $models[] = $model['name'];
                }
            }
        }
    }

    $comfyUrl = '';
    $imageConfig = $config['image_api'] ?? [];
    if (is_array($imageConfig) && ($imageConfig['service'] ?? '') === 'comfyui') {
        $comfyUrl = rtrim((string)($imageConfig['api_url'] ?? ''), '/');
    }
    if ($comfyUrl === '') {
        $comfyUrl = 'http://127.0.0.1:8188';
    }
    $comfyProbe = probeLocalService($comfyUrl . '/system_stats');

    jsonResponse([
        'success' => true,
        'ollama' => [
            'online' => $ollamaProbe['online'],
            'url' => $ollamaUrl,
            'models' => $models,
        ],
        'comfyui' => [
            'online' => $comfyProbe['online'],
            'url' => $comfyUrl,
        ],
        'local_service_keys' => $localServiceKeys,
    ]);
}
