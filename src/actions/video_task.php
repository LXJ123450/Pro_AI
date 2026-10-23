<?php
/**
 * 动作：查询视频异步任务状态
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleGetVideoTask(): void {
    global $config;

    $taskId = postHexId('task_id', '任务标识');
    if ($taskId === '') {
        jsonResponse(['success' => false, 'error' => '缺少任务标识'], 400);
    }
    $store = VideoTaskStore::create();
    if ($store === null) {
        jsonResponse(['success' => false, 'error' => '服务端任务存储未启用（请检查 config.php 的 database 配置）'], 400);
    }
    $task = $store->find($taskId);
    if ($task === null) {
        jsonResponse(['success' => false, 'error' => '任务不存在'], 404);
    }

    $status = (string)$task['status'];
    $remoteTaskId = (string)($task['remote_task_id'] ?? '');
    $statusUrl = trim((string)($config['video_api']['status_url'] ?? ''));

    if (in_array($status, ['pending', 'processing'], true) && $remoteTaskId !== '' && $statusUrl !== '') {
        $url = str_replace('{task_id}', rawurlencode($remoteTaskId), $statusUrl);
        $headers = ['Accept: application/json'];
        if (!empty($config['video_api']['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $config['video_api']['api_key'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 查询失败多为临时网络问题，保持原状态等待下一次轮询
        if ($curlError === '' && $httpCode === 200) {
            $data = json_decode((string)$response, true);
            if (is_array($data)) {
                $videoUrl = extractVideoUrlFromResponse($data);
                $remoteStatus = extractRemoteStatusFromResponse($data);
                if ($videoUrl !== '') {
                    $store->updateTask($taskId, ['status' => 'completed', 'video_url' => $videoUrl]);
                } elseif (in_array($remoteStatus, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                    $message = (string)($data['error']['message'] ?? $data['message'] ?? $data['error'] ?? '视频生成失败');
                    $store->updateTask($taskId, ['status' => 'failed', 'error' => mb_substr($message, 0, 500)]);
                } else {
                    $store->updateTask($taskId, ['status' => 'processing']);
                }
            }
        }
        $reloaded = $store->find($taskId);
        if ($reloaded !== null) {
            $task = $reloaded;
        }
    }

    jsonResponse([
        'success' => true,
        'task_id' => $taskId,
        'status' => (string)$task['status'],
        'video_url' => (string)($task['video_url'] ?? ''),
        'error' => (string)($task['error'] ?? ''),
    ]);
}
