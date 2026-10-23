<?php
/**
 * 数据库连接（PDO 单例）
 *
 * 数据库未启用或连接失败时统一返回 null，调用方应降级处理，
 * 避免因数据库不可用导致页面或接口直接报错。
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

/**
 * @return PDO|null
 */
function getDbConnection() {
    static $pdo = null;
    static $resolved = false;
    if ($resolved) {
        return $pdo;
    }
    $resolved = true;

    global $config;
    $db = $config['database'] ?? null;
    if (!is_array($db) || empty($db['enabled'])) {
        return null;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string)($db['host'] ?? '127.0.0.1'),
        (int)($db['port'] ?? 3306),
        (string)($db['name'] ?? ''),
        (string)($db['charset'] ?? 'utf8mb4')
    );
    try {
        $pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
    } catch (Throwable $e) {
        $pdo = null;
    }
    return $pdo;
}
