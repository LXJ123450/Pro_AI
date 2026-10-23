<?php
/**
 * 动作注册表：统一分发 POST action
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/chat_stream.php';
require_once __DIR__ . '/conversation.php';
require_once __DIR__ . '/novel.php';
require_once __DIR__ . '/novel_pipeline.php';
require_once __DIR__ . '/image.php';
require_once __DIR__ . '/video.php';
require_once __DIR__ . '/video_task.php';

/**
 * 动作名 => 处理函数
 */
function getActionHandlers(): array {
    return [
        'get_ai_services' => 'handleGetAiServices',
        'check_config' => 'handleCheckConfig',
        'chat' => 'handleChat',
        'chat_stream' => 'handleChatStream',
        'get_conversation' => 'handleGetConversation',
        'generate_novel' => 'handleGenerateNovel',
        'generate_novel_pipeline' => 'handleGenerateNovelPipeline',
        'generate_image' => 'handleGenerateImage',
        'generate_video' => 'handleGenerateVideo',
        'get_video_task' => 'handleGetVideoTask',
    ];
}

/**
 * 校验请求并调用对应动作处理器
 */
function dispatchAction(): void {
    $action = postString('action');
    $handlers = getActionHandlers();
    if (!isset($handlers[$action])) {
        jsonResponse(['success' => false, 'error' => '未知操作'], 400);
    }

    // 同源 + CSRF + 限流三道校验
    verifySameOrigin();
    verifyCsrfToken();
    enforceRateLimit($action);

    $handlers[$action]();

    // 处理器均以 jsonResponse 结束请求，此处为兜底
    jsonResponse(['success' => false, 'error' => '未知操作'], 400);
}
