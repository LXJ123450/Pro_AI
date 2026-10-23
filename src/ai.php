<?php
/**
 * AI 上游调用：请求、重试与响应解析
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 判断上游错误是否可重试
 */
function isRetryableResult(array $result): bool {
    if ((int)$result['http_code'] === 0) {
        if ($result['error'] === '') {
            return false;
        }
        // 证书/主机名校验失败属于配置问题，重试无意义
        foreach (['SSL', 'certificate', 'hostname'] as $needle) {
            if (stripos($result['error'], $needle) !== false) {
                return false;
            }
        }
        return true;
    }
    return in_array((int)$result['http_code'], [429, 500, 502, 503, 504], true);
}

/**
 * 发送一次聊天请求
 */
function sendChatRequest(string $url, string $apiKey, array $postData, array $requestConfig): array {
    $retryAfter = 0;
    $ch = curl_init();
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => (int)$requestConfig['connect_timeout'],
        CURLOPT_TIMEOUT => (int)$requestConfig['timeout'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfter) {
            if (stripos($header, 'Retry-After:') === 0) {
                $retryAfter = (int)trim(substr($header, 12));
            }
            return strlen($header);
        },
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'response' => is_string($response) ? $response : '',
        'http_code' => $httpCode,
        'error' => $error,
        'retry_after' => max(0, $retryAfter),
        'url' => $url,
    ];
}

/**
 * 调用 OpenAI 兼容聊天接口，含超时与指数退避重试
 */
function callAIChat(array $aiConfig, array $messages, ?string $model = null): array {
    $requestConfig = getRequestConfig();
    $baseUrl = rtrim((string)($aiConfig['api_base_url'] ?? ''), '/');
    if ($baseUrl === '') {
        return [
            'response' => '',
            'http_code' => 0,
            'error' => '该 AI 服务未配置 api_base_url',
            'retry_after' => 0,
            'url' => '',
        ];
    }
    $postData = [
        'model' => $model ?? (string)($aiConfig['default_model'] ?? ''),
        'messages' => $messages,
        'stream' => false,
    ];
    if (!empty($requestConfig['max_tokens'])) {
        $postData['max_tokens'] = (int)$requestConfig['max_tokens'];
    }

    $attempts = max(1, (int)$requestConfig['max_retries']);
    $result = null;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $result = sendChatRequest(
            $baseUrl . '/chat/completions',
            (string)($aiConfig['api_key'] ?? ''),
            $postData,
            $requestConfig
        );
        if ($result['error'] === '' && $result['http_code'] === 200) {
            return $result;
        }
        if (!isRetryableResult($result) || $attempt === $attempts) {
            break;
        }
        $delay = $result['retry_after'] > 0 ? min($result['retry_after'], 5) : min($attempt, 3);
        sleep($delay);
    }
    return $result;
}

/**
 * 将上游聊天响应解析为统一结构（不输出响应，便于各层复用）
 *
 * @return array{success: bool, content: string, error: string, http_code: int}
 */
function parseChatResult(array $result, string $serviceName): array {
    if ($result['error'] !== '') {
        return [
            'success' => false,
            'content' => '',
            'error' => "无法连接到{$serviceName} API，请检查网络连接。错误: " . $result['error'],
            'http_code' => 502,
        ];
    }
    if ((int)$result['http_code'] !== 200) {
        $preview = mb_substr(trim($result['response']), 0, 300);
        return [
            'success' => false,
            'content' => '',
            'error' => "{$serviceName} API返回错误: HTTP {$result['http_code']}" . ($preview !== '' ? ' - ' . $preview : ''),
            'http_code' => 502,
        ];
    }
    $data = json_decode($result['response'], true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        $preview = mb_substr(trim($result['response']), 0, 300);
        return [
            'success' => false,
            'content' => '',
            'error' => "无法解析{$serviceName}响应，可能是服务未启动或返回了非JSON内容。" . ($preview !== '' ? ' 响应预览: ' . $preview : ''),
            'http_code' => 502,
        ];
    }
    if (isset($data['choices'][0]['message']['content']) && is_string($data['choices'][0]['message']['content'])) {
        return [
            'success' => true,
            'content' => $data['choices'][0]['message']['content'],
            'error' => '',
            'http_code' => 200,
        ];
    }
    $errorMessage = '无法获取响应内容';
    if (isset($data['error']['message']) && is_string($data['error']['message'])) {
        $errorMessage = $data['error']['message'];
    } elseif (isset($data['error']) && is_scalar($data['error'])) {
        $errorMessage = (string)$data['error'];
    }
    return [
        'success' => false,
        'content' => '',
        'error' => "{$serviceName}返回错误: " . $errorMessage,
        'http_code' => 502,
    ];
}

/**
 * 从上游响应中提取正文，失败时直接输出统一错误
 */
function extractChatContent(array $result, string $serviceName): string {
    $parsed = parseChatResult($result, $serviceName);
    if (!$parsed['success']) {
        jsonResponse(['success' => false, 'error' => $parsed['error']], 502);
    }
    return $parsed['content'];
}
