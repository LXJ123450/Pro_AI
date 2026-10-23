<?php
/**
 * 视频异步任务存储
 */

if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/db.php';

class VideoTaskStore {
    /** @var PDO */
    private $pdo;
    /** @var bool */
    private $schemaReady = false;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * @return VideoTaskStore|null
     */
    public static function create() {
        $pdo = getDbConnection();
        return $pdo === null ? null : new self($pdo);
    }

    public static function newId(): string {
        return bin2hex(random_bytes(16));
    }

    private function ensureSchema(): void {
        if ($this->schemaReady) {
            return;
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS video_tasks (' .
            'id CHAR(32) NOT NULL, ' .
            'prompt TEXT NOT NULL, ' .
            "status VARCHAR(20) NOT NULL DEFAULT 'pending', " .
            'remote_task_id VARCHAR(128) NULL, ' .
            'video_url VARCHAR(1024) NULL, ' .
            'error VARCHAR(512) NULL, ' .
            'created_at DATETIME NOT NULL, ' .
            'updated_at DATETIME NOT NULL, ' .
            'PRIMARY KEY (id)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->schemaReady = true;
    }

    public function createTask(string $id, string $prompt, string $status, string $remoteTaskId = ''): bool {
        try {
            $this->ensureSchema();
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'INSERT INTO video_tasks (id, prompt, status, remote_task_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $prompt, $status, $remoteTaskId !== '' ? $remoteTaskId : null, $now, $now]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 按白名单字段更新任务
     */
    public function updateTask(string $id, array $fields): bool {
        $allowed = ['status', 'remote_task_id', 'video_url', 'error'];
        $sets = [];
        $values = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $sets[] = $field . ' = ?';
            $values[] = $fields[$field];
        }
        if (empty($sets)) {
            return false;
        }
        $sets[] = 'updated_at = ?';
        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;
        try {
            $this->ensureSchema();
            $stmt = $this->pdo->prepare('UPDATE video_tasks SET ' . implode(', ', $sets) . ' WHERE id = ?');
            return $stmt->execute($values);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function find(string $id): ?array {
        try {
            $this->ensureSchema();
            $stmt = $this->pdo->prepare('SELECT * FROM video_tasks WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
