<?php

require_once __DIR__ . '/Repository.php';

class UserBlockRepository extends Repository
{
    public function block(int $blockerUserId, int $blockedUserId, string $blockType = 'interaction'): array
    {
        if ($blockerUserId <= 0 || $blockedUserId <= 0 || $blockerUserId === $blockedUserId) {
            throw new InvalidArgumentException('Nie mozna zablokowac tego profilu.', 422);
        }

        $blockType = in_array($blockType, ['interaction', 'full'], true) ? $blockType : 'interaction';
        $db = $this->database->connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                "INSERT INTO user_blocks (blocker_user_id, blocked_user_id, block_type)
                 VALUES (:blockerUserId, :blockedUserId, :blockType)
                 ON CONFLICT (blocker_user_id, blocked_user_id)
                 DO UPDATE SET block_type = EXCLUDED.block_type,
                               updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([
                ':blockerUserId' => $blockerUserId,
                ':blockedUserId' => $blockedUserId,
                ':blockType' => $blockType,
            ]);

            $deleteFollows = $db->prepare(
                'DELETE FROM user_follows
                 WHERE (follower_user_id = :blockerUserId AND followed_user_id = :blockedUserId)
                    OR (follower_user_id = :blockedUserId AND followed_user_id = :blockerUserId)'
            );
            $deleteFollows->execute([
                ':blockerUserId' => $blockerUserId,
                ':blockedUserId' => $blockedUserId,
            ]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $this->state($blockerUserId, $blockedUserId);
    }

    public function unblock(int $blockerUserId, int $blockedUserId): array
    {
        $stmt = $this->database->connect()->prepare(
            'DELETE FROM user_blocks
             WHERE blocker_user_id = :blockerUserId AND blocked_user_id = :blockedUserId'
        );
        $stmt->execute([
            ':blockerUserId' => $blockerUserId,
            ':blockedUserId' => $blockedUserId,
        ]);

        return $this->state($blockerUserId, $blockedUserId);
    }

    public function state(int $viewerUserId, int $targetUserId): array
    {
        return [
            'viewerBlocksTarget' => $this->blocks($viewerUserId, $targetUserId),
            'targetBlocksViewer' => $this->blocks($targetUserId, $viewerUserId),
        ];
    }

    public function blocks(int $blockerUserId, int $blockedUserId): bool
    {
        if ($blockerUserId <= 0 || $blockedUserId <= 0 || $blockerUserId === $blockedUserId) {
            return false;
        }

        $stmt = $this->database->connect()->prepare(
            'SELECT 1
             FROM user_blocks
             WHERE blocker_user_id = :blockerUserId AND blocked_user_id = :blockedUserId
             LIMIT 1'
        );
        $stmt->execute([
            ':blockerUserId' => $blockerUserId,
            ':blockedUserId' => $blockedUserId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function hasInteractionBlockBetween(int $ownerUserId, int $actorUserId): bool
    {
        if ($ownerUserId <= 0 || $actorUserId <= 0 || $ownerUserId === $actorUserId) {
            return false;
        }

        $stmt = $this->database->connect()->prepare(
            'SELECT 1
             FROM user_blocks
             WHERE (blocker_user_id = :ownerUserId AND blocked_user_id = :actorUserId)
                OR (blocker_user_id = :actorUserId AND blocked_user_id = :ownerUserId)
             LIMIT 1'
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':actorUserId' => $actorUserId,
        ]);

        return (bool)$stmt->fetchColumn();
    }
}
