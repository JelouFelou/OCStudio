<?php

require_once 'Repository.php';
require_once __DIR__.'/../models/User.php';

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
            SELECT * FROM users WHERE email = :email
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
            SELECT * FROM users WHERE username = :username
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
        $query = $this->database->connect()->prepare('SELECT * FROM users WHERE id = :id');
        $query->bindParam(':id', $id, PDO::PARAM_INT);
        $query->execute();

        $user = $query->fetch(PDO::FETCH_ASSOC);
        return $user ? $this->hydrate($user) : null;
    }

    public function createUser(
        string $email,
        string $hashedPassword,
        string $firstname,
        string $lastname,
        string $username,
        string $bio = ''
    ) {
        $query = $this->database->connect()->prepare(
            "
            INSERT INTO users (firstname, lastname, email, password, username, bio)
            VALUES (?, ?, ?, ?, ?, ?);
            "
        );
        $query->execute([
            $firstname,
            $lastname,
            $email, 
            $hashedPassword,
            $username,
            $bio
        ]);
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

        $query = $this->database->connect()->prepare('
            SELECT
                u.id,
                u.email,
                u.username,
                u.firstname,
                u.lastname,
                u.account_type,
                u.banned_until,
                u.ban_reason,
                u.deletion_scheduled_at,
                COUNT(c.id) AS character_count
            FROM users u
            LEFT JOIN characters c ON c.id_user = u.id
            ' . $where . '
            GROUP BY u.id
            ORDER BY u.id ASC
        ');
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
            SELECT id FROM users
            WHERE deletion_scheduled_at IS NOT NULL
              AND deletion_scheduled_at <= NOW()
              AND account_type <> 1
        ');
        $query->execute();

        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function deleteUserById(int $userId): void
    {
        $query = $this->database->connect()->prepare('DELETE FROM users WHERE id = :userId AND account_type <> 1');
        $query->bindValue(':userId', $userId, PDO::PARAM_INT);
        $query->execute();
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
            $user['deletion_scheduled_at'] ?? null
        );
    }
}
