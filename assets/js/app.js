        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
        const WELCOME_MESSAGE = '👋 你好！我是你的AI助手，有什么可以帮助你的吗？';
        let currentAIService = '';
        const sending = { chat: false, novel: false, image: false, video: false };
        // 服务端会话标识：由后端生成并持久化在本地，刷新页面后可继续上下文
        const CONVERSATION_KEY = 'ai_conversation_id';
        let conversationId = localStorage.getItem(CONVERSATION_KEY) || '';

        // 统一 POST 封装：自动携带 CSRF Token，并处理非 JSON / 错误响应
        async function apiPost(params) {
            params.csrf_token = CSRF_TOKEN;
            const response = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params).toString()
            });
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error(`服务器返回了非JSON响应 (HTTP ${response.status})`);
            }
            if (!data.success) {
                throw new Error(data.error || `请求失败 (HTTP ${response.status})`);
            }
            return data;
        }

        // 以纯文本渲染结果，避免把模型输出当作 HTML 执行
        function renderTextResult(container, heading, text) {
            container.replaceChildren();
            const title = document.createElement('h3');
            title.textContent = heading;
            const body = document.createElement('div');
            body.textContent = text;
            container.append(title, document.createElement('br'), body);
            container.style.display = 'block';
        }

        function renderErrorResult(container, message) {
            renderTextResult(container, '❌ 出错了', message);
        }

        // 只接受 http/https 资源地址，拦截 javascript: 等危险协议
        function safeResourceUrl(raw) {
            try {
                const url = new URL(raw, window.location.href);
                return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
            } catch (error) {
                return null;
            }
        }

        // 加载AI服务列表
        let aiServicesCache = [];

        async function loadAIServices(preserveSelection) {
            let result;
            try {
                result = await apiPost({ action: 'get_ai_services' });
            } catch (error) {
                const select = document.getElementById('ai-select');
                select.innerHTML = '';
                const option = document.createElement('option');
                option.value = '';
                option.textContent = '加载失败';
                select.appendChild(option);
                document.getElementById('ai-status-text').textContent = error.message;
                return;
            }
            const select = document.getElementById('ai-select');
            select.innerHTML = '';
            aiServicesCache = result.services;

            result.services.forEach(service => {
                const option = document.createElement('option');
                option.value = service.key;
                option.textContent = service.name;
                if (service.configured) {
                    option.textContent += ' ✓';
                }
                select.appendChild(option);
            });

            // 保留当前选择，否则使用默认服务
            const keep = preserveSelection && result.services.some(service => service.key === currentAIService);
            const preferred = keep ? currentAIService : result.default_ai;
            select.value = preferred;
            currentAIService = preferred;

            // 更新状态文本
            updateAIStatus();
        }

        function updateAIStatus() {
            const service = aiServicesCache.find(s => s.key === currentAIService);
            const statusText = document.getElementById('ai-status-text');
            if (service) {
                statusText.textContent = service.configured
                    ? `${service.name} 已连接`
                    : `${service.name} 未配置（点击「⚙ 配置」填写）`;
            }
        }

        // 重置聊天
        function resetChat() {
            const container = document.getElementById('chat-messages');
            container.replaceChildren();
            const div = document.createElement('div');
            div.className = 'message ai-message';
            div.textContent = WELCOME_MESSAGE;
            container.appendChild(div);
            chatMessages = [{ role: 'assistant', content: WELCOME_MESSAGE }];
            conversationId = '';
            localStorage.removeItem(CONVERSATION_KEY);
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
            const badge = document.getElementById('status-badge');
            try {
                const result = await apiPost({ action: 'check_config' });
                if (result.configured) {
                    badge.className = 'status-badge configured';
                    badge.textContent = 'API已配置 ✓';
                } else {
                    badge.className = 'status-badge not-configured';
                    badge.textContent = 'API未配置 ✗';
                }
            } catch (error) {
                badge.className = 'status-badge not-configured';
                badge.textContent = '状态获取失败';
            }
        }

        // 初始化
        loadAIServices();
        checkConfig();

        document.getElementById('ai-select').addEventListener('change', (e) => {
            currentAIService = e.target.value;
            updateAIStatus();
            resetChat();
            closeConfigPanel();
        });

        // AI 服务配置面板
        const configPanel = document.getElementById('ai-config-panel');
        const configMessage = document.getElementById('config-message');

        function setConfigMessage(text, type) {
            configMessage.textContent = text || '';
            configMessage.className = 'config-message' + (type ? ' ' + type : '');
        }

        function closeConfigPanel() {
            configPanel.hidden = true;
            setConfigMessage('', '');
        }

        async function openConfigPanel() {
            if (!currentAIService) return;
            configPanel.hidden = false;
            setConfigMessage('', '');
            document.getElementById('config-api-key').value = '';
            try {
                const data = await apiPost({ action: 'get_ai_service_config', ai_service: currentAIService });
                const service = data.service;
                document.getElementById('config-title').textContent = `配置：${service.name}`;
                document.getElementById('config-desc').textContent = service.description || '';
                document.getElementById('config-base-url').value = service.api_base_url || '';
                document.getElementById('config-model').value = service.default_model || '';
                document.getElementById('config-key-hint').textContent = service.has_key
                    ? `已保存 Key（${service.key_hint}），留空即可保持不变`
                    : (service.requires_key ? '尚未填写 Key，填写后保存' : '该服务无需 Key');
            } catch (error) {
                setConfigMessage(error.message, 'error');
            }
        }

        async function saveServiceConfig(clearKey) {
            if (!currentAIService) return;
            const saveButton = document.getElementById('config-save');
            saveButton.disabled = true;
            try {
                const data = await apiPost({
                    action: 'save_ai_service_config',
                    ai_service: currentAIService,
                    api_base_url: document.getElementById('config-base-url').value.trim(),
                    default_model: document.getElementById('config-model').value.trim(),
                    api_key: clearKey ? '' : document.getElementById('config-api-key').value,
                    clear_key: clearKey ? '1' : '0'
                });
                document.getElementById('config-api-key').value = '';
                document.getElementById('config-key-hint').textContent = data.service.has_key
                    ? `已保存 Key（${data.service.key_hint}），留空即可保持不变`
                    : '尚未填写 Key';
                setConfigMessage(data.message || '配置已保存', 'success');
                await loadAIServices(true);
                updateAIStatus();
            } catch (error) {
                setConfigMessage(error.message, 'error');
            } finally {
                saveButton.disabled = false;
            }
        }

        document.getElementById('btn-ai-config').addEventListener('click', openConfigPanel);
        document.getElementById('config-close').addEventListener('click', closeConfigPanel);
        document.getElementById('config-save').addEventListener('click', () => saveServiceConfig(false));
        document.getElementById('config-clear-key').addEventListener('click', () => {
            if (window.confirm('确定清除该服务已保存的 API Key？')) {
                saveServiceConfig(true);
            }
        });

        // 聊天功能
        let chatMessages = [{ role: 'assistant', content: WELCOME_MESSAGE }];

        document.getElementById('send-chat').addEventListener('click', sendChatMessage);
        document.getElementById('chat-input').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                sendChatMessage();
            }
        });

        // 记录服务端会话标识
        function rememberConversation(id) {
            if (!id) return;
            conversationId = id;
            localStorage.setItem(CONVERSATION_KEY, id);
        }

        // SSE 流式对话：逐段渲染到目标气泡，返回完整文本
        async function streamChat(targetDiv) {
            const params = new URLSearchParams({
                action: 'chat_stream',
                csrf_token: CSRF_TOKEN,
                messages: JSON.stringify(chatMessages),
                ai_service: currentAIService,
                conversation_id: conversationId
            });
            const response = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            });
            const contentType = response.headers.get('Content-Type') || '';
            if (!response.ok || !contentType.includes('text/event-stream')) {
                let message = `流式请求失败 (HTTP ${response.status})`;
                try {
                    const payload = await response.json();
                    if (payload && payload.error) message = payload.error;
                } catch (error) {
                    // 非 JSON 响应，保留默认错误信息
                }
                throw new Error(message);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let content = '';
            let finished = false;
            let streamError = '';

            while (true) {
                const chunk = await reader.read();
                if (chunk.done) break;
                buffer += decoder.decode(chunk.value, { stream: true });
                let boundary;
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, boundary).trim();
                    buffer = buffer.slice(boundary + 2);
                    if (!raw.startsWith('data:')) continue;
                    let event;
                    try {
                        event = JSON.parse(raw.slice(5).trim());
                    } catch (error) {
                        continue;
                    }
                    if (event.type === 'start') {
                        rememberConversation(event.conversation_id);
                    } else if (event.type === 'delta' && typeof event.delta === 'string') {
                        content += event.delta;
                        targetDiv.textContent = content;
                        scrollToBottom();
                    } else if (event.type === 'done') {
                        finished = true;
                        if (typeof event.content === 'string' && event.content !== '') {
                            content = event.content;
                        }
                        rememberConversation(event.conversation_id);
                    } else if (event.type === 'error') {
                        streamError = event.error || '流式响应出错';
                    }
                }
            }
            if (streamError !== '') throw new Error(streamError);
            if (!finished && content === '') throw new Error('流式响应中断，请重试');
            return content;
        }

        // 小说分阶段流式生成：持续接收增量，避免长文本请求超时
        async function streamNovelStage(payload, onProgress) {
            const params = new URLSearchParams();
            Object.keys(payload).forEach(key => params.append(key, payload[key]));
            params.append('action', 'generate_novel_stream');
            params.append('csrf_token', CSRF_TOKEN);

            const response = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            });
            const contentType = response.headers.get('Content-Type') || '';
            if (!response.ok || !contentType.includes('text/event-stream')) {
                let message = `请求失败 (HTTP ${response.status})`;
                try {
                    const errorPayload = await response.json();
                    if (errorPayload && errorPayload.error) message = errorPayload.error;
                } catch (error) {
                    // 非 JSON 响应，保留默认错误信息
                }
                throw new Error(message);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let content = '';
            let finished = false;
            let streamError = '';

            while (true) {
                const chunk = await reader.read();
                if (chunk.done) break;
                buffer += decoder.decode(chunk.value, { stream: true });
                let boundary;
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, boundary).trim();
                    buffer = buffer.slice(boundary + 2);
                    if (!raw.startsWith('data:')) continue;
                    let event;
                    try {
                        event = JSON.parse(raw.slice(5).trim());
                    } catch (error) {
                        continue;
                    }
                    if (event.type === 'delta' && typeof event.delta === 'string') {
                        content += event.delta;
                        if (typeof onProgress === 'function') onProgress(content);
                    } else if (event.type === 'done') {
                        finished = true;
                        if (typeof event.content === 'string' && event.content !== '') {
                            content = event.content;
                        }
                    } else if (event.type === 'error') {
                        streamError = event.error || '生成失败';
                    }
                }
            }

            if (streamError !== '') throw new Error(streamError);
            if (!finished && content === '') throw new Error('生成中断，请重试');
            return content;
        }

        // 刷新页面后恢复服务端会话历史
        async function restoreConversation() {
            if (!conversationId) return;
            let data;
            try {
                data = await apiPost({ action: 'get_conversation', conversation_id: conversationId });
            } catch (error) {
                return; // 会话不可用时保持新会话
            }
            const history = (data.messages || []).filter(message =>
                message && (message.role === 'user' || message.role === 'assistant') && typeof message.content === 'string'
            );
            if (history.length === 0) {
                conversationId = '';
                localStorage.removeItem(CONVERSATION_KEY);
                return;
            }
            const container = document.getElementById('chat-messages');
            container.replaceChildren();
            chatMessages = [];
            history.forEach(message => {
                addMessageToChat(message.content, message.role === 'user' ? 'user' : 'ai');
                chatMessages.push({ role: message.role, content: message.content });
            });
            scrollToBottom();
        }

        async function sendChatMessage() {
            if (sending.chat) return;
            const input = document.getElementById('chat-input');
            const message = input.value.trim();
            if (!message) return;

            sending.chat = true;
            const sendButton = document.getElementById('send-chat');
            sendButton.disabled = true;

            // 添加用户消息
            chatMessages.push({ role: 'user', content: message });
            addMessageToChat(message, 'user');
            input.value = '';

            // 添加AI占位消息
            const aiMessageDiv = addMessageToChat('🤔 正在思考中...', 'ai');
            let content = '';
            let succeeded = false;

            try {
                content = await streamChat(aiMessageDiv);
                succeeded = true;
            } catch (error) {
                // 流式失败时回退到普通接口，保证功能可用
                try {
                    const result = await apiPost({
                        action: 'chat',
                        messages: JSON.stringify(chatMessages),
                        ai_service: currentAIService,
                        conversation_id: conversationId
                    });
                    content = result.response;
                    rememberConversation(result.conversation_id);
                    succeeded = true;
                } catch (fallbackError) {
                    aiMessageDiv.textContent = `❌ 错误: ${fallbackError.message}`;
                    // 请求失败时回退最后一条用户消息，避免重复发送
                    chatMessages.pop();
                }
            }

            if (succeeded) {
                aiMessageDiv.textContent = content;
                chatMessages.push({ role: 'assistant', content });
            }
            sending.chat = false;
            sendButton.disabled = false;
            scrollToBottom();
        }

        function addMessageToChat(content, type) {
            const container = document.getElementById('chat-messages');
            const div = document.createElement('div');
            div.className = `message ${type}-message`;
            div.textContent = content;
            container.appendChild(div);
            return div;
        }

        function scrollToBottom() {
            const container = document.getElementById('chat-messages');
            container.scrollTop = container.scrollHeight;
        }

        // 小说创作：多步流水线（大纲 → 分章 → 梗概）
        function renderNovelStages(container, title, stages) {
            container.replaceChildren();
            const heading = document.createElement('h3');
            heading.textContent = title ? `✅ 创作完成：${title}` : '✅ 创作完成';
            container.append(heading);
            stages.forEach(stage => {
                const label = document.createElement('h4');
                label.textContent = stage.label || stage.key;
                const body = document.createElement('div');
                body.className = 'stage-content';
                body.textContent = stage.content || '';
                container.append(label, body);
            });
            container.style.display = 'block';
        }

        document.getElementById('novel-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (sending.novel) return;
            sending.novel = true;

            const prompt = document.getElementById('novel-prompt').value.trim();
            const title = document.getElementById('novel-title').value.trim();
            const genre = document.getElementById('novel-genre').value;
            const chapterCount = Number(document.getElementById('novel-chapters').value);

            const loading = document.getElementById('novel-loading');
            const loadingText = loading.querySelector('p');
            const result = document.getElementById('novel-result');

            loading.classList.add('active');
            loadingText.textContent = '正在生成世界观与分章大纲...';
            result.style.display = 'none';

            const stages = [];
            let lastNovelRequestAt = 0;
            // Pollinations 免注册接口限制每 30 秒 1 次，阶段之间主动等待
            const throttlePollinations = async () => {
                if (currentAIService !== 'pollinations') return;
                const remainMs = 31000 - (Date.now() - lastNovelRequestAt);
                if (remainMs <= 1000) return;
                for (let seconds = Math.ceil(remainMs / 1000); seconds > 0; seconds--) {
                    loadingText.textContent = `免注册接口每 30 秒仅 1 次，${seconds} 秒后继续...`;
                    await new Promise(resolve => setTimeout(resolve, 1000));
                }
            };
            try {
                lastNovelRequestAt = Date.now();
                const outlineContent = await streamNovelStage({
                    stage: 'outline',
                    prompt,
                    title,
                    genre,
                    chapters: chapterCount,
                    ai_service: currentAIService
                }, text => {
                    loadingText.textContent = `正在生成世界观与大纲...（已生成 ${text.length} 字）`;
                });
                stages.push({ label: '世界观与大纲', content: outlineContent });

                let previous = '';
                for (let index = 1; index <= chapterCount; index++) {
                    await throttlePollinations();
                    loadingText.textContent = `正在创作第 ${index} / ${chapterCount} 章...`;
                    lastNovelRequestAt = Date.now();
                    const chapterContent = await streamNovelStage({
                        stage: 'chapter',
                        prompt,
                        title,
                        genre,
                        chapters: chapterCount,
                        index,
                        outline: outlineContent,
                        previous,
                        ai_service: currentAIService
                    }, text => {
                        loadingText.textContent = `正在创作第 ${index} / ${chapterCount} 章...（已生成 ${text.length} 字）`;
                    });
                    stages.push({ label: `第 ${index} 章`, content: chapterContent });
                    previous = chapterContent;
                }

                await throttlePollinations();
                loadingText.textContent = '正在生成梗概与标题建议...';
                const excerpt = stages
                    .filter(stage => stage.label !== '世界观与大纲')
                    .map(stage => stage.content)
                    .join('\n\n')
                    .slice(-3000);
                try {
                    lastNovelRequestAt = Date.now();
                    const summaryContent = await streamNovelStage({
                        stage: 'summary',
                        prompt,
                        title,
                        genre,
                        chapters: chapterCount,
                        outline: outlineContent,
                        excerpt,
                        ai_service: currentAIService
                    }, text => {
                        loadingText.textContent = `正在生成梗概与标题建议...（已生成 ${text.length} 字）`;
                    });
                    stages.push({ label: '梗概与标题建议', content: summaryContent });
                } catch (summaryError) {
                    // 梗概失败不影响已生成的大纲与正文
                }

                renderNovelStages(result, title, stages);
            } catch (error) {
                renderErrorResult(result, error.message);
            } finally {
                sending.novel = false;
                loading.classList.remove('active');
                loadingText.textContent = '正在分阶段创作（大纲 → 分章 → 梗概），请稍候...';
            }
        });

        // 图片生成
        document.getElementById('image-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (sending.image) return;
            sending.image = true;

            const prompt = document.getElementById('image-prompt').value.trim();
            const loading = document.getElementById('image-loading');
            const result = document.getElementById('image-result');

            loading.classList.add('active');
            result.style.display = 'none';

            try {
                const data = await apiPost({ action: 'generate_image', prompt });
                const imageUrl = safeResourceUrl(data.image_url);
                if (!imageUrl) {
                    throw new Error('返回的图片地址不合法');
                }
                result.replaceChildren();
                const title = document.createElement('h3');
                title.textContent = '✅ 生成完成';
                const img = document.createElement('img');
                img.src = imageUrl;
                img.alt = prompt;
                result.append(title, document.createElement('br'), img);
                result.style.display = 'block';
            } catch (error) {
                renderErrorResult(result, error.message);
            } finally {
                sending.image = false;
                loading.classList.remove('active');
            }
        });

        // 视频生成：兼容同步返回与异步任务轮询
        function renderVideoResult(container, videoUrl) {
            const safeUrl = safeResourceUrl(videoUrl);
            if (!safeUrl) {
                throw new Error('返回的视频地址不合法');
            }
            container.replaceChildren();
            const title = document.createElement('h3');
            title.textContent = '✅ 生成完成';
            const video = document.createElement('video');
            video.src = safeUrl;
            video.controls = true;
            video.setAttribute('playsinline', '');
            container.append(title, document.createElement('br'), video);
            container.style.display = 'block';
        }

        async function pollVideoTask(taskId, pollInterval) {
            const deadline = Date.now() + 5 * 60 * 1000;
            const interval = Math.max(2, Number(pollInterval) || 5);
            while (Date.now() < deadline) {
                await new Promise(resolve => setTimeout(resolve, interval * 1000));
                const data = await apiPost({ action: 'get_video_task', task_id: taskId });
                if (data.status === 'completed' && data.video_url) {
                    return data.video_url;
                }
                if (data.status === 'failed') {
                    throw new Error(data.error || '视频生成失败');
                }
            }
            throw new Error('视频生成超时，请稍后重试');
        }

        document.getElementById('video-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (sending.video) return;
            sending.video = true;

            const prompt = document.getElementById('video-prompt').value.trim();
            const loading = document.getElementById('video-loading');
            const result = document.getElementById('video-result');

            loading.classList.add('active');
            result.style.display = 'none';

            try {
                const data = await apiPost({ action: 'generate_video', prompt });
                let videoUrl = data.video_url || '';
                if (!videoUrl && data.task_id) {
                    loading.querySelector('p').textContent = '视频生成中，正在获取进度...';
                    videoUrl = await pollVideoTask(data.task_id, data.poll_interval);
                }
                if (!videoUrl) {
                    throw new Error('视频生成API未返回视频地址');
                }
                renderVideoResult(result, videoUrl);
            } catch (error) {
                renderErrorResult(result, error.message);
            } finally {
                sending.video = false;
                loading.classList.remove('active');
                loading.querySelector('p').textContent = '正在提交任务，结果生成后会自动展示...';
            }
        });

        // 页面就绪后尝试恢复上次的服务端会话
        restoreConversation();
