<?php
/**
 * 配置文件改写：只更新 ai_services 下指定服务的字段
 *
 * 设计目标：
 * 1. 保留 config.php 中的注释与其他配置，不做整体重写；
 * 2. 定位失败或写入失败时明确返回错误，避免破坏配置文件；
 * 3. 采用临时文件 + rename 写入，Windows 下 rename 失败时降级覆盖。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * 配置文件绝对路径
 */
function configFilePath(): string {
    return dirname(__DIR__) . '/config.php';
}

/**
 * 转义 PHP 单引号字符串内容
 */
function escapePhpSingleQuoted(string $value): string {
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
}

/**
 * 从 $openPos（必须指向 '['）开始，找到匹配的数组结束位置
 *
 * @return array|null [起始位置, 结束位置]
 */
function findPhpArrayBounds(string $source, int $openPos): ?array {
    $length = strlen($source);
    $depth = 0;
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = $openPos; $i < $length; $i++) {
        $char = $source[$i];
        $next = ($i + 1 < $length) ? $source[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }
        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }
        if ($inSingle || $inDouble) {
            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($inSingle && $char === "'") {
                $inSingle = false;
            } elseif ($inDouble && $char === '"') {
                $inDouble = false;
            }
            continue;
        }

        if ($char === '/' && $next === '/') {
            $inLineComment = true;
            $i++;
            continue;
        }
        if ($char === '#') {
            $inLineComment = true;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $inBlockComment = true;
            $i++;
            continue;
        }
        if ($char === "'") {
            $inSingle = true;
            continue;
        }
        if ($char === '"') {
            $inDouble = true;
            continue;
        }
        if ($char === '[') {
            $depth++;
            continue;
        }
        if ($char === ']') {
            $depth--;
            if ($depth === 0) {
                return [$openPos, $i];
            }
        }
    }
    return null;
}

/**
 * 在数组块内替换指定字段；字段缺失时补在块尾
 */
function replacePhpArrayField(string $block, string $field, string $value): string {
    $escaped = escapePhpSingleQuoted($value);
    $quotedField = preg_quote($field, '/');
    $pattern = "/^([ \t]*)'" . $quotedField . "'[ \t]*=>[ \t]*'(?:[^'\\\\]|\\\\.)*'[ \t]*,?[ \t]*\r?$/m";
    $replacement = '${1}' . "'" . $field . "' => '" . $escaped . "',";
    if (preg_match($pattern, $block)) {
        $updated = preg_replace($pattern, $replacement, $block, 1);
        if (is_string($updated)) {
            return $updated;
        }
    }

    // 字段不存在：插入到块尾的 ']' 之前，缩进比 ']' 多 4 个空格
    if (preg_match("/\n([ \t]*)\]\s*$/", $block, $matches)) {
        $indent = $matches[1];
        $line = "\n" . $indent . "    '" . $field . "' => '" . $escaped . "',";
        $position = strrpos($block, "\n" . $matches[1] . ']');
        if ($position !== false) {
            return substr($block, 0, $position) . $line . substr($block, $position);
        }
    }
    return $block;
}

/**
 * 备份当前配置为 config.php.bak<YYYYMMDD><2 位序号>，例如 config.php.bak2026102901
 *
 * @return string 备份文件名；失败或当日序号用尽返回空字符串
 */
function backupConfigFile(string $path): string {
    $prefix = $path . '.bak' . date('Ymd');
    for ($index = 1; $index <= 99; $index++) {
        $candidate = $prefix . sprintf('%02d', $index);
        if (file_exists($candidate)) {
            continue;
        }
        return @copy($path, $candidate) ? basename($candidate) : '';
    }
    return '';
}

/**
 * 原子写入配置文件
 */
function writeConfigFileAtomically(string $path, string $content): bool {
    $directory = dirname($path);
    $temp = @tempnam($directory, 'pro_ai_config_');
    if ($temp === false) {
        return false;
    }
    if (@file_put_contents($temp, $content, LOCK_EX) === false) {
        @unlink($temp);
        return false;
    }
    if (is_file($path)) {
        @chmod($temp, fileperms($path) & 0777);
    }
    if (@rename($temp, $path)) {
        return true;
    }
    // Windows 下目标文件存在时 rename 可能失败，降级为直接覆盖
    $ok = @file_put_contents($path, $content, LOCK_EX) !== false;
    @unlink($temp);
    return $ok;
}

/**
 * 保存指定 AI 服务的可编辑字段
 *
 * @param array $fields 仅允许包含 api_key / api_base_url / default_model
 * @return array{success: bool, error: string, backup: string}
 */
function saveAiServiceConfig(string $serviceKey, array $fields): array {
    $path = configFilePath();
    if (!is_file($path)) {
        return ['success' => false, 'error' => '配置文件不存在'];
    }
    if (!is_writable($path)) {
        return ['success' => false, 'error' => '配置文件不可写，请检查文件权限'];
    }
    $source = @file_get_contents($path);
    if (!is_string($source)) {
        return ['success' => false, 'error' => '无法读取配置文件'];
    }

    if (!preg_match("/'ai_services'\s*=>/", $source, $match, PREG_OFFSET_CAPTURE)) {
        return ['success' => false, 'error' => '配置文件中未找到 ai_services'];
    }
    $searchFrom = $match[0][1] + strlen($match[0][0]);
    $bracketPos = strpos($source, '[', $searchFrom);
    if ($bracketPos === false) {
        return ['success' => false, 'error' => '配置文件中 ai_services 结构异常'];
    }
    $servicesBounds = findPhpArrayBounds($source, $bracketPos);
    if ($servicesBounds === null) {
        return ['success' => false, 'error' => '无法解析 ai_services 数组'];
    }

    $servicesBlock = substr($source, $servicesBounds[0], $servicesBounds[1] - $servicesBounds[0] + 1);
    if (!preg_match("/'" . preg_quote($serviceKey, '/') . "'\s*=>/", $servicesBlock, $serviceMatch, PREG_OFFSET_CAPTURE)) {
        return ['success' => false, 'error' => '配置文件中未找到该 AI 服务'];
    }
    $entrySearchFrom = $serviceMatch[0][1] + strlen($serviceMatch[0][0]);
    $entryBracket = strpos($servicesBlock, '[', $entrySearchFrom);
    if ($entryBracket === false) {
        return ['success' => false, 'error' => '配置文件中该服务结构异常'];
    }
    $entryBounds = findPhpArrayBounds($servicesBlock, $entryBracket);
    if ($entryBounds === null) {
        return ['success' => false, 'error' => '无法解析该服务配置'];
    }

    $entryBlock = substr($servicesBlock, $entryBounds[0], $entryBounds[1] - $entryBounds[0] + 1);
    foreach (['api_key', 'api_base_url', 'default_model'] as $field) {
        if (array_key_exists($field, $fields)) {
            $entryBlock = replacePhpArrayField($entryBlock, $field, (string)$fields[$field]);
        }
    }

    $newServicesBlock = substr($servicesBlock, 0, $entryBounds[0])
        . $entryBlock
        . substr($servicesBlock, $entryBounds[1] + 1);
    $newSource = substr($source, 0, $servicesBounds[0])
        . $newServicesBlock
        . substr($source, $servicesBounds[1] + 1);

    if ($newSource === $source) {
        return ['success' => true, 'error' => '', 'backup' => ''];
    }

    // 写入前先备份，便于追溯历史配置
    $backup = backupConfigFile($path);
    if ($backup === '') {
        return ['success' => false, 'error' => '配置备份失败，已取消写入（请检查目录写入权限或当日备份数量是否已达 99 份）'];
    }

    if (!writeConfigFileAtomically($path, $newSource)) {
        return ['success' => false, 'error' => '写入配置文件失败'];
    }
    return ['success' => true, 'error' => '', 'backup' => $backup];
}
