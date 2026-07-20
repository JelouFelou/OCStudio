<?php

require_once __DIR__ . '/Repository.php';

class NotificationRepository extends Repository
{
    public function create(
        int $userId,
        ?int $actorUserId,
        string $type,
        string $title,
        string $body = '',
        string $targetType = '',
        ?int $targetId = null,
        string $url = '',
        array $metadata = []
    ): void {
        if ($userId <= 0) {
            return;
        }

        $stmt = $this->database->connect()->prepare(
            "INSERT INTO notifications (user_id, actor_user_id, type, title, body, target_type, target_id, url, metadata)
             VALUES (:userId, :actorUserId, :type, :title, :body, :targetType, :targetId, :url, CAST(:metadata AS jsonb))"
        );
        $stmt->execute([
            ':userId' => $userId,
            ':actorUserId' => $actorUserId,
            ':type' => mb_substr($type, 0, 40),
            ':title' => mb_substr($title, 0, 160),
            ':body' => mb_substr($body, 0, 500),
            ':targetType' => mb_substr($targetType, 0, 40),
            ':targetId' => $targetId,
            ':url' => mb_substr($url, 0, 500),
            ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function createForAdmins(
        ?int $actorUserId,
        string $type,
        string $title,
        string $body = '',
        string $targetType = '',
        ?int $targetId = null,
        string $url = '',
        array $metadata = []
    ): void {
        $stmt = $this->database->connect()->query(
            'SELECT u.id
             FROM users u
             LEFT JOIN account_types at ON at.id = u.account_type
             WHERE COALESCE(at.is_admin, u.account_type = 1) = TRUE'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            $this->create((int)$adminId, $actorUserId, $type, $title, $body, $targetType, $targetId, $url, $metadata);
        }
    }

    public function latestForUser(int $userId, int $limit = 20): array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT n.*, actor.username AS actor_username
             FROM notifications n
             LEFT JOIN users actor ON actor.id = n.actor_user_id
             WHERE n.user_id = :userId
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn(array $row) => $this->decorate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :userId AND read_at IS NULL'
        );
        $stmt->execute([':userId' => $userId]);

        return (int)$stmt->fetchColumn();
    }

    public function markRead(int $userId, int $notificationId): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE notifications
             SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE id = :id AND user_id = :userId'
        );
        $stmt->execute([':id' => $notificationId, ':userId' => $userId]);
    }

    public function markAllRead(int $userId): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE notifications
             SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE user_id = :userId AND read_at IS NULL'
        );
        $stmt->execute([':userId' => $userId]);
    }

    private function decorate(array $row): array
    {
        $metadata = json_decode((string)($row['metadata'] ?? '{}'), true);

        return [
            'id' => (int)$row['id'],
            'type' => (string)$row['type'],
            'title' => (string)$row['title'],
            'body' => (string)$row['body'],
            'targetType' => (string)$row['target_type'],
            'targetId' => isset($row['target_id']) ? (int)$row['target_id'] : null,
            'url' => (string)$row['url'],
            'metadata' => is_array($metadata) ? $metadata : [],
            'read' => $row['read_at'] !== null,
            'readAt' => $row['read_at'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'actorUsername' => $row['actor_username'] ?? null,
        ];
    }
}
