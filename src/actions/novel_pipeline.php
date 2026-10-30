<?php
/**
 * 动作：小说分阶段创作（大纲 / 分章 / 梗概）
 *
 * 说明：
 * 1. 一次性完成大纲+多章+梗概会超过 Apache mod_fcgid 默认 40 秒 IO 超时，
 *    因此拆成独立请求，由前端按阶段依次调用；
 * 2. 长文本阶段优先走流式接口（novel_stream.php），通过持续输出避免超时；
 *    本文件保留非流式接口，供脚本调用与降级使用。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 读取可选长文本参数并做长度/编码校验
 */
function novelOptionalText(string $field, string $label, int $maxLength): string {
    $value = postString($field);
    if ($value === '') {
        return '';
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        jsonResponse(['success' => false, 'error' => "{$label}包含非法字符编码"], 400);
    }
    if (mb_strlen($value) > $maxLength) {
        jsonResponse(['success' => false, 'error' => "{$label}过长（最多 {$maxLength} 字）"], 400);
    }
    return $value;
}

/**
 * 读取参考文本与使用方式
 *
 * reference 为用户粘贴或上传的一段小说片段：
 * - style：只模仿它的文风、叙事节奏与用词，不照搬情节；
 * - continue：从它的结尾直接续写。
 *
 * @return array{0: string, 1: string}
 */
function novelReferenceInput(): array {
    $reference = novelOptionalText('reference', '参考文本', 6000);
    $mode = postString('reference_mode');
    if ($mode !== 'style' && $mode !== 'continue') {
        $mode = 'style';
    }
    return [$reference, $mode];
}

/**
 * 校验参数并组装指定阶段的消息
 *
 * @return array{stage: string, messages: array}
 */
function buildNovelStageMessages(): array {
    $stage = postString('stage');
    if (!in_array($stage, ['outline', 'chapter', 'summary'], true)) {
        jsonResponse(['success' => false, 'error' => '未知的创作阶段'], 400);
    }

    $prompt = novelOptionalText('prompt', '创作提示', 4000);
    $title = postString('title');
    if (mb_strlen($title) > 100) {
        jsonResponse(['success' => false, 'error' => '小说标题过长（最多 100 字）'], 400);
    }
    $genre = postString('genre');
    $allowedGenres = ['玄幻', '都市', '言情', '悬疑', '科幻', '历史'];
    if ($genre !== '' && !in_array($genre, $allowedGenres, true)) {
        jsonResponse(['success' => false, 'error' => '小说类型不合法'], 400);
    }
    list($reference, $referenceMode) = novelReferenceInput();
    if ($prompt === '' && $reference === '') {
        jsonResponse(['success' => false, 'error' => '请输入创作提示，或上传一段参考文本'], 400);
    }
    $chapters = (int)postString('chapters');
    if ($chapters <= 0) {
        $chapters = 3;
    }
    if ($chapters > 5) {
        $chapters = 5;
    }

    $requirements = $prompt !== '' ? '创作要求：' . $prompt : '创作要求：沿用参考文本的设定继续创作';
    if ($title !== '') {
        $requirements .= "\n小说标题：" . $title;
    }
    if ($genre !== '') {
        $requirements .= "\n小说类型：" . $genre;
    }
    $referenceBlock = '';
    if ($reference !== '') {
        $referenceBlock = $referenceMode === 'continue'
            ? "\n已有正文（请从这段文字的结尾继续写，保持人称、设定与文风，不要复述或改写已给内容）：\n" . $reference
            : "\n风格参考（只模仿它的语言风格、叙事节奏与用词习惯，不要照搬情节、人物名与设定）：\n" . $reference;
    }

    if ($stage === 'outline') {
        $system = $referenceMode === 'continue' && $reference !== ''
            ? '你是资深小说策划编辑。用户已提供一段已有正文，请先梳理其中的人物、设定与未解线索，再输出后续内容的分章大纲。不要复述已有正文，不要输出正文。'
            : '你是资深小说策划编辑。请输出简洁但完整的小说设定，包含：1. 世界观与背景；2. 主要人物（姓名、性格、目标）；3. 全书分章大纲。不要输出正文。';
        return [
            'stage' => $stage,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $requirements . $referenceBlock . "\n计划章节数：" . $chapters . " 章。"],
            ],
        ];
    }

    if ($stage === 'chapter') {
        $outline = novelOptionalText('outline', '全书大纲', 20000);
        if ($outline === '') {
            jsonResponse(['success' => false, 'error' => '缺少全书大纲，请先完成大纲阶段'], 400);
        }
        $previous = novelOptionalText('previous', '上一章内容', 4000);
        $index = (int)postString('index');
        if ($index < 1 || $index > $chapters) {
            jsonResponse(['success' => false, 'error' => '章节序号不合法'], 400);
        }

        $userContent = "全书大纲：\n" . $outline . "\n\n";
        if ($previous !== '') {
            $userContent .= "上一章结尾（用于衔接）：\n" . mb_substr($previous, -800) . "\n\n";
        }
        if ($reference !== '') {
            // 续写模式只在第 1 章带原文，后续章节靠「上一章结尾」衔接；风格模式每章都带样例
            if ($referenceMode === 'continue' && $index === 1) {
                $userContent .= "已有正文（续写起点，不要复述）：\n" . mb_substr($reference, -1500) . "\n\n";
            } elseif ($referenceMode !== 'continue') {
                $userContent .= "风格参考（只学文风与节奏）：\n" . mb_substr($reference, 0, 1500) . "\n\n";
            }
        }
        $userContent .= "请创作第 {$index} 章的正文，约 500-700 字。直接输出正文，不要重复大纲，不要额外说明。";

        return [
            'stage' => $stage,
            'messages' => [
                ['role' => 'system', 'content' => '你是专业小说作者，擅长情节推进与人物刻画，文风统一、对白自然。'],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];
    }

    $outline = novelOptionalText('outline', '全书大纲', 20000);
    $excerpt = novelOptionalText('excerpt', '章节内容', 6000);
    if ($outline === '' && $excerpt === '') {
        jsonResponse(['success' => false, 'error' => '缺少可用于总结的内容'], 400);
    }
    return [
        'stage' => $stage,
        'messages' => [
            ['role' => 'system', 'content' => '你是小说编辑。请输出 200 字以内的故事梗概，并给出 3 个备选标题。'],
            ['role' => 'user', 'content' =>
                '原始标题：' . ($title !== '' ? $title : '待定') .
                "\n全书大纲：\n" . $outline .
                "\n章节内容节选：\n" . $excerpt
            ],
        ],
    ];
}

function handleGenerateNovelStage(): void {
    $provider = resolveChatProvider();
    $built = buildNovelStageMessages();

    $result = $provider->chat($built['messages']);
    if (!$result['success']) {
        jsonResponse(['success' => false, 'stage' => $built['stage'], 'error' => $result['error']], 502);
    }

    jsonResponse([
        'success' => true,
        'stage' => $built['stage'],
        'content' => $result['content'],
    ]);
}
