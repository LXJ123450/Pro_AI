<?php
/**
 * 动作：范例解析（SSE 流式）
 *
 * 用户粘贴文字范例或上传参考图，AI 拆解要素并给出可复现的生成流程与提示词。
 * 采用流式输出，避免 Apache mod_fcgid 默认 40 秒 IO 超时中断长文本生成。
 *
 * 上传图片时需要「能看图」的多模态模型，由 config.php 的 vision 段指定；
 * 未指定时退回到当前选中的 AI 服务（识图会失败并给出明确提示）。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 读取并校验上传的参考图（前端已压缩为 data URL）
 */
function parseExampleImageData(): string {
    $raw = $_POST['image_data'] ?? '';
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }
    if (strlen($raw) > 3 * 1024 * 1024) {
        jsonResponse(['success' => false, 'error' => '参考图过大，请换一张小一些的图片'], 400);
    }
    if (!preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,[A-Za-z0-9+/=\s]+$#', $raw)) {
        jsonResponse(['success' => false, 'error' => '参考图格式不支持，请上传 PNG / JPG / WEBP 图片'], 400);
    }
    return $raw;
}

/**
 * 解析识图所用的服务与模型
 *
 * @return array{0: Provider, 1: string}
 */
function resolveVisionProvider(): array {
    global $config;

    $vision = is_array($config['vision'] ?? null) ? $config['vision'] : [];
    $serviceKey = trim((string)($vision['service'] ?? ''));
    $model = trim((string)($vision['model'] ?? ''));

    if ($serviceKey === '') {
        jsonResponse([
            'success' => false,
            'error' => '图片解析需要能看图的模型：请在 config.php 的 vision 段设置 service 与 model（例如智谱 zhipu + glm-4v-flash），或在「⚙ 配置」中填入该服务的 Key',
        ], 400);
    }
    $provider = ProviderFactory::fromConfig($serviceKey);
    if ($provider === null) {
        jsonResponse(['success' => false, 'error' => "识图服务 {$serviceKey} 不存在，请检查 config.php 的 vision.service"], 400);
    }
    if (!$provider->isConfigured()) {
        jsonResponse([
            'success' => false,
            'error' => "图片解析需要能看图的模型：{$provider->name()} 尚未配置 API Key。"
                . '请点击右上角「⚙ 配置」填入免费 Key（智谱 glm-4v-flash 支持看图）；'
                . '或改用本地视觉模型：在 config.php 的 vision 段把 service 设为 ollama、model 设为已下载的视觉模型（如 qwen2.5vl:3b）',
        ], 400);
    }
    return [$provider, $model];
}

/**
 * 校验参数并组装范例解析消息
 *
 * @return array{messages: array}
 */
function buildParseExampleMessages(string $imageData): array {
    $type = postString('type');
    $typeNames = ['novel' => '小说', 'image' => '图片', 'video' => '视频'];
    if (!isset($typeNames[$type])) {
        jsonResponse(['success' => false, 'error' => '范例类型不合法'], 400);
    }

    $sample = postString('sample');
    if (mb_strlen($sample) > 4000) {
        jsonResponse(['success' => false, 'error' => '范例文字过长（最多 4000 字）'], 400);
    }
    if ($sample === '' && $imageData === '') {
        jsonResponse(['success' => false, 'error' => '请上传参考图或粘贴范例内容'], 400);
    }
    if ($imageData !== '' && $type !== 'image') {
        jsonResponse(['success' => false, 'error' => '参考图仅支持在「图片生成」的范例解析中使用'], 400);
    }

    $goal = postString('goal');
    if ($goal !== '' && mb_strlen($goal) > 500) {
        jsonResponse(['success' => false, 'error' => '目标描述过长（最多 500 字）'], 400);
    }

    $typeName = $typeNames[$type];
    $hasImage = $imageData !== '';

    if ($hasImage) {
        $system = '你是有经验的 AI 绘画提示词工程师，熟悉 Stable Diffusion 1.5 的提示词写法。'
            . '用户会提供一张参考图，你需要观察它的主体、服饰与道具、画风、色彩与光影、构图与镜头，并反推可复现的生成方案。'
            . "请严格按以下四段输出，全部使用中文，总字数控制在 600 字以内：\n"
            . "1. 画面拆解：逐条描述图中可复现的要素（主体与人数、动作表情、服饰道具、场景、构图镜头、光影色调、画风）。\n"
            . "2. 生成流程：分 3-5 步说明如何从零画出类似的图，每一步单独一行并写明这一步的产出。\n"
            . "3. 可直接使用的提示词：先给出一段英文 tag 风格的正向提示词（英文逗号分隔，按 主体 → 服饰道具 → 场景 → 构图镜头 → 光影 → 画风画质 排序），另起一行给出负面提示词。\n"
            . "4. 参数建议：给出 2-4 条可调参数建议（画面比例、采样步数、CFG、适合的 LoRA 类型等）。\n"
            . '提示词必须只描述图中真实存在的内容，不要臆造人物身份或作品名；不要输出四段以外的寒暄。';
    } else {
        $focus = [
            'novel' => '提示词需包含题材、人物设定、核心冲突与章节结构；同时说明如何沿用该范例的叙事节奏。',
            'image' => '提示词需覆盖主体、服饰与道具、画风、光影、构图、画质词，并给出一条负面提示词。',
            'video' => '提示词需覆盖主体动作、镜头运动、场景光线、时长与节奏，并说明分镜顺序。',
        ][$type];
        $system = "你是资深 AI 创作策划与提示词工程师。用户会给出一个{$typeName}范例，"
            . "你需要分析它并给出可复现的生成流程。请严格按以下四段输出，全部使用中文，总字数控制在 600 字以内：\n"
            . "1. 要素拆解：逐条列出范例中的核心要素（题材、风格、人物、构图或镜头、情绪等）。\n"
            . "2. 生成流程：分 3-5 步说明如何从零复现，每一步单独一行并写明这一步的产出。\n"
            . "3. 可直接使用的提示词：给出一段完整提示词，便于直接粘贴到本平台的生成输入框。{$focus}\n"
            . "4. 参数建议：给出 2-4 条可调参数建议（如模型、尺寸、步数、时长、强度等）。\n"
            . "不要输出与以上四段无关的寒暄或总结。";
    }

    $textParts = [];
    $textParts[] = $hasImage ? "范例类型：图片\n下面是一张参考图，请据此反推生成方案。" : "范例类型：{$typeName}\n范例内容：\n" . $sample;
    if ($hasImage && $sample !== '') {
        $textParts[] = "补充说明：\n" . $sample;
    }
    if ($goal !== '') {
        $textParts[] = "我的目标：\n" . $goal;
    }
    $text = implode("\n\n", $textParts);

    $userContent = [['type' => 'text', 'text' => $text]];
    if ($hasImage) {
        $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => $imageData]];
    }

    return [
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ],
    ];
}

function handleParseExample(): void {
    $imageData = parseExampleImageData();

    if ($imageData !== '') {
        list($provider, $model) = resolveVisionProvider();
    } else {
        $provider = resolveChatProvider();
        $model = null;
    }

    $built = buildParseExampleMessages($imageData);

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

    $send(['type' => 'start']);
    $result = $provider->streamChat($built['messages'], $model, function ($delta) use ($send) {
        $send(['type' => 'delta', 'delta' => $delta]);
    });

    if ($result['success']) {
        $send(['type' => 'done', 'content' => $result['content']]);
    } else {
        $send(['type' => 'error', 'error' => $result['error']]);
    }
    exit;
}
