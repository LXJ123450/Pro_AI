<?php
/**
 * OpenAI 兼容协议 Provider
 *
 * 适用于 OpenAI / DeepSeek / Groq / Ollama 等实现了
 * POST {base}/chat/completions 的服务。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/provider.php';

class OpenAICompatibleProvider extends Provider {

    /**
     * 拼接聊天接口地址
     */
    private function chatEndpoint(): string {
        return rtrim((string)($this->serviceConfig['api_base_url'] ?? ''), '/') . '/chat/completions';
    }

    /**
     * 构造公共请求头
     */
    private function buildHeaders(array $extra = []): array {
        $headers = ['Content-Type: application/json'];
        if (!empty($this->serviceConfig['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $this->serviceConfig['api_key'];
        }
        return array_merge($headers, $extra);
    }

    /**
     * 构造请求体
     */
    private function buildPayload(array $messages, $model, bool $stream): array {
        $payload = [
            'model' => ($model !== null && $model !== '') ? $model : $this->defaultModel(),
            'messages' => $messages,
            'stream' => $stream,
        ];
        if (!empty($this->requestConfig['max_tokens'])) {
            $payload['max_tokens'] = (int)$this->requestConfig['max_tokens'];
        }
        return $payload;
    }

    public function chat(array $messages, $model = null): array {
        if (rtrim((string)($this->serviceConfig['api_base_url'] ?? ''), '/') === '') {
            return [
                'success' => false,
                'content' => '',
                'error' => '该 AI 服务未配置 api_base_url',
                'http_code' => 502,
            ];
        }
        return parseChatResult(callAIChat($this->serviceConfig, $messages, $model), $this->name());
    }

    public function streamChat(array $messages, $model, callable $onDelta): array {
        $baseUrl = rtrim((string)($this->serviceConfig['api_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return [
                'success' => false,
                'content' => '',
                'error' => '该 AI 服务未配置 api_base_url',
                'http_code' => 502,
            ];
        }

        $content = '';
        $buffer = '';
        $rawBody = '';
        $sawSse = false;
        $sawDone = false;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->chatEndpoint(),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($this->buildPayload($messages, $model, true)),
            CURLOPT_CONNECTTIMEOUT => (int)$this->requestConfig['connect_timeout'],
            CURLOPT_TIMEOUT => max(30, (int)$this->requestConfig['timeout']),
            CURLOPT_HTTPHEADER => $this->buildHeaders(['Accept: text/event-stream']),
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$content, &$buffer, &$rawBody, &$sawSse, &$sawDone, $onDelta) {
                if (connection_aborted()) {
                    return 0; // 客户端已断开，终止上游请求
                }
                if (strlen($rawBody) < 262144) {
                    $rawBody .= $chunk;
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || strpos($line, ':') === 0) {
                        continue;
                    }
                    if (strpos($line, 'data:') !== 0) {
                        continue;
                    }
                    $payload = trim(substr($line, 5));
                    if ($payload === '[DONE]') {
                        $sawSse = true;
                        $sawDone = true;
                        continue;
                    }
                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $delta = '';
                    if (isset($decoded['choices'][0]['delta']['content']) && is_string($decoded['choices'][0]['delta']['content'])) {
                        $delta = $decoded['choices'][0]['delta']['content'];
                    } elseif (isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
                        $delta = $decoded['choices'][0]['message']['content'];
                    } elseif (isset($decoded['choices'][0]['text']) && is_string($decoded['choices'][0]['text'])) {
                        $delta = $decoded['choices'][0]['text'];
                    }
                    if ($delta === '') {
                        continue;
                    }
                    $sawSse = true;
                    $content .= $delta;
                    $onDelta($delta);
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            if (connection_aborted()) {
                return ['success' => false, 'content' => $content, 'error' => '客户端已断开连接', 'http_code' => 499];
            }
            return ['success' => false, 'content' => $content, 'error' => '流式请求失败: ' . $curlError, 'http_code' => 502];
        }
        if ($httpCode !== 200) {
            $preview = mb_substr(trim($rawBody), 0, 300);
            return [
                'success' => false,
                'content' => $content,
                'error' => $this->name() . ' 流式接口返回错误: HTTP ' . $httpCode . ($preview !== '' ? ' - ' . $preview : ''),
                'http_code' => 502,
            ];
        }
        if (!$sawSse) {
            // 上游忽略了 stream 参数，按普通 JSON 响应兜底处理
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded) && isset($decoded['choices'][0]['message']['content']) && is_string($decoded['choices'][0]['message']['content'])) {
                $text = $decoded['choices'][0]['message']['content'];
                if ($text !== '') {
                    $onDelta($text);
                }
                return ['success' => true, 'content' => $text, 'error' => '', 'http_code' => 200];
            }
            $preview = mb_substr(trim($rawBody), 0, 300);
            return [
                'success' => false,
                'content' => '',
                'error' => '上游未返回流式数据（可能不支持 stream）' . ($preview !== '' ? ' 响应预览: ' . $preview : ''),
                'http_code' => 502,
            ];
        }
        if ($content === '' && !$sawDone) {
            return ['success' => false, 'content' => '', 'error' => '流式响应中没有可用内容', 'http_code' => 502];
        }
        return ['success' => true, 'content' => $content, 'error' => '', 'http_code' => 200];
    }
}
