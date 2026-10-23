<?php
/**
 * 动作：小说生成
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleGenerateNovel(): void {
    $provider = resolveChatProvider();

    $prompt = requireText('prompt', '创作提示', 4000);
    $title = postString('title');
    if (mb_strlen($title) > 100) {
        jsonResponse(['success' => false, 'error' => '小说标题过长（最多 100 字）'], 400);
    }
    if ($title !== '' && !mb_check_encoding($title, 'UTF-8')) {
        jsonResponse(['success' => false, 'error' => '小说标题包含非法字符编码'], 400);
    }
    $genre = postString('genre');
    $allowedGenres = ['玄幻', '都市', '言情', '悬疑', '科幻', '历史'];
    if ($genre !== '' && !in_array($genre, $allowedGenres, true)) {
        jsonResponse(['success' => false, 'error' => '小说类型不合法'], 400);
    }

    $systemPrompt = '你是一位专业的小说创作AI。请根据用户的要求创作一部吸引人的小说。';
    if ($title !== '') {
        $systemPrompt .= " 小说标题：{$title}。";
    }
    if ($genre !== '') {
        $systemPrompt .= " 小说类型：{$genre}。";
    }

    $result = $provider->chat([
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $prompt],
    ]);
    if (!$result['success']) {
        jsonResponse(['success' => false, 'error' => $result['error']], 502);
    }
    jsonResponse(['success' => true, 'content' => $result['content']]);
}
