<?php
/**
 * 动作：小说分阶段创作（SSE 流式）
 *
 * 持续向前端输出增量文本，避免 Apache mod_fcgid 默认 40 秒 IO 超时中断长文本生成。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleGenerateNovelStream(): void {
    $provider = resolveChatProvider();
    $built = buildNovelStageMessages();

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

    $send(['type' => 'start', 'stage' => $built['stage']]);
    $result = $provider->streamChat($built['messages'], null, function ($delta) use ($send) {
        $send(['type' => 'delta', 'delta' => $delta]);
    });

    if ($result['success']) {
        $send(['type' => 'done', 'content' => $result['content']]);
    } else {
        $send(['type' => 'error', 'error' => $result['error']]);
    }
    exit;
}
