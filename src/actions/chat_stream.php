<?php
/**
 * 动作：对话聊天（SSE 流式）
 *
 * 使用 POST + CSRF（前端以 fetch 读取流），不依赖 EventSource。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleChatStream(): void {
    $provider = resolveChatProvider();

    $messages = validateMessages($_POST['messages'] ?? '');
    $model = validateModelName(postString('model'));
    $requestedId = postHexId('conversation_id', '会话标识');

    $store = ConversationStore::create();
    $conversationId = '';
    $context = buildChatContext($messages, $store, $requestedId, $conversationId);
    if (empty($context)) {
        jsonResponse(['success' => false, 'error' => '请输入内容后再发送'], 400);
    }

    // 长连接期间释放会话锁，避免同一用户的其他请求被阻塞
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    @ini_set('zlib.output_compression', '0');
    @set_time_limit(0);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    $send = function (array $event) {
        if (connection_aborted()) {
            return;
        }
        echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        @flush();
    };

    $send(['type' => 'start', 'conversation_id' => $conversationId]);

    $result = $provider->streamChat($context, $model, function ($delta) use ($send) {
        $send(['type' => 'delta', 'delta' => $delta]);
    });

    if ($result['success']) {
        $last = end($messages);
        if ($store !== null && $conversationId !== '' && is_array($last)) {
            $store->saveTurn($conversationId, $provider->key(), (string)$last['content'], $result['content']);
        }
        $send([
            'type' => 'done',
            'content' => $result['content'],
            'conversation_id' => $conversationId,
        ]);
    } else {
        $send(['type' => 'error', 'error' => $result['error']]);
    }
    exit;
}
