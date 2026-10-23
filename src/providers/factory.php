<?php
/**
 * Provider 工厂
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/openai_compatible.php';

class ProviderFactory {
    /**
     * 按服务配置创建 Provider；未知服务返回 null
     */
    public static function make(string $key, array $serviceConfig): Provider {
        // 目前所有服务均为 OpenAI 兼容协议，后续新增协议时在此扩展
        return new OpenAICompatibleProvider($key, $serviceConfig);
    }

    /**
     * 从全局配置读取服务并创建 Provider
     */
    public static function fromConfig(string $key): ?Provider {
        $serviceConfig = getAIConfig($key);
        if ($serviceConfig === null) {
            return null;
        }
        return self::make($key, $serviceConfig);
    }
}
