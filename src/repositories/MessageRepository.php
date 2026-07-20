<?php

require_once __DIR__ . '/Repository.php';

class MessageRepository extends Repository
{
    public function listConversations(int $userId, int $limit = 30): array
    {
        $limit = max(1, min(80, $limit));
        $stmt = $this->database->connect()->prepare(
            "SELECT c.id,
                    c.uuid,
                    c.updated_at,
                    other_user.id AS other_user_id,
                    other_user.username AS other_username,
                    latest.id AS latest_message_id,
                    latest.body AS latest_body,
                    latest.sender_user_id AS latest_sender_user_id,
                    latest.created_at AS latest_created_at,
                    COUNT(unread.id) AS unread_count
             FROM conversations c
             JOIN conversation_participants self_cp
                ON self_cp.conversation_id = c.id
               AND self_cp.user_id = :userId
               AND self_cp.left_at IS NULL
             JOIN users other_user
                ON other_user.id = CASE
                    WHEN c.peer_low_user_id = :userId THEN c.peer_high_user_id
                    ELSE c.peer_low_user_id
                END
             LEFT JOIN LATERAL (
                SELECT m.id, m.body, m.sender_user_id, m.created_at
                FROM messages m
                WHERE m.conversation_id = c.id
                  AND m.deleted_at IS NULL
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
             ) latest ON TRUE
             LEFT JOIN messages unread
                ON unread.conversation_id = c.id
               AND unread.deleted_at IS NULL
               AND unread.sender_user_id <> :userId
               AND unread.id > COALESCE(self_cp.last_read_message_id, 0)
             WHERE other_user.is_active = TRUE
               AND other_user.deletion_scheduled_at IS NULL
             GROUP BY c.id,
                      c.uuid,
                      c.updated_at,
                      other_user.id,
                      other_user.username,
                      latest.id,
                      latest.body,
                      latest.sender_user_id,
                      latest.created_at
             ORDER BY COALESCE(latest.created_at, c.updated_at) DESC, c.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':userId' => $userId]);

        return array_map(fn(array $row): array => $this->decorateConversation($row, $userId), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function searchRecipients(int $userId, string $query, int $limit = 12): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $stmt = $this->database->connect()->prepare(
            "SELECT u.id, u.username, u.bio
             FROM users u
             WHERE u.id <> :userId
               AND u.is_active = TRUE
               AND u.deletion_scheduled_at IS NULL
               AND LOWER(COALESCE(u.username, '')) LIKE :query
               AND NOT EXISTS (
                    SELECT 1
                    FROM user_blocks b
                    WHERE (b.blocker_user_id = :userId AND b.blocked_user_id = u.id)
                       OR (b.blocker_user_id = u.id AND b.blocked_user_id = :userId)
               )
             ORDER BY u.username ASC
             LIMIT {$limit}"
        );
        $stmt->execute([
            ':userId' => $userId,
            ':query' => '%' . mb_strtolower($query) . '%',
        ]);

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'username' => (string)($row['username'] ?? ''),
                'bio' => mb_substr((string)($row['bio'] ?? ''), 0, 120),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findOrCreateDirectConversation(int $userId, int $otherUserId): array
    {
        if ($userId <= 0 || $otherUserId <= 0 || $userId === $otherUserId) {
            throw new InvalidArgumentException('Nie mozna rozpoczac tej rozmowy.', 422);
        }

        if (!$this->userCanReceiveMessages($otherUserId)) {
            throw new InvalidArgumentException('Uzytkownik nie zostal znaleziony.', 404);
        }

        if ($this->hasBlockBetween($userId, $otherUserId)) {
            throw new InvalidArgumentException('Nie mozna napisac do tego uzytkownika.', 403);
        }

        $lowId = min($userId, $otherUserId);
        $highId = max($userId, $otherUserId);
        $db = $this->database->connect();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                "INSERT INTO conversations (created_by_user_id, peer_low_user_id, peer_high_user_id)
                 VALUES (:createdBy, :lowId, :highId)
                 ON CONFLICT (peer_low_user_id, peer_high_user_id)
                 WHERE conversation_type = 'direct'
                 DO UPDATE SET updated_at = conversations.updated_at
                 RETURNING id, uuid, updated_at"
            );
            $stmt->execute([
                ':createdBy' => $userId,
                ':lowId' => $lowId,
                ':highId' => $highId,
            ]);
            $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$conversation) {
                throw new RuntimeException('Nie udalo sie utworzyc rozmowy.');
            }

            $participants = $db->prepare(
                "INSERT INTO conversation_participants (conversation_id, user_id, left_at)
                 VALUES (:conversationId, :userId, NULL)
                 ON CONFLICT (conversation_id, user_id)
                 DO UPDATE SET left_at = NULL"
            );
            $participants->execute([':conversationId' => (int)$conversation['id'], ':userId' => $userId]);
            $participants->execute([':conversationId' => (int)$conversation['id'], ':userId' => $otherUserId]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $this->conversationForUser($userId, (string)$conversation['uuid']) ?? [];
    }

    public function conversationForUser(int $userId, string $conversationUuid): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT c.id,
                    c.uuid,
                    c.updated_at,
                    other_user.id AS other_user_id,
                    other_user.username AS other_username,
                    latest.id AS latest_message_id,
                    latest.body AS latest_body,
                    latest.sender_user_id AS latest_sender_user_id,
                    latest.created_at AS latest_created_at,
                    0 AS unread_count
             FROM conversations c
             JOIN conversation_participants self_cp
                ON self_cp.conversation_id = c.id
               AND self_cp.user_id = :userId
               AND self_cp.left_at IS NULL
             JOIN users other_user
                ON other_user.id = CASE
                    WHEN c.peer_low_user_id = :userId THEN c.peer_high_user_id
                    ELSE c.peer_low_user_id
                END
             LEFT JOIN LATERAL (
                SELECT m.id, m.body, m.sender_user_id, m.created_at
                FROM messages m
                WHERE m.conversation_id = c.id
                  AND m.deleted_at IS NULL
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT 1
             ) latest ON TRUE
             WHERE c.uuid = CAST(:uuid AS uuid)
               AND other_user.is_active = TRUE
               AND other_user.deletion_scheduled_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            ':userId' => $userId,
            ':uuid' => $conversationUuid,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decorateConversation($row, $userId) : null;
    }

    public function messagesForUser(int $userId, string $conversationUuid, int $afterId = 0, int $limit = 80): array
    {
        $conversationId = $this->conversationIdForUser($userId, $conversationUuid);
        if ($conversationId === null) {
            throw new InvalidArgumentException('Rozmowa nie zostala znaleziona.', 404);
        }

        $limit = max(1, min(120, $limit));
        $stmt = $this->database->connect()->prepare(
            "SELECT m.id, m.sender_user_id, sender.username AS sender_username, m.body, m.created_at
             FROM messages m
             LEFT JOIN users sender ON sender.id = m.sender_user_id
             WHERE m.conversation_id = :conversationId
               AND m.deleted_at IS NULL
               AND m.id > :afterId
             ORDER BY m.created_at ASC, m.id ASC
             LIMIT {$limit}"
        );
        $stmt->execute([
            ':conversationId' => $conversationId,
            ':afterId' => max(0, $afterId),
        ]);

        $messages = array_map(fn(array $row): array => $this->decorateMessage($row, $userId), $stmt->fetchAll(PDO::FETCH_ASSOC));
        $this->markRead($userId, $conversationId);

        return $messages;
    }

    public function sendMessage(int $userId, string $conversationUuid, string $body): array
    {
        $body = mb_substr(trim($body), 0, 2000);
        if ($body === '') {
            throw new InvalidArgumentException('Wiadomosc nie moze byc pusta.', 422);
        }

        $conversation = $this->conversationForUser($userId, $conversationUuid);
        if (!$conversation) {
            throw new InvalidArgumentException('Rozmowa nie zostala znaleziona.', 404);
        }

        $otherUserId = (int)$conversation['otherUser']['id'];
        if ($this->hasBlockBetween($userId, $otherUserId)) {
            throw new InvalidArgumentException('Nie mozna napisac do tego uzytkownika.', 403);
        }

        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                "INSERT INTO messages (conversation_id, sender_user_id, body)
                 VALUES (:conversationId, :senderUserId, :body)
                 RETURNING id, sender_user_id, body, created_at"
            );
            $stmt->execute([
                ':conversationId' => (int)$conversation['id'],
                ':senderUserId' => $userId,
                ':body' => $body,
            ]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);

            $db->prepare('UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute([':id' => (int)$conversation['id']]);
            $this->markRead($userId, (int)$conversation['id']);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if (!$message) {
            throw new RuntimeException('Nie udalo sie wyslac wiadomosci.');
        }

        $message['sender_username'] = $_SESSION['username'] ?? '';
        return $this->decorateMessage($message, $userId);
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT COUNT(m.id)
             FROM messages m
             JOIN conversation_participants cp ON cp.conversation_id = m.conversation_id
             WHERE cp.user_id = :userId
               AND cp.left_at IS NULL
               AND m.deleted_at IS NULL
               AND m.sender_user_id <> :userId
               AND m.id > COALESCE(cp.last_read_message_id, 0)"
        );
        $stmt->execute([':userId' => $userId]);

        return (int)$stmt->fetchColumn();
    }

    private function conversationIdForUser(int $userId, string $conversationUuid): ?int
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT c.id
             FROM conversations c
             JOIN conversation_participants cp ON cp.conversation_id = c.id
             WHERE c.uuid = CAST(:uuid AS uuid)
               AND cp.user_id = :userId
               AND cp.left_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            ':uuid' => $conversationUuid,
            ':userId' => $userId,
        ]);

        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function markRead(int $userId, int $conversationId): void
    {
        $stmt = $this->database->connect()->prepare(
            "UPDATE conversation_participants cp
             SET last_read_message_id = GREATEST(
                 COALESCE(cp.last_read_message_id, 0),
                 COALESCE((SELECT MAX(id) FROM messages WHERE conversation_id = :conversationId), 0)
             )
             WHERE cp.conversation_id = :conversationId
               AND cp.user_id = :userId"
        );
        $stmt->execute([
            ':conversationId' => $conversationId,
            ':userId' => $userId,
        ]);
    }

    private function userCanReceiveMessages(int $userId): bool
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT 1
             FROM users
             WHERE id = :userId
               AND is_active = TRUE
               AND deletion_scheduled_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([':userId' => $userId]);

        return (bool)$stmt->fetchColumn();
    }

    private function hasBlockBetween(int $userId, int $otherUserId): bool
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT 1
             FROM user_blocks
             WHERE (blocker_user_id = :userId AND blocked_user_id = :otherUserId)
                OR (blocker_user_id = :otherUserId AND blocked_user_id = :userId)
             LIMIT 1"
        );
        $stmt->execute([
            ':userId' => $userId,
            ':otherUserId' => $otherUserId,
        ]);

        return (bool)$stmt->fetchColumn();
    }

    private function decorateConversation(array $row, int $userId): array
    {
        return [
            'id' => (int)$row['id'],
            'uuid' => (string)$row['uuid'],
            'updatedAt' => $row['updated_at'] ?? null,
            'otherUser' => [
                'id' => (int)$row['other_user_id'],
                'username' => (string)($row['other_username'] ?? 'Uzytkownik'),
            ],
            'latestMessage' => isset($row['latest_message_id']) ? [
                'id' => (int)$row['latest_message_id'],
                'body' => (string)($row['latest_body'] ?? ''),
                'mine' => (int)($row['latest_sender_user_id'] ?? 0) === $userId,
                'createdAt' => $row['latest_created_at'] ?? null,
            ] : null,
            'unreadCount' => (int)($row['unread_count'] ?? 0),
        ];
    }

    private function decorateMessage(array $row, int $userId): array
    {
        return [
            'id' => (int)$row['id'],
            'senderUserId' => isset($row['sender_user_id']) ? (int)$row['sender_user_id'] : null,
            'senderUsername' => (string)($row['sender_username'] ?? 'Uzytkownik'),
            'body' => (string)($row['body'] ?? ''),
            'mine' => (int)($row['sender_user_id'] ?? 0) === $userId,
            'createdAt' => $row['created_at'] ?? null,
        ];
    }
}
