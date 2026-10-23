<?php
/**
 * 动作：对话聊天（非流式）
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 解析并校验请求中的 AI 服务，返回 Provider
 */
function resolveChatProvider(): Provider {
    global $config;

    $aiService = postString('ai_service');
    if ($aiService === '') {
        $aiService = (string)$config['default_ai'];
    }
    $provider = ProviderFactory::fromConfig($aiService);
    if ($provider === null) {
        jsonResponse(['success' => false, 'error' => '未知的 AI 服务'], 400);
    }
    if (!$provider->isConfigured()) {
        jsonResponse(['success' => false, 'error' => "请先在config.php中配置{$provider->name()} API密钥"], 400);
    }
    return $provider;
}

function handleChat(): void {
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

    $result = $provider->chat($context, $model);
    if (!$result['success']) {
        jsonResponse(['success' => false, 'error' => $result['error']], 502);
    }

    $last = end($messages);
    if ($store !== null && $conversationId !== '' && is_array($last)) {
        $store->saveTurn($conversationId, $provider->key(), (string)$last['content'], $result['content']);
    }

    jsonResponse([
        'success' => true,
        'response' => $result['content'],
        'conversation_id' => $conversationId,
    ]);
}
