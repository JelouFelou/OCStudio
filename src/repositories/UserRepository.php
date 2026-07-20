<?php

require_once 'Repository.php';
require_once __DIR__.'/../models/User.php';
require_once __DIR__ . '/AccountTypeRepository.php';

class UsersRepository extends Repository {

    public function getUsers(): ?array 
    {
        $query = $this->database->connect()->prepare(
            "
            SELECT * FROM users;
            "
        );
        $query->execute();

        $users = $query->fetchAll(PDO::FETCH_ASSOC);
        return $users;
    }

  public function getUserByEmail(string $email) {
        $query = $this->database->connect()->prepare(
            "
            SELECT u.*, COALESCE(at.is_admin, u.account_type = 1) AS account_type_is_admin
            FROM users u
            LEFT JOIN account_types at ON at.id = u.account_type
            WHERE u.email = :email
            "
        );
        $query->bindParam(':email', $email);
        $query->execute();

        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        return $this->hydrate($user);
    }

    public function getUserByUsername(string $username) {
        $query = $this->database->connect()->prepare(
            "
            SELECT u.*, COALESCE(at.is_admin, u.account_type = 1) AS account_type_is_admin
            FROM users u
            LEFT JOIN account_types at ON at.id = u.account_type
            WHERE u.username = :username
            "
        );
        $query->bindParam(':username', $username);
        $query->execute();

        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        return $this->hydrate($user);
    }

    public function getUserById(int $id): ?User
    {
        $query = $this->database->connect()->prepare(
            'SELECT u.*, COALESCE(at.is_admin, u.account_type = 1) AS account_type_is_admin
             FROM users u
             LEFT JOIN account_types at ON at.id = u.account_type
             WHERE u.id = :id'
        );
        $query->bindParam(':id', $id, PDO::PARAM_INT);
        $query->execute();

        $user = $query->fetch(PDO::FETCH_ASSOC);
        return $user ? $this->hydrate($user) : null;
    }

    public function getPublicProfileByUsername(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $query = $this->database->connect()->prepare(
            "SELECT u.id, u.username, u.bio, u.created_at, ia.id AS avatar_image_asset_id, ia.filename AS avatar_filename
             FROM users u
             LEFT JOIN image_assets ia ON ia.id = u.avatar_image_asset_id AND ia.visibility = 'normal'
             WHERE LOWER(u.username) = LOWER(:username)
               AND u.is_active = TRUE
               AND u.deletion_scheduled_at IS NULL
             LIMIT 1"
        );
        $query->execute([':username' => $username]);
        $user = $query->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        return [
            'id' => (int)$user['id'],
            'username' => (string)($user['username'] ?? ''),
            'bio' => (string)($user['bio'] ?? ''),
            'createdAt' => $user['created_at'] ?? null,
            'avatarImageAssetId' => (int)($user['avatar_image_asset_id'] ?? 0),
            'avatarFilename' => (string)($user['avatar_filename'] ?? ''),
            'avatarUrl' => trim((string)($user['avatar_filename'] ?? '')) !== ''
                ? '/media/' . rawurlencode((string)$user['avatar_filename'])
                : '',
        ];
    }

    public function getPublicProfileById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $query = $this->database->connect()->prepare(
            "SELECT u.id, u.username, u.bio, u.created_at, ia.id AS avatar_image_asset_id, ia.filename AS avatar_filename
             FROM users u
             LEFT JOIN image_assets ia ON ia.id = u.avatar_image_asset_id AND ia.visibility = 'normal'
             WHERE u.id = :userId
               AND u.is_active = TRUE
               AND u.deletion_scheduled_at IS NULL
             LIMIT 1"
        );
        $query->execute([':userId' => $userId]);
        $user = $query->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        return [
            'id' => (int)$user['id'],
            'username' => (string)($user['username'] ?? ''),
            'bio' => (string)($user['bio'] ?? ''),
            'createdAt' => $user['created_at'] ?? null,
            'avatarImageAssetId' => (int)($user['avatar_image_asset_id'] ?? 0),
            'avatarFilename' => (string)($user['avatar_filename'] ?? ''),
            'avatarUrl' => trim((string)($user['avatar_filename'] ?? '')) !== ''
                ? '/media/' . rawurlencode((string)$user['avatar_filename'])
                : '',
        ];
    }

    public function searchPublicProfiles(string $query, int $viewerUserId, int $limit = 16, string $sort = 'desc'): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return $this->publicProfilesDirectory($viewerUserId, $limit, '', $sort);
        }

        return $this->publicProfilesDirectory($viewerUserId, $limit, $query, $sort);
    }

    public function publicProfilesDirectory(int $viewerUserId, int $limit = 24, string $query = '', string $sort = 'desc'): array
    {
        $query = trim($query);
        $limit = max(1, min(40, $limit));
        $queryClause = mb_strlen($query) >= 2 ? " AND LOWER(COALESCE(u.username, '')) LIKE :query" : '';
        $sort = in_array($sort, ['asc', 'desc', 'random'], true) ? $sort : 'desc';
        $orderClause = match ($sort) {
            'asc' => 'publication_count ASC, u.username ASC',
            'random' => 'RANDOM()',
            default => 'publication_count DESC, u.username ASC',
        };
        $stmt = $this->database->connect()->prepare(
            "SELECT u.id,
                    u.username,
                    u.bio,
                    u.created_at,
                    ia.id AS avatar_image_asset_id,
                    ia.filename AS avatar_filename,
                    COUNT(p.id) AS publication_count
             FROM users u
             LEFT JOIN image_assets ia ON ia.id = u.avatar_image_asset_id AND ia.visibility = 'normal'
             LEFT JOIN publications p ON p.owner_user_id = u.id
                AND p.status = 'published'
                AND p.moderation_state = 'visible'
             WHERE u.is_active = TRUE
               AND u.deletion_scheduled_at IS NULL
               AND COALESCE(u.promote_public_profile, TRUE) = TRUE
               {$queryClause}
             GROUP BY u.id, ia.id, ia.filename
             HAVING COUNT(p.id) > 0
             ORDER BY {$orderClause}
             LIMIT {$limit}"
        );
        $params = [];
        if (mb_strlen($query) >= 2) {
            $params[':query'] = '%' . mb_strtolower($query) . '%';
        }
        $stmt->execute($params);

        return array_map(static function (array $row) use ($viewerUserId): array {
            return [
                'id' => (int)$row['id'],
                'username' => (string)($row['username'] ?? ''),
                'bio' => (string)($row['bio'] ?? ''),
                'createdAt' => $row['created_at'] ?? null,
                'avatarImageAssetId' => (int)($row['avatar_image_asset_id'] ?? 0),
                'avatarFilename' => (string)($row['avatar_filename'] ?? ''),
                'avatarUrl' => trim((string)($row['avatar_filename'] ?? '')) !== ''
                    ? '/media/' . rawurlencode((string)$row['avatar_filename'])
                    : '',
                'publicationCount' => (int)($row['publication_count'] ?? 0),
                'isOwn' => (int)$row['id'] === $viewerUserId,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getCommunitySettings(int $userId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT promote_public_profile, copy_attribution_enabled FROM users WHERE id = :userId LIMIT 1'
        );
        $stmt->execute([':userId' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'promotePublicProfile' => $row ? $this->pgBool($row['promote_public_profile'] ?? true) : true,
            'copyAttributionEnabled' => $row ? $this->pgBool($row['copy_attribution_enabled'] ?? true) : true,
        ];
    }

    public function setPromotePublicProfile(int $userId, bool $enabled): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE users SET promote_public_profile = :enabled WHERE id = :userId'
        );
        $stmt->execute([
            ':enabled' => $enabled ? 'true' : 'false',
            ':userId' => $userId,
        ]);
    }

    public function setCopyAttributionEnabled(int $userId, bool $enabled): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE users SET copy_attribution_enabled = :enabled WHERE id = :userId'
        );
        $stmt->execute([
            ':enabled' => $enabled ? 'true' : 'false',
            ':userId' => $userId,
        ]);
    }

    public function createUser(
        string $email,
        string $hashedPassword,
        string $firstname,
        string $lastname,
        string $username,
        string $bio = '',
        string $locale = 'pl',
        int $accountType = 0
    ): int {
        $query = $this->database->connect()->prepare(
            "
            INSERT INTO users (firstname, lastname, email, password, username, bio, locale, account_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id;
            "
        );
        $query->execute([
            $firstname,
            $lastname,
            $email, 
            $hashedPassword,
            $username,
            $bio,
            $locale,
            $accountType
        ]);

        return (int)$query->fetchColumn();
    }

    public function countUsers(): int
    {
        $query = $this->database->connect()->query('SELECT COUNT(*) FROM users');
        return (int)$query->fetchColumn();
    }

    public function activeUserOptions(): array
    {
        $query = $this->database->connect()->query(
            "SELECT u.id, u.email, u.username, u.firstname, u.lastname, u.account_type,
                    COALESCE(at.name, CASE WHEN u.account_type = 1 THEN 'Admin' ELSE 'User' END) AS account_type_name,
                    COALESCE(at.is_admin, u.account_type = 1) AS account_type_is_admin
             FROM users u
             LEFT JOIN account_types at ON at.id = u.account_type
             WHERE u.is_active = TRUE
               AND u.deletion_scheduled_at IS NULL
             ORDER BY u.id ASC"
        );

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setLocale(int $userId, string $locale): void
    {
        $query = $this->database->connect()->prepare(
            'UPDATE users SET locale = :locale WHERE id = :userId'
        );
        $query->execute([
            ':locale' => $locale,
            ':userId' => $userId,
        ]);
    }

    public function updateBio(int $userId, string $bio): void
    {
        $bio = mb_substr(trim($bio), 0, 1200);
        $query = $this->database->connect()->prepare(
            'UPDATE users SET bio = :bio WHERE id = :userId'
        );
        $query->execute([
            ':bio' => $bio,
            ':userId' => $userId,
        ]);
    }

    public function updateProfileAvatar(int $userId, ?int $imageAssetId): bool
    {
        if ($imageAssetId === null || $imageAssetId <= 0) {
            $stmt = $this->database->connect()->prepare(
                'UPDATE users SET avatar_image_asset_id = NULL WHERE id = :userId'
            );
            $stmt->execute([':userId' => $userId]);
            return true;
        }

        $stmt = $this->database->connect()->prepare(
            "UPDATE users
             SET avatar_image_asset_id = :imageAssetId
             WHERE id = :userId
               AND EXISTS (
                   SELECT 1
                   FROM image_assets ia
                   WHERE ia.id = :imageAssetId
                     AND ia.id_user = :userId
                     AND ia.visibility = 'normal'
               )"
        );
        $stmt->execute([
            ':imageAssetId' => $imageAssetId,
            ':userId' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function updatePasswordByEmail(string $email, string $hashedPassword): bool
    {
        $query = $this->database->connect()->prepare(
            '
            UPDATE users
            SET password = :password
            WHERE email = :email
            '
        );
        $query->bindValue(':password', $hashedPassword);
        $query->bindValue(':email', $email);
        $query->execute();

        return $query->rowCount() > 0;
    }

    public function getAdminUserRows(?string $search = null): array
    {
        $search = trim((string)$search);
        $params = [];
        $where = '';

        if ($search !== '') {
            $where = '
            WHERE CAST(u.id AS TEXT) = :exactSearch
               OR LOWER(u.email) LIKE :search
               OR LOWER(COALESCE(u.username, \'\')) LIKE :search
               OR LOWER(COALESCE(u.firstname, \'\')) LIKE :search
               OR LOWER(COALESCE(u.lastname, \'\')) LIKE :search
            ';
            $params[':exactSearch'] = $search;
            $params[':search'] = '%' . mb_strtolower($search) . '%';
        }

        $sql = "
            SELECT
                u.id,
                u.email,
                u.username,
                u.firstname,
                u.lastname,
                u.account_type,
                COALESCE(at.name, CASE WHEN u.account_type = 1 THEN 'Admin' ELSE 'User' END) AS account_type_name,
                COALESCE(at.is_admin, u.account_type = 1) AS account_type_is_admin,
                u.banned_until,
                u.ban_reason,
                u.deletion_scheduled_at,
                COUNT(c.id) AS character_count
            FROM users u
            LEFT JOIN account_types at ON at.id = u.account_type
            LEFT JOIN characters c ON c.id_user = u.id
            " . $where . "
            GROUP BY u.id, at.name, at.is_admin
            ORDER BY u.id ASC
        ";
        $query = $this->database->connect()->prepare($sql);
        $query->execute($params);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setBan(int $userId, ?string $bannedUntil, string $reason): void
    {
        $query = $this->database->connect()->prepare('
            UPDATE users
            SET banned_until = :bannedUntil, ban_reason = :reason
            WHERE id = :userId
        ');
        $query->bindValue(':bannedUntil', $bannedUntil);
        $query->bindValue(':reason', $reason);
        $query->bindValue(':userId', $userId, PDO::PARAM_INT);
        $query->execute();
    }

    public function clearBan(int $userId): void
    {
        $query = $this->database->connect()->prepare('
            UPDATE users
            SET banned_until = NULL, ban_reason = NULL
            WHERE id = :userId
        ');
        $query->bindValue(':userId', $userId, PDO::PARAM_INT);
        $query->execute();
    }

    public function scheduleDeletion(int $userId): void
    {
        $query = $this->database->connect()->prepare("
            UPDATE users
            SET deletion_scheduled_at = NOW() + INTERVAL '14 days'
            WHERE id = :userId
        ");
        $query->bindValue(':userId', $userId, PDO::PARAM_INT);
        $query->execute();
    }

    public function cancelDeletion(int $userId): void
    {
        $query = $this->database->connect()->prepare('
            UPDATE users
            SET deletion_scheduled_at = NULL
            WHERE id = :userId
        ');
        $query->bindValue(':userId', $userId, PDO::PARAM_INT);
        $query->execute();
    }

    public function getExpiredDeletionUserIds(): array
    {
        $query = $this->database->connect()->prepare('
            SELECT u.id FROM users u
            WHERE u.deletion_scheduled_at IS NOT NULL
              AND u.deletion_scheduled_at <= NOW()
              AND u.account_type <> 1
              AND COALESCE((SELECT at.is_admin FROM account_types at WHERE at.id = u.account_type), FALSE) = FALSE
        ');
        $query->execute();

        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function deleteUserById(int $userId): void
    {
        $query = $this->database->connect()->prepare(
            'DELETE FROM users u
             WHERE u.id = :userId
               AND u.account_type <> 1
               AND COALESCE((SELECT at.is_admin FROM account_types at WHERE at.id = u.account_type), FALSE) = FALSE'
        );
        $query->bindValue(':userId', $userId, PDO::PARAM_INT);
        $query->execute();
    }

    public function setAccountType(int $userId, int $accountType, int $currentAdminId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Nie znaleziono uzytkownika.');
        }

        $accountTypes = new AccountTypeRepository();
        if (!$accountTypes->storageQuotaMbForAccountType($accountType)) {
            throw new InvalidArgumentException('Nie znaleziono typu konta.');
        }

        $currentUser = $this->getUserById($userId);
        if (!$currentUser) {
            throw new InvalidArgumentException('Nie znaleziono uzytkownika.');
        }

        if ($userId === $currentAdminId && !$accountTypes->isAdminType($accountType)) {
            throw new RuntimeException('Nie mozna odebrac sobie dostepu do panelu admina.');
        }

        if ($currentUser->isAdmin() && !$accountTypes->isAdminType($accountType) && $this->adminUserCount() <= 1) {
            throw new RuntimeException('Nie mozna odebrac uprawnien ostatniemu administratorowi.');
        }

        $stmt = $this->database->connect()->prepare(
            'UPDATE users SET account_type = :accountType WHERE id = :userId'
        );
        $stmt->bindValue(':accountType', $accountType, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        if ($userId === $currentAdminId) {
            $_SESSION['account_type'] = $accountType;
            $_SESSION['account_type_name'] = $accountTypes->nameForAccountType($accountType);
        }
    }

    public function adminUserCount(): int
    {
        $stmt = $this->database->connect()->query(
            'SELECT COUNT(*)
             FROM users u
             LEFT JOIN account_types at ON at.id = u.account_type
             WHERE COALESCE(at.is_admin, u.account_type = 1) = TRUE'
        );

        return (int)$stmt->fetchColumn();
    }

    private function hydrate(array $user): User
    {
        return new User(
            $user['email'],
            $user['password'],
            $user['firstname'],
            $user['lastname'],
            $user['bio'] ?? '',
            $user['id'],
            $user['username'] ?? '',
            (int)($user['account_type'] ?? 0),
            $user['banned_until'] ?? null,
            $user['ban_reason'] ?? null,
            $user['deletion_scheduled_at'] ?? null,
            $user['locale'] ?? 'pl',
            array_key_exists('account_type_is_admin', $user)
                ? $this->pgBool($user['account_type_is_admin'])
                : null
        );
    }

    private function pgBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
