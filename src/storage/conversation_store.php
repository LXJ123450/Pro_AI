<?php
/**
 * 服务端会话存储：对话记录持久化 + 上下文裁剪
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/db.php';

/**
 * 上下文裁剪：只保留最近的消息，并限制总字符数
 *
 * 从最后一条往前收集（保留最新内容），总长度超限时丢弃更早的消息；
 * 若裁剪后首条为 assistant，则一并丢弃，避免上下文以助手发言开头。
 */
function trimConversationContext(array $messages, int $maxChars = 6000, int $maxMessages = 20): array {
    $kept = [];
    $chars = 0;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if (count($kept) >= $maxMessages) {
            break;
        }
        $message = $messages[$i];
        if (!is_array($message) || !isset($message['role'], $message['content'])) {
            continue;
        }
        $role = is_string($message['role']) ? $message['role'] : '';
        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }
        $content = is_string($message['content']) ? $message['content'] : '';
        if ($content === '') {
            continue;
        }
        $length = mb_strlen($content);
        if ($chars + $length > $maxChars) {
            if (!empty($kept)) {
                break;
            }
            // 单条最新消息就超过上限时保留尾部，保证用户当前输入不丢失
            $content = mb_substr($content, $length - $maxChars);
            $length = $maxChars;
        }
        array_unshift($kept, ['role' => $role, 'content' => $content]);
        $chars += $length;
    }
    while (!empty($kept) && $kept[0]['role'] !== 'user') {
        array_shift($kept);
    }
    return $kept;
}

/**
 * 构建本次请求的上下文：优先使用服务端历史，其次使用客户端消息
 *
 * @param array $messages 已通过 validateMessages 校验的消息（最后一条为 user）
 * @param ConversationStore|null $store
 * @param string $requestedId 客户端提交的会话 ID，可为空
 * @param string $conversationId 输出：本次使用的会话 ID（数据库不可用时保持为空）
 * @return array 裁剪后的上下文消息
 */
function buildChatContext(array $messages, $store, string $requestedId, string &$conversationId): array {
    $last = end($messages);
    $userContent = is_array($last) ? (string)($last['content'] ?? '') : '';

    if ($store !== null && $requestedId !== '') {
        $history = $store->history($requestedId);
        if (!empty($history)) {
            $history[] = ['role' => 'user', 'content' => $userContent];
            $conversationId = $requestedId;
            return trimConversationContext($history);
        }
    }

    if ($store !== null && $requestedId === '') {
        $conversationId = ConversationStore::newId();
    } else {
        $conversationId = $requestedId;
    }
    return trimConversationContext($messages);
}

class ConversationStore {
    /** @var PDO */
    private $pdo;
    /** @var bool */
    private $schemaReady = false;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * 数据库不可用时返回 null
     *
     * @return ConversationStore|null
     */
    public static function create() {
        $pdo = getDbConnection();
        return $pdo === null ? null : new self($pdo);
    }

    /**
     * 生成 32 位十六进制会话 ID
     */
    public static function newId(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * 懒创建表结构，避免额外的手工建表步骤
     */
    private function ensureSchema(): void {
        if ($this->schemaReady) {
            return;
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS conversations (' .
            'id CHAR(64) NOT NULL, ' .
            "service VARCHAR(32) NOT NULL DEFAULT '', " .
            'created_at DATETIME NOT NULL, ' .
            'updated_at DATETIME NOT NULL, ' .
            'PRIMARY KEY (id)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS conversation_messages (' .
            'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ' .
            'conversation_id CHAR(64) NOT NULL, ' .
            'role VARCHAR(16) NOT NULL, ' .
            'content MEDIUMTEXT NOT NULL, ' .
            'created_at DATETIME NOT NULL, ' .
            'PRIMARY KEY (id), ' .
            'KEY idx_conversation (conversation_id, id)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->schemaReady = true;
    }

    /**
     * 读取会话历史（按时间正序，最多 200 条）
     */
    public function history(string $conversationId): array {
        try {
            $this->ensureSchema();
            $stmt = $this->pdo->prepare(
                'SELECT role, content FROM conversation_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 200'
            );
            $stmt->execute([$conversationId]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        $rows = array_reverse(is_array($rows) ? $rows : []);
        $messages = [];
        foreach ($rows as $row) {
            $messages[] = ['role' => (string)$row['role'], 'content' => (string)$row['content']];
        }
        return $messages;
    }

    /**
     * 保存一轮对话（用户消息 + 助手回复）
     */
    public function saveTurn(string $conversationId, string $service, string $userContent, string $assistantContent): bool {
        try {
            $this->ensureSchema();
            $now = date('Y-m-d H:i:s');
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                'INSERT INTO conversations (id, service, created_at, updated_at) VALUES (?, ?, ?, ?) ' .
                'ON DUPLICATE KEY UPDATE service = VALUES(service), updated_at = VALUES(updated_at)'
            );
            $stmt->execute([$conversationId, $service, $now, $now]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO conversation_messages (conversation_id, role, content, created_at) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$conversationId, 'user', $userContent, $now]);
            $stmt->execute([$conversationId, 'assistant', $assistantContent, $now]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return false;
        }
    }
}
