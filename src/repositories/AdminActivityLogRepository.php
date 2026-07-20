<?php

require_once __DIR__ . '/Repository.php';

class AdminActivityLogRepository extends Repository
{
    public function log(
        int $adminUserId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $details = null,
        ?string $ipAddress = null
    ): void {
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO admin_activity_logs (admin_user_id, action, target_type, target_id, details, ip_address)
             VALUES (:adminUserId, :action, :targetType, :targetId, :details, :ipAddress)'
        );
        $stmt->bindValue(':adminUserId', $adminUserId, PDO::PARAM_INT);
        $stmt->bindValue(':action', mb_substr($action, 0, 80));
        $stmt->bindValue(':targetType', $targetType !== null ? mb_substr($targetType, 0, 80) : null);
        $stmt->bindValue(':targetId', $targetId, $targetId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':details', $details);
        $stmt->bindValue(':ipAddress', $ipAddress !== null ? mb_substr($ipAddress, 0, 64) : null);
        $stmt->execute();
    }

    public function latest(int $limit = 12): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT l.*, u.username, u.email
             FROM admin_activity_logs l
             LEFT JOIN users u ON u.id = l.admin_user_id
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function latestAction(string $action): ?array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT l.*, u.username, u.email
             FROM admin_activity_logs l
             LEFT JOIN users u ON u.id = l.admin_user_id
             WHERE l.action = :action
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT 1'
        );
        $stmt->execute([':action' => $action]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
