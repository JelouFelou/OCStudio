<?php

require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/SocialFeatureSettingsRepository.php';

class AccountTypeRepository extends Repository
{
    public function all(): array
    {
        $this->ensureDefaults();

        $stmt = $this->database->connect()->query(
            'SELECT id, slug, name, is_admin, is_builtin, is_active, storage_quota_mb, created_at, updated_at
             FROM account_types
             ORDER BY is_builtin DESC, id ASC'
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $permissions = $this->permissionsByType();

        return array_map(function (array $row) use ($permissions): array {
            $id = (int)$row['id'];
            return [
                'id' => $id,
                'slug' => (string)$row['slug'],
                'name' => (string)$row['name'],
                'isAdmin' => $this->pgBool($row['is_admin'] ?? false),
                'isBuiltin' => $this->pgBool($row['is_builtin'] ?? false),
                'isActive' => $this->pgBool($row['is_active'] ?? true),
                'storageQuotaMb' => (int)($row['storage_quota_mb'] ?? 500),
                'permissions' => $permissions[$id] ?? $this->defaultPermissions(),
                'createdAt' => $row['created_at'] ?? null,
                'updatedAt' => $row['updated_at'] ?? null,
            ];
        }, $rows);
    }

    public function activeOptions(): array
    {
        return array_values(array_filter($this->all(), static fn(array $type): bool => !empty($type['isActive'])));
    }

    public function create(string $name, int $storageQuotaMb, bool $isAdmin, array $permissions): int
    {
        $this->ensureDefaults();
        $name = $this->cleanName($name);
        if ($name === '') {
            throw new InvalidArgumentException('Podaj nazwe typu konta.');
        }

        $slug = $this->uniqueSlug($this->slugify($name));
        $storageQuotaMb = $this->clampQuota($storageQuotaMb);

        $db = $this->database->connect();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO account_types (slug, name, is_admin, is_builtin, is_active, storage_quota_mb)
                 VALUES (:slug, :name, :isAdmin, FALSE, TRUE, :quota)
                 RETURNING id'
            );
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':isAdmin', $isAdmin, PDO::PARAM_BOOL);
            $stmt->bindValue(':quota', $storageQuotaMb, PDO::PARAM_INT);
            $stmt->execute();
            $id = (int)$stmt->fetchColumn();

            $this->savePermissionsForType($id, $permissions, $db);
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function update(int $id, string $name, int $storageQuotaMb, bool $isAdmin, bool $isActive, array $permissions): void
    {
        $this->ensureDefaults();
        if ($id < 0 || !$this->exists($id)) {
            throw new InvalidArgumentException('Nie znaleziono typu konta.');
        }

        $name = $this->cleanName($name);
        if ($name === '') {
            throw new InvalidArgumentException('Podaj nazwe typu konta.');
        }

        if ($this->isBuiltin($id)) {
            $isActive = true;
        }

        if ($this->isAdminType($id) && !$isAdmin && $this->adminUserCountForType($id) > 0 && $this->totalAdminUserCount() <= $this->adminUserCountForType($id)) {
            throw new RuntimeException('Nie mozna odebrac uprawnien admina ostatniemu typowi konta admina.');
        }

        $db = $this->database->connect();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'UPDATE account_types
                 SET name = :name,
                     is_admin = :isAdmin,
                     is_active = :isActive,
                     storage_quota_mb = :quota,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':isAdmin', $isAdmin, PDO::PARAM_BOOL);
            $stmt->bindValue(':isActive', $isActive, PDO::PARAM_BOOL);
            $stmt->bindValue(':quota', $this->clampQuota($storageQuotaMb), PDO::PARAM_INT);
            $stmt->execute();

            $this->savePermissionsForType($id, $permissions, $db);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function storageQuotaMbForAccountType(int $accountType): ?int
    {
        try {
            $this->ensureDefaults();
            $stmt = $this->database->connect()->prepare(
                'SELECT storage_quota_mb FROM account_types WHERE id = :id AND is_active = TRUE LIMIT 1'
            );
            $stmt->execute([':id' => $accountType]);
            $value = $stmt->fetchColumn();
            return $value === false ? null : max(1, (int)$value);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function isAdminType(int $accountType): bool
    {
        if ($accountType === 1) {
            return true;
        }

        try {
            $this->ensureDefaults();
            $stmt = $this->database->connect()->prepare(
                'SELECT is_admin FROM account_types WHERE id = :id AND is_active = TRUE LIMIT 1'
            );
            $stmt->execute([':id' => $accountType]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->pgBool($row['is_admin'] ?? false) : false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function nameForAccountType(int $accountType): string
    {
        try {
            $this->ensureDefaults();
            $stmt = $this->database->connect()->prepare('SELECT name FROM account_types WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $accountType]);
            $value = $stmt->fetchColumn();
            if ($value !== false && trim((string)$value) !== '') {
                return (string)$value;
            }
        } catch (Throwable $e) {
            // Fall back to legacy labels below.
        }

        return $accountType === 1 ? 'Admin' : 'User';
    }

    public function isFeatureAllowed(int $accountType, string $featureKey): bool
    {
        if (in_array($featureKey, ['auth.login.enabled', 'auth.offline_user_id'], true)) {
            return true;
        }

        try {
            $this->ensureDefaults();
            $stmt = $this->database->connect()->prepare(
                'SELECT enabled
                 FROM account_type_feature_permissions
                 WHERE account_type_id = :accountType AND feature_key = :featureKey
                 LIMIT 1'
            );
            $stmt->execute([
                ':accountType' => $accountType,
                ':featureKey' => $featureKey,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->pgBool($row['enabled'] ?? true) : true;
        } catch (Throwable $e) {
            return true;
        }
    }

    public function featureDefinitions(): array
    {
        return array_filter(
            SocialFeatureSettingsRepository::DEFINITIONS,
            static fn(array $definition, string $key): bool => $definition['type'] === 'boolean'
                && !in_array($key, ['auth.login.enabled', 'auth.offline_user_id'], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function permissionsByType(): array
    {
        $this->ensurePermissionDefaults();
        $stmt = $this->database->connect()->query(
            'SELECT account_type_id, feature_key, enabled
             FROM account_type_feature_permissions
             ORDER BY account_type_id ASC, feature_key ASC'
        );

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $typeId = (int)$row['account_type_id'];
            $rows[$typeId][(string)$row['feature_key']] = $this->pgBool($row['enabled'] ?? true);
        }

        return $rows;
    }

    private function savePermissionsForType(int $accountTypeId, array $permissions, PDO $db): void
    {
        $stmt = $db->prepare(
            'INSERT INTO account_type_feature_permissions (account_type_id, feature_key, enabled, updated_at)
             VALUES (:accountTypeId, :featureKey, :enabled, CURRENT_TIMESTAMP)
             ON CONFLICT (account_type_id, feature_key) DO UPDATE
             SET enabled = EXCLUDED.enabled,
                 updated_at = CURRENT_TIMESTAMP'
        );

        foreach (array_keys($this->featureDefinitions()) as $featureKey) {
            $enabled = !empty($permissions[$featureKey]);
            $stmt->bindValue(':accountTypeId', $accountTypeId, PDO::PARAM_INT);
            $stmt->bindValue(':featureKey', $featureKey);
            $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
            $stmt->execute();
        }
    }

    private function ensureDefaults(): void
    {
        $db = $this->database->connect();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS account_types (
                id SERIAL PRIMARY KEY,
                slug VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(80) NOT NULL,
                is_admin BOOLEAN NOT NULL DEFAULT FALSE,
                is_builtin BOOLEAN NOT NULL DEFAULT FALSE,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                storage_quota_mb INTEGER NOT NULL DEFAULT 500,
                created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $stmt = $db->prepare(
            'INSERT INTO account_types (id, slug, name, is_admin, is_builtin, is_active, storage_quota_mb)
             VALUES (:id, :slug, :name, :isAdmin, TRUE, TRUE, :quota)
             ON CONFLICT (id) DO UPDATE
             SET slug = EXCLUDED.slug,
                 name = EXCLUDED.name,
                 is_admin = EXCLUDED.is_admin,
                 is_builtin = TRUE,
                 is_active = TRUE,
                 updated_at = CURRENT_TIMESTAMP'
        );

        foreach ([[0, 'user', 'User', false, 500], [1, 'admin', 'Admin', true, 500]] as $type) {
            [$id, $slug, $name, $isAdmin, $quota] = $type;
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':isAdmin', $isAdmin, PDO::PARAM_BOOL);
            $stmt->bindValue(':quota', $quota, PDO::PARAM_INT);
            $stmt->execute();
        }

        $db->exec("SELECT setval(pg_get_serial_sequence('account_types', 'id'), GREATEST((SELECT MAX(id) FROM account_types), 1))");

        $this->ensurePermissionDefaults();
    }

    private function ensurePermissionDefaults(): void
    {
        $db = $this->database->connect();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS account_type_feature_permissions (
                account_type_id INTEGER NOT NULL REFERENCES account_types(id) ON DELETE CASCADE,
                feature_key VARCHAR(80) NOT NULL,
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (account_type_id, feature_key)
            )"
        );

        $stmt = $db->prepare(
            'INSERT INTO account_type_feature_permissions (account_type_id, feature_key, enabled)
             VALUES (:accountTypeId, :featureKey, TRUE)
             ON CONFLICT (account_type_id, feature_key) DO NOTHING'
        );

        $typeIds = $db->query('SELECT id FROM account_types')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($typeIds as $typeId) {
            foreach (array_keys($this->featureDefinitions()) as $featureKey) {
                $stmt->bindValue(':accountTypeId', (int)$typeId, PDO::PARAM_INT);
                $stmt->bindValue(':featureKey', $featureKey);
                $stmt->execute();
            }
        }
    }

    private function defaultPermissions(): array
    {
        return array_fill_keys(array_keys($this->featureDefinitions()), true);
    }

    private function exists(int $id): bool
    {
        $stmt = $this->database->connect()->prepare('SELECT 1 FROM account_types WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    private function isBuiltin(int $id): bool
    {
        $stmt = $this->database->connect()->prepare('SELECT is_builtin FROM account_types WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->pgBool($row['is_builtin'] ?? false) : false;
    }

    private function adminUserCountForType(int $id): int
    {
        $stmt = $this->database->connect()->prepare('SELECT COUNT(*) FROM users WHERE account_type = :id');
        $stmt->execute([':id' => $id]);
        return (int)$stmt->fetchColumn();
    }

    private function totalAdminUserCount(): int
    {
        $stmt = $this->database->connect()->query(
            'SELECT COUNT(*)
             FROM users u
             LEFT JOIN account_types at ON at.id = u.account_type
             WHERE COALESCE(at.is_admin, u.account_type = 1) = TRUE'
        );
        return (int)$stmt->fetchColumn();
    }

    private function cleanName(string $name): string
    {
        return mb_substr(trim($name), 0, 80);
    }

    private function clampQuota(int $quota): int
    {
        return max(1, min(1048576, $quota));
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? '', '-'));
        return $slug !== '' ? mb_substr($slug, 0, 64) : 'account-type';
    }

    private function uniqueSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $index = 2;
        $stmt = $this->database->connect()->prepare('SELECT 1 FROM account_types WHERE slug = :slug');
        while (true) {
            $stmt->execute([':slug' => $slug]);
            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $suffix = '-' . $index++;
            $slug = mb_substr($baseSlug, 0, 64 - mb_strlen($suffix)) . $suffix;
        }
    }

    private function pgBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
