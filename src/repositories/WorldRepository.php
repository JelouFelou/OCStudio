<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/World.php';
require_once __DIR__ . '/SiteEffectRepository.php';

class WorldRepository extends Repository
{
    private const ICON_COLORS = [
        '#7B61FF',
        '#2F80ED',
        '#27AE60',
        '#F39C12',
        '#E94D7B',
        '#00A6A6',
        '#D65A31',
        '#4F8FD9',
        '#8E44AD',
        '#B7791F',
    ];

    public function getWorldsByUserId(int $userId, bool $includeHidden = false): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM worlds WHERE id_user = :userId ' . $this->hiddenWorldClause($includeHidden) . ' ORDER BY parent_id NULLS FIRST, id ASC'
        );
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $worlds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($worlds as $world) {
            $result[] = $this->hydrate($world);
        }

        return $result;
    }

    /**
     * Zwraca bezpośrednie podfoldery danego folderu.
     * Dla folderu głównego ($parentId = null) zwraca foldery bez rodzica.
     */
    public function getChildWorlds(int $userId, ?int $parentId, bool $includeHidden = false): array
    {
        if ($parentId === null) {
            $stmt = $this->database->connect()->prepare(
                'SELECT * FROM worlds WHERE id_user = :userId AND parent_id IS NULL ' . $this->hiddenWorldClause($includeHidden) . ' ORDER BY id ASC'
            );
            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        } else {
            $stmt = $this->database->connect()->prepare(
                'SELECT * FROM worlds WHERE id_user = :userId AND parent_id = :parentId ' . $this->hiddenWorldClause($includeHidden) . ' ORDER BY id ASC'
            );
            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':parentId', $parentId, PDO::PARAM_INT);
        }

        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $world) {
            $result[] = $this->hydrate($world);
        }

        return $result;
    }

    public function getWorldByIdAndUserId(int $id, int $userId): ?World
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM worlds WHERE id = :id AND id_user = :userId'
        );
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $world = $stmt->fetch(PDO::FETCH_ASSOC);
        return $world ? $this->hydrate($world) : null;
    }

    public function getWorldByPublicIdAndUserId(string $publicId, int $userId): ?World
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM worlds WHERE public_id::text = :publicId AND id_user = :userId'
        );
        $stmt->bindValue(':publicId', $publicId);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $world = $stmt->fetch(PDO::FETCH_ASSOC);
        return $world ? $this->hydrate($world) : null;
    }

    public function addWorld(string $name, string $description, int $userId, ?int $parentId = null, ?string $image = 'default.jpg'): int
    {
        $iconColor = self::ICON_COLORS[random_int(0, count(self::ICON_COLORS) - 1)];
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO worlds (name, description, image, id_user, parent_id, icon_color) VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([$name, $description, $image, $userId, $parentId, $iconColor]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Buduje ścieżkę breadcrumb od folderu głównego do podanego $worldId.
     * Zwraca tablicę World obiektów od najwyższego do bieżącego.
     */
    public function getBreadcrumb(int $worldId, int $userId): array
    {
        $path    = [];
        $current = $this->getWorldByIdAndUserId($worldId, $userId);

        while ($current !== null) {
            array_unshift($path, $current);
            $parentId = $current->getParentId();
            $current  = $parentId ? $this->getWorldByIdAndUserId($parentId, $userId) : null;
        }

        return $path;
    }

    /**
     * Wyszukuje foldery po nazwie (case-insensitive, LIKE).
     * Zwraca tablicę World.
     */
    public function searchWorldsByName(int $userId, string $q, bool $includeHidden = false): array
    {
        $tokens = preg_split('/\s+/', trim($q));
        $clauses = [];
        $params = [':userId' => $userId];

        foreach ($tokens as $i => $token) {
            if ($token === '') {
                continue;
            }
            $key = ':q' . $i;
            $clauses[] = 'LOWER(name) LIKE ' . $key;
            $params[$key] = '%' . mb_strtolower($token) . '%';
        }

        $sql = 'SELECT * FROM worlds WHERE id_user = :userId' . $this->hiddenWorldClause($includeHidden);
        if (!empty($clauses)) {
            $sql .= ' AND ' . implode(' AND ', $clauses);
        }
        $sql .= ' ORDER BY name ASC LIMIT 5';

        $stmt = $this->database->connect()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->hydrate($row);
        }

        return $result;
    }

    /**
     * Zwraca wszystkie ID folderów w poddrzewie zaczynającym od $rootWorldId
     * (włącznie z samym $rootWorldId), używając rekursywnego CTE.
     * Działa na PostgreSQL.
     */
    public function getDescendantWorldIds(int $rootWorldId, int $userId): array
    {
        $stmt = $this->database->connect()->prepare('
            WITH RECURSIVE subtree(id) AS (
                SELECT id FROM worlds WHERE id = :rootId AND id_user = :userId
                UNION ALL
                SELECT w.id FROM worlds w
                JOIN subtree s ON w.parent_id = s.id
                WHERE w.id_user = :userId
            )
            SELECT id FROM subtree
        ');
        $stmt->bindParam(':rootId',  $rootWorldId, PDO::PARAM_INT);
        $stmt->bindParam(':userId',  $userId,      PDO::PARAM_INT);
        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    }

    private function hydrate(array $row): World
    {
        return new World(
            $row['name'],
            $row['description'],
            $row['image'],
            $row['id_user'],
            $row['id'],
            $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
            $row['status_id'] !== null ? (int)$row['status_id'] : null,
            (bool)($row['is_hidden'] ?? false),
            $row['public_id'] ?? null,
            $this->worldIconColor($row),
            (string)($row['background_effect'] ?? 'none'),
            (string)($row['effect_symbols'] ?? ''),
            (string)($row['effect_intensity'] ?? 'medium'),
            (string)($row['effect_size'] ?? 'medium'),
            (string)($row['effect_layer'] ?? 'under')
        );
    }

    private function worldIconColor(array $row): string
    {
        $color = trim((string)($row['icon_color'] ?? ''));
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return strtoupper($color);
        }

        $seed = (string)($row['id'] ?? '') . ':' . (string)($row['name'] ?? '');
        $index = abs((int)crc32($seed)) % count(self::ICON_COLORS);
        return self::ICON_COLORS[$index];
    }

    public function setHidden(int $worldId, int $userId, bool $hidden): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE worlds SET is_hidden = :hidden WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([
            ':hidden' => $hidden ? 1 : 0,
            ':id' => $worldId,
            ':userId' => $userId,
        ]);
    }

    public function isHiddenInPath(int $worldId, int $userId): bool
    {
        $stmt = $this->database->connect()->prepare(
            "WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                SELECT id, parent_id, COALESCE(is_hidden, FALSE)
                FROM worlds
                WHERE id = :worldId AND id_user = :userId
                UNION ALL
                SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                FROM worlds w
                JOIN ancestors a ON w.id = a.parent_id
                WHERE w.id_user = :userId
            )
            SELECT EXISTS (SELECT 1 FROM ancestors WHERE is_hidden = TRUE)"
        );
        $stmt->execute([':worldId' => $worldId, ':userId' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    private function hiddenWorldClause(bool $includeHidden): string
    {
        if ($includeHidden) {
            return '';
        }

        return " AND COALESCE(is_hidden, FALSE) = FALSE
            AND NOT EXISTS (
                WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                    SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                    FROM worlds w
                    WHERE w.id = worlds.parent_id
                    UNION ALL
                    SELECT w2.id, w2.parent_id, COALESCE(w2.is_hidden, FALSE)
                    FROM worlds w2
                    JOIN ancestors a ON w2.id = a.parent_id
                )
                SELECT 1 FROM ancestors WHERE is_hidden = TRUE
            )";
    }

    public function updateWorldStatus(int $worldId, ?int $statusId): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE worlds SET status_id = ? WHERE id = ?'
        );
        $stmt->execute([$statusId, $worldId]);
    }

    public function updateWorldName(int $worldId, int $userId, string $name): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE worlds SET name = :name WHERE id = :id AND id_user = :userId'
        );
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':id', $worldId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updateWorldDetails(int $worldId, int $userId, string $name, string $description, string $image): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE worlds SET name = :name, description = :description, image = :image WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':image' => $image,
            ':id' => $worldId,
            ':userId' => $userId,
        ]);
    }

    public function updateWorldEffect(int $worldId, int $userId, string $effect, string $symbols, string $intensity = 'medium', string $size = 'medium', string $layer = 'under'): void
    {
        $effectRepository = new SiteEffectRepository();
        $effect = $effectRepository->normalizeEffect($effect, 'none');
        if ($effect === 'auto' || $effect === 'off') {
            $effect = 'none';
        }
        $symbols = mb_substr(trim($symbols), 0, 120);
        $intensity = in_array($intensity, SiteEffectRepository::INTENSITIES, true) ? $intensity : 'medium';
        $size = in_array($size, SiteEffectRepository::SIZES, true) ? $size : 'medium';
        $layer = in_array($layer, SiteEffectRepository::LAYERS, true) ? $layer : 'under';

        $stmt = $this->database->connect()->prepare(
            'UPDATE worlds
             SET background_effect = :effect,
                 effect_symbols = :symbols,
                 effect_intensity = :intensity,
                 effect_size = :size,
                 effect_layer = :layer
             WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([
            ':effect' => $effect,
            ':symbols' => $symbols,
            ':intensity' => $intensity,
            ':size' => $size,
            ':layer' => $layer,
            ':id' => $worldId,
            ':userId' => $userId,
        ]);
    }

    public function moveCharactersFromWorldsToRoot(int $userId, array $worldIds): void
    {
        if (empty($worldIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($worldIds), '?'));
        $stmt = $this->database->connect()->prepare("
            UPDATE characters
            SET id_world = NULL
            WHERE id_user = ? AND id_world IN ($placeholders)
        ");
        $stmt->execute(array_merge([$userId], array_map('intval', $worldIds)));
    }

    public function deleteWorld(int $worldId, int $userId): void
    {
        $stmt = $this->database->connect()->prepare(
            'DELETE FROM worlds WHERE id = :id AND id_user = :userId'
        );
        $stmt->bindValue(':id', $worldId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }
}
