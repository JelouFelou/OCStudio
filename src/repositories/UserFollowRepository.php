<?php

require_once __DIR__ . '/Repository.php';

class UserFollowRepository extends Repository
{
    public function toggle(int $followerUserId, int $followedUserId): array
    {
        if ($followerUserId <= 0 || $followedUserId <= 0 || $followerUserId === $followedUserId) {
            throw new InvalidArgumentException('Nie mozna obserwowac tego profilu.', 422);
        }

        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $existing = $db->prepare(
                'SELECT 1
                 FROM user_follows
                 WHERE follower_user_id = :followerUserId AND followed_user_id = :followedUserId
                 FOR UPDATE'
            );
            $existing->execute([
                ':followerUserId' => $followerUserId,
                ':followedUserId' => $followedUserId,
            ]);

            if ($existing->fetchColumn()) {
                $delete = $db->prepare(
                    'DELETE FROM user_follows
                     WHERE follower_user_id = :followerUserId AND followed_user_id = :followedUserId'
                );
                $delete->execute([
                    ':followerUserId' => $followerUserId,
                    ':followedUserId' => $followedUserId,
                ]);
                $following = false;
            } else {
                $insert = $db->prepare(
                    'INSERT INTO user_follows (follower_user_id, followed_user_id)
                     VALUES (:followerUserId, :followedUserId)
                     ON CONFLICT DO NOTHING'
                );
                $insert->execute([
                    ':followerUserId' => $followerUserId,
                    ':followedUserId' => $followedUserId,
                ]);
                $following = true;
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'following' => $following,
            'followerCount' => $this->followerCount($followedUserId),
            'followingCount' => $this->followingCount($followerUserId),
        ];
    }

    public function isFollowing(int $followerUserId, int $followedUserId): bool
    {
        if ($followerUserId <= 0 || $followedUserId <= 0 || $followerUserId === $followedUserId) {
            return false;
        }

        $stmt = $this->database->connect()->prepare(
            'SELECT 1
             FROM user_follows
             WHERE follower_user_id = :followerUserId AND followed_user_id = :followedUserId
             LIMIT 1'
        );
        $stmt->execute([
            ':followerUserId' => $followerUserId,
            ':followedUserId' => $followedUserId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    public function followerCount(int $userId): int
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT COUNT(*) FROM user_follows WHERE followed_user_id = :userId'
        );
        $stmt->execute([':userId' => $userId]);

        return (int)$stmt->fetchColumn();
    }

    public function followingCount(int $userId): int
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT COUNT(*) FROM user_follows WHERE follower_user_id = :userId'
        );
        $stmt->execute([':userId' => $userId]);

        return (int)$stmt->fetchColumn();
    }

    public function followerUserIds(int $followedUserId, int $limit = 500): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT follower_user_id
             FROM user_follows
             WHERE followed_user_id = :followedUserId
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':followedUserId', $followedUserId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, min(2000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
