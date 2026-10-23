<?php
/**
 * 动作：小说多步创作流水线（大纲 → 分章正文 → 梗概与标题建议）
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

function handleGenerateNovelPipeline(): void {
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
    $chapters = (int)postString('chapters');
    if ($chapters <= 0) {
        $chapters = 3;
    }
    if ($chapters > 5) {
        $chapters = 5;
    }

    // 多步生成耗时较长：释放会话锁并放宽脚本执行时间
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @set_time_limit(300);

    $requirements = '创作要求：' . $prompt;
    if ($title !== '') {
        $requirements .= "\n小说标题：" . $title;
    }
    if ($genre !== '') {
        $requirements .= "\n小说类型：" . $genre;
    }

    // 阶段一：世界观与分章大纲
    $outlineResult = $provider->chat([
        ['role' => 'system', 'content' => '你是资深小说策划编辑。请输出简洁但完整的小说设定，包含：1. 世界观与背景；2. 主要人物（姓名、性格、目标）；3. 全书分章大纲。不要输出正文。'],
        ['role' => 'user', 'content' => $requirements . "\n计划章节数：" . $chapters . " 章。"],
    ]);
    if (!$outlineResult['success']) {
        jsonResponse(['success' => false, 'stage' => 'outline', 'error' => '大纲阶段失败：' . $outlineResult['error']], 502);
    }
    $outline = $outlineResult['content'];

    $stages = [
        ['key' => 'outline', 'label' => '世界观与大纲', 'content' => $outline],
    ];

    // 阶段二：逐章生成正文
    $chapterTexts = [];
    $previous = '';
    for ($index = 1; $index <= $chapters; $index++) {
        $userContent = "全书大纲：\n" . $outline . "\n\n";
        if ($previous !== '') {
            $userContent .= "上一章结尾（用于衔接）：\n" . mb_substr($previous, -800) . "\n\n";
        }
        $userContent .= "请创作第 {$index} 章的正文，约 1200-1800 字。直接输出正文，不要重复大纲，不要额外说明。";

        $chapterResult = $provider->chat([
            ['role' => 'system', 'content' => '你是专业小说作者，擅长情节推进与人物刻画，文风统一、对白自然。'],
            ['role' => 'user', 'content' => $userContent],
        ]);
        if (!$chapterResult['success']) {
            jsonResponse([
                'success' => false,
                'stage' => 'chapter_' . $index,
                'error' => "第 {$index} 章生成失败：" . $chapterResult['error'],
            ], 502);
        }
        $chapterTexts[] = $chapterResult['content'];
        $stages[] = [
            'key' => 'chapter_' . $index,
            'label' => '第 ' . $index . ' 章',
            'content' => $chapterResult['content'],
        ];
        $previous = $chapterResult['content'];
    }

    // 阶段三：故事梗概与标题建议
    $summaryResult = $provider->chat([
        ['role' => 'system', 'content' => '你是小说编辑。请输出 200 字以内的故事梗概，并给出 3 个备选标题。'],
        ['role' => 'user', 'content' =>
            '原始标题：' . ($title !== '' ? $title : '待定') .
            "\n全书大纲：\n" . $outline .
            "\n章节内容节选：\n" . mb_substr(implode("\n\n", $chapterTexts), -3000)
        ],
    ]);
    $summary = $summaryResult['success'] ? $summaryResult['content'] : '';
    if ($summary !== '') {
        $stages[] = ['key' => 'summary', 'label' => '梗概与标题建议', 'content' => $summary];
    }

    jsonResponse([
        'success' => true,
        'title' => $title,
        'chapters' => $chapters,
        'stages' => $stages,
        'summary' => $summary,
    ]);
}
