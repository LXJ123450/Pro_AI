<?php
/**
 * 动作：本地生图提示词库（姿势 / 服装）
 *
 * 数据来源：E:\AI\gen-ai-pose-image-main 的姿势与服装索引，只保留非成人条目，
 * 并把中文姿势名整理成 SD1.5 可用的英文 tag；图片文件不进入本项目。
 *
 * 提供两个能力：
 * 1. handleGetImagePresets：按关键词 / 分类检索姿势或服装，供前端选择；
 * 2. buildImagePresetPrompt：把所选姿势、服装与镜头角度合成到正向提示词，
 *    并给出需要追加的负面词，供 generate_image 使用。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 读取提示词库数据（进程内缓存，避免每次请求重复解析 140KB JSON）
 */
function loadImagePresetData(): array {
    static $data = null;
    if (is_array($data)) {
        return $data;
    }
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'image_presets.json';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        jsonResponse(['success' => false, 'error' => '提示词库数据缺失，请检查 src/data/image_presets.json'], 500);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !is_array($decoded['poses'] ?? null) || !is_array($decoded['costumes'] ?? null)) {
        jsonResponse(['success' => false, 'error' => '提示词库数据格式异常'], 500);
    }
    $data = $decoded;
    return $data;
}

/**
 * 检索姿势或服装
 */
function handleGetImagePresets(): void {
    $data = loadImagePresetData();

    $type = postString('type');
    if ($type !== 'pose' && $type !== 'costume') {
        jsonResponse(['success' => false, 'error' => '提示词库类型不合法'], 400);
    }
    $query = postString('q');
    if (mb_strlen($query) > 50) {
        jsonResponse(['success' => false, 'error' => '搜索词过长（最多 50 字）'], 400);
    }
    $category = postString('category');
    if (mb_strlen($category) > 30) {
        jsonResponse(['success' => false, 'error' => '分类名称过长'], 400);
    }
    $limit = (int)postString('limit');
    if ($limit <= 0) {
        $limit = 30;
    }
    $limit = min(60, $limit);

    $items = $type === 'pose' ? $data['poses'] : $data['costumes'];
    $categories = [];
    $matched = [];
    $needle = mb_strtolower($query);
    foreach ($items as $item) {
        $itemCategory = (string)($item['category'] ?? '');
        if ($itemCategory !== '' && !in_array($itemCategory, $categories, true)) {
            $categories[] = $itemCategory;
        }
        if ($category !== '' && $itemCategory !== $category) {
            continue;
        }
        if ($needle !== '') {
            $hay = mb_strtolower(
                (string)($item['id'] ?? '') . ' ' .
                (string)($item['name'] ?? '') . ' ' .
                (string)($item['label'] ?? '') . ' ' .
                (string)($item['keywords'] ?? '') . ' ' .
                (string)($item['tags'] ?? '') . ' ' .
                $itemCategory
            );
            if (mb_strpos($hay, $needle) === false) {
                continue;
            }
        }
        $itemName = (string)($item['name'] ?? '');
        if ($itemName === '') {
            // 服装没有独立名称，用「分类 · 编号」区分 1400 多套关键词
            $itemLabel = (string)($item['label'] ?? '');
            $itemName = $itemLabel !== '' ? $itemLabel . ' · ' . (string)$item['id'] : (string)$item['id'];
        }
        $matched[] = [
            'id' => (string)$item['id'],
            'name' => $itemName,
            'category' => $itemCategory,
            'tags' => (string)($item['tags'] ?? $item['keywords'] ?? ''),
        ];
        if (count($matched) >= $limit) {
            break;
        }
    }

    jsonResponse([
        'success' => true,
        'type' => $type,
        'categories' => $categories,
        'items' => $matched,
    ]);
}

/**
 * 校验前端选择的姿势 / 服装 / 镜头角度
 *
 * @return array{pose: ?array, costume: ?array, angle: string}
 */
function resolveImagePresetSelection(): array {
    $data = loadImagePresetData();

    $poseId = postString('pose_id');
    $costumeId = postString('costume_id');
    $angle = postString('pose_angle');

    if ($poseId !== '' && !preg_match('/^[A-Za-z0-9_]{3,40}$/', $poseId)) {
        jsonResponse(['success' => false, 'error' => '姿势标识不合法'], 400);
    }
    if ($costumeId !== '' && !preg_match('/^[A-Z]{3}-\d{4}$/', $costumeId)) {
        jsonResponse(['success' => false, 'error' => '服装标识不合法'], 400);
    }
    $angles = is_array($data['angles'] ?? null) ? $data['angles'] : [];
    if ($angle !== '' && !isset($angles[$angle])) {
        jsonResponse(['success' => false, 'error' => '镜头角度不合法'], 400);
    }

    $pose = null;
    if ($poseId !== '') {
        foreach ($data['poses'] as $item) {
            if ((string)$item['id'] === $poseId) {
                $pose = $item;
                break;
            }
        }
        if ($pose === null) {
            jsonResponse(['success' => false, 'error' => '找不到所选姿势'], 400);
        }
    }

    $costume = null;
    if ($costumeId !== '') {
        foreach ($data['costumes'] as $item) {
            if ((string)$item['id'] === $costumeId) {
                $costume = $item;
                break;
            }
        }
        if ($costume === null) {
            jsonResponse(['success' => false, 'error' => '找不到所选服装'], 400);
        }
    }

    return ['pose' => $pose, 'costume' => $costume, 'angle' => $angle];
}

/**
 * 把所选姿势 / 服装 / 角度合成到正向提示词
 *
 * @return array{prompt: string, negative: string, tags: string}
 */
function buildImagePresetPrompt(string $basePrompt): array {
    $selection = resolveImagePresetSelection();
    $pose = $selection['pose'];
    $costume = $selection['costume'];
    if ($pose === null && $costume === null) {
        return ['prompt' => $basePrompt, 'negative' => '', 'tags' => ''];
    }

    $data = loadImagePresetData();
    $fragments = [];
    $tags = [];
    if ($pose !== null) {
        $fragments[] = (string)$pose['tags'];
        $tags[] = (string)$pose['name'];
        if ($selection['angle'] !== '') {
            $angles = is_array($data['angles'] ?? null) ? $data['angles'] : [];
            $fragments[] = (string)($angles[$selection['angle']] ?? '');
        }
    }
    if ($costume !== null) {
        $fragments[] = (string)$costume['keywords'] . ', full outfit';
        $costumeLabel = (string)($costume['label'] ?? '');
        $tags[] = $costumeLabel !== '' ? $costumeLabel . ' · ' . (string)$costume['id'] : (string)$costume['id'];
    }
    $fragments[] = 'anime illustration, detailed, best quality';

    $parts = [];
    if ($basePrompt !== '') {
        $parts[] = $basePrompt;
    }
    foreach ($fragments as $fragment) {
        $fragment = trim($fragment);
        if ($fragment !== '') {
            $parts[] = $fragment;
        }
    }

    return [
        'prompt' => implode(', ', $parts),
        // 姿势 / 服装模式额外压掉草稿感、分格与文字，避免画面出现线稿或多视图
        'negative' => 'sketch, monochrome, multiple views, text, watermark, border',
        'tags' => implode(' · ', $tags),
    ];
}
