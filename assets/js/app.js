        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
        const WELCOME_MESSAGE = '👋 你好！我是你的AI助手，有什么可以帮助你的吗？';
        let currentAIService = '';
        const sending = { chat: false, novel: false, image: false, video: false, parse: false };
        // 图片生成：姿势 / 服装提示词库当前选择（由选择面板维护，提交时带给后端）
        const imagePresetSelection = { pose: null, costume: null };
        // 本地 AI 服务：Ollama（对话）、ComfyUI（图片）
        let localServiceKeys = [];
        let localStatus = { ollama: null, comfyui: null };
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
        refreshLocalStatus();

        document.getElementById('ai-select').addEventListener('change', (e) => {
            currentAIService = e.target.value;
            updateAIStatus();
            resetChat();
            closeConfigPanel();
            checkSelectedLocalService();
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

        // 本地 AI 服务（Ollama / ComfyUI）状态检测与启动指引弹窗
        const LOCAL_AI_GUIDES = {
            ollama: {
                title: '本地对话模型（Ollama）未启动',
                steps: [
                    '按 Win + R 输入 powershell 回车，打开命令行窗口',
                    "执行：<code>$env:OLLAMA_MODELS='E:\\AI\\ollma\\model'; E:\\AI\\ollma\\ollama.exe serve</code>",
                    '看到 <code>Listening on 127.0.0.1:11434</code> 表示启动成功，保持窗口不要关闭',
                    '回到本页点击下方「我已启动，重新检测」，再重新发送请求'
                ]
            },
            comfyui: {
                title: '本地图片服务（ComfyUI）未启动',
                steps: [
                    '双击 <code>E:\\AI\\ComfyUI\\start_comfyui.bat</code>，会在后台启动（窗口最小化属正常）',
                    '首次启动需要加载约 2GB 底模，等待 40~90 秒',
                    '浏览器能打开 <code>http://127.0.0.1:8188</code> 即表示就绪',
                    '仍不可用时查看日志 <code>E:\\Project\\Pro_AI\\.tmp\\comfy.log</code>'
                ]
            }
        };

        const localAiModal = document.getElementById('local-ai-modal');

        function showLocalAiModal(kind) {
            const guide = LOCAL_AI_GUIDES[kind];
            if (!guide) return;
            document.getElementById('local-ai-modal-title').textContent = guide.title;
            document.getElementById('local-ai-modal-body').innerHTML = '<p>该功能依赖本机已部署的服务，按以下步骤启动后即可使用：</p><ol>'
                + guide.steps.map(step => '<li>' + step + '</li>').join('')
                + '</ol>';
            document.getElementById('local-ai-modal-message').textContent = '';
            document.getElementById('local-ai-modal-retry').dataset.kind = kind;
            localAiModal.hidden = false;
        }

        function hideLocalAiModal() {
            localAiModal.hidden = true;
            document.getElementById('local-ai-modal-message').textContent = '';
        }

        // 图片生成页顶部的本地服务状态提示
        function renderComfyStatus() {
            const hint = document.getElementById('comfy-status');
            if (!hint) return;
            hint.replaceChildren();
            const comfy = localStatus.comfyui;
            if (!comfy) return;
            const text = document.createElement('span');
            if (comfy.online) {
                text.className = 'status-ok';
                text.textContent = '本地图片服务（ComfyUI）已就绪：' + comfy.url;
                hint.appendChild(text);
                return;
            }
            text.className = 'status-bad';
            text.textContent = '本地图片服务（ComfyUI）未启动。';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'link-btn';
            button.textContent = '查看启动方法';
            button.addEventListener('click', () => showLocalAiModal('comfyui'));
            hint.append(text, button);
        }

        async function refreshLocalStatus() {
            try {
                const data = await apiPost({ action: 'get_local_ai_status' });
                localStatus = { ollama: data.ollama, comfyui: data.comfyui };
                localServiceKeys = data.local_service_keys || [];
            } catch (error) {
                return null;
            }
            renderComfyStatus();
            return localStatus;
        }

        // 切换到本地服务时，若服务未启动则直接弹出启动指引
        function checkSelectedLocalService() {
            if (!localServiceKeys.includes(currentAIService)) return;
            if (localStatus.ollama && localStatus.ollama.online) return;
            showLocalAiModal('ollama');
        }

        document.getElementById('local-ai-modal-close').addEventListener('click', hideLocalAiModal);
        document.getElementById('local-ai-modal-dismiss').addEventListener('click', hideLocalAiModal);
        localAiModal.addEventListener('click', (event) => {
            if (event.target === localAiModal) hideLocalAiModal();
        });
        document.getElementById('local-ai-modal-retry').addEventListener('click', async () => {
            const button = document.getElementById('local-ai-modal-retry');
            const message = document.getElementById('local-ai-modal-message');
            const kind = button.dataset.kind || 'ollama';
            button.disabled = true;
            message.className = 'config-message';
            message.textContent = '正在重新检测...';
            const status = await refreshLocalStatus();
            button.disabled = false;
            const target = status ? status[kind] : null;
            if (target && target.online) {
                message.className = 'config-message success';
                message.textContent = '服务已就绪，可以继续使用';
                setTimeout(hideLocalAiModal, 1200);
            } else {
                message.className = 'config-message error';
                message.textContent = '仍未检测到服务，请确认启动窗口没有报错';
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

        // 通用 SSE 动作：持续接收增量，避免长文本请求超时
        async function streamSseAction(action, payload, onProgress) {
            const params = new URLSearchParams();
            Object.keys(payload).forEach(key => params.append(key, payload[key]));
            params.append('action', action);
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

        // 小说分阶段流式生成
        function streamNovelStage(payload, onProgress) {
            return streamSseAction('generate_novel_stream', payload, onProgress);
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
                    // 本地模型未启动时弹出启动指引
                    if (localServiceKeys.includes(currentAIService)) {
                        showLocalAiModal('ollama');
                    }
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

            const prompt = document.getElementById('novel-prompt').value.trim();
            const title = document.getElementById('novel-title').value.trim();
            const genre = document.getElementById('novel-genre').value;
            const chapterCount = Number(document.getElementById('novel-chapters').value);
            const reference = document.getElementById('novel-reference').value.trim();
            const referenceMode = document.getElementById('novel-reference-mode').value;

            const loading = document.getElementById('novel-loading');
            const loadingText = loading.querySelector('p');
            const result = document.getElementById('novel-result');

            if (!prompt && !reference) {
                renderErrorResult(result, '请输入创作要求，或上传一段参考文本');
                return;
            }

            sending.novel = true;
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
                    reference,
                    reference_mode: referenceMode,
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
                        reference,
                        reference_mode: referenceMode,
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

        // 图片生成：本地 ComfyUI 为「提交任务 → 前端轮询」两段式，避免长请求被 40 秒超时中断
        async function pollImageTask(taskId, pollInterval, timeoutSeconds, loadingText) {
            const deadline = Date.now() + (Number(timeoutSeconds) || 180) * 1000;
            const interval = Math.max(1, Number(pollInterval) || 2);
            const startedAt = Date.now();
            while (Date.now() < deadline) {
                await new Promise(resolve => setTimeout(resolve, interval * 1000));
                const data = await apiPost({ action: 'get_image_task', task_id: taskId });
                if (data.status === 'completed' && data.image_url) {
                    return data.image_url;
                }
                if (loadingText) {
                    const elapsed = Math.round((Date.now() - startedAt) / 1000);
                    loadingText.textContent = `正在生成中（本地模型首次加载较慢）...已等待 ${elapsed} 秒`;
                }
            }
            const waited = Math.round((Date.now() - startedAt) / 1000);
            throw new Error(`生成超时（已等待 ${waited} 秒）。请确认 ComfyUI 仍在运行，或降低分辨率/步数后重试`);
        }

        // 结果图片卡片：每张图都可在此基础上继续微调（图生图）
        function appendImageResult(container, imageUrl, prompt, titleText, loraName, loraWeight) {
            container.style.display = 'block';

            const card = document.createElement('div');
            card.className = 'image-card';

            const title = document.createElement('h3');
            title.textContent = titleText;
            if (loraName && loraName !== 'none') {
                const loraTag = document.createElement('span');
                loraTag.className = 'image-card-lora';
                loraTag.textContent = 'LoRA：' + loraName + '（' + Number(loraWeight || 0).toFixed(2) + '）';
                title.append(' ', loraTag);
            }

            const img = document.createElement('img');
            img.src = imageUrl;
            img.alt = prompt;

            const refine = document.createElement('details');
            refine.className = 'refine-panel';
            const summary = document.createElement('summary');
            summary.textContent = '✏️ 基于这张图继续微调';

            const descField = document.createElement('div');
            descField.className = 'form-group';
            const descLabel = document.createElement('label');
            descLabel.textContent = '微调描述';
            const textarea = document.createElement('textarea');
            textarea.value = prompt;
            textarea.placeholder = '在原图基础上补充或修改，例如：改成夜景、加强光影、人物表情更柔和';
            descField.append(descLabel, textarea);

            const strengthRow = document.createElement('div');
            strengthRow.className = 'refine-strength';
            const strengthLabel = document.createElement('span');
            const range = document.createElement('input');
            range.type = 'range';
            range.min = '0.15';
            range.max = '0.9';
            range.step = '0.05';
            range.value = '0.5';
            const syncStrength = () => {
                strengthLabel.textContent = '改动幅度 ' + Number(range.value).toFixed(2);
            };
            syncStrength();
            range.addEventListener('input', syncStrength);
            strengthRow.append(strengthLabel, range);

            // 微调时可重新选择画风 / 角色 LoRA：沿用主表单的可选项，默认选中原图使用的 LoRA
            const mainLoraSelect = document.getElementById('image-lora');
            const mainLoraWeight = document.getElementById('image-lora-weight');
            let refineLoraSelect = null;
            let refineLoraWeight = null;
            let loraRow = null;
            if (mainLoraSelect) {
                const currentLora = (loraName && loraName !== 'none') ? loraName : 'none';
                const currentWeight = Number(loraWeight) > 0
                    ? Number(loraWeight)
                    : (mainLoraWeight ? Number(mainLoraWeight.value) : 0.7);

                loraRow = document.createElement('div');
                loraRow.className = 'lora-row';

                const loraField = document.createElement('div');
                loraField.className = 'form-group';
                const loraLabel = document.createElement('label');
                loraLabel.textContent = '画风 / 角色 LoRA';
                refineLoraSelect = document.createElement('select');
                Array.from(mainLoraSelect.options).forEach(option => {
                    const copy = new Option(option.textContent, option.value);
                    if (option.value === currentLora) {
                        copy.selected = true;
                    }
                    refineLoraSelect.add(copy);
                });
                loraField.append(loraLabel, refineLoraSelect);

                const weightField = document.createElement('div');
                weightField.className = 'form-group';
                const weightLabel = document.createElement('label');
                refineLoraWeight = document.createElement('input');
                refineLoraWeight.type = 'range';
                refineLoraWeight.min = '0.1';
                refineLoraWeight.max = '1.2';
                refineLoraWeight.step = '0.05';
                refineLoraWeight.value = Math.max(0.1, Math.min(1.2, currentWeight)).toFixed(2);
                const syncLoraWeight = () => {
                    weightLabel.textContent = 'LoRA 强度：' + Number(refineLoraWeight.value).toFixed(2);
                };
                syncLoraWeight();
                refineLoraWeight.addEventListener('input', syncLoraWeight);
                weightField.append(weightLabel, refineLoraWeight);

                loraRow.append(loraField, weightField);
            }

            const hint = document.createElement('p');
            hint.className = 'form-hint';
            hint.textContent = '幅度越小越接近原图（0.2~0.4 局部调整），越大重绘越明显（0.6~0.8 重新构图）；更换 LoRA 时建议把幅度调到 0.55 以上才能明显生效';

            const actions = document.createElement('div');
            actions.className = 'refine-actions';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-small btn-ghost';
            button.textContent = '开始微调';
            const message = document.createElement('span');
            message.className = 'config-message';
            actions.append(button, message);

            button.addEventListener('click', async () => {
                const text = textarea.value.trim();
                if (!text) {
                    message.className = 'config-message error';
                    message.textContent = '请填写微调描述';
                    return;
                }
                button.disabled = true;
                message.className = 'config-message';
                message.textContent = '已提交，正在生成...';
                const usedLora = refineLoraSelect ? refineLoraSelect.value : (loraName || 'none');
                const usedWeight = refineLoraWeight ? refineLoraWeight.value : (loraWeight || '');
                try {
                    const data = await apiPost({
                        action: 'refine_image',
                        source: imageUrl,
                        prompt: text,
                        strength: range.value,
                        lora: usedLora,
                        lora_weight: usedWeight
                    });
                    let refinedUrl = data.image_url || '';
                    if (!refinedUrl && data.task_id) {
                        refinedUrl = await pollImageTask(data.task_id, data.poll_interval, data.poll_timeout, message);
                    }
                    if (!refinedUrl) {
                        throw new Error('微调未返回结果');
                    }
                    const safeUrl = safeResourceUrl(refinedUrl);
                    if (!safeUrl) {
                        throw new Error('返回的图片地址不合法');
                    }
                    message.className = 'config-message';
                    message.textContent = '';
                    appendImageResult(container, safeUrl, text, '✏️ 微调完成', usedLora, usedWeight);
                } catch (error) {
                    message.className = 'config-message error';
                    message.textContent = error.message;
                    if (/本地图片服务|ComfyUI/i.test(error.message)) {
                        showLocalAiModal('comfyui');
                        refreshLocalStatus();
                    }
                } finally {
                    button.disabled = false;
                }
            });

            if (loraRow) {
                refine.append(descField, loraRow, strengthRow, hint, actions);
            } else {
                refine.append(descField, strengthRow, hint, actions);
            }
            card.append(title, document.createElement('br'), img, refine);
            container.appendChild(card);
        }

        const loraSelect = document.getElementById('image-lora');
        const loraWeightInput = document.getElementById('image-lora-weight');
        const loraWeightLabel = document.getElementById('image-lora-weight-value');
        if (loraWeightInput && loraWeightLabel) {
            loraWeightInput.addEventListener('input', () => {
                loraWeightLabel.textContent = Number(loraWeightInput.value).toFixed(2);
            });
        }

        document.getElementById('image-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (sending.image) return;

            const prompt = document.getElementById('image-prompt').value.trim();
            const loraName = loraSelect ? loraSelect.value : '';
            const loraWeight = loraWeightInput ? loraWeightInput.value : '';
            const angleValue = document.getElementById('preset-angle') ? document.getElementById('preset-angle').value : '';
            const aspectValue = document.getElementById('image-aspect') ? document.getElementById('image-aspect').value : '';
            const loading = document.getElementById('image-loading');
            const loadingText = loading.querySelector('p');
            const result = document.getElementById('image-result');

            if (!prompt && !imagePresetSelection.pose && !imagePresetSelection.costume) {
                renderErrorResult(result, '请输入图片描述，或先选择姿势 / 服装');
                return;
            }

            sending.image = true;
            loading.classList.add('active');
            loadingText.textContent = '正在提交任务...';
            result.replaceChildren();
            result.style.display = 'none';

            try {
                const payload = { action: 'generate_image', prompt };
                if (loraSelect) {
                    payload.lora = loraName;
                    payload.lora_weight = loraWeight;
                }
                if (imagePresetSelection.pose) {
                    payload.pose_id = imagePresetSelection.pose.id;
                    if (angleValue) payload.pose_angle = angleValue;
                }
                if (imagePresetSelection.costume) {
                    payload.costume_id = imagePresetSelection.costume.id;
                }
                if (aspectValue) payload.aspect = aspectValue;
                const data = await apiPost(payload);
                let imageUrl = data.image_url || '';
                if (!imageUrl && data.task_id) {
                    loadingText.textContent = '正在生成中（本地模型首次加载较慢）...';
                    imageUrl = await pollImageTask(data.task_id, data.poll_interval, data.poll_timeout, loadingText);
                }
                if (!imageUrl) {
                    throw new Error('图片生成未返回结果');
                }
                const safeUrl = safeResourceUrl(imageUrl);
                if (!safeUrl) {
                    throw new Error('返回的图片地址不合法');
                }
                appendImageResult(result, safeUrl, prompt, '✅ 生成完成', loraName, loraWeight);
            } catch (error) {
                renderErrorResult(result, error.message);
                // 本地图片服务未启动时弹出启动指引，并刷新状态提示
                if (/本地图片服务|ComfyUI/i.test(error.message)) {
                    showLocalAiModal('comfyui');
                    refreshLocalStatus();
                }
            } finally {
                sending.image = false;
                loading.classList.remove('active');
                loadingText.textContent = '正在生成中，请稍候...';
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

        // 把本地图片压成 data URL（长边最多 768px），避免上传体积过大
        function fileToDataUrl(file, maxSize) {
            return new Promise((resolve, reject) => {
                const image = new Image();
                const objectUrl = URL.createObjectURL(file);
                image.onload = () => {
                    const scale = Math.min(1, maxSize / Math.max(image.width, image.height));
                    const width = Math.max(1, Math.round(image.width * scale));
                    const height = Math.max(1, Math.round(image.height * scale));
                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    canvas.getContext('2d').drawImage(image, 0, 0, width, height);
                    URL.revokeObjectURL(objectUrl);
                    resolve(canvas.toDataURL('image/jpeg', 0.85));
                };
                image.onerror = () => {
                    URL.revokeObjectURL(objectUrl);
                    reject(new Error('无法读取这张图片，请换一张试试'));
                };
                image.src = objectUrl;
            });
        }

        // 小说参考文本：支持上传 .txt/.md，读取后填入文本框（最多 6000 字）
        function bindNovelReference() {
            const input = document.getElementById('novel-reference-file');
            const textarea = document.getElementById('novel-reference');
            const message = document.getElementById('novel-reference-message');
            if (!input || !textarea || !message) return;

            input.addEventListener('change', () => {
                const file = input.files && input.files[0];
                if (!file) return;
                message.className = 'config-message';
                if (file.size > 200 * 1024) {
                    message.className = 'config-message error';
                    message.textContent = '文件过大（最多 200KB），请直接粘贴文字';
                    input.value = '';
                    return;
                }
                const reader = new FileReader();
                reader.onload = () => {
                    const text = String(reader.result || '').slice(0, 6000);
                    textarea.value = text;
                    message.className = 'config-message success';
                    message.textContent = `已读取：${file.name}（${text.length} 字）`;
                };
                reader.onerror = () => {
                    message.className = 'config-message error';
                    message.textContent = '读取失败，请直接粘贴文字';
                };
                reader.readAsText(file, 'UTF-8');
            });
        }

        // 图片提示词库：检索姿势 / 服装，单选后由后端合成英文 tag
        function bindImagePresets() {
            const panel = document.getElementById('image-preset-panel');
            if (!panel) return;
            const searchInput = document.getElementById('preset-search');
            const typeSelect = document.getElementById('preset-type');
            const categorySelect = document.getElementById('preset-category');
            const angleSelect = document.getElementById('preset-angle');
            const list = document.getElementById('preset-list');
            const selectedBox = document.getElementById('preset-selected');
            const message = document.getElementById('preset-message');
            const angleNames = { front: '正面', diag: '斜侧', side: '侧面', high: '俯视', low: '仰视' };
            const labels = { pose: '姿势', costume: '服装' };
            let lastItems = [];
            let categoriesLoaded = false;
            let timer = null;

            const setMessage = (text, state) => {
                message.className = 'config-message' + (state ? ' ' + state : '');
                message.textContent = text;
            };

            const renderSelected = () => {
                selectedBox.replaceChildren();
                let count = 0;
                ['pose', 'costume'].forEach(key => {
                    const item = imagePresetSelection[key];
                    if (!item) return;
                    count++;
                    const chip = document.createElement('span');
                    chip.className = 'preset-chip';
                    const text = document.createElement('span');
                    const angle = (key === 'pose' && angleSelect) ? angleNames[angleSelect.value] : '';
                    text.textContent = labels[key] + '：' + item.name + (angle ? `（${angle}）` : '');
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'preset-chip-remove';
                    remove.setAttribute('aria-label', '取消选择');
                    remove.textContent = '×';
                    remove.addEventListener('click', () => {
                        imagePresetSelection[key] = null;
                        renderSelected();
                        renderItems(lastItems);
                    });
                    chip.append(text, remove);
                    selectedBox.append(chip);
                });
                selectedBox.hidden = count === 0;
            };

            const renderItems = items => {
                lastItems = items;
                list.replaceChildren();
                if (!items.length) {
                    const empty = document.createElement('p');
                    empty.className = 'form-hint';
                    empty.textContent = '没有匹配的条目，换个关键词试试';
                    list.append(empty);
                    return;
                }
                const type = typeSelect.value;
                items.forEach(item => {
                    const picked = imagePresetSelection[type] && imagePresetSelection[type].id === item.id;
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'preset-item' + (picked ? ' active' : '');
                    const name = document.createElement('span');
                    name.className = 'preset-item-name';
                    name.textContent = item.name;
                    const meta = document.createElement('span');
                    meta.className = 'preset-item-meta';
                    meta.textContent = (item.category ? item.category + ' · ' : '') + item.tags;
                    button.append(name, meta);
                    button.addEventListener('click', () => {
                        const key = typeSelect.value;
                        if (imagePresetSelection[key] && imagePresetSelection[key].id === item.id) {
                            imagePresetSelection[key] = null;
                        } else {
                            imagePresetSelection[key] = { id: item.id, name: item.name };
                        }
                        renderSelected();
                        renderItems(lastItems);
                    });
                    list.append(button);
                });
            };

            const loadPresets = async () => {
                setMessage('正在加载...');
                try {
                    const data = await apiPost({
                        action: 'get_image_presets',
                        type: typeSelect.value,
                        q: searchInput.value.trim(),
                        category: categorySelect.value,
                        limit: 30
                    });
                    if (!categoriesLoaded) {
                        categoriesLoaded = true;
                        (data.categories || []).forEach(name => {
                            const option = document.createElement('option');
                            option.value = name;
                            option.textContent = name;
                            categorySelect.append(option);
                        });
                    }
                    renderItems(data.items || []);
                    setMessage('共 ' + (data.items || []).length + ' 条，点击条目选择');
                } catch (error) {
                    setMessage(error.message, 'error');
                }
            };

            panel.addEventListener('toggle', () => {
                if (panel.open && lastItems.length === 0) loadPresets();
            });
            typeSelect.addEventListener('change', () => {
                categorySelect.value = '';
                loadPresets();
            });
            categorySelect.addEventListener('change', loadPresets);
            searchInput.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(loadPresets, 300);
            });
            if (angleSelect) angleSelect.addEventListener('change', () => {
                renderSelected();
            });
            renderSelected();
        }

        // 范例解析：各生成 tab 内嵌面板；图片 tab 支持直接上传参考图交给识图模型
        function bindSamplePanels() {
            document.querySelectorAll('details.sample-panel[data-type]').forEach(panel => {
                const type = panel.dataset.type;
                const button = panel.querySelector('.sample-parse-btn');
                const textarea = panel.querySelector('.sample-textarea');
                const textFileInput = panel.querySelector('.sample-file');
                const imageFileInput = panel.querySelector('.sample-image-file');
                const preview = panel.querySelector('.sample-preview');
                const message = panel.querySelector('.sample-message');
                const resultBox = panel.querySelector('.sample-result');
                let imageData = '';

                const setMessage = (text, state) => {
                    message.className = 'config-message sample-message' + (state ? ' ' + state : '');
                    message.textContent = text;
                };

                if (textFileInput) {
                    textFileInput.addEventListener('change', () => {
                        const file = textFileInput.files && textFileInput.files[0];
                        if (!file) return;
                        if (file.size > 200 * 1024) {
                            setMessage('文件过大（最多 200KB），请直接粘贴文字内容', 'error');
                            return;
                        }
                        const reader = new FileReader();
                        reader.onload = () => {
                            textarea.value = String(reader.result || '').slice(0, 4000);
                            setMessage('已读取：' + file.name);
                        };
                        reader.readAsText(file, 'UTF-8');
                    });
                }

                if (imageFileInput) {
                    imageFileInput.addEventListener('change', async () => {
                        const file = imageFileInput.files && imageFileInput.files[0];
                        imageData = '';
                        preview.hidden = true;
                        if (!file) return;
                        if (file.size > 10 * 1024 * 1024) {
                            setMessage('图片过大（最多 10MB），请压缩后再上传', 'error');
                            imageFileInput.value = '';
                            return;
                        }
                        try {
                            imageData = await fileToDataUrl(file, 768);
                            preview.src = imageData;
                            preview.hidden = false;
                            setMessage('已选择参考图：' + file.name);
                        } catch (error) {
                            setMessage(error.message, 'error');
                            imageFileInput.value = '';
                        }
                    });
                }

                button.addEventListener('click', async () => {
                    if (sending.parse) return;
                    const sample = textarea.value.trim();
                    if (!sample && !imageData) {
                        setMessage('请先上传参考图或粘贴范例内容', 'error');
                        return;
                    }
                    sending.parse = true;
                    button.disabled = true;
                    setMessage('正在解析...');
                    resultBox.hidden = false;
                    resultBox.textContent = '正在解析范例...';
                    try {
                        const payload = { type, sample };
                        if (imageData) payload.image_data = imageData;
                        const content = await streamSseAction('parse_example', payload, text => {
                            resultBox.textContent = text;
                        });
                        resultBox.textContent = content;
                        setMessage('解析完成，可复制其中的提示词到上方输入框', 'success');
                    } catch (error) {
                        resultBox.textContent = '❌ 出错了：' + error.message;
                        setMessage(error.message, 'error');
                        if (!imageData && localServiceKeys.includes(currentAIService)) {
                            showLocalAiModal('ollama');
                        }
                    } finally {
                        sending.parse = false;
                        button.disabled = false;
                    }
                });
            });
        }

        bindNovelReference();
        bindImagePresets();
        bindSamplePanels();

        // 页面就绪后尝试恢复上次的服务端会话
        restoreConversation();
