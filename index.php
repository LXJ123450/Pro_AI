<?php
// 加载配置文件
$config = require 'config.php';

// 获取当前AI服务配置
function getAIConfig($ai_service) {
    global $config;
    if (isset($config['ai_services'][$ai_service])) {
        return $config['ai_services'][$ai_service];
    }
    return $config['ai_services'][$config['default_ai']];
}

// 调用AI聊天API
function callAIChat($ai_config, $messages, $model = null) {
    $api_key = $ai_config['api_key'];
    $api_base_url = rtrim($ai_config['api_base_url'], '/');
    $model = $model ?? $ai_config['default_model'];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_base_url . '/chat/completions');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $model,
        'messages' => $messages,
        'stream' => false
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'response' => $response,
        'http_code' => $http_code,
        'error' => $error
    ];
}

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $ai_service = $_POST['ai_service'] ?? $config['default_ai'];
    $ai_config = getAIConfig($ai_service);
    $api_key = $ai_config['api_key'];
    
    // 检查API密钥
    if (empty($api_key)) {
        echo json_encode([
            'success' => false,
            'error' => "请先在config.php中配置{$ai_config['name']} API密钥"
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'get_ai_services') {
        // 返回可用的AI服务列表
        $services = [];
        foreach ($config['ai_services'] as $key => $service) {
            $services[] = [
                'key' => $key,
                'name' => $service['name'],
                'description' => $service['description'],
                'configured' => !empty($service['api_key']),
                'default_model' => $service['default_model']
            ];
        }
        echo json_encode([
            'success' => true,
            'services' => $services,
            'default_ai' => $config['default_ai']
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'chat') {
        $messages = json_decode($_POST['messages'], true);
        $model = $_POST['model'] ?? $ai_config['default_model'];
        
        $result = callAIChat($ai_config, $messages, $model);
        
        if (!empty($result['error'])) {
            echo json_encode([
                'success' => false,
                'error' => "无法连接到{$ai_config['name']} API，请检查网络连接。错误: " . $result['error']
            ]);
            exit;
        }
        
        if ($result['http_code'] !== 200) {
            echo json_encode([
                'success' => false,
                'error' => "{$ai_config['name']} API返回错误: HTTP " . $result['http_code'] . ' - ' . $result['response']
            ]);
            exit;
        }
        
        $result_data = json_decode($result['response'], true);
        
        if (isset($result_data['choices'][0]['message']['content'])) {
            echo json_encode([
                'success' => true,
                'response' => $result_data['choices'][0]['message']['content']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => "无法解析{$ai_config['name']}响应: " . $result['response']
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'generate_novel') {
        $prompt = $_POST['prompt'] ?? '';
        $title = $_POST['title'] ?? '';
        $genre = $_POST['genre'] ?? '';
        
        if (empty($prompt)) {
            echo json_encode([
                'success' => false,
                'error' => '请输入创作提示'
            ]);
            exit;
        }
        
        $system_prompt = "你是一位专业的小说创作AI。请根据用户的要求创作一部吸引人的小说。";
        if (!empty($title)) {
            $system_prompt .= " 小说标题：{$title}。";
        }
        if (!empty($genre)) {
            $system_prompt .= " 小说类型：{$genre}。";
        }
        
        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $prompt]
        ];
        
        $result = callAIChat($ai_config, $messages);
        
        if (!empty($result['error'])) {
            echo json_encode([
                'success' => false,
                'error' => "无法连接到{$ai_config['name']} API，请检查网络连接。错误: " . $result['error']
            ]);
            exit;
        }
        
        if ($result['http_code'] !== 200) {
            echo json_encode([
                'success' => false,
                'error' => "{$ai_config['name']} API返回错误: HTTP " . $result['http_code'] . ' - ' . $result['response']
            ]);
            exit;
        }
        
        $result_data = json_decode($result['response'], true);
        
        if (isset($result_data['choices'][0]['message']['content'])) {
            echo json_encode([
                'success' => true,
                'content' => $result_data['choices'][0]['message']['content']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => "无法解析{$ai_config['name']}响应: " . $result['response']
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'generate_image') {
        $image_config = $config['image_api'];
        
        if (!$image_config['enabled'] || empty($image_config['api_key'])) {
            echo json_encode([
                'success' => false,
                'error' => '图片生成功能未配置，请在config.php中启用并配置image_api'
            ]);
            exit;
        }
        
        $prompt = $_POST['prompt'] ?? '';
        
        if (empty($prompt)) {
            echo json_encode([
                'success' => false,
                'error' => '请输入图片描述'
            ]);
            exit;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $image_config['api_url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'prompt' => $prompt,
            'n' => 1,
            'size' => $image_config['default_size']
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $image_config['api_key']
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            echo json_encode([
                'success' => false,
                'error' => '无法连接到图片生成API，请检查网络连接。错误: ' . curl_error($ch)
            ]);
            curl_close($ch);
            exit;
        }
        
        curl_close($ch);
        
        if ($http_code !== 200) {
            echo json_encode([
                'success' => false,
                'error' => '图片生成API返回错误: HTTP ' . $http_code . ' - ' . $response
            ]);
            exit;
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['data'][0]['url'])) {
            echo json_encode([
                'success' => true,
                'image_url' => $result['data'][0]['url']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => '无法解析图片生成响应: ' . $response
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'generate_video') {
        $video_config = $config['video_api'];
        
        if (!$video_config['enabled'] || empty($video_config['api_key'])) {
            echo json_encode([
                'success' => false,
                'error' => '视频生成功能未配置，请在config.php中启用并配置video_api'
            ]);
            exit;
        }
        
        $prompt = $_POST['prompt'] ?? '';
        
        if (empty($prompt)) {
            echo json_encode([
                'success' => false,
                'error' => '请输入视频描述'
            ]);
            exit;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $video_config['api_url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'prompt' => $prompt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $video_config['api_key']
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            echo json_encode([
                'success' => false,
                'error' => '无法连接到视频生成API，请检查网络连接。错误: ' . curl_error($ch)
            ]);
            curl_close($ch);
            exit;
        }
        
        curl_close($ch);
        
        if ($http_code !== 200) {
            echo json_encode([
                'success' => false,
                'error' => '视频生成API返回错误: HTTP ' . $http_code . ' - ' . $response
            ]);
            exit;
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['video_url'])) {
            echo json_encode([
                'success' => true,
                'video_url' => $result['video_url']
            ]);
        } elseif (isset($result['data'][0]['url'])) {
            echo json_encode([
                'success' => true,
                'video_url' => $result['data'][0]['url']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => '无法解析视频生成响应: ' . $response
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'check_config') {
        $configured = false;
        $message = '未配置任何AI服务';
        
        foreach ($config['ai_services'] as $service) {
            if (!empty($service['api_key'])) {
                $configured = true;
                $message = "已配置: {$service['name']}";
                break;
            }
        }
        
        echo json_encode([
            'configured' => $configured,
            'message' => $message
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI创作平台 - 智能写作助手</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Noto+Sans+SC:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #6366f1;
            --primary-dark: #4f46e5;
            --secondary-color: #8b5cf6;
            --accent-color: #ec4899;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --bg-primary: #ffffff;
            --bg-secondary: #f9fafb;
            --bg-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --border-color: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius-sm: 6px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }

        body {
            font-family: 'Poppins', 'Noto Sans SC', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-gradient);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--text-primary);
            line-height: 1.6;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 30px 20px;
            flex: 1;
            width: 100%;
        }

        /* AI选择器 */
        .ai-selector {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }

        .ai-select-wrapper {
            position: relative;
        }

        .ai-select {
            appearance: none;
            padding: 10px 40px 10px 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.95);
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 180px;
        }

        .ai-select:hover {
            border-color: rgba(255, 255, 255, 0.5);
        }

        .ai-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .ai-select-wrapper::after {
            content: '▼';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: var(--text-secondary);
            font-size: 0.75rem;
        }

        .ai-status {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 16px;
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--radius-md);
            font-size: 0.9rem;
        }

        .ai-status .indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #10b981;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Hero Section */
        .hero {
            text-align: center;
            padding: 40px 20px 60px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--radius-xl);
            margin-bottom: 30px;
            box-shadow: var(--shadow-xl);
            backdrop-filter: blur(10px);
        }

        .hero h1 {
            font-size: 3rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
        }

        .hero .subtitle {
            font-size: 1.25rem;
            color: var(--text-secondary);
            font-weight: 300;
            margin-bottom: 24px;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 12px;
        }

        .status-badge.configured {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.not-configured {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Features Grid */
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 30px 24px;
            border-radius: var(--radius-lg);
            text-align: center;
            box-shadow: var(--shadow-lg);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .feature-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .feature-card h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .feature-card p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 300;
        }

        /* Main Content */
        .main-content {
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--radius-xl);
            padding: 30px;
            box-shadow: var(--shadow-xl);
            backdrop-filter: blur(10px);
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 12px;
            background: var(--bg-secondary);
            padding: 8px;
            border-radius: var(--radius-lg);
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .tab-btn {
            flex: 1;
            min-width: 120px;
            padding: 14px 20px;
            border: none;
            background: transparent;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .tab-btn:hover {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary-color);
        }

        .tab-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Chat */
        .chat-container {
            display: flex;
            flex-direction: column;
            height: 600px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }

        .message {
            margin-bottom: 20px;
            padding: 16px 20px;
            border-radius: 16px;
            max-width: 80%;
            line-height: 1.6;
            box-shadow: var(--shadow-sm);
        }

        .user-message {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            margin-left: auto;
        }

        .ai-message {
            background: var(--bg-primary);
            color: var(--text-primary);
            margin-right: auto;
            box-shadow: var(--shadow-md);
        }

        .chat-input-container {
            display: flex;
            gap: 12px;
            padding: 20px;
            background: var(--bg-primary);
            border-top: 1px solid var(--border-color);
        }

        .chat-input {
            flex: 1;
            padding: 14px 18px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 1rem;
            resize: none;
            font-family: inherit;
            transition: border-color 0.3s ease;
            min-height: 50px;
        }

        .chat-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        /* Buttons */
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: var(--radius-md);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: inherit;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Form */
        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 1rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.3s ease;
            background: var(--bg-primary);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .form-group textarea {
            min-height: 200px;
            resize: vertical;
        }

        /* Result */
        .result-container {
            margin-top: 24px;
            padding: 24px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            min-height: 200px;
            white-space: pre-wrap;
            line-height: 1.8;
            box-shadow: var(--shadow-sm) inset;
        }

        .result-container img {
            max-width: 100%;
            border-radius: var(--radius-md);
            margin: 16px 0;
            box-shadow: var(--shadow-md);
        }

        .result-container video {
            max-width: 100%;
            border-radius: var(--radius-md);
            margin: 16px 0;
        }

        /* Loading */
        .loading {
            display: none;
            text-align: center;
            padding: 40px 20px;
        }

        .loading.active {
            display: block;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Footer */
        footer {
            text-align: center;
            padding: 30px 20px;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 300;
            font-size: 0.95rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2rem;
            }

            .hero .subtitle {
                font-size: 1rem;
            }

            .features {
                grid-template-columns: 1fr;
            }

            .tabs {
                gap: 8px;
            }

            .tab-btn {
                min-width: auto;
                padding: 10px 14px;
                font-size: 0.9rem;
            }

            .message {
                max-width: 90%;
            }

            .chat-container {
                height: 500px;
            }

            .container {
                padding: 20px 15px;
            }

            .main-content {
                padding: 20px;
            }

            .ai-selector {
                flex-direction: column;
                align-items: flex-start;
            }

            .ai-status {
                margin-left: 0;
                margin-top: 12px;
            }
        }

        @media (max-width: 480px) {
            .tab-btn {
                flex: 1 1 40%;
            }

            .chat-input-container {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- AI选择器 -->
        <div class="ai-selector">
            <div class="ai-select-wrapper">
                <select id="ai-select" class="ai-select">
                    <option value="">加载中...</option>
                </select>
            </div>
            <div class="ai-status">
                <span class="indicator"></span>
                <span id="ai-status-text">连接中...</span>
            </div>
        </div>

        <!-- Hero Section -->
        <div class="hero">
            <h1>AI创作平台</h1>
            <p class="subtitle">多AI服务支持的智能创作助手，让创作变得简单高效</p>
            <div id="status-badge" class="status-badge">检查配置中...</div>
        </div>

        <!-- Features -->
        <div class="features">
            <div class="feature-card">
                <div class="feature-icon">💬</div>
                <h3>智能对话</h3>
                <p>随时随地与AI进行流畅对话，解答疑问，获取灵感</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📖</div>
                <h3>小说创作</h3>
                <p>一键生成精彩小说，支持多种题材，轻松创作</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🖼️</div>
                <h3>图片生成</h3>
                <p>根据文字描述生成精美图片，创意可视化</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🎬</div>
                <h3>视频生成</h3>
                <p>AI驱动视频创作，让想法动起来</p>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" data-tab="chat">💬 对话聊天</button>
                <button class="tab-btn" data-tab="novel">📖 小说创作</button>
                <button class="tab-btn" data-tab="image">🖼️ 图片生成</button>
                <button class="tab-btn" data-tab="video">🎬 视频生成</button>
            </div>

            <!-- 对话聊天 -->
            <div id="chat" class="tab-content active">
                <div class="chat-container">
                    <div id="chat-messages" class="chat-messages">
                        <div class="message ai-message">
                            👋 你好！我是你的AI助手，有什么可以帮助你的吗？
                        </div>
                    </div>
                    <div class="chat-input-container">
                        <input type="text" id="chat-input" class="chat-input" placeholder="请输入你的问题...">
                        <button id="send-chat" class="btn btn-primary">发送</button>
                    </div>
                </div>
            </div>

            <!-- 小说创作 -->
            <div id="novel" class="tab-content">
                <form id="novel-form">
                    <div class="form-group">
                        <label for="novel-title">小说标题</label>
                        <input type="text" id="novel-title" placeholder="留空将由AI自动生成">
                    </div>
                    <div class="form-group">
                        <label for="novel-genre">小说类型</label>
                        <select id="novel-genre">
                            <option value="">请选择类型</option>
                            <option value="玄幻">玄幻</option>
                            <option value="都市">都市</option>
                            <option value="言情">言情</option>
                            <option value="悬疑">悬疑</option>
                            <option value="科幻">科幻</option>
                            <option value="历史">历史</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="novel-prompt">创作要求</label>
                        <textarea id="novel-prompt" placeholder="请描述你想要创作的小说内容、故事背景、人物设定等..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">开始创作</button>
                </form>
                <div class="loading" id="novel-loading">
                    <div class="spinner"></div>
                    <p>正在创作中，请稍候...</p>
                </div>
                <div id="novel-result" class="result-container" style="display: none;"></div>
            </div>

            <!-- 图片生成 -->
            <div id="image" class="tab-content">
                <form id="image-form">
                    <div class="form-group">
                        <label for="image-prompt">图片描述</label>
                        <textarea id="image-prompt" placeholder="请详细描述你想要生成的图片内容，例如：一个美丽的城堡在山上，日出，油画风格..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">生成图片</button>
                </form>
                <div class="loading" id="image-loading">
                    <div class="spinner"></div>
                    <p>正在生成中，请稍候...</p>
                </div>
                <div id="image-result" class="result-container" style="display: none;"></div>
            </div>

            <!-- 视频生成 -->
            <div id="video" class="tab-content">
                <form id="video-form">
                    <div class="form-group">
                        <label for="video-prompt">视频描述</label>
                        <textarea id="video-prompt" placeholder="请详细描述你想要生成的视频内容..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">生成视频</button>
                </form>
                <div class="loading" id="video-loading">
                    <div class="spinner"></div>
                    <p>正在生成中，请稍候...</p>
                </div>
                <div id="video-result" class="result-container" style="display: none;"></div>
            </div>
        </div>
    </div>

    <footer>
        <p>Powered by Multiple AI Services • Built with ❤️ by AI</p>
    </footer>

    <script>
        let currentAIService = '';

        // 加载AI服务列表
        async function loadAIServices() {
            const response = await fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=get_ai_services'
            });
            const result = await response.json();
            
            if (result.success) {
                const select = document.getElementById('ai-select');
                select.innerHTML = '';
                
                result.services.forEach(service => {
                    const option = document.createElement('option');
                    option.value = service.key;
                    option.textContent = service.name;
                    if (service.configured) {
                        option.textContent += ' ✓';
                    }
                    select.appendChild(option);
                });
                
                // 设置默认选中
                select.value = result.default_ai;
                currentAIService = result.default_ai;
                
                // 更新状态文本
                updateAIStatus(result.services);
                
                // 添加事件监听
                select.addEventListener('change', (e) => {
                    currentAIService = e.target.value;
                    updateAIStatus(result.services);
                    // 重置聊天消息
                    resetChat();
                });
            }
        }

        function updateAIStatus(services) {
            const service = services.find(s => s.key === currentAIService);
            const statusText = document.getElementById('ai-status-text');
            if (service) {
                statusText.textContent = service.configured ? `${service.name} 已连接` : `${service.name} 未配置`;
            }
        }

        // 重置聊天
        function resetChat() {
            const container = document.getElementById('chat-messages');
            container.innerHTML = '<div class="message ai-message">👋 你好！我是你的AI助手，有什么可以帮助你的吗？</div>';
            chatMessages = [{ role: 'assistant', content: '👋 你好！我是你的AI助手，有什么可以帮助你的吗？' }];
        }

        // 切换标签
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                const tabId = btn.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });

        // 检查配置
        async function checkConfig() {
            const response = await fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=check_config'
            });
            const result = await response.json();
            const badge = document.getElementById('status-badge');
            if (result.configured) {
                badge.className = 'status-badge configured';
                badge.textContent = 'API已配置 ✓';
            } else {
                badge.className = 'status-badge not-configured';
                badge.textContent = 'API未配置 ✗';
            }
        }

        // 初始化
        loadAIServices();
        checkConfig();

        // 聊天功能
        let chatMessages = [
            { role: 'assistant', content: '👋 你好！我是你的AI助手，有什么可以帮助你的吗？' }
        ];

        document.getElementById('send-chat').addEventListener('click', sendChatMessage);
        document.getElementById('chat-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                sendChatMessage();
            }
        });

        async function sendChatMessage() {
            const input = document.getElementById('chat-input');
            const message = input.value.trim();
            if (!message) return;

            // 添加用户消息
            chatMessages.push({ role: 'user', content: message });
            addMessageToChat(message, 'user');
            input.value = '';

            // 添加AI占位消息
            const aiMessageDiv = addMessageToChat('🤔 正在思考中...', 'ai');

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=chat&messages=${encodeURIComponent(JSON.stringify(chatMessages))}&ai_service=${encodeURIComponent(currentAIService)}`
                });
                const result = await response.json();

                if (result.success) {
                    chatMessages.push({ role: 'assistant', content: result.response });
                    aiMessageDiv.innerHTML = result.response;
                } else {
                    aiMessageDiv.innerHTML = `❌ 错误: ${result.error}`;
                }
            } catch (error) {
                aiMessageDiv.innerHTML = `❌ 错误: ${error.message}`;
            }

            scrollToBottom();
        }

        function addMessageToChat(content, type) {
            const container = document.getElementById('chat-messages');
            const div = document.createElement('div');
            div.className = `message ${type}-message`;
            div.innerHTML = content;
            container.appendChild(div);
            return div;
        }

        function scrollToBottom() {
            const container = document.getElementById('chat-messages');
            container.scrollTop = container.scrollHeight;
        }

        // 小说创作
        document.getElementById('novel-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const prompt = document.getElementById('novel-prompt').value.trim();
            const title = document.getElementById('novel-title').value.trim();
            const genre = document.getElementById('novel-genre').value;

            const loading = document.getElementById('novel-loading');
            const result = document.getElementById('novel-result');

            loading.classList.add('active');
            result.style.display = 'none';

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=generate_novel&prompt=${encodeURIComponent(prompt)}&title=${encodeURIComponent(title)}&genre=${encodeURIComponent(genre)}&ai_service=${encodeURIComponent(currentAIService)}`
                });
                const data = await response.json();

                if (data.success) {
                    result.innerHTML = `<h3>✅ 创作完成</h3><br>${data.content}`;
                    result.style.display = 'block';
                } else {
                    result.innerHTML = `<h3>❌ 出错了</h3><br>${data.error}`;
                    result.style.display = 'block';
                }
            } catch (error) {
                result.innerHTML = `<h3>❌ 出错了</h3><br>${error.message}`;
                result.style.display = 'block';
            }

            loading.classList.remove('active');
        });

        // 图片生成
        document.getElementById('image-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const prompt = document.getElementById('image-prompt').value.trim();

            const loading = document.getElementById('image-loading');
            const result = document.getElementById('image-result');

            loading.classList.add('active');
            result.style.display = 'none';

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=generate_image&prompt=${encodeURIComponent(prompt)}`
                });
                const data = await response.json();

                if (data.success) {
                    result.innerHTML = `<h3>✅ 生成完成</h3><br><img src="${data.image_url}" alt="${prompt}">`;
                    result.style.display = 'block';
                } else {
                    result.innerHTML = `<h3>❌ 出错了</h3><br>${data.error}`;
                    result.style.display = 'block';
                }
            } catch (error) {
                result.innerHTML = `<h3>❌ 出错了</h3><br>${error.message}`;
                result.style.display = 'block';
            }

            loading.classList.remove('active');
        });

        // 视频生成
        document.getElementById('video-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const prompt = document.getElementById('video-prompt').value.trim();

            const loading = document.getElementById('video-loading');
            const result = document.getElementById('video-result');

            loading.classList.add('active');
            result.style.display = 'none';

            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=generate_video&prompt=${encodeURIComponent(prompt)}`
                });
                const data = await response.json();

                if (data.success) {
                    result.innerHTML = `<h3>✅ 生成完成</h3><br><video src="${data.video_url}" controls></video>`;
                    result.style.display = 'block';
                } else {
                    result.innerHTML = `<h3>❌ 出错了</h3><br>${data.error}`;
                    result.style.display = 'block';
                }
            } catch (error) {
                result.innerHTML = `<h3>❌ 出错了</h3><br>${error.message}`;
                result.style.display = 'block';
            }

            loading.classList.remove('active');
        });
    </script>
</body>
</html>