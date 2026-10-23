<?php
/**
 * 动作：图片生成
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleGenerateImage(): void {
    global $config;

    $imageConfig = $config['image_api'];
    if (!$imageConfig['enabled'] || empty($imageConfig['api_key'])) {
        jsonResponse(['success' => false, 'error' => '图片生成功能未配置，请在config.php中启用并配置image_api'], 400);
    }
    $prompt = requireText('prompt', '图片描述', 2000);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $imageConfig['api_url'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'prompt' => $prompt,
            'n' => 1,
            'size' => $imageConfig['default_size'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $imageConfig['api_key'],
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        jsonResponse(['success' => false, 'error' => '无法连接到图片生成API，请检查网络连接。错误: ' . $curlError], 502);
    }
    if ($httpCode !== 200) {
        jsonResponse(['success' => false, 'error' => '图片生成API返回错误: HTTP ' . $httpCode . ' - ' . mb_substr((string)$response, 0, 300)], 502);
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data) || !isset($data['data'][0]['url'])) {
        jsonResponse(['success' => false, 'error' => '无法解析图片生成响应: ' . mb_substr((string)$response, 0, 300)], 502);
    }
    jsonResponse(['success' => true, 'image_url' => $data['data'][0]['url']]);
}
