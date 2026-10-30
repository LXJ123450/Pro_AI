    <div class="container">
        <!-- AI选择器 -->
        <div class="ai-selector">
            <div class="ai-select-wrapper">
                <select id="ai-select" class="ai-select">
                    <option value="">加载中...</option>
                </select>
            </div>
            <button type="button" id="btn-ai-config" class="btn btn-small btn-ghost" title="配置当前 AI 服务的密钥与模型">⚙ 配置</button>
            <div class="ai-status">
                <span class="indicator"></span>
                <span id="ai-status-text">连接中...</span>
            </div>
        </div>

        <!-- AI服务配置面板 -->
        <div id="ai-config-panel" class="config-panel" hidden>
            <div class="config-panel-head">
                <h3 id="config-title">配置 AI 服务</h3>
                <button type="button" id="config-close" class="config-close" aria-label="关闭">×</button>
            </div>
            <p id="config-desc" class="config-desc"></p>
            <div class="form-group">
                <label for="config-base-url">接口地址（Base URL）</label>
                <input type="url" id="config-base-url" placeholder="https://api.example.com/v1">
            </div>
            <div class="form-group">
                <label for="config-model">默认模型</label>
                <input type="text" id="config-model" placeholder="例如 gpt-4o">
            </div>
            <div class="form-group">
                <label for="config-api-key">API Key</label>
                <input type="password" id="config-api-key" placeholder="留空表示不修改已保存的 Key" autocomplete="off">
                <small id="config-key-hint" class="config-hint"></small>
            </div>
            <div class="config-actions">
                <button type="button" id="config-save" class="btn btn-primary">保存配置</button>
                <button type="button" id="config-clear-key" class="btn btn-ghost">清除 Key</button>
                <span id="config-message" class="config-message"></span>
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
                        <label for="novel-chapters">章节数量</label>
                        <select id="novel-chapters">
                            <option value="1">1 章</option>
                            <option value="2">2 章</option>
                            <option value="3" selected>3 章</option>
                            <option value="4">4 章</option>
                            <option value="5">5 章</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="novel-reference">参考文本 / 已有正文（可选）</label>
                        <textarea id="novel-reference" placeholder="粘贴一段小说片段：选择「参考风格写文」会模仿它的文风与节奏；选择「直接续写」会接着这段的结尾往下写"></textarea>
                    </div>
                    <div class="sample-actions">
                        <input type="file" id="novel-reference-file" class="sample-file" accept=".txt,.md" aria-label="上传参考文本">
                        <select id="novel-reference-mode" class="novel-mode-select" aria-label="参考文本使用方式">
                            <option value="style" selected>参考风格写文</option>
                            <option value="continue">直接续写</option>
                        </select>
                        <span id="novel-reference-message" class="config-message"></span>
                    </div>
                    <div class="form-group">
                        <label for="novel-prompt">创作要求</label>
                        <textarea id="novel-prompt" placeholder="请描述你想要创作的小说内容、故事背景、人物设定等..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">开始创作</button>
                </form>
                <p class="form-hint">若提示额度受限，请点击右上角「⚙ 配置」，切换到智谱 GLM 或硅基流动的免费模型（需免费注册，页面内附地址）。</p>
                <details class="sample-panel" data-type="novel">
                    <summary>📎 有参考范例？让 AI 反推写作提示词与生成流程</summary>
                    <div class="form-group">
                        <label for="novel-sample">范例内容</label>
                        <textarea id="novel-sample" class="sample-textarea" placeholder="粘贴一段小说片段或故事梗概，AI 会分析其题材、人物、节奏，并给出可复现的创作提示词"></textarea>
                    </div>
                    <div class="sample-actions">
                        <input type="file" class="sample-file" accept=".txt,.md" aria-label="上传文本范例">
                        <button type="button" class="btn btn-small btn-ghost sample-parse-btn">解析范例</button>
                        <span class="config-message sample-message"></span>
                    </div>
                    <div class="sample-result" hidden></div>
                </details>
                <div class="loading" id="novel-loading">
                    <div class="spinner"></div>
                    <p>正在分阶段创作（大纲 → 分章 → 梗概），请稍候...</p>
                </div>
                <div id="novel-result" class="result-container" style="display: none;"></div>
            </div>

            <!-- 图片生成 -->
            <div id="image" class="tab-content">
                <?php
                $comfyUiOptions = is_array($config['image_api']['comfyui'] ?? null) ? $config['image_api']['comfyui'] : [];
                $loraChoices = is_array($comfyUiOptions['lora_options'] ?? null) ? $comfyUiOptions['lora_options'] : [];
                $defaultLoraName = (string)($comfyUiOptions['lora'] ?? '');
                $defaultLoraStrength = (float)($comfyUiOptions['lora_weight'] ?? 0.7);
                ?>
                <form id="image-form">
                    <div class="form-group">
                        <label for="image-prompt">图片描述</label>
                        <textarea id="image-prompt" placeholder="请详细描述你想要生成的图片内容，例如：一个美丽的城堡在山上，日出，油画风格..."></textarea>
                    </div>
                    <?php if ($loraChoices !== []): ?>
                    <div class="lora-row">
                        <div class="form-group">
                            <label for="image-lora">画风 / 角色 LoRA</label>
                            <select id="image-lora">
                                <option value="none"<?php echo $defaultLoraName === '' ? ' selected' : ''; ?>>不使用 LoRA</option>
                                <?php foreach ($loraChoices as $loraFile => $loraLabel): ?>
                                <option value="<?php echo htmlspecialchars((string)$loraFile, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $loraFile === $defaultLoraName ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$loraLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="image-lora-weight">LoRA 强度：<span id="image-lora-weight-value"><?php echo number_format($defaultLoraStrength, 2); ?></span></label>
                            <input type="range" id="image-lora-weight" min="0.1" max="1.2" step="0.05" value="<?php echo number_format($defaultLoraStrength, 2); ?>">
                        </div>
                    </div>
                    <p class="form-hint">LoRA 仅对本地 ComfyUI 生效；0.5~0.8 较稳，过高容易出现画面崩坏。</p>
                    <?php endif; ?>
                    <details class="sample-panel preset-panel" id="image-preset-panel">
                        <summary>🎭 姿势 / 服装提示词库（可选，自动合成英文 tag）</summary>
                        <div class="preset-toolbar">
                            <input type="text" id="preset-search" class="preset-search" placeholder="搜索姿势或服装，例如：站立 / 女仆装">
                            <select id="preset-type" aria-label="提示词库类型">
                                <option value="pose" selected>姿势</option>
                                <option value="costume">服装</option>
                            </select>
                            <select id="preset-category" aria-label="提示词库分类">
                                <option value="">全部分类</option>
                            </select>
                        </div>
                        <div class="preset-actions">
                            <label for="preset-angle">镜头角度</label>
                            <select id="preset-angle" aria-label="镜头角度">
                                <option value="">默认</option>
                                <option value="front">正面</option>
                                <option value="diag">斜侧</option>
                                <option value="side">侧面</option>
                                <option value="high">俯视</option>
                                <option value="low">仰视</option>
                            </select>
                            <span id="preset-message" class="config-message"></span>
                        </div>
                        <div id="preset-selected" class="preset-selected" hidden></div>
                        <div id="preset-list" class="preset-list"></div>
                    </details>
                    <div class="form-group">
                        <label for="image-aspect">画面比例（仅本地 ComfyUI 生效）</label>
                        <select id="image-aspect">
                            <option value="3:4" selected>3:4 竖版（512×640）</option>
                            <option value="1:1">1:1 方图（512×512）</option>
                            <option value="4:3">4:3 横版（640×512）</option>
                            <option value="9:16">9:16 长竖版（448×768）</option>
                            <option value="16:9">16:9 宽屏（768×448）</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">生成图片</button>
                </form>
                <p class="form-hint" id="comfy-status">正在检测本地图片服务...</p>
                <details class="sample-panel" data-type="image">
                    <summary>📎 有参考图？上传图片，让 AI 反推绘画提示词与生成流程</summary>
                    <div class="form-group">
                        <label for="image-sample-file">参考图（PNG / JPG / WEBP）</label>
                        <input type="file" id="image-sample-file" class="sample-image-file" accept="image/png,image/jpeg,image/webp" aria-label="上传参考图">
                        <img class="sample-preview" alt="参考图预览" hidden>
                    </div>
                    <div class="form-group">
                        <label for="image-sample">补充描述（可选）</label>
                        <textarea id="image-sample" class="sample-textarea" placeholder="可补充你想强调或改动的地方，例如：保留这个画风，但把背景换成夜晚的街道"></textarea>
                    </div>
                    <div class="sample-actions">
                        <button type="button" class="btn btn-small btn-ghost sample-parse-btn">解析参考图</button>
                        <span class="config-message sample-message"></span>
                    </div>
                    <div class="sample-result" hidden></div>
                </details>
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
                <p class="form-hint">视频生成使用智谱 CogVideoX-Flash 免费模型。首次使用请到
                    <a href="https://open.bigmodel.cn/" target="_blank" rel="noopener noreferrer">open.bigmodel.cn</a>
                    免费注册获取 API Key，然后点击右上角「⚙ 配置」填写。</p>
                <details class="sample-panel" data-type="video">
                    <summary>📎 有参考范例？让 AI 反推视频提示词与分镜流程</summary>
                    <div class="form-group">
                        <label for="video-sample">范例内容</label>
                        <textarea id="video-sample" class="sample-textarea" placeholder="粘贴一段视频分镜描述或镜头说明，AI 会分析镜头语言并给出可复现的生成提示词"></textarea>
                    </div>
                    <div class="sample-actions">
                        <input type="file" class="sample-file" accept=".txt,.md" aria-label="上传文本范例">
                        <button type="button" class="btn btn-small btn-ghost sample-parse-btn">解析范例</button>
                        <span class="config-message sample-message"></span>
                    </div>
                    <div class="sample-result" hidden></div>
                </details>
                <div class="loading" id="video-loading">
                    <div class="spinner"></div>
                    <p>正在提交任务，结果生成后会自动展示...</p>
                </div>
                <div id="video-result" class="result-container" style="display: none;"></div>
            </div>
        </div>
    </div>

    <!-- 本地 AI 服务未启动提示弹窗 -->
    <div id="local-ai-modal" class="modal-overlay" hidden>
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="local-ai-modal-title">
            <div class="modal-head">
                <h3 id="local-ai-modal-title">本地 AI 服务未启动</h3>
                <button type="button" id="local-ai-modal-close" class="config-close" aria-label="关闭">×</button>
            </div>
            <div id="local-ai-modal-body" class="modal-body"></div>
            <div class="modal-actions">
                <button type="button" id="local-ai-modal-retry" class="btn btn-primary">我已启动，重新检测</button>
                <button type="button" id="local-ai-modal-dismiss" class="btn btn-ghost">稍后再说</button>
                <span id="local-ai-modal-message" class="config-message"></span>
            </div>
        </div>
    </div>

    <footer>
        <p>Powered by Multiple AI Services • Built with ❤️ by AI</p>
    </footer>

