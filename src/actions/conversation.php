<?php
/**
 * 动作：读取服务端会话历史
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleGetConversation(): void {
    $conversationId = postHexId('conversation_id', '会话标识');
    if ($conversationId === '') {
        jsonResponse(['success' => false, 'error' => '缺少会话标识'], 400);
    }
    $store = ConversationStore::create();
    if ($store === null) {
        jsonResponse(['success' => false, 'error' => '服务端会话未启用（请检查 config.php 的 database 配置）'], 400);
    }
    jsonResponse([
        'success' => true,
        'conversation_id' => $conversationId,
        'messages' => $store->history($conversationId),
    ]);
}
