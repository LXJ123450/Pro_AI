<?php
/**
 * 动作：视频生成
 *
 * 支持两种上游模式：
 * 1. 同步返回视频地址；
 * 2. 返回远程任务 ID，由前端轮询 get_video_task 获取结果。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 从上游响应中提取视频地址（兼容常见字段嵌套）
 */
function extractVideoUrlFromResponse(array $data): string {
    $candidates = [
        $data['video_url'] ?? null,
        $data['data'][0]['url'] ?? null,
        $data['output']['video_url'] ?? null,
        $data['result']['video_url'] ?? null,
        $data['url'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }
    return '';
}

/**
 * 从上游响应中提取远程任务 ID
 */
function extractRemoteTaskIdFromResponse(array $data): string {
    $candidates = [
        $data['task_id'] ?? null,
        $data['id'] ?? null,
        $data['data']['task_id'] ?? null,
        $data['data']['id'] ?? null,
        $data['output']['task_id'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_scalar($candidate) && (string)$candidate !== '') {
            return (string)$candidate;
        }
    }
    return '';
}

/**
 * 从上游响应中提取任务状态
 */
function extractRemoteStatusFromResponse(array $data): string {
    $candidates = [
        $data['status'] ?? null,
        $data['state'] ?? null,
        $data['data']['status'] ?? null,
        $data['output']['status'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return strtolower($candidate);
        }
    }
    return '';
}

function handleGenerateVideo(): void {
    global $config;

    $videoConfig = $config['video_api'];
    if (empty($videoConfig['enabled']) || empty($videoConfig['api_key'])) {
        jsonResponse(['success' => false, 'error' => '视频生成功能未配置，请在config.php中启用并配置video_api'], 400);
    }
    if (empty($videoConfig['api_url'])) {
        jsonResponse(['success' => false, 'error' => '视频生成API地址未配置（video_api.api_url）'], 400);
    }
    $prompt = requireText('prompt', '视频描述', 2000);

    $store = VideoTaskStore::create();
    $taskId = '';
    if ($store !== null) {
        $taskId = VideoTaskStore::newId();
        if (!$store->createTask($taskId, $prompt, 'pending')) {
            // 记录失败时降级为同步模式，不阻塞生成
            $store = null;
            $taskId = '';
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $videoConfig['api_url'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $videoConfig['api_key'],
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        if ($store !== null) {
            $store->updateTask($taskId, ['status' => 'failed', 'error' => mb_substr($curlError, 0, 500)]);
        }
        jsonResponse(['success' => false, 'error' => '无法连接到视频生成API，请检查网络连接。错误: ' . $curlError], 502);
    }
    if ($httpCode !== 200) {
        $message = '视频生成API返回错误: HTTP ' . $httpCode . ' - ' . mb_substr((string)$response, 0, 300);
        if ($store !== null) {
            $store->updateTask($taskId, ['status' => 'failed', 'error' => mb_substr($message, 0, 500)]);
        }
        jsonResponse(['success' => false, 'error' => $message], 502);
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        $message = '无法解析视频生成响应: ' . mb_substr((string)$response, 0, 300);
        if ($store !== null) {
            $store->updateTask($taskId, ['status' => 'failed', 'error' => mb_substr($message, 0, 500)]);
        }
        jsonResponse(['success' => false, 'error' => $message], 502);
    }

    // 模式一：上游直接返回视频地址
    $videoUrl = extractVideoUrlFromResponse($data);
    if ($videoUrl !== '') {
        if ($store !== null) {
            $store->updateTask($taskId, ['status' => 'completed', 'video_url' => $videoUrl]);
        }
        jsonResponse([
            'success' => true,
            'status' => 'completed',
            'video_url' => $videoUrl,
            'task_id' => $taskId,
        ]);
    }

    // 模式二：上游返回远程任务 ID，交由前端轮询
    $remoteTaskId = extractRemoteTaskIdFromResponse($data);
    $statusUrl = trim((string)($videoConfig['status_url'] ?? ''));
    $asyncEnabled = !empty($videoConfig['async']) && $statusUrl !== '';
    if ($remoteTaskId === '' || !$asyncEnabled || $store === null) {
        $preview = mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300);
        $message = '视频生成API未返回视频地址。若上游为异步任务，请在 config.php 中配置 video_api.status_url 并开启 async。响应: ' . $preview;
        if ($store !== null) {
            $store->updateTask($taskId, ['status' => 'failed', 'error' => mb_substr($message, 0, 500)]);
        }
        jsonResponse(['success' => false, 'error' => $message], 502);
    }

    $store->updateTask($taskId, ['status' => 'processing', 'remote_task_id' => $remoteTaskId]);
    jsonResponse([
        'success' => true,
        'status' => 'processing',
        'task_id' => $taskId,
        'poll_interval' => max(2, (int)($videoConfig['poll_interval'] ?? 5)),
    ]);
}
