<?php
/**
 * 动作：图片生成
 *
 * 本地 ComfyUI 采用「提交任务 + 前端轮询」两段式：
 * 提交接口只排队并立即返回任务标识，长耗时的模型加载/采样由前端分次轮询，
 * 避免单次请求被 Apache mod_fcgid 默认 40 秒 IO 超时中断（冷启动首次生成约需 48 秒）。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 发送请求到本地 ComfyUI 服务
 */
function comfyuiRequest(string $url, ?array $payload, int $timeoutSeconds): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeoutSeconds,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'body' => $body === false ? '' : (string)$body,
        'code' => $httpCode,
        'error' => $curlError,
    ];
}

/**
 * 构建 SD1.5 文生图工作流（节点 key 必须是非纯数字字符串，避免 json_encode 变成数组）
 */
function buildComfyUiWorkflow(array $imageConfig, string $prompt, string $loraName = '', float $loraWeight = 0.7): array {
    $options = is_array($imageConfig['comfyui'] ?? null) ? $imageConfig['comfyui'] : [];

    $parts = explode('x', strtolower((string)($imageConfig['default_size'] ?? '512x640')));
    $width = isset($parts[0]) ? max(256, (int)$parts[0]) : 512;
    $height = isset($parts[1]) ? max(256, (int)$parts[1]) : 640;

    $lora = trim($loraName);

    $clipRef = $lora !== '' ? ['lora', 1] : ['ckpt', 1];
    $modelRef = $lora !== '' ? ['lora', 0] : ['ckpt', 0];

    $workflow = [
        'ckpt' => ['class_type' => 'CheckpointLoaderSimple', 'inputs' => ['ckpt_name' => (string)($options['checkpoint'] ?? '')]],
        'pos' => ['class_type' => 'CLIPTextEncode', 'inputs' => ['text' => $prompt, 'clip' => $clipRef]],
        'neg' => ['class_type' => 'CLIPTextEncode', 'inputs' => [
            'text' => (string)($options['negative_prompt'] ?? 'lowres, bad anatomy, worst quality, low quality, watermark'),
            'clip' => $clipRef,
        ]],
        'latent' => ['class_type' => 'EmptyLatentImage', 'inputs' => ['width' => $width, 'height' => $height, 'batch_size' => 1]],
        'sampler' => ['class_type' => 'KSampler', 'inputs' => [
            'seed' => mt_rand(1, 2147483647),
            'steps' => max(1, (int)($options['steps'] ?? 20)),
            'cfg' => (float)($options['cfg'] ?? 7),
            'sampler_name' => (string)($options['sampler'] ?? 'euler'),
            'scheduler' => (string)($options['scheduler'] ?? 'normal'),
            'denoise' => 1.0,
            'model' => $modelRef,
            'positive' => ['pos', 0],
            'negative' => ['neg', 0],
            'latent_image' => ['latent', 0],
        ]],
        'vae' => ['class_type' => 'VAEDecode', 'inputs' => ['samples' => ['sampler', 0], 'vae' => ['ckpt', 2]]],
        'save' => ['class_type' => 'SaveImage', 'inputs' => ['filename_prefix' => 'pro_ai', 'images' => ['vae', 0]]],
    ];
    if ($lora !== '') {
        $workflow['lora'] = ['class_type' => 'LoraLoader', 'inputs' => [
            'model' => ['ckpt', 0],
            'clip' => ['ckpt', 1],
            'lora_name' => $lora,
            'strength_model' => $loraWeight,
            'strength_clip' => $loraWeight,
        ]];
    }
    return $workflow;
}

/**
 * 取回图片并落盘到 assets/generated，输出完成响应
 */
function saveComfyUiImage(array $imageConfig, string $baseUrl, array $imageInfo): void {
    $options = is_array($imageConfig['comfyui'] ?? null) ? $imageConfig['comfyui'] : [];

    $viewUrl = $baseUrl . '/view?' . http_build_query([
        'filename' => (string)($imageInfo['filename'] ?? ''),
        'subfolder' => (string)($imageInfo['subfolder'] ?? ''),
        'type' => (string)($imageInfo['type'] ?? 'output'),
    ]);
    $view = comfyuiRequest($viewUrl, null, 20);
    if ($view['error'] !== '' || $view['code'] !== 200 || strlen($view['body']) < 100) {
        jsonResponse(['success' => false, 'error' => '无法从本地图片服务取回图片' . ($view['error'] !== '' ? ': ' . $view['error'] : '')], 502);
    }
    if (@getimagesizefromstring($view['body']) === false) {
        jsonResponse(['success' => false, 'error' => '本地图片服务返回的内容不是有效图片'], 502);
    }

    $publicDir = trim((string)($options['public_dir'] ?? 'assets/generated'), '/');
    $targetDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $publicDir);
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        jsonResponse(['success' => false, 'error' => '无法创建图片保存目录: ' . $publicDir], 500);
    }
    $fileName = 'comfy_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
    if (@file_put_contents($targetDir . DIRECTORY_SEPARATOR . $fileName, $view['body']) === false) {
        jsonResponse(['success' => false, 'error' => '图片保存失败，请检查目录写入权限: ' . $publicDir], 500);
    }

    jsonResponse([
        'success' => true,
        'status' => 'completed',
        'image_url' => $publicDir . '/' . $fileName,
    ]);
}

/**
 * 解析前端选择的 LoRA：空值表示沿用配置默认，none 表示不使用
 *
 * @return array{0: string, 1: float}
 */
function resolveComfyUiLora(array $imageConfig): array {
    $options = is_array($imageConfig['comfyui'] ?? null) ? $imageConfig['comfyui'] : [];
    $allowed = is_array($options['lora_options'] ?? null) ? $options['lora_options'] : [];

    $lora = trim(postString('lora'));
    if ($lora === 'none') {
        return ['', 0.0];
    }
    if ($lora === '') {
        $lora = trim((string)($options['lora'] ?? ''));
    }
    if ($lora !== '' && !isset($allowed[$lora])) {
        jsonResponse(['success' => false, 'error' => '所选 LoRA 不在可选列表中'], 400);
    }

    $weight = (float)postString('lora_weight');
    if ($weight <= 0) {
        $weight = (float)($options['lora_weight'] ?? 0.7);
    }
    return [$lora, max(0.1, min(1.2, $weight))];
}

function handleComfyUiImage(array $imageConfig, string $prompt): void {
    $baseUrl = rtrim((string)($imageConfig['api_url'] ?? ''), '/');
    if ($baseUrl === '') {
        jsonResponse(['success' => false, 'error' => '图片生成功能未配置 api_url'], 400);
    }

    $options = is_array($imageConfig['comfyui'] ?? null) ? $imageConfig['comfyui'] : [];
    if ((string)($options['checkpoint'] ?? '') === '') {
        jsonResponse(['success' => false, 'error' => '图片生成功能未配置本地底模（comfyui.checkpoint）'], 400);
    }

    list($lora, $loraWeight) = resolveComfyUiLora($imageConfig);
    submitComfyUiTask($imageConfig, buildComfyUiWorkflow($imageConfig, $prompt, $lora, $loraWeight));
}

/**
 * 构建图生图（微调）工作流：加载上一张结果图，按改动幅度重绘
 */
function buildComfyUiRefineWorkflow(array $imageConfig, string $prompt, float $strength, string $imageName, string $loraName = '', float $loraWeight = 0.7): array {
    $options = is_array($imageConfig['comfyui'] ?? null) ? $imageConfig['comfyui'] : [];

    $lora = trim($loraName);
    $clipRef = $lora !== '' ? ['lora', 1] : ['ckpt', 1];
    $modelRef = $lora !== '' ? ['lora', 0] : ['ckpt', 0];

    $workflow = [
        'ckpt' => ['class_type' => 'CheckpointLoaderSimple', 'inputs' => ['ckpt_name' => (string)($options['checkpoint'] ?? '')]],
        'pos' => ['class_type' => 'CLIPTextEncode', 'inputs' => ['text' => $prompt, 'clip' => $clipRef]],
        'neg' => ['class_type' => 'CLIPTextEncode', 'inputs' => [
            'text' => (string)($options['negative_prompt'] ?? 'lowres, bad anatomy, worst quality, low quality, watermark'),
            'clip' => $clipRef,
        ]],
        'load' => ['class_type' => 'LoadImage', 'inputs' => ['image' => $imageName]],
        'encode' => ['class_type' => 'VAEEncode', 'inputs' => ['pixels' => ['load', 0], 'vae' => ['ckpt', 2]]],
        'sampler' => ['class_type' => 'KSampler', 'inputs' => [
            'seed' => mt_rand(1, 2147483647),
            'steps' => max(1, (int)($options['steps'] ?? 20)),
            'cfg' => (float)($options['cfg'] ?? 7),
            'sampler_name' => (string)($options['sampler'] ?? 'euler'),
            'scheduler' => (string)($options['scheduler'] ?? 'normal'),
            'denoise' => max(0.15, min(0.95, $strength)),
            'model' => $modelRef,
            'positive' => ['pos', 0],
            'negative' => ['neg', 0],
            'latent_image' => ['encode', 0],
        ]],
        'vae' => ['class_type' => 'VAEDecode', 'inputs' => ['samples' => ['sampler', 0], 'vae' => ['ckpt', 2]]],
        'save' => ['class_type' => 'SaveImage', 'inputs' => ['filename_prefix' => 'pro_ai_refine', 'images' => ['vae', 0]]],
    ];
    if ($lora !== '') {
        $workflow['lora'] = ['class_type' => 'LoraLoader', 'inputs' => [
            'model' => ['ckpt', 0],
            'clip' => ['ckpt', 1],
            'lora_name' => $lora,
            'strength_model' => $loraWeight,
            'strength_clip' => $loraWeight,
        ]];
    }
    return $workflow;
}

/**
 * 把本地图片上传到 ComfyUI 的 input 目录，返回其内部文件名
 */
function uploadComfyUiImage(string $baseUrl, string $filePath, string $uploadName): string {
    $ch = curl_init($baseUrl . '/upload/image');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'image' => new CURLFile($filePath, 'image/png', $uploadName),
            'overwrite' => 'true',
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        jsonResponse([
            'success' => false,
            'error' => '无法把原图传给本地图片服务，请确认 ComfyUI 正在运行' . ($curlError !== '' ? ': ' . $curlError : ''),
        ], 502);
    }
    $data = json_decode((string)$body, true);
    $name = is_array($data) ? (string)($data['name'] ?? '') : '';
    if ($name === '') {
        jsonResponse(['success' => false, 'error' => '本地图片服务未能接收原图'], 502);
    }
    return $name;
}

/**
 * 提交工作流并返回任务标识（文生图与图生图共用）
 */
function submitComfyUiTask(array $imageConfig, array $workflow): void {
    $baseUrl = rtrim((string)($imageConfig['api_url'] ?? ''), '/');
    $options = is_array($imageConfig['comfyui'] ?? null) ? $imageConfig['comfyui'] : [];

    $submit = comfyuiRequest($baseUrl . '/prompt', [
        'prompt' => $workflow,
        'client_id' => 'pro_ai_' . bin2hex(random_bytes(6)),
    ], 20);
    if ($submit['error'] !== '') {
        jsonResponse(['success' => false, 'error' => '无法连接到本地图片服务，请先启动 E:\\AI\\ComfyUI 的 ComfyUI。错误: ' . $submit['error']], 502);
    }
    if ($submit['code'] !== 200) {
        jsonResponse(['success' => false, 'error' => '本地图片服务返回错误: HTTP ' . $submit['code'] . ' - ' . mb_substr($submit['body'], 0, 300)], 502);
    }
    $submitData = json_decode($submit['body'], true);
    $promptId = is_array($submitData) ? (string)($submitData['prompt_id'] ?? '') : '';
    if ($promptId === '') {
        jsonResponse(['success' => false, 'error' => '无法解析本地图片服务响应: ' . mb_substr($submit['body'], 0, 300)], 502);
    }

    jsonResponse([
        'success' => true,
        'task_id' => $promptId,
        'poll_interval' => max(1, (int)($options['poll_interval'] ?? 2)),
        'poll_timeout' => max(30, (int)($options['poll_timeout'] ?? 240)),
    ]);
}

/**
 * 在已生成的图片基础上继续微调（图生图）
 */
function handleRefineImage(): void {
    global $config;

    $imageConfig = $config['image_api'] ?? [];
    if (empty($imageConfig['enabled']) || ($imageConfig['service'] ?? '') !== 'comfyui') {
        jsonResponse(['success' => false, 'error' => '当前图片服务不支持微调'], 400);
    }
    $options = is_array($imageConfig['comfyui'] ?? null) ? $imageConfig['comfyui'] : [];
    if ((string)($options['checkpoint'] ?? '') === '') {
        jsonResponse(['success' => false, 'error' => '图片生成功能未配置本地底模（comfyui.checkpoint）'], 400);
    }

    $baseName = basename(postString('source'));
    if (!preg_match('/^[A-Za-z0-9_.-]{1,120}\.png$/i', $baseName)) {
        jsonResponse(['success' => false, 'error' => '原图地址不合法'], 400);
    }
    $publicDir = trim((string)($options['public_dir'] ?? 'assets/generated'), '/');
    $sourcePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $publicDir) . DIRECTORY_SEPARATOR . $baseName;
    if (!is_file($sourcePath)) {
        jsonResponse(['success' => false, 'error' => '原图不存在，请重新生成后再微调'], 400);
    }
    if (filesize($sourcePath) > 15 * 1024 * 1024) {
        jsonResponse(['success' => false, 'error' => '原图体积过大，无法微调'], 400);
    }

    $prompt = requireText('prompt', '微调描述', 2000);
    $strength = (float)postString('strength');
    if ($strength <= 0) {
        $strength = 0.5;
    }
    $strength = max(0.15, min(0.95, $strength));

    $baseUrl = rtrim((string)($imageConfig['api_url'] ?? ''), '/');
    list($lora, $loraWeight) = resolveComfyUiLora($imageConfig);
    $uploadedName = uploadComfyUiImage($baseUrl, $sourcePath, 'pro_ai_refine_source.png');
    submitComfyUiTask($imageConfig, buildComfyUiRefineWorkflow($imageConfig, $prompt, $strength, $uploadedName, $lora, $loraWeight));
}

/**
 * 查询本地图片任务：running / completed（附图片地址）
 */
function handleGetImageTask(): void {
    global $config;

    $imageConfig = $config['image_api'] ?? [];
    if (empty($imageConfig['enabled']) || ($imageConfig['service'] ?? '') !== 'comfyui') {
        jsonResponse(['success' => false, 'error' => '当前图片服务不支持任务查询'], 400);
    }

    $taskId = postString('task_id');
    if (!preg_match('/^[A-Za-z0-9_\-]{6,64}$/', $taskId)) {
        jsonResponse(['success' => false, 'error' => '任务标识不合法'], 400);
    }

    $baseUrl = rtrim((string)($imageConfig['api_url'] ?? ''), '/');
    $poll = comfyuiRequest($baseUrl . '/history/' . rawurlencode($taskId), null, 8);
    if ($poll['error'] !== '') {
        jsonResponse(['success' => false, 'error' => '无法连接到本地图片服务，请先启动 ComfyUI。错误: ' . $poll['error']], 502);
    }
    if ($poll['code'] !== 200) {
        jsonResponse(['success' => false, 'error' => '本地图片服务返回错误: HTTP ' . $poll['code']], 502);
    }

    $history = json_decode($poll['body'], true);
    $entry = is_array($history) ? ($history[$taskId] ?? null) : null;
    if (!is_array($entry)) {
        jsonResponse(['success' => true, 'status' => 'running']);
    }

    $status = is_array($entry['status'] ?? null) ? $entry['status'] : [];
    if (($status['status_str'] ?? '') === 'error') {
        jsonResponse(['success' => false, 'error' => '本地图片生成失败，请检查 ComfyUI 控制台日志（底模或 LoRA 文件是否匹配）'], 502);
    }
    if (empty($status['completed'])) {
        jsonResponse(['success' => true, 'status' => 'running']);
    }

    $imageInfo = $entry['outputs']['save']['images'][0] ?? null;
    if (!is_array($imageInfo)) {
        jsonResponse(['success' => false, 'error' => '本地图片服务未返回图片信息'], 502);
    }

    saveComfyUiImage($imageConfig, $baseUrl, $imageInfo);
}

function handleGenerateImage(): void {
    global $config;

    $imageConfig = $config['image_api'];
    if (empty($imageConfig['enabled'])) {
        jsonResponse(['success' => false, 'error' => '图片生成功能未配置，请在config.php中启用image_api'], 400);
    }
    $prompt = requireText('prompt', '图片描述', 2000);

    // Pollinations：免注册，GET 地址直接返回图片，仅需构造 URL 交给前端加载
    if (($imageConfig['service'] ?? '') === 'pollinations') {
        $template = (string)($imageConfig['api_url'] ?? '');
        if ($template === '') {
            jsonResponse(['success' => false, 'error' => '图片生成功能未配置 api_url'], 400);
        }
        $parts = explode('x', strtolower((string)($imageConfig['default_size'] ?? '1024x1024')));
        $width = isset($parts[0]) ? max(64, (int)$parts[0]) : 1024;
        $height = isset($parts[1]) ? max(64, (int)$parts[1]) : 1024;
        $url = str_replace('{prompt}', rawurlencode($prompt), $template);
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query([
            'width' => $width,
            'height' => $height,
            'nologo' => 'true',
            'seed' => mt_rand(1, 1000000),
        ]);
        jsonResponse(['success' => true, 'image_url' => $url]);
    }

    // 本地 ComfyUI：提交任务，由前端轮询 get_image_task 获取结果
    if (($imageConfig['service'] ?? '') === 'comfyui') {
        handleComfyUiImage($imageConfig, $prompt);
    }

    if (empty($imageConfig['api_key'])) {
        jsonResponse(['success' => false, 'error' => '图片生成功能未配置，请在config.php中配置image_api密钥'], 400);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $imageConfig['api_url'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'prompt' => $prompt,
            'n' => 1,
            'size' => $imageConfig['default_size'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $imageConfig['api_key'],
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        jsonResponse(['success' => false, 'error' => '无法连接到图片生成API，请检查网络连接。错误: ' . $curlError], 502);
    }
    if ($httpCode !== 200) {
        jsonResponse(['success' => false, 'error' => '图片生成API返回错误: HTTP ' . $httpCode . ' - ' . mb_substr((string)$response, 0, 300)], 502);
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data) || !isset($data['data'][0]['url'])) {
        jsonResponse(['success' => false, 'error' => '无法解析图片生成响应: ' . mb_substr((string)$response, 0, 300)], 502);
    }
    jsonResponse(['success' => true, 'image_url' => $data['data'][0]['url']]);
}
