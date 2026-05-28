<?php
// Ollama API配置
$ollama_host = 'http://127.0.0.1:11434';
$model_name = 'huihui_ai/deepseek-r1-abliterated:8b';
// Ollama安装路径（Windows）
$ollama_path = 'E:\\AI\\ollma\\ollama.exe';

// 处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'chat') {
        $messages = json_decode($_POST['messages'], true);
        
        // 调用Ollama API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $ollama_host . '/api/chat');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => $model_name,
            'messages' => $messages,
            'stream' => false
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            echo json_encode([
                'success' => false,
                'error' => '无法连接到Ollama服务，请确保Ollama已启动。错误: ' . curl_error($ch)
            ]);
            curl_close($ch);
            exit;
        }
        
        curl_close($ch);
        
        if ($http_code !== 200) {
            echo json_encode([
                'success' => false,
                'error' => 'Ollama服务返回错误: HTTP ' . $http_code . ' - ' . $response
            ]);
            exit;
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['message']['content'])) {
            echo json_encode([
                'success' => true,
                'response' => $result['message']['content']
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => '无法解析Ollama响应: ' . $response
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'check') {
        // 检查Ollama服务是否可用
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $ollama_host . '/api/tags');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response && $http_code === 200) {
            $data = json_decode($response, true);
            $models = array_column($data['models'] ?? [], 'name');
            $model_loaded = in_array($model_name, $models) || !empty($models);
            
            echo json_encode([
                'available' => true,
                'model_loaded' => $model_loaded,
                'models' => $models
            ]);
        } else {
            echo json_encode([
                'available' => false
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'start') {
        // 启动AI模型
        $command = 'start /B "' . $ollama_path . '" run ' . $model_name;
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        
        $process = proc_open($command, $descriptorspec, $pipes);
        
        if (is_resource($process)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit_code = proc_close($process);
            
            echo json_encode([
                'success' => true,
                'message' => '启动命令已发送，请等待模型加载'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => '无法启动进程，请检查Ollama路径配置'
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'stop') {
        // 停止AI模型
        // Windows下通过taskkill杀死进程
        $command = 'taskkill /F /IM ollama.exe 2>&1';
        $output = [];
        $return_var = 0;
        exec($command, $output, $return_var);
        
        if ($return_var === 0 || str_contains(strtolower(implode(' ', $output)), '成功')) {
            echo json_encode([
                'success' => true,
                'message' => 'Ollama进程已停止'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => '已尝试停止Ollama进程: ' . implode(' ', $output)
            ]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>本地AI聊天 - DeepSeek R1</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #333;
            font-size: 2rem;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #666;
            font-size: 1rem;
        }

        .status-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #ccc;
            animation: pulse 2s infinite;
        }

        .status-indicator.online {
            background: #10b981;
        }

        .status-indicator.offline {
            background: #ef4444;
        }

        .status-text {
            font-size: 0.9rem;
            color: #555;
        }

        .chat-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            min-height: 500px;
            max-height: 60vh;
        }

        .message {
            display: flex;
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease-in;
        }

        .message.user {
            justify-content: flex-end;
        }

        .message-content {
            max-width: 70%;
            padding: 16px 20px;
            border-radius: 16px;
            line-height: 1.6;
            font-size: 1rem;
        }

        .message.ai .message-content {
            background: #f3f4f6;
            color: #333;
            border-bottom-left-radius: 4px;
        }

        .message.user .message-content {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .message.ai .message-avatar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .message.user .message-avatar {
            background: #e5e7eb;
            color: #666;
            margin-left: 12px;
            margin-right: 0;
        }

        .message-wrapper {
            display: flex;
            align-items: flex-end;
        }

        .message.user .message-wrapper {
            flex-direction: row-reverse;
        }

        .input-container {
            padding: 20px 24px;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .input-wrapper {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        textarea {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 1rem;
            font-family: inherit;
            resize: none;
            outline: none;
            transition: border-color 0.3s;
            min-height: 50px;
            max-height: 200px;
        }

        textarea:focus {
            border-color: #667eea;
        }

        button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            min-height: 50px;
        }

        button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .clear-btn {
            background: #ef4444;
            margin-top: 12px;
        }

        .start-btn {
            background: #10b981;
            margin-top: 12px;
        }

        .stop-btn {
            background: #f59e0b;
            margin-top: 12px;
        }

        .buttons-container {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .typing-indicator {
            display: none;
            padding: 12px 16px;
        }

        .typing-indicator.active {
            display: block;
        }

        .typing-indicator span {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #667eea;
            border-radius: 50%;
            margin: 0 2px;
            animation: typing 1.4s infinite ease-in-out both;
        }

        .typing-indicator span:nth-child(1) {
            animation-delay: -0.32s;
        }

        .typing-indicator span:nth-child(2) {
            animation-delay: -0.16s;
        }

        @keyframes typing {
            0%, 80%, 100% {
                transform: scale(0);
            }
            40% {
                transform: scale(1);
            }
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

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }

        .empty-state {
            text-align: center;
            color: #9ca3af;
            padding: 60px 20px;
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .model-info {
            display: inline-block;
            padding: 4px 12px;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 8px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            h1 {
                font-size: 1.5rem;
            }

            .message-content {
                max-width: 85%;
            }

            .buttons-container {
                flex-direction: column;
            }

            button {
                width: 100%;
            }
        }

        pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            margin: 12px 0;
        }

        code {
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }

        pre code {
            background: none;
            padding: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🤖 本地AI聊天</h1>
            <p class="subtitle">与本地运行的 DeepSeek R1 模型对话</p>
            <div class="model-info">模型: <?php echo htmlspecialchars($model_name); ?></div>
            <div class="status-bar">
                <div class="status-indicator" id="statusIndicator"></div>
                <span class="status-text" id="statusText">正在检测Ollama服务...</span>
            </div>
        </header>

        <div class="chat-container">
            <div class="chat-messages" id="chatMessages">
                <div class="empty-state" id="emptyState">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8c0 4.418-4.03 8-9 8z" />
                    </svg>
                    <p>开始与你的本地AI对话吧！</p>
                </div>
                <div class="typing-indicator" id="typingIndicator">
                    <span></span>
                    <span></span>
                    <span></span>
                    AI正在思考...
                </div>
            </div>

            <div class="input-container">
                <div class="input-wrapper">
                    <textarea 
                        id="userInput" 
                        placeholder="输入你的问题...按 Enter 发送，Shift + Enter 换行"
                        rows="1"
                    ></textarea>
                    <button id="sendBtn" disabled>发送</button>
                </div>
                <div class="buttons-container">
                    <button class="start-btn" id="startBtn">启动AI</button>
                    <button class="stop-btn" id="stopBtn">停止AI</button>
                    <button class="clear-btn" id="clearBtn">清空对话</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let messages = [];
        let isConnected = false;

        const chatMessages = document.getElementById('chatMessages');
        const userInput = document.getElementById('userInput');
        const sendBtn = document.getElementById('sendBtn');
        const clearBtn = document.getElementById('clearBtn');
        const startBtn = document.getElementById('startBtn');
        const stopBtn = document.getElementById('stopBtn');
        const statusIndicator = document.getElementById('statusIndicator');
        const statusText = document.getElementById('statusText');
        const typingIndicator = document.getElementById('typingIndicator');
        const emptyState = document.getElementById('emptyState');

        // 检查Ollama服务状态
        async function checkStatus() {
            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=check'
                });
                
                const data = await response.json();
                
                if (data.available) {
                    isConnected = true;
                    statusIndicator.classList.add('online');
                    statusIndicator.classList.remove('offline');
                    statusText.textContent = 'Ollama服务已连接';
                    sendBtn.disabled = false;
                    
                    if (!data.model_loaded) {
                        statusText.textContent += ' - 请注意: 模型可能未加载，请先运行 ollama run huihui_ai/deepseek-r1-abliterated:8b';
                    }
                } else {
                    isConnected = false;
                    statusIndicator.classList.add('offline');
                    statusIndicator.classList.remove('online');
                    statusText.textContent = 'Ollama服务未连接，请确保Ollama已启动';
                    sendBtn.disabled = true;
                }
            } catch (error) {
                isConnected = false;
                statusIndicator.classList.add('offline');
                statusIndicator.classList.remove('online');
                statusText.textContent = '检测失败: ' + error.message;
                sendBtn.disabled = true;
            }
        }

        // 添加消息到界面
        function addMessage(content, isUser) {
            if (emptyState) {
                emptyState.style.display = 'none';
            }
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isUser ? 'user' : 'ai'}`;
            
            const avatar = document.createElement('div');
            avatar.className = 'message-avatar';
            avatar.textContent = isUser ? '你' : 'AI';
            
            const wrapper = document.createElement('div');
            wrapper.className = 'message-wrapper';
            
            const contentDiv = document.createElement('div');
            contentDiv.className = 'message-content';
            
            // 简单的Markdown解析（支持代码块）
            let formattedContent = content
                .replace(/```([^`]+)```/g, '<pre><code>$1</code></pre>')
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\n/g, '<br>');
            
            contentDiv.innerHTML = formattedContent;
            
            wrapper.appendChild(avatar);
            wrapper.appendChild(contentDiv);
            messageDiv.appendChild(wrapper);
            
            typingIndicator.parentNode.insertBefore(messageDiv, typingIndicator);
            
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // 发送消息
        async function sendMessage() {
            const content = userInput.value.trim();
            
            if (!content || !isConnected) {
                return;
            }
            
            // 添加用户消息
            addMessage(content, true);
            messages.push({ role: 'user', content: content });
            userInput.value = '';
            userInput.style.height = 'auto';
            sendBtn.disabled = true;
            typingIndicator.classList.add('active');
            
            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=chat&messages=' + encodeURIComponent(JSON.stringify(messages))
                });
                
                const data = await response.json();
                
                typingIndicator.classList.remove('active');
                
                if (data.success) {
                    addMessage(data.response, false);
                    messages.push({ role: 'assistant', content: data.response });
                } else {
                    addMessage('错误: ' + data.error, false);
                }
            } catch (error) {
                typingIndicator.classList.remove('active');
                addMessage('发送失败: ' + error.message, false);
            }
            
            sendBtn.disabled = false;
            userInput.focus();
        }

        // 清空对话
        function clearChat() {
            messages = [];
            while (chatMessages.firstChild) {
                if (chatMessages.firstChild === emptyState || chatMessages.firstChild === typingIndicator) {
                    break;
                }
                chatMessages.removeChild(chatMessages.firstChild);
            }
            if (emptyState) {
                emptyState.style.display = 'block';
            }
        }

        // 自动调整textarea高度
        userInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 200) + 'px';
        });

        // 快捷键发送
        userInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // 点击发送按钮
        sendBtn.addEventListener('click', sendMessage);

        // 清空对话
        clearBtn.addEventListener('click', clearChat);

        // 启动AI模型
        startBtn.addEventListener('click', async function() {
            this.disabled = true;
            this.textContent = '启动中...';
            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=start'
                });
                
                const data = await response.json();
                if (data.success) {
                    alert(data.message);
                    setTimeout(checkStatus, 2000);
                } else {
                    alert('启动失败: ' + data.error);
                }
            } catch (error) {
                alert('启动失败: ' + error.message);
            } finally {
                this.disabled = false;
                this.textContent = '启动AI';
            }
        });

        // 停止AI模型
        stopBtn.addEventListener('click', async function() {
            this.disabled = true;
            this.textContent = '停止中...';
            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=stop'
                });
                
                const data = await response.json();
                alert(data.message);
                setTimeout(checkStatus, 2000);
            } catch (error) {
                alert('停止失败: ' + error.message);
            } finally {
                this.disabled = false;
                this.textContent = '停止AI';
            }
        });

        // 初始检查状态
        checkStatus();
        
        // 定时检查状态
        setInterval(checkStatus, 30000);
    </script>
</body>
</html>