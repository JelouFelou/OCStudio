<?php

require_once 'Repository.php';
require_once __DIR__ . '/FilterRepository.php';
require_once __DIR__ . '/../services/ImageThumbnailService.php';

class ImageRepository extends Repository
{
    private const DEFAULT_IMAGES = ['default.png', 'default.jpg', 'default_dark.png'];
    private ImageThumbnailService $thumbnailService;

    public function __construct()
    {
        parent::__construct();
        $this->thumbnailService = new ImageThumbnailService();
    }

    public function findByHash(int $userId, string $sha256): ?array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM image_assets WHERE id_user = :userId AND sha256 = :sha256 LIMIT 1'
        );
        $stmt->execute([':userId' => $userId, ':sha256' => $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decorateAsset($row) : null;
    }

    public function createAsset(int $userId, string $filename, string $mimeType, int $sizeBytes, string $sha256, array $filterIds, string $visibility = 'normal'): array
    {
        $db = $this->database->connect();
        $visibility = $this->normalizeVisibility($visibility);
        try {
            $db->beginTransaction();
            $stmt = $db->prepare(
                'INSERT INTO image_assets (id_user, filename, original_name, mime_type, size_bytes, sha256, visibility)
                 VALUES (:userId, :filename, :originalName, :mimeType, :sizeBytes, :sha256, :visibility)
                 RETURNING *'
            );
            $stmt->execute([
                ':userId' => $userId,
                ':filename' => $filename,
                ':originalName' => $filename,
                ':mimeType' => $mimeType,
                ':sizeBytes' => $sizeBytes,
                ':sha256' => $sha256,
                ':visibility' => $visibility,
            ]);
            $asset = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->replaceImageFiltersInConnection($db, (int)$asset['id'], $filterIds);
            $db->commit();
            return $this->decorateAsset($asset);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function getAsset(int $userId, int $imageId): ?array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM image_assets WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([':id' => $imageId, ':userId' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decorateAsset($row) : null;
    }

    public function getAssetByFilename(int $userId, string $filename): ?array
    {
        if ($this->isDefaultImage($filename)) {
            return null;
        }

        $stmt = $this->database->connect()->prepare(
            'SELECT * FROM image_assets WHERE id_user = :userId AND filename = :filename LIMIT 1'
        );
        $stmt->execute([':userId' => $userId, ':filename' => basename($filename)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->decorateAsset($row) : null;
    }

    public function listAssets(int $userId, ?string $query = null, bool $includeHidden = false): array
    {
        $params = [':userId' => $userId];
        $where = 'ia.id_user = :userId';
        if ($query !== null && trim($query) !== '') {
            $params[':query'] = '%' . mb_strtolower(trim($query)) . '%';
            $where .= ' AND (
                LOWER(ia.filename) LIKE :query
                OR EXISTS (
                    SELECT 1 FROM image_asset_filters iaf
                    JOIN filters f ON f.id = iaf.id_filter
                    LEFT JOIN filter_aliases fa ON fa.id_filter = f.id
                    WHERE iaf.id_image = ia.id
                      AND (LOWER(f.label) LIKE :query OR LOWER(f.slug) LIKE :query OR LOWER(fa.alias) LIKE :query)
                )
            )';
        }

        $stmt = $this->database->connect()->prepare(
            "SELECT ia.* FROM image_assets ia WHERE {$where} ORDER BY ia.updated_at DESC, ia.id DESC"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$this->uploadFileExists((string)$row['filename'])) {
                continue;
            }
            $rows[] = $row;
        }

        $hiddenReferencesByFilename = $this->getHiddenReferencesByFilename(
            $userId,
            array_map(fn($row) => (string)$row['filename'], $rows)
        );

        $visibleRows = [];
        foreach ($rows as $row) {
            $filename = (string)$row['filename'];
            $hasHiddenReferences = !empty($hiddenReferencesByFilename[$filename]);
            $visibility = $this->normalizeVisibility((string)($row['visibility'] ?? 'normal'));
            if (!$includeHidden && ($hasHiddenReferences || $visibility !== 'normal')) {
                continue;
            }
            $visibleRows[] = $row;
        }

        $tagsByImageId = $this->getImageTagsByImageIds(array_map(fn($row) => (int)$row['id'], $visibleRows));
        $usageByFilename = $this->countReferencesByFilename(
            $userId,
            array_map(fn($row) => (string)$row['filename'], $visibleRows)
        );

        $assets = [];
        foreach ($visibleRows as $row) {
            $filename = (string)$row['filename'];
            $imageId = (int)$row['id'];
            $assets[] = $this->decorateAsset(
                $row,
                !empty($hiddenReferencesByFilename[$filename]),
                $tagsByImageId[$imageId] ?? [],
                $usageByFilename[$filename] ?? 0
            );
        }
        return $assets;
    }

    public function deleteAsset(int $userId, int $imageId, bool $forceMissing = false): void
    {
        $asset = $this->getAsset($userId, $imageId);
        if (!$asset) {
            throw new InvalidArgumentException('Zdjecie nie istnieje.', 404);
        }

        $exists = $this->uploadFileExists($asset['filename']);
        $usageCount = $this->countReferences($userId, $asset['filename']);
        if ($usageCount > 0 && !($forceMissing && !$exists)) {
            throw new InvalidArgumentException('Nie mozna usunac zdjecia, bo jest uzywane w postaciach lub polach.', 409);
        }

        $db = $this->database->connect();
        $db->prepare('DELETE FROM image_assets WHERE id = :id AND id_user = :userId')
            ->execute([':id' => $imageId, ':userId' => $userId]);

        $this->deleteUploadFile($asset['filename']);
        $this->thumbnailService->deleteGalleryThumbnail($asset['filename']);
    }

    public function getStorageBytes(int $userId): int
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT filename, size_bytes FROM image_assets WHERE id_user = :userId'
        );
        $stmt->execute([':userId' => $userId]);

        $bytes = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->uploadFileExists((string)$row['filename'])) {
                $bytes += (int)($row['size_bytes'] ?? 0);
            }
        }

        return $bytes;
    }

    public function updateTags(int $userId, int $imageId, array $filterIds): array
    {
        $asset = $this->getAsset($userId, $imageId);
        if (!$asset) {
            throw new InvalidArgumentException('Zdjecie nie istnieje.', 404);
        }

        $db = $this->database->connect();
        $this->replaceImageFiltersInConnection($db, $imageId, $filterIds);
        $db->prepare('UPDATE image_assets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$imageId]);

        return $this->getAsset($userId, $imageId);
    }

    public function updateTagsAndVisibility(int $userId, int $imageId, array $filterIds, ?string $visibility): array
    {
        $asset = $this->getAsset($userId, $imageId);
        if (!$asset) {
            throw new InvalidArgumentException('Zdjecie nie istnieje.', 404);
        }

        $visibility = $visibility === null
            ? $this->normalizeVisibility((string)($asset['visibility'] ?? 'normal'))
            : $this->normalizeVisibility($visibility);
        $db = $this->database->connect();
        try {
            $db->beginTransaction();
            $this->replaceImageFiltersInConnection($db, $imageId, $filterIds);
            $db->prepare('UPDATE image_assets SET visibility = :visibility, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                ->execute([':visibility' => $visibility, ':id' => $imageId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $this->getAsset($userId, $imageId);
    }

    public function updateVisibility(int $userId, int $imageId, string $visibility): array
    {
        $asset = $this->getAsset($userId, $imageId);
        if (!$asset) {
            throw new InvalidArgumentException('Zdjecie nie istnieje.', 404);
        }

        $visibility = $this->normalizeVisibility($visibility, 'hidden');
        $stmt = $this->database->connect()->prepare(
            'UPDATE image_assets SET visibility = :visibility, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([':visibility' => $visibility, ':id' => $imageId, ':userId' => $userId]);

        return $this->getAsset($userId, $imageId);
    }

    public function elevateVisibility(int $userId, int $imageId, string $visibility): array
    {
        $asset = $this->getAsset($userId, $imageId);
        if (!$asset) {
            throw new InvalidArgumentException('Zdjecie nie istnieje.', 404);
        }

        $current = $this->visibilityRank((string)($asset['visibility'] ?? 'normal'));
        $next = $this->normalizeVisibility($visibility);
        if ($this->visibilityRank($next) <= $current) {
            return $asset;
        }

        $stmt = $this->database->connect()->prepare(
            'UPDATE image_assets SET visibility = :visibility, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([':visibility' => $next, ':id' => $imageId, ':userId' => $userId]);

        return $this->getAsset($userId, $imageId);
    }

    public function listAdultFilenames(int $userId): array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT filename FROM image_assets WHERE id_user = :userId AND visibility = 'adult'"
        );
        $stmt->execute([':userId' => $userId]);

        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    public function mergeImages(int $userId, int $sourceId, int $targetId): array
    {
        if ($sourceId === $targetId) {
            throw new InvalidArgumentException('Wybierz dwa rozne zdjecia.', 422);
        }

        $source = $this->getAsset($userId, $sourceId);
        $target = $this->getAsset($userId, $targetId);
        if (!$source || !$target) {
            throw new InvalidArgumentException('Nie znaleziono zdjec do polaczenia.', 404);
        }

        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $db->prepare('UPDATE characters SET image = :target WHERE id_user = :userId AND image = :source')
                ->execute([':target' => $target['filename'], ':userId' => $userId, ':source' => $source['filename']]);

            $db->prepare(
                'UPDATE character_variants cv
                 SET image = :target
                 FROM characters c
                 WHERE cv.id_character = c.id AND c.id_user = :userId AND cv.image = :source'
            )->execute([':target' => $target['filename'], ':userId' => $userId, ':source' => $source['filename']]);

            $this->replaceJsonImageReferences($db, $userId, $source, $target);

            $db->prepare('DELETE FROM image_assets WHERE id = :id AND id_user = :userId')
                ->execute([':id' => $sourceId, ':userId' => $userId]);

            $db->commit();

            $this->deleteUploadFile($source['filename']);
            return $this->getAsset($userId, $targetId);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function countReferences(int $userId, string $filename): int
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT
                (SELECT COUNT(*) FROM characters WHERE id_user = :userId AND image = :filename)
                +
                (SELECT COUNT(*) FROM character_variants cv JOIN characters c ON c.id = cv.id_character WHERE c.id_user = :userId AND cv.image = :filename)
                +
                (SELECT COUNT(*) FROM character_field_values cfv JOIN characters c ON c.id = cfv.id_character WHERE c.id_user = :userId AND cfv.value LIKE :jsonNeedle)
                +
                (SELECT COUNT(*) FROM character_variant_field_values cvfv JOIN character_variants cv ON cv.id = cvfv.id_variant JOIN characters c ON c.id = cv.id_character WHERE c.id_user = :userId AND cvfv.value LIKE :jsonNeedle)
                +
                (SELECT COUNT(*) FROM worlds WHERE id_user = :userId AND image = :filename)
                +
                (SELECT COUNT(*) FROM stories WHERE id_user = :userId AND image = :filename)
                +
                (SELECT COUNT(*) FROM story_field_values sfv JOIN stories s ON s.id = sfv.id_story WHERE s.id_user = :userId AND sfv.value LIKE :jsonNeedle)"
        );
        $stmt->execute([
            ':userId' => $userId,
            ':filename' => $filename,
            ':jsonNeedle' => '%' . $filename . '%',
        ]);
        return (int)$stmt->fetchColumn();
    }

    public function usageDetails(int $userId, string $filename): array
    {
        $filename = basename($filename);
        if ($filename === '') {
            return [];
        }

        $db = $this->database->connect();
        $items = [];
        $queries = [
            [
                'sql' => "SELECT name AS title, public_id::text AS public_id FROM characters WHERE id_user = :userId AND image = :filename ORDER BY name ASC",
                'type' => 'Postac',
                'href' => fn($row) => '/character/' . $row['public_id'],
                'context' => 'Portret postaci',
            ],
            [
                'sql' => "SELECT c.name AS title, c.public_id::text AS public_id, cv.name AS extra FROM character_variants cv JOIN characters c ON c.id = cv.id_character WHERE c.id_user = :userId AND cv.image = :filename ORDER BY c.name ASC, cv.name ASC",
                'type' => 'Wariant postaci',
                'href' => fn($row) => '/editCharacter/' . $row['public_id'],
                'context' => fn($row) => $row['extra'] ?? 'Wariant',
            ],
            [
                'sql' => "SELECT name AS title, public_id::text AS public_id FROM worlds WHERE id_user = :userId AND image = :filename ORDER BY name ASC",
                'type' => 'Folder',
                'href' => fn($row) => '/characters/' . $row['public_id'],
                'context' => 'Miniatura folderu',
            ],
            [
                'sql' => "SELECT title, public_id::text AS public_id FROM stories WHERE id_user = :userId AND image = :filename ORDER BY title ASC",
                'type' => 'Historia',
                'href' => fn($row) => '/story/' . $row['public_id'],
                'context' => 'Okładka historii',
            ],
        ];

        foreach ($queries as $query) {
            if (!$this->tablesInUsageSqlExist($db, $query['sql'])) {
                continue;
            }
            $stmt = $db->prepare($query['sql']);
            $stmt->execute([':userId' => $userId, ':filename' => $filename]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $context = is_callable($query['context']) ? $query['context']($row) : $query['context'];
                $items[] = [
                    'type' => $query['type'],
                    'title' => $row['title'] ?? '',
                    'context' => $context,
                    'href' => $query['href']($row),
                ];
            }
        }

        $needle = '%' . $filename . '%';
        $jsonQueries = [
            [
                'sql' => "SELECT c.name AS title, c.public_id::text AS public_id, tf.label AS field_label
                          FROM character_field_values cfv
                          JOIN characters c ON c.id = cfv.id_character
                          LEFT JOIN template_fields tf ON tf.id = cfv.id_template_field
                          WHERE c.id_user = :userId AND cfv.value LIKE :needle",
                'type' => 'Postac',
                'href' => fn($row) => '/character/' . $row['public_id'],
            ],
            [
                'sql' => "SELECT s.title, s.public_id::text AS public_id, sf.label AS field_label
                          FROM story_field_values sfv
                          JOIN stories s ON s.id = sfv.id_story
                          LEFT JOIN story_fields sf ON sf.id = sfv.id_story_field
                          WHERE s.id_user = :userId AND sfv.value LIKE :needle",
                'type' => 'Historia',
                'href' => fn($row) => '/story/' . $row['public_id'],
            ],
        ];

        foreach ($jsonQueries as $query) {
            if (!$this->tablesInUsageSqlExist($db, $query['sql'])) {
                continue;
            }
            $stmt = $db->prepare($query['sql']);
            $stmt->execute([':userId' => $userId, ':needle' => $needle]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'type' => $query['type'],
                    'title' => $row['title'] ?? '',
                    'context' => 'Pole: ' . ($row['field_label'] ?? 'zdjecie'),
                    'href' => $query['href']($row),
                ];
            }
        }

        return $items;
    }

    private function replaceJsonImageReferences(PDO $db, int $userId, array $source, array $target): void
    {
        $replacements = [
            $source['filename'] => $target['filename'],
            $source['url'] => $target['url'],
            '"imageId":' . $source['id'] => '"imageId":' . $target['id'],
            '"imageId": ' . $source['id'] => '"imageId": ' . $target['id'],
        ];

        $tables = [
            ['character_field_values', 'id_character', 'characters', 'id'],
            ['character_variant_field_values', 'id_variant', 'character_variants', 'id'],
        ];

        foreach ($tables as [$valueTable, $valueFk, $ownerTable, $ownerPk]) {
            if ($valueTable === 'character_field_values') {
                $select = $db->prepare(
                    'SELECT cfv.id, cfv.value
                     FROM character_field_values cfv
                     JOIN characters c ON c.id = cfv.id_character
                     WHERE c.id_user = :userId AND cfv.value LIKE :needle'
                );
            } else {
                $select = $db->prepare(
                    'SELECT cvfv.id, cvfv.value
                     FROM character_variant_field_values cvfv
                     JOIN character_variants cv ON cv.id = cvfv.id_variant
                     JOIN characters c ON c.id = cv.id_character
                     WHERE c.id_user = :userId AND cvfv.value LIKE :needle'
                );
            }
            $select->execute([':userId' => $userId, ':needle' => '%' . $source['filename'] . '%']);
            $update = $db->prepare("UPDATE {$valueTable} SET value = :value WHERE id = :id");
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $value = (string)$row['value'];
                foreach ($replacements as $from => $to) {
                    $value = str_replace($from, $to, $value);
                }
                $update->execute([':value' => $value, ':id' => (int)$row['id']]);
            }
        }
    }

    private function tablesInUsageSqlExist(PDO $db, string $sql): bool
    {
        if (!preg_match_all('/(?:FROM|JOIN)\s+([a-z_]+)/i', $sql, $matches)) {
            return true;
        }
        foreach (array_unique($matches[1]) as $table) {
            $stmt = $db->prepare("SELECT to_regclass(:table) IS NOT NULL");
            $stmt->execute([':table' => 'public.' . $table]);
            if (!$stmt->fetchColumn()) {
                return false;
            }
        }
        return true;
    }

    private function replaceImageFiltersInConnection(PDO $db, int $imageId, array $filterIds): void
    {
        $filterIds = array_values(array_unique(array_filter(array_map('intval', $filterIds))));
        $db->prepare('DELETE FROM image_asset_filters WHERE id_image = ?')->execute([$imageId]);
        $insert = $db->prepare(
            'INSERT INTO image_asset_filters (id_image, id_filter)
             VALUES (:imageId, :filterId)
             ON CONFLICT (id_image, id_filter) DO NOTHING'
        );
        foreach ($filterIds as $filterId) {
            $insert->execute([':imageId' => $imageId, ':filterId' => $filterId]);
        }
    }

    private function decorateAsset(array $row, ?bool $hasHiddenReferences = null, ?array $tags = null, ?int $usageCount = null): array
    {
        $id = (int)$row['id'];
        $tags = $tags ?? $this->getImageTags($id);
        $hasHiddenReferences = $hasHiddenReferences ?? $this->hasHiddenReferences((int)$row['id_user'], (string)$row['filename']);
        $visibility = $this->normalizeVisibility((string)($row['visibility'] ?? 'normal'));
        return [
            'id' => $id,
            'filename' => $row['filename'],
            'url' => '/public/uploads/' . $row['filename'],
            'thumbnailUrl' => $this->thumbnailService->galleryThumbnailUrl((string)$row['filename'], (string)($row['mime_type'] ?? '')),
            'mimeType' => $row['mime_type'] ?? '',
            'sizeBytes' => (int)($row['size_bytes'] ?? 0),
            'sha256' => $row['sha256'] ?? '',
            'description' => $row['description'] ?? '',
            'visibility' => $visibility,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
            'tags' => $tags,
            'usageCount' => $usageCount ?? $this->countReferences((int)$row['id_user'], $row['filename']),
            'hasHiddenReferences' => $hasHiddenReferences || $visibility !== 'normal',
            'hiddenWithoutOwnFilters' => $hasHiddenReferences && empty($tags) && $visibility === 'normal',
        ];
    }

    private function getImageTagsByImageIds(array $imageIds): array
    {
        $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds))));
        if (empty($imageIds)) {
            return [];
        }

        [$placeholders, $params] = $this->placeholders($imageIds, 'imageId');
        $stmt = $this->database->connect()->prepare(
            "SELECT iaf.id_image, f.id, f.slug, f.label AS name
             FROM image_asset_filters iaf
             JOIN filters f ON f.id = iaf.id_filter
             WHERE iaf.id_image IN ({$placeholders})
             ORDER BY iaf.id_image ASC, f.label ASC, f.name ASC"
        );
        $stmt->execute($params);

        $tags = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $imageId = (int)$row['id_image'];
            $tags[$imageId][] = [
                'id' => (int)$row['id'],
                'slug' => $row['slug'],
                'name' => $row['name'],
            ];
        }

        return $tags;
    }

    private function countReferencesByFilename(int $userId, array $filenames): array
    {
        $filenames = array_values(array_unique(array_filter(array_map('strval', $filenames))));
        if (empty($filenames)) {
            return [];
        }

        $counts = array_fill_keys($filenames, 0);
        [$placeholders, $filenameParams] = $this->placeholders($filenames, 'filename');
        $db = $this->database->connect();

        $queries = [
            "SELECT image AS filename, COUNT(*) AS count
             FROM characters
             WHERE id_user = :userId AND image IN ({$placeholders})
             GROUP BY image",
            "SELECT cv.image AS filename, COUNT(*) AS count
             FROM character_variants cv
             JOIN characters c ON c.id = cv.id_character
             WHERE c.id_user = :userId AND cv.image IN ({$placeholders})
             GROUP BY cv.image",
            "SELECT image AS filename, COUNT(*) AS count
             FROM worlds
             WHERE id_user = :userId AND image IN ({$placeholders})
             GROUP BY image",
            "SELECT image AS filename, COUNT(*) AS count
             FROM stories
             WHERE id_user = :userId AND image IN ({$placeholders})
             GROUP BY image",
        ];

        foreach ($queries as $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute(array_merge([':userId' => $userId], $filenameParams));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $filename = (string)$row['filename'];
                $counts[$filename] = ($counts[$filename] ?? 0) + (int)$row['count'];
            }
        }

        foreach ($this->jsonImageReferenceValues($userId) as $value) {
            foreach ($filenames as $filename) {
                if ($filename !== '' && str_contains($value, $filename)) {
                    $counts[$filename]++;
                }
            }
        }

        return $counts;
    }

    private function normalizeVisibility(string $visibility, string $fallback = 'normal'): string
    {
        $fallback = in_array($fallback, ['normal', 'hidden', 'adult'], true) ? $fallback : 'normal';
        return in_array($visibility, ['normal', 'hidden', 'adult'], true) ? $visibility : $fallback;
    }

    private function visibilityRank(string $visibility): int
    {
        return ['normal' => 0, 'hidden' => 1, 'adult' => 2][$this->normalizeVisibility($visibility)];
    }

    private function getHiddenReferencesByFilename(int $userId, array $filenames): array
    {
        $filenames = array_values(array_unique(array_filter(array_map('strval', $filenames))));
        if (empty($filenames)) {
            return [];
        }

        [$placeholders, $filenameParams] = $this->placeholders($filenames, 'hiddenFilename');
        $params = array_merge([':userId' => $userId], $filenameParams);
        $stmt = $this->database->connect()->prepare(
            "WITH RECURSIVE
            restricted_filters AS (
                SELECT f.id
                FROM filters f
                LEFT JOIN filter_aliases fa ON fa.id_filter = f.id
                WHERE LOWER(COALESCE(f.slug, '')) IN ('nsfw', '+18', '18+')
                   OR LOWER(COALESCE(f.name, '')) IN ('nsfw', '+18', '18+')
                   OR LOWER(COALESCE(f.label, '')) IN ('nsfw', '+18', '18+')
                   OR LOWER(COALESCE(fa.alias, '')) IN ('nsfw', '+18', '18+')
            ),
            candidate_images AS (
                SELECT filename
                FROM image_assets
                WHERE id_user = :userId AND filename IN ({$placeholders})
            ),
            world_ancestors(world_id, id, parent_id, is_hidden) AS (
                SELECT w.id, w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                FROM worlds w
                WHERE w.id_user = :userId
                UNION ALL
                SELECT wa.world_id, parent.id, parent.parent_id, COALESCE(parent.is_hidden, FALSE)
                FROM worlds parent
                JOIN world_ancestors wa ON parent.id = wa.parent_id
                WHERE parent.id_user = :userId
            ),
            hidden_worlds AS (
                SELECT DISTINCT world_id AS id
                FROM world_ancestors
                WHERE is_hidden = TRUE
            ),
            restricted_worlds AS (
                SELECT DISTINCT wa.world_id AS id
                FROM world_ancestors wa
                WHERE EXISTS (
                    SELECT 1
                    FROM world_filters wf
                    WHERE wf.id_world = wa.id
                      AND wf.id_filter IN (SELECT id FROM restricted_filters)
                )
                OR EXISTS (
                    SELECT 1
                    FROM content_filters cf
                    WHERE cf.object_type = 'world'
                      AND cf.object_id = wa.id
                      AND cf.id_filter IN (SELECT id FROM restricted_filters)
                )
            ),
            hidden_characters AS (
                SELECT c.id
                FROM characters c
                WHERE c.id_user = :userId
                  AND (
                    COALESCE(c.is_hidden, FALSE) = TRUE
                    OR c.id_world IN (SELECT id FROM hidden_worlds)
                  )
            ),
            restricted_characters AS (
                SELECT c.id
                FROM characters c
                WHERE c.id_user = :userId
                  AND (
                    c.id_world IN (SELECT id FROM restricted_worlds)
                    OR EXISTS (
                        SELECT 1
                        FROM character_filters cf
                        WHERE cf.id_character = c.id
                          AND cf.id_filter IN (SELECT id FROM restricted_filters)
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM content_filters cf
                        WHERE cf.object_type = 'character'
                          AND cf.object_id = c.id
                          AND cf.id_filter IN (SELECT id FROM restricted_filters)
                    )
                  )
            ),
            hidden_stories AS (
                SELECT s.id
                FROM stories s
                WHERE s.id_user = :userId
                  AND (
                    COALESCE(s.is_hidden, FALSE) = TRUE
                    OR s.id_world IN (SELECT id FROM hidden_worlds)
                  )
            ),
            restricted_stories AS (
                SELECT s.id
                FROM stories s
                WHERE s.id_user = :userId
                  AND (
                    s.id_world IN (SELECT id FROM restricted_worlds)
                    OR EXISTS (
                        SELECT 1
                        FROM content_filters cf
                        WHERE cf.object_type = 'story'
                          AND cf.object_id = s.id
                          AND cf.id_filter IN (SELECT id FROM restricted_filters)
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM story_characters sc
                        WHERE sc.id_story = s.id
                          AND sc.id_character IN (SELECT id FROM restricted_characters)
                    )
                  )
            ),
            restricted_character_ids AS (
                SELECT id FROM hidden_characters
                UNION
                SELECT id FROM restricted_characters
            ),
            restricted_story_ids AS (
                SELECT id FROM hidden_stories
                UNION
                SELECT id FROM restricted_stories
            )
            SELECT DISTINCT filename FROM (
                SELECT c.image AS filename
                FROM characters c
                WHERE c.id IN (SELECT id FROM restricted_character_ids)
                  AND c.image IN (SELECT filename FROM candidate_images)
                UNION ALL
                SELECT cv.image AS filename
                FROM character_variants cv
                WHERE cv.id_character IN (SELECT id FROM restricted_character_ids)
                  AND cv.image IN (SELECT filename FROM candidate_images)
                UNION ALL
                SELECT ci.filename
                FROM candidate_images ci
                JOIN character_field_values cfv ON cfv.value LIKE '%' || ci.filename || '%'
                WHERE cfv.id_character IN (SELECT id FROM restricted_character_ids)
                UNION ALL
                SELECT ci.filename
                FROM candidate_images ci
                JOIN character_variant_field_values cvfv ON cvfv.value LIKE '%' || ci.filename || '%'
                JOIN character_variants cv ON cv.id = cvfv.id_variant
                WHERE cv.id_character IN (SELECT id FROM restricted_character_ids)
                UNION ALL
                SELECT w.image AS filename
                FROM worlds w
                WHERE w.id_user = :userId
                  AND w.image IN (SELECT filename FROM candidate_images)
                  AND w.id IN (
                    SELECT id FROM hidden_worlds
                    UNION
                    SELECT id FROM restricted_worlds
                  )
                UNION ALL
                SELECT s.image AS filename
                FROM stories s
                WHERE s.id IN (SELECT id FROM restricted_story_ids)
                  AND s.image IN (SELECT filename FROM candidate_images)
                UNION ALL
                SELECT ci.filename
                FROM candidate_images ci
                JOIN story_field_values sfv ON sfv.value LIKE '%' || ci.filename || '%'
                WHERE sfv.id_story IN (SELECT id FROM restricted_story_ids)
                UNION ALL
                SELECT ia.filename
                FROM image_asset_filters iaf
                JOIN image_assets ia ON ia.id = iaf.id_image
                WHERE ia.id_user = :userId
                  AND ia.filename IN (SELECT filename FROM candidate_images)
                  AND iaf.id_filter IN (SELECT id FROM restricted_filters)
            ) hidden_matches
            WHERE filename IS NOT NULL AND filename <> ''"
        );
        $stmt->execute($params);

        return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    private function jsonImageReferenceValues(int $userId): array
    {
        $db = $this->database->connect();
        $values = [];
        $queries = [
            'SELECT cfv.value
             FROM character_field_values cfv
             JOIN characters c ON c.id = cfv.id_character
             WHERE c.id_user = :userId AND cfv.value IS NOT NULL',
            'SELECT cvfv.value
             FROM character_variant_field_values cvfv
             JOIN character_variants cv ON cv.id = cvfv.id_variant
             JOIN characters c ON c.id = cv.id_character
             WHERE c.id_user = :userId AND cvfv.value IS NOT NULL',
            'SELECT sfv.value
             FROM story_field_values sfv
             JOIN stories s ON s.id = sfv.id_story
             WHERE s.id_user = :userId AND sfv.value IS NOT NULL',
        ];

        foreach ($queries as $sql) {
            $stmt = $db->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
                $values[] = (string)$value;
            }
        }

        return $values;
    }

    private function placeholders(array $values, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($values) as $index => $value) {
            $key = ':' . $prefix . $index;
            $placeholders[] = $key;
            $params[$key] = $value;
        }

        return [implode(', ', $placeholders), $params];
    }

    private function hasHiddenReferences(int $userId, string $filename): bool
    {
        $stmt = $this->database->connect()->prepare(
            "WITH RECURSIVE
            restricted_filters AS (
                SELECT f.id
                FROM filters f
                LEFT JOIN filter_aliases fa ON fa.id_filter = f.id
                WHERE LOWER(COALESCE(f.slug, '')) IN ('nsfw', '+18', '18+')
                   OR LOWER(COALESCE(f.name, '')) IN ('nsfw', '+18', '18+')
                   OR LOWER(COALESCE(f.label, '')) IN ('nsfw', '+18', '18+')
                   OR LOWER(COALESCE(fa.alias, '')) IN ('nsfw', '+18', '18+')
            ),
            world_ancestors(world_id, id, parent_id, is_hidden) AS (
                SELECT w.id, w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                FROM worlds w
                WHERE w.id_user = :userId
                UNION ALL
                SELECT wa.world_id, parent.id, parent.parent_id, COALESCE(parent.is_hidden, FALSE)
                FROM worlds parent
                JOIN world_ancestors wa ON parent.id = wa.parent_id
                WHERE parent.id_user = :userId
            ),
            hidden_worlds AS (
                SELECT DISTINCT world_id AS id
                FROM world_ancestors
                WHERE is_hidden = TRUE
            ),
            restricted_worlds AS (
                SELECT DISTINCT wa.world_id AS id
                FROM world_ancestors wa
                WHERE EXISTS (
                    SELECT 1
                    FROM world_filters wf
                    WHERE wf.id_world = wa.id
                      AND wf.id_filter IN (SELECT id FROM restricted_filters)
                )
                OR EXISTS (
                    SELECT 1
                    FROM content_filters cf
                    WHERE cf.object_type = 'world'
                      AND cf.object_id = wa.id
                      AND cf.id_filter IN (SELECT id FROM restricted_filters)
                )
            ),
            hidden_characters AS (
                SELECT c.id
                FROM characters c
                WHERE c.id_user = :userId
                  AND (
                    COALESCE(c.is_hidden, FALSE) = TRUE
                    OR c.id_world IN (SELECT id FROM hidden_worlds)
                  )
            ),
            restricted_characters AS (
                SELECT c.id
                FROM characters c
                WHERE c.id_user = :userId
                  AND (
                    c.id_world IN (SELECT id FROM restricted_worlds)
                    OR EXISTS (
                        SELECT 1
                        FROM character_filters cf
                        WHERE cf.id_character = c.id
                          AND cf.id_filter IN (SELECT id FROM restricted_filters)
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM content_filters cf
                        WHERE cf.object_type = 'character'
                          AND cf.object_id = c.id
                          AND cf.id_filter IN (SELECT id FROM restricted_filters)
                    )
                  )
            ),
            hidden_stories AS (
                SELECT s.id
                FROM stories s
                WHERE s.id_user = :userId
                  AND (
                    COALESCE(s.is_hidden, FALSE) = TRUE
                    OR s.id_world IN (SELECT id FROM hidden_worlds)
                  )
            ),
            restricted_stories AS (
                SELECT s.id
                FROM stories s
                WHERE s.id_user = :userId
                  AND (
                    s.id_world IN (SELECT id FROM restricted_worlds)
                    OR EXISTS (
                        SELECT 1
                        FROM content_filters cf
                        WHERE cf.object_type = 'story'
                          AND cf.object_id = s.id
                          AND cf.id_filter IN (SELECT id FROM restricted_filters)
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM story_characters sc
                        WHERE sc.id_story = s.id
                          AND sc.id_character IN (SELECT id FROM restricted_characters)
                    )
                  )
            )
            SELECT EXISTS (
                SELECT 1 FROM characters c
                WHERE c.id IN (
                    SELECT id FROM hidden_characters
                    UNION
                    SELECT id FROM restricted_characters
                ) AND c.image = :filename
                UNION ALL
                SELECT 1 FROM character_variants cv
                WHERE cv.id_character IN (
                    SELECT id FROM hidden_characters
                    UNION
                    SELECT id FROM restricted_characters
                ) AND cv.image = :filename
                UNION ALL
                SELECT 1 FROM character_field_values cfv
                WHERE cfv.id_character IN (
                    SELECT id FROM hidden_characters
                    UNION
                    SELECT id FROM restricted_characters
                ) AND cfv.value LIKE :jsonNeedle
                UNION ALL
                SELECT 1 FROM character_variant_field_values cvfv
                JOIN character_variants cv ON cv.id = cvfv.id_variant
                WHERE cv.id_character IN (
                    SELECT id FROM hidden_characters
                    UNION
                    SELECT id FROM restricted_characters
                ) AND cvfv.value LIKE :jsonNeedle
                UNION ALL
                SELECT 1 FROM worlds w
                WHERE w.id_user = :userId
                  AND w.image = :filename
                  AND w.id IN (
                    SELECT id FROM hidden_worlds
                    UNION
                    SELECT id FROM restricted_worlds
                  )
                UNION ALL
                SELECT 1 FROM stories s
                WHERE s.id IN (
                    SELECT id FROM hidden_stories
                    UNION
                    SELECT id FROM restricted_stories
                ) AND s.image = :filename
                UNION ALL
                SELECT 1 FROM story_field_values sfv
                WHERE sfv.id_story IN (
                    SELECT id FROM hidden_stories
                    UNION
                    SELECT id FROM restricted_stories
                ) AND sfv.value LIKE :jsonNeedle
                UNION ALL
                SELECT 1 FROM image_asset_filters iaf
                JOIN image_assets ia ON ia.id = iaf.id_image
                WHERE ia.id_user = :userId
                  AND ia.filename = :filename
                  AND iaf.id_filter IN (SELECT id FROM restricted_filters)
            )"
        );
        $stmt->execute([
            ':userId' => $userId,
            ':filename' => $filename,
            ':jsonNeedle' => '%' . $filename . '%',
        ]);

        return (bool)$stmt->fetchColumn();
    }

    private function getImageTags(int $imageId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT f.id, f.slug, f.label AS name
             FROM image_asset_filters iaf
             JOIN filters f ON f.id = iaf.id_filter
             WHERE iaf.id_image = :imageId
             ORDER BY f.label ASC, f.name ASC'
        );
        $stmt->execute([':imageId' => $imageId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deleteUploadFile(string $filename): void
    {
        if ($this->isDefaultImage($filename)) {
            return;
        }
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function uploadFileExists(string $filename): bool
    {
        if ($this->isDefaultImage($filename)) {
            return true;
        }
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($filename);
        return is_file($path);
    }

    private function isDefaultImage(string $filename): bool
    {
        return in_array(basename($filename), self::DEFAULT_IMAGES, true);
    }
}
