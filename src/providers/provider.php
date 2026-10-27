<?php
/**
 * Provider 抽象：屏蔽不同 AI 服务商的调用差异
 *
 * 说明：项目需兼容 PHP 7.3，故不使用属性类型声明。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../ai.php';

abstract class Provider {
    /** @var string 服务标识（对应 config.php 中 ai_services 的 key） */
    protected $key;
    /** @var array 服务配置 */
    protected $serviceConfig;
    /** @var array 请求控制配置（超时、重试、max_tokens） */
    protected $requestConfig;

    public function __construct(string $key, array $serviceConfig) {
        $this->key = $key;
        $this->serviceConfig = $serviceConfig;
        $this->requestConfig = getRequestConfig();
    }

    public function key(): string {
        return $this->key;
    }

    public function name(): string {
        return (string)($this->serviceConfig['name'] ?? $this->key);
    }

    public function defaultModel(): string {
        return (string)($this->serviceConfig['default_model'] ?? '');
    }

    /**
     * 服务是否已配置可用
     *
     * requires_key=false 的服务（本地 Ollama、免注册在线服务）无需密钥。
     */
    public function isConfigured(): bool {
        if (array_key_exists('requires_key', $this->serviceConfig)) {
            return !$this->serviceConfig['requires_key'] || !empty($this->serviceConfig['api_key']);
        }
        return $this->key === 'ollama' || !empty($this->serviceConfig['api_key']);
    }

    /**
     * 非流式对话
     *
     * @return array{success: bool, content: string, error: string, http_code: int}
     */
    abstract public function chat(array $messages, $model = null): array;

    /**
     * 流式对话，每收到一段增量文本回调一次 $onDelta
     *
     * @return array{success: bool, content: string, error: string, http_code: int}
     */
    abstract public function streamChat(array $messages, $model, callable $onDelta): array;
}
