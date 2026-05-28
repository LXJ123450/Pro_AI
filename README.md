# 本地AI聊天网页

一个基于PHP的网页界面，用于与本地Ollama运行的DeepSeek R1 AI模型进行对话。

## 功能特性

- 🎨 美观现代化的聊天界面
- 🔌 自动检测Ollama服务连接状态
- ▶️ 网页端一键启动AI模型
- ⏹️ 网页端一键停止AI模型
- 💬 支持多行输入，按Enter发送，Shift+Enter换行
- 📝 支持代码块格式化显示
- 📱 响应式设计，支持移动端
- 🧹 一键清空对话功能

## 环境要求

- PHP 7.0 或更高版本
- PHP cURL扩展
- 本地已安装Ollama
- 已下载模型：`huihui_ai/deepseek-r1-abliterated:8b`

## 快速开始

### 1. 配置Ollama路径

打开 `index.php` 文件，修改顶部的Ollama路径配置：
```php
// Ollama安装路径（Windows）
$ollama_path = 'E:\\AI\\ollma\\ollama.exe';
```

请根据你的实际安装路径修改。如果模型还未下载，Ollama会自动下载。

### 2. 使用phpstudy部署（推荐）

你的phpstudy安装在 `D:\software\phpstudy\phpstudy_pro\COM`，已创建网站 `localhost:1597`：

1. 将项目文件（`index.php` 和 `README.md`）复制到phpstudy网站根目录
2. 在phpstudy中确认网站配置正确，端口设置为 `1597`
3. 确保phpstudy的PHP版本已启用cURL扩展和proc_open函数（默认已启用）
4. 启动phpstudy的Apache/Nginx服务

### 3. 打开浏览器访问

在浏览器中打开：
```
http://localhost:1597
```

### （替代方案）使用PHP内置服务器

如果你不想使用phpstudy，也可以直接使用PHP内置服务器：

在项目目录 `e:\Project\Pro_AI` 打开命令提示符，执行：

```bash
php -S localhost:8000
```

然后在浏览器中打开：
```
http://localhost:8000
```

## 配置说明

如果需要修改Ollama地址或模型名称，可以编辑 `index.php` 文件顶部：

```php
// Ollama API配置
$ollama_host = 'http://127.0.0.1:11434';
$model_name = 'huihui_ai/deepseek-r1-abliterated:8b';
```

## 使用说明

1. 页面加载后会自动检测Ollama服务状态
2. 如果Ollama未启动，点击"启动AI"按钮即可启动模型
3. 等待模型加载完成（首次启动需要等待下载和加载），状态显示"Ollama服务已连接"后就可以开始聊天了
4. 在输入框输入问题，按Enter发送
5. 使用完毕后，点击"停止AI"按钮可以停止Ollama进程
6. 点击"清空对话"按钮可以清除所有对话历史

## 故障排除

**问题：显示"Ollama服务未连接"**
- 解决方案：点击页面上的"启动AI"按钮启动模型
- 如果启动失败，请检查 `index.php` 中的 `$ollama_path` 配置是否正确

**问题：发送消息后返回错误"无法连接到Ollama服务"**
- 检查Ollama是否正在运行
- 确认Ollama默认端口11434没有被占用
- 检查防火墙设置

**问题：点击"启动AI"按钮后提示"无法启动进程"**
- 解决方案：检查 `index.php` 文件顶部的Ollama路径配置 `$ollama_path` 是否正确
- 确保路径中使用双反斜杠 `\\` 分隔目录
- 确保PHP启用了 `proc_open` 函数（大多数环境默认已启用）

**问题：PHP找不到命令**
- 解决方案：需要先安装PHP，并将PHP添加到系统环境变量PATH中
- 下载地址：https://windows.php.net/download/

## 项目结构

```
Pro_AI/
├── index.php          # 主程序（包含前端和后端）
└── README.md          # 说明文档
```

## 技术栈

- 后端：PHP
- 前端：原生JavaScript + CSS3
- AI后端：Ollama + DeepSeek R1

## 许可证

MIT