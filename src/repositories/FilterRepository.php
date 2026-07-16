<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/Filter.php';

class FilterRepository extends Repository
{
    public const MIN_TAGS = 5;

    public function getAvailableFilters(int $userId = 0): array
    {
        $stmt = $this->database->connect()->query(
            'SELECT * FROM filters WHERE is_active = TRUE ORDER BY label ASC, name ASC'
        );

        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function searchFilters(string $query, int $userId = 0, array $allowedBlockedFilterIds = []): array
    {
        $searchQuery = '%' . mb_strtolower(trim($query)) . '%';
        $allowedBlockedFilterIds = array_values(array_unique(array_filter(array_map('intval', $allowedBlockedFilterIds))));
        $blockedClause = '';
        if ($userId > 0) {
            $allowedSql = empty($allowedBlockedFilterIds) ? '0' : implode(',', $allowedBlockedFilterIds);
            $blockedClause = " AND NOT EXISTS (
                SELECT 1 FROM user_blocked_filters ubf
                WHERE ubf.id_user = :userId
                  AND ubf.id_filter = f.id
                  AND f.id NOT IN ({$allowedSql})
            )";
        }

        $stmt = $this->database->connect()->prepare(
            'SELECT DISTINCT f.*
             FROM filters f
             LEFT JOIN filter_aliases fa ON fa.id_filter = f.id
             WHERE f.is_active = TRUE
               AND (LOWER(f.name) LIKE :query
                    OR LOWER(f.slug) LIKE :query
                    OR LOWER(f.label) LIKE :query
                    OR LOWER(fa.alias) LIKE :query)' . $blockedClause . '
             ORDER BY f.label ASC, f.name ASC
             LIMIT 12'
        );
        $stmt->bindValue(':query', $searchQuery);
        if ($userId > 0) {
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getFilterById(int $id): ?Filter
    {
        $stmt = $this->database->connect()->prepare('SELECT * FROM filters WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function getFiltersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        $stmt = $this->database->connect()->query(
            'SELECT * FROM filters WHERE id IN (' . implode(',', $ids) . ') ORDER BY label ASC, name ASC'
        );
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getOrCreateFilter(string $name, int $userId = 0, bool $isPublic = true): int
    {
        $resolved = $this->resolveTags([$name]);
        return (int)($resolved[0]['id'] ?? 0);
    }

    public function resolveTags(array|string $tags): array
    {
        $tokens = is_array($tags) ? $tags : explode(',', $tags);
        $tokens = array_values(array_unique(array_filter(array_map(
            fn($tag) => trim((string)$tag),
            $tokens
        ))));

        $resolved = [];
        foreach ($tokens as $token) {
            $resolved[] = $this->resolveTag($token);
        }

        return $resolved;
    }

    public function validateMinimumTags(array|string $tags): array
    {
        $resolved = $this->resolveTags($tags);
        if (count($resolved) < self::MIN_TAGS) {
            throw new InvalidArgumentException('Podaj minimum 5 filtrow/tagow.', 422);
        }
        return $resolved;
    }

    public function replaceObjectFilters(string $objectType, int $objectId, array $filterIds): void
    {
        $filterIds = array_values(array_unique(array_filter(array_map('intval', $filterIds))));
        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $delete = $db->prepare('DELETE FROM content_filters WHERE object_type = :type AND object_id = :id');
            $delete->execute([':type' => $objectType, ':id' => $objectId]);

            $insert = $db->prepare(
                'INSERT INTO content_filters (object_type, object_id, id_filter)
                 VALUES (:type, :id, :filter)
                 ON CONFLICT (object_type, object_id, id_filter) DO NOTHING'
            );
            foreach ($filterIds as $filterId) {
                $insert->execute([':type' => $objectType, ':id' => $objectId, ':filter' => $filterId]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function getObjectFilters(string $objectType, int $objectId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.*
             FROM content_filters cf
             JOIN filters f ON f.id = cf.id_filter
             WHERE cf.object_type = :type AND cf.object_id = :id
             ORDER BY f.label ASC, f.name ASC'
        );
        $stmt->execute([':type' => $objectType, ':id' => $objectId]);

        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addCharacterFilter(int $characterId, int $filterId, bool $isInherited = false): void
    {
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO character_filters (id_character, id_filter, is_inherited)
             VALUES (:characterId, :filterId, :isInherited)
             ON CONFLICT (id_character, id_filter) DO UPDATE SET is_inherited = :isInherited'
        );
        $stmt->execute([
            ':characterId' => $characterId,
            ':filterId' => $filterId,
            ':isInherited' => $isInherited,
        ]);
    }

    public function removeCharacterFilter(int $characterId, int $filterId): void
    {
        $stmt = $this->database->connect()->prepare(
            'DELETE FROM character_filters WHERE id_character = :characterId AND id_filter = :filterId'
        );
        $stmt->execute([':characterId' => $characterId, ':filterId' => $filterId]);
    }

    public function getCharacterDirectFilters(int $characterId): array
    {
        return $this->getLegacyCharacterFilters($characterId, false);
    }

    public function getCharacterInheritedFilters(int $characterId): array
    {
        return $this->getLegacyCharacterFilters($characterId, true);
    }

    public function getAllCharacterFilters(int $characterId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.* FROM filters f
             JOIN character_filters cf ON f.id = cf.id_filter
             WHERE cf.id_character = :characterId
             UNION
             SELECT f.* FROM filters f
             JOIN content_filters cf2 ON f.id = cf2.id_filter
             WHERE cf2.object_type = :type AND cf2.object_id = :characterId
             ORDER BY label ASC, name ASC'
        );
        $stmt->execute([':characterId' => $characterId, ':type' => 'character']);
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addWorldFilter(int $worldId, int $filterId): void
    {
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO world_filters (id_world, id_filter)
             VALUES (:worldId, :filterId)
             ON CONFLICT (id_world, id_filter) DO NOTHING'
        );
        $stmt->execute([':worldId' => $worldId, ':filterId' => $filterId]);
    }

    public function getWorldFilters(int $worldId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.* FROM filters f
             JOIN world_filters wf ON f.id = wf.id_filter
             WHERE wf.id_world = :worldId
             UNION
             SELECT f.* FROM filters f
             JOIN content_filters cf ON f.id = cf.id_filter
             WHERE cf.object_type = :type AND cf.object_id = :worldId
             ORDER BY label ASC, name ASC'
        );
        $stmt->execute([':worldId' => $worldId, ':type' => 'world']);
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getWorldAndAncestorFilters(int $worldId, int $userId): array
    {
        $stmt = $this->database->connect()->prepare(
            "WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT id, parent_id FROM worlds WHERE id = :worldId AND id_user = :userId
                UNION ALL
                SELECT w.id, w.parent_id
                FROM worlds w
                JOIN ancestors a ON w.id = a.parent_id
                WHERE w.id_user = :userId
            )
            SELECT DISTINCT f.*
            FROM filters f
            JOIN (
                SELECT wf.id_filter
                FROM world_filters wf
                WHERE wf.id_world IN (SELECT id FROM ancestors)
                UNION
                SELECT cf.id_filter
                FROM content_filters cf
                WHERE cf.object_type = :type
                  AND cf.object_id IN (SELECT id FROM ancestors)
            ) inherited ON inherited.id_filter = f.id
            ORDER BY f.label ASC, f.name ASC"
        );
        $stmt->execute([':worldId' => $worldId, ':userId' => $userId, ':type' => 'world']);
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getUserBlockedFilters(int $userId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.*
             FROM filters f
             JOIN user_blocked_filters ubf ON f.id = ubf.id_filter
             WHERE ubf.id_user = :userId
             ORDER BY f.label ASC, f.name ASC'
        );
        $stmt->execute([':userId' => $userId]);
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function replaceUserBlockedFilters(int $userId, array $filterIds): void
    {
        $filterIds = array_values(array_unique(array_filter(array_map('intval', $filterIds))));
        $db = $this->database->connect();
        try {
            $db->beginTransaction();
            $db->prepare('DELETE FROM user_blocked_filters WHERE id_user = ?')->execute([$userId]);
            $insert = $db->prepare(
                'INSERT INTO user_blocked_filters (id_user, id_filter)
                 VALUES (:userId, :filterId)
                 ON CONFLICT (id_user, id_filter) DO NOTHING'
            );
            foreach ($filterIds as $filterId) {
                $insert->execute([':userId' => $userId, ':filterId' => $filterId]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function blockFilter(int $userId, int $filterId): void
    {
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO user_blocked_filters (id_user, id_filter)
             VALUES (:userId, :filterId)
             ON CONFLICT (id_user, id_filter) DO NOTHING'
        );
        $stmt->execute([':userId' => $userId, ':filterId' => $filterId]);
    }

    public function unblockFilter(int $userId, int $filterId): void
    {
        $stmt = $this->database->connect()->prepare(
            'DELETE FROM user_blocked_filters WHERE id_user = :userId AND id_filter = :filterId'
        );
        $stmt->execute([':userId' => $userId, ':filterId' => $filterId]);
    }

    public function blockedFilterIds(int $userId): array
    {
        $stmt = $this->database->connect()->prepare('SELECT id_filter FROM user_blocked_filters WHERE id_user = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function resolveTag(string $tag): array
    {
        $label = trim($tag);
        if ($label === '') {
            throw new InvalidArgumentException('Pusty filtr.', 422);
        }

        $normalized = mb_strtolower($label);
        $stmt = $this->database->connect()->prepare(
            'SELECT f.*
             FROM filters f
             LEFT JOIN filter_aliases fa ON fa.id_filter = f.id
             WHERE LOWER(f.slug) = :value
                OR LOWER(f.name) = :value
                OR LOWER(f.label) = :value
                OR LOWER(fa.alias) = :value
             LIMIT 1'
        );
        $stmt->execute([':value' => $normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $slug = $this->slugify($label);
            $db = $this->database->connect();
            $insert = $db->prepare(
                'INSERT INTO filters (slug, name, label, is_active)
                 VALUES (:slug, :name, :label, TRUE)
                 ON CONFLICT (slug) DO UPDATE SET label = EXCLUDED.label
                 RETURNING *'
            );
            $insert->execute([':slug' => $slug, ':name' => $slug, ':label' => $label]);
            $row = $insert->fetch(PDO::FETCH_ASSOC);
            $this->insertAlias((int)$row['id'], $label, $this->detectLanguage($label));
        }

        $this->insertAlias((int)$row['id'], $label, $this->detectLanguage($label));

        return [
            'id' => (int)$row['id'],
            'slug' => $row['slug'] ?? $row['name'],
            'name' => $row['label'] ?? $row['name'],
        ];
    }

    private function insertAlias(int $filterId, string $alias, string $language): void
    {
        $alias = trim($alias);
        if ($alias === '') {
            return;
        }

        $stmt = $this->database->connect()->prepare(
            'INSERT INTO filter_aliases (id_filter, alias, language)
             VALUES (:filterId, :alias, :language)
             ON CONFLICT (alias) DO NOTHING'
        );
        $stmt->execute([':filterId' => $filterId, ':alias' => $alias, ':language' => $language]);
    }

    private function getLegacyCharacterFilters(int $characterId, bool $inherited): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.* FROM filters f
             JOIN character_filters cf ON f.id = cf.id_filter
             WHERE cf.id_character = :characterId AND cf.is_inherited = :inherited
             ORDER BY f.label ASC, f.name ASC'
        );
        $stmt->execute([':characterId' => $characterId, ':inherited' => $inherited]);
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function hydrate(array $row): Filter
    {
        return new Filter(
            $row['label'] ?? $row['name'],
            isset($row['id']) ? (int)$row['id'] : null,
            $row['slug'] ?? $row['name'],
            (bool)($row['is_active'] ?? true)
        );
    }

    private function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'tag';
        return trim($value, '-') ?: 'tag';
    }

    private function detectLanguage(string $value): string
    {
        return preg_match('/[ąćęłńóśżź]/iu', $value) ? 'pl' : 'en';
    }
}
