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
                        <label for="novel-prompt">创作要求</label>
                        <textarea id="novel-prompt" placeholder="请描述你想要创作的小说内容、故事背景、人物设定等..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">开始创作</button>
                </form>
                <div class="loading" id="novel-loading">
                    <div class="spinner"></div>
                    <p>正在分阶段创作（大纲 → 分章 → 梗概），请稍候...</p>
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
                    <p>正在提交任务，结果生成后会自动展示...</p>
                </div>
                <div id="video-result" class="result-container" style="display: none;"></div>
            </div>
        </div>
    </div>

    <footer>
        <p>Powered by Multiple AI Services • Built with ❤️ by AI</p>
    </footer>

