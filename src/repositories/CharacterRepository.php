<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/Character.php';

class CharacterRepository extends Repository
{
    private ?bool $variantVisibilityColumnsExist = null;
    private ?bool $variantImageColumnsExist = null;
    private ?bool $variantDescriptionColumnExist = null;

    public function getCharactersByUserId(int $userId, bool $includeHidden = false): array
    {
        $result = [];

        $stmt = $this->database->connect()->prepare('
            SELECT c.* FROM characters c
            WHERE c.id_user = :userId ' . $this->hiddenContentClause('c', $includeHidden) . '
            ORDER BY c.is_main_character DESC, c.is_pinned DESC, c.name ASC, c.id ASC
        ');
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $characters = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($characters as $char) {
            $result[] = $this->hydrate($char);
        }

        return $result;
    }

    /**
     * Zwraca postacie w konkretnym folderze.
     * $worldId = null  → postacie bez przypisanego folderu (folder główny)
     */
    public function getCharactersByWorld(int $userId, ?int $worldId, array $blockedFilterIds = [], bool $includeHidden = false, bool $includeAdult = true): array
    {
        $visibilityClause = $this->characterListVisibilityClause($blockedFilterIds, $includeHidden, $includeAdult);
        if ($worldId === null) {
            $stmt = $this->database->connect()->prepare('
                SELECT c.* FROM characters c
                WHERE c.id_user = :userId AND c.id_world IS NULL ' . $visibilityClause . '
                ORDER BY c.is_main_character DESC, c.is_pinned DESC, c.name ASC, c.id ASC
            ');
            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        } else {
            $stmt = $this->database->connect()->prepare('
                SELECT c.* FROM characters c
                WHERE c.id_user = :userId AND c.id_world = :worldId ' . $visibilityClause . '
                ORDER BY c.is_main_character DESC, c.is_pinned DESC, c.name ASC, c.id ASC
            ');
            $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':worldId', $worldId, PDO::PARAM_INT);
        }

        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $char) {
            $result[] = $this->hydrate($char);
        }

        return $result;
    }

    public function addCharacter(string $name, string $description, string $image, int $userId, ?int $templateId, ?int $worldId = null, array $imageDisplay = [], string $intro = ''): int
    {
        $imageDisplay = $this->normalizeImageDisplay($imageDisplay);
        $stmt = $this->database->connect()->prepare('
            INSERT INTO characters (name, intro, description, image, id_user, id_template, id_world, image_display_mode, image_fit, image_focus_x, image_focus_y, image_zoom)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ');

        $stmt->execute([
            $name,
            $intro,
            $description,
            $image,
            $userId,
            $templateId,
            $worldId,
            $imageDisplay['mode'],
            $imageDisplay['fit'],
            $imageDisplay['focusX'],
            $imageDisplay['focusY'],
            $imageDisplay['zoom'],
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function getCharacterById(int $id): ?Character
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM characters WHERE id = :id
        ');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $char = $stmt->fetch(PDO::FETCH_ASSOC);
        return $char ? $this->hydrate($char) : null;
    }
    
    /**
     * Wyszukiwanie postaci po nazwie i filtrach (wszystkie filtry muszą pasować).
     * Filtry są porównywane po nazwie (case-insensitive). Dziedziczenie filtrów
     * z folderów jest obsługiwane przez rekursywne CTE (ancestors).
     *
     * @param int $userId
     * @param string|null $nameLike
     * @param array $filterNames
     * @return Character[]
     */
    public function searchCharactersByNameAndFilters(int $userId, ?string $nameLike, array $filterNames = [], array $blockedFilterIds = [], bool $includeHidden = false): array
    {
        $params = [':userId' => $userId];

        $nameClause = '';
        if ($nameLike !== null && trim($nameLike) !== '') {
            $tokens = preg_split('/\s+/', trim($nameLike));
            $tokenClauses = [];
            foreach ($tokens as $i => $token) {
                $key = ':nameLike' . $i;
                $params[$key] = '%' . mb_strtolower($token) . '%';
                $tokenClauses[] = 'LOWER(c.name) LIKE ' . $key;
            }
            if (!empty($tokenClauses)) {
                $nameClause = 'AND (' . implode(' AND ', $tokenClauses) . ')';
            }
        }

        // Start building SQL
        $sql = "SELECT DISTINCT c.* FROM characters c WHERE c.id_user = :userId " . $nameClause . $this->blockedContentClause('c.id', 'character', $blockedFilterIds) . $this->hiddenContentClause('c', $includeHidden);

        // For each filter name require existence either on character or in ancestor worlds
        foreach ($filterNames as $i => $fname) {
            $key = ':filter' . $i;
            $params[$key] = mb_strtolower($fname);

            $sql .= " AND (
                EXISTS(
                    SELECT 1 FROM character_filters cf
                    JOIN filters f ON cf.id_filter = f.id
                    WHERE cf.id_character = c.id AND (LOWER(f.name) = " . $key . " OR LOWER(f.slug) = " . $key . " OR LOWER(f.label) = " . $key . "))
                OR EXISTS(
                    WITH RECURSIVE ancestors(id, parent_id) AS (
                        SELECT w.id, w.parent_id FROM worlds w WHERE w.id = c.id_world
                        UNION ALL
                        SELECT w2.id, w2.parent_id FROM worlds w2 JOIN ancestors a ON w2.id = a.parent_id
                    )
                    SELECT 1 FROM world_filters wf JOIN filters fw ON wf.id_filter = fw.id
                    WHERE wf.id_world IN (SELECT id FROM ancestors) AND (LOWER(fw.name) = " . $key . " OR LOWER(fw.slug) = " . $key . " OR LOWER(fw.label) = " . $key . ")
                )
            )";
        }

        $sql .= " ORDER BY c.is_main_character DESC, c.is_pinned DESC, c.name ASC, c.id ASC";

        $stmt = $this->database->connect()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $r) {
            $result[] = $this->hydrate($r);
        }

        return $result;
    }

    public function searchGlobalCharacters(int $userId, string $query, array $blockedFilterIds = [], bool $includeHidden = false, bool $includeAdult = false, int $limit = 8): array
    {
        $like = '%' . mb_strtolower(trim($query)) . '%';
        $adultClause = $includeAdult ? '' : $this->adultContentClause('c.id', 'character', 'c.image');
        $sql = "
            SELECT DISTINCT c.*
            FROM characters c
            LEFT JOIN worlds w ON w.id = c.id_world
            LEFT JOIN character_field_values cfv ON cfv.id_character = c.id
            LEFT JOIN template_fields tf ON tf.id = cfv.id_template_field
            LEFT JOIN character_filters legacy_cf ON legacy_cf.id_character = c.id
            LEFT JOIN filters legacy_f ON legacy_f.id = legacy_cf.id_filter
            LEFT JOIN content_filters obj_cf ON obj_cf.object_type = 'character' AND obj_cf.object_id = c.id
            LEFT JOIN filters obj_f ON obj_f.id = obj_cf.id_filter
            WHERE c.id_user = :userId
              " . $this->blockedContentClause('c.id', 'character', $blockedFilterIds) . "
              " . $this->hiddenContentClause('c', $includeHidden) . "
              {$adultClause}
              AND (
                  LOWER(c.name) LIKE :q
                  OR LOWER(COALESCE(c.intro, '')) LIKE :q
                  OR LOWER(COALESCE(c.description, '')) LIKE :q
                  OR LOWER(COALESCE(w.name, '')) LIKE :q
                  OR LOWER(COALESCE(tf.label, '')) LIKE :q
                  OR LOWER(COALESCE(cfv.value, '')) LIKE :q
                  OR LOWER(COALESCE(legacy_f.name, '')) LIKE :q
                  OR LOWER(COALESCE(legacy_f.slug, '')) LIKE :q
                  OR LOWER(COALESCE(legacy_f.label, '')) LIKE :q
                  OR LOWER(COALESCE(obj_f.name, '')) LIKE :q
                  OR LOWER(COALESCE(obj_f.slug, '')) LIKE :q
                  OR LOWER(COALESCE(obj_f.label, '')) LIKE :q
              )
            ORDER BY c.is_main_character DESC, c.is_pinned DESC, c.name ASC, c.id ASC
            LIMIT :limit
        ";
        $stmt = $this->database->connect()->prepare($sql);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':q', $like);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getCharacterByIdAndUserId(int $id, int $userId): ?Character
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM characters WHERE id = :id AND id_user = :userId
        ');
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $char = $stmt->fetch(PDO::FETCH_ASSOC);
        return $char ? $this->hydrate($char) : null;
    }

    public function getCharacterByPublicIdAndUserId(string $publicId, int $userId): ?Character
    {
        $stmt = $this->database->connect()->prepare('
            SELECT * FROM characters WHERE public_id::text = :publicId AND id_user = :userId
        ');
        $stmt->bindValue(':publicId', $publicId);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $char = $stmt->fetch(PDO::FETCH_ASSOC);
        return $char ? $this->hydrate($char) : null;
    }

    public function updateCharacter(int $id, string $name, string $description, string $image, ?int $templateId, ?int $worldId = null, array $imageDisplay = [], string $intro = ''): void
    {
        $imageDisplay = $this->normalizeImageDisplay($imageDisplay);
        $stmt = $this->database->connect()->prepare('
            UPDATE characters 
            SET name = ?, intro = ?, description = ?, image = ?, id_template = ?, id_world = ?,
                image_display_mode = ?, image_fit = ?, image_focus_x = ?, image_focus_y = ?, image_zoom = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $name,
            $intro,
            $description,
            $image,
            $templateId,
            $worldId,
            $imageDisplay['mode'],
            $imageDisplay['fit'],
            $imageDisplay['focusX'],
            $imageDisplay['focusY'],
            $imageDisplay['zoom'],
            $id
        ]);
    }

    public function updateCharacterStatus(int $characterId, ?int $statusId): void
    {
        $stmt = $this->database->connect()->prepare('
            UPDATE characters 
            SET status_id = ?
            WHERE id = ?
        ');
        $stmt->execute([$statusId, $characterId]);
    }

    public function setHidden(int $characterId, int $userId, bool $hidden): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE characters SET is_hidden = :hidden WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([
            ':hidden' => $hidden ? 1 : 0,
            ':id' => $characterId,
            ':userId' => $userId,
        ]);
    }

    public function setPinned(int $characterId, int $userId, bool $pinned): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE characters
             SET is_pinned = :pinned
             WHERE id = :id AND id_user = :userId AND COALESCE(is_main_character, FALSE) = FALSE'
        );
        $stmt->execute([
            ':pinned' => $pinned ? 1 : 0,
            ':id' => $characterId,
            ':userId' => $userId,
        ]);
    }

    public function setMainCharacter(int $characterId, int $userId, bool $isMainCharacter): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE characters
             SET is_main_character = :isMainCharacter,
                 is_pinned = CASE WHEN :clearPinned = 1 THEN FALSE ELSE is_pinned END
             WHERE id = :id AND id_user = :userId'
        );
        $stmt->execute([
            ':isMainCharacter' => $isMainCharacter ? 1 : 0,
            ':clearPinned' => $isMainCharacter ? 1 : 0,
            ':id' => $characterId,
            ':userId' => $userId,
        ]);
    }

    public function isHiddenInPath(int $characterId, int $userId): bool
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT EXISTS (
                SELECT 1
                FROM characters c
                WHERE c.id = :id
                  AND c.id_user = :userId
                  AND (
                    COALESCE(c.is_hidden, FALSE) = TRUE
                    OR EXISTS (
                        WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                            SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                            FROM worlds w
                            WHERE w.id = c.id_world
                            UNION ALL
                            SELECT w2.id, w2.parent_id, COALESCE(w2.is_hidden, FALSE)
                            FROM worlds w2
                            JOIN ancestors a ON w2.id = a.parent_id
                        )
                        SELECT 1 FROM ancestors WHERE is_hidden = TRUE
                    )
                  )
            )"
        );
        $stmt->execute([':id' => $characterId, ':userId' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public function duplicateCharacter(int $characterId, int $userId): ?int
    {
        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT * FROM characters WHERE id = :id AND id_user = :userId');
            $stmt->bindValue(':id', $characterId, PDO::PARAM_INT);
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $character = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$character) {
                $db->rollBack();
                return null;
            }

            $insert = $db->prepare('
                INSERT INTO characters (name, intro, description, image, id_user, id_template, id_world, status_id, is_hidden)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id
            ');
            $insert->execute([
                $character['name'] . ' (kopia)',
                $character['intro'] ?? '',
                $character['description'],
                $character['image'],
                $character['id_user'],
                $character['id_template'],
                $character['id_world'],
                $character['status_id'],
                $character['is_hidden'] ?? false,
            ]);
            $newCharacterId = (int)$insert->fetchColumn();

            $copyFields = $db->prepare('
                INSERT INTO character_field_values (id_character, id_template_field, value)
                SELECT ?, id_template_field, value
                FROM character_field_values
                WHERE id_character = ?
            ');
            $copyFields->execute([$newCharacterId, $characterId]);

            $variantStmt = $db->prepare('
                SELECT * FROM character_variants
                WHERE id_character = ?
                ORDER BY order_number ASC, id ASC
            ');
            $variantStmt->execute([$characterId]);

            $hasVariantVisibility = $this->characterVariantVisibilityColumnsExist();
            $insertVariant = $db->prepare($hasVariantVisibility
                ? 'INSERT INTO character_variants (id_character, name, image, is_adult, is_hidden, order_number) VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
                : 'INSERT INTO character_variants (id_character, name, image, order_number) VALUES (?, ?, ?, ?) RETURNING id'
            );
            $copyVariantValues = $db->prepare('
                INSERT INTO character_variant_field_values (id_variant, id_template_field, value)
                SELECT ?, id_template_field, value
                FROM character_variant_field_values
                WHERE id_variant = ?
            ');

            foreach ($variantStmt->fetchAll(PDO::FETCH_ASSOC) as $variant) {
                $variantPayload = [
                    $newCharacterId,
                    $variant['name'],
                    $variant['image'],
                ];
                if ($hasVariantVisibility) {
                    $variantPayload[] = !empty($variant['is_adult']) ? 1 : 0;
                    $variantPayload[] = !empty($variant['is_hidden']) ? 1 : 0;
                }
                $variantPayload[] = $variant['order_number'];
                $insertVariant->execute($variantPayload);
                $newVariantId = (int)$insertVariant->fetchColumn();
                $copyVariantValues->execute([$newVariantId, $variant['id']]);
            }

            $db->commit();
            return $newCharacterId;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteCharacter(int $characterId, int $userId): void
    {
        $stmt = $this->database->connect()->prepare(
            'DELETE FROM characters WHERE id = :id AND id_user = :userId'
        );
        $stmt->bindValue(':id', $characterId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function countImageReferences(string $filename): int
    {
        $stmt = $this->database->connect()->prepare('
            SELECT
                (SELECT COUNT(*) FROM characters WHERE image = :filename)
                +
                (SELECT COUNT(*) FROM character_variants WHERE image = :filename)
        ');
        $stmt->bindValue(':filename', $filename);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public function getCharacterFieldValues(int $characterId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT id_template_field, value 
            FROM character_field_values 
            WHERE id_character = :characterId
        ');
        $stmt->bindParam(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->execute();

        $mapped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapped[$row['id_template_field']] = $row['value'];
        }

        return $mapped;
    }

    public function getCharacterVariants(int $characterId): array
    {
        $select = $this->characterVariantSelectList();
        $stmt = $this->database->connect()->prepare('
            SELECT ' . $select . ' FROM character_variants
            WHERE id_character = :characterId
            ORDER BY order_number ASC, id ASC
        ');
        $stmt->bindParam(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->execute();

        $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filtersByVariantId = $this->getVariantFiltersByIds(array_column($variants, 'id'));
        foreach ($variants as &$variant) {
            $variant['values'] = $this->getVariantFieldValues((int)$variant['id']);
            $variant['content_filters'] = $filtersByVariantId[(int)$variant['id']] ?? [];
        }

        return $variants;
    }

    public function getCharacterVariantsByCharacterIds(array $characterIds, bool $includeHidden = false, bool $includeAdult = true): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $characterIds))));
        if (empty($ids)) {
            return [];
        }

        $hasVariantVisibility = $this->characterVariantVisibilityColumnsExist();
        $select = $this->characterVariantSelectList();
        $clauses = ['id_character IN (' . implode(',', $ids) . ')'];
        if ($hasVariantVisibility && !$includeHidden) {
            $clauses[] = 'COALESCE(is_hidden, FALSE) = FALSE';
        }
        if ($hasVariantVisibility && !$includeAdult) {
            $clauses[] = 'COALESCE(is_adult, FALSE) = FALSE';
        }

        $stmt = $this->database->connect()->prepare('
            SELECT ' . $select . ' FROM character_variants
            WHERE ' . implode(' AND ', $clauses) . '
            ORDER BY id_character ASC, order_number ASC, id ASC
        ');
        $stmt->execute();

        $mapped = [];
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filtersByVariantId = $this->getVariantFiltersByIds(array_column($rows, 'id'));
        foreach ($rows as $variant) {
            $variant['content_filters'] = $filtersByVariantId[(int)$variant['id']] ?? [];
            if (!$includeAdult && $this->filtersHaveAdult($variant['content_filters'])) {
                continue;
            }
            $mapped[(int)$variant['id_character']][] = $variant;
        }

        return $mapped;
    }

    public function getCharacterVariant(int $variantId, int $characterId): ?array
    {
        $select = $this->characterVariantSelectList();
        $stmt = $this->database->connect()->prepare('
            SELECT ' . $select . ' FROM character_variants
            WHERE id = :variantId AND id_character = :characterId
        ');
        $stmt->bindParam(':variantId', $variantId, PDO::PARAM_INT);
        $stmt->bindParam(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->execute();

        $variant = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$variant) {
            return null;
        }

        $variant['values'] = $this->getVariantFieldValues((int)$variant['id']);
        $variant['content_filters'] = $this->getVariantFiltersByIds([(int)$variant['id']])[(int)$variant['id']] ?? [];
        return $variant;
    }

    public function replaceCharacterVariants(int $characterId, array $variants): void
    {
        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $oldIdsStmt = $db->prepare('SELECT id FROM character_variants WHERE id_character = ?');
            $oldIdsStmt->execute([$characterId]);
            $oldVariantIds = array_map('intval', $oldIdsStmt->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($oldVariantIds)) {
                $db->exec("DELETE FROM content_filters WHERE object_type = 'character_variant' AND object_id IN (" . implode(',', $oldVariantIds) . ")");
            }

            $stmtDel = $db->prepare('DELETE FROM character_variants WHERE id_character = ?');
            $stmtDel->execute([$characterId]);

            $hasVariantVisibility = $this->characterVariantVisibilityColumnsExist();
            $hasVariantImage = $this->characterVariantImageColumnsExist();
            $columns = ['id_character', 'name'];
            if ($this->characterVariantDescriptionColumnExist()) {
                $columns[] = 'description';
            }
            $columns[] = 'image';
            if ($hasVariantVisibility) {
                array_push($columns, 'is_adult', 'is_hidden');
            }
            if ($hasVariantImage) {
                array_push($columns, 'image_fit', 'image_focus_x', 'image_focus_y', 'image_zoom');
            }
            $columns[] = 'order_number';
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmtVariant = $db->prepare(
                'INSERT INTO character_variants (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ') RETURNING id'
            );
            $stmtValue = $db->prepare('
                INSERT INTO character_variant_field_values (id_variant, id_template_field, value)
                VALUES (?, ?, ?)
            ');
            $stmtFilter = $db->prepare(
                "INSERT INTO content_filters (object_type, object_id, id_filter)
                 VALUES ('character_variant', ?, ?)
                 ON CONFLICT (object_type, object_id, id_filter) DO NOTHING"
            );

            foreach ($variants as $index => $variant) {
                $name = trim($variant['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $variantPayload = [
                    $characterId,
                    $name,
                ];
                if ($this->characterVariantDescriptionColumnExist()) {
                    $variantPayload[] = (string)($variant['description'] ?? '');
                }
                $variantPayload[] = $variant['image'] ?? null;
                if ($hasVariantVisibility) {
                    $variantPayload[] = !empty($variant['is_adult']) ? 1 : 0;
                    $variantPayload[] = !empty($variant['is_hidden']) ? 1 : 0;
                }
                if ($hasVariantImage) {
                    $imageDisplay = $this->normalizeImageDisplay([
                        'fit' => $variant['image_fit'] ?? 'cover',
                        'focusX' => $variant['image_focus_x'] ?? 50,
                        'focusY' => $variant['image_focus_y'] ?? 50,
                        'zoom' => $variant['image_zoom'] ?? 1,
                    ]);
                    $variantPayload[] = $imageDisplay['fit'];
                    $variantPayload[] = $imageDisplay['focusX'];
                    $variantPayload[] = $imageDisplay['focusY'];
                    $variantPayload[] = $imageDisplay['zoom'];
                }
                $variantPayload[] = $index;
                $stmtVariant->execute($variantPayload);
                $variantId = (int)$stmtVariant->fetchColumn();

                foreach (array_values(array_unique(array_filter(array_map('intval', $variant['filter_ids'] ?? [])))) as $filterId) {
                    $stmtFilter->execute([$variantId, $filterId]);
                }

                foreach (($variant['values'] ?? []) as $fieldId => $value) {
                    if ($value !== null && $value !== '') {
                        $stmtValue->execute([$variantId, $fieldId, $value]);
                    }
                }
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function getVariantFieldValues(int $variantId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT id_template_field, value
            FROM character_variant_field_values
            WHERE id_variant = :variantId
        ');
        $stmt->bindParam(':variantId', $variantId, PDO::PARAM_INT);
        $stmt->execute();

        $mapped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapped[$row['id_template_field']] = $row['value'];
        }

        return $mapped;
    }

    private function getVariantFiltersByIds(array $variantIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $variantIds))));
        if (empty($ids)) {
            return [];
        }

        $stmt = $this->database->connect()->query(
            'SELECT cf.object_id AS variant_id, f.*
             FROM content_filters cf
             JOIN filters f ON f.id = cf.id_filter
             WHERE cf.object_type = ' . $this->database->connect()->quote('character_variant') . '
               AND cf.object_id IN (' . implode(',', $ids) . ')
             ORDER BY f.label ASC, f.name ASC'
        );

        $mapped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $variantId = (int)$row['variant_id'];
            unset($row['variant_id']);
            $mapped[$variantId][] = $row;
        }

        return $mapped;
    }

    private function characterVariantSelectList(): string
    {
        $select = ['*'];
        if (!$this->characterVariantVisibilityColumnsExist()) {
            $select[] = 'FALSE AS is_adult';
            $select[] = 'FALSE AS is_hidden';
        }
        if (!$this->characterVariantImageColumnsExist()) {
            $select[] = "'cover' AS image_fit";
            $select[] = '50 AS image_focus_x';
            $select[] = '50 AS image_focus_y';
            $select[] = '1 AS image_zoom';
        }
        if (!$this->characterVariantDescriptionColumnExist()) {
            $select[] = "'' AS description";
        }
        return implode(', ', $select);
    }

    private function filtersHaveAdult(array $filters): bool
    {
        foreach ($filters as $filter) {
            foreach (['slug', 'name', 'label'] as $key) {
                $value = mb_strtolower(trim((string)($filter[$key] ?? '')));
                if (in_array($value, ['adult', 'nsfw', '+18', '18+'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function characterVariantVisibilityColumnsExist(): bool
    {
        if ($this->variantVisibilityColumnsExist !== null) {
            return $this->variantVisibilityColumnsExist;
        }

        $stmt = $this->database->connect()->query("
            SELECT COUNT(*) = 2
            FROM information_schema.columns
            WHERE table_name = 'character_variants'
              AND column_name IN ('is_adult', 'is_hidden')
        ");
        $this->variantVisibilityColumnsExist = (bool)$stmt->fetchColumn();
        return $this->variantVisibilityColumnsExist;
    }

    private function characterVariantImageColumnsExist(): bool
    {
        if ($this->variantImageColumnsExist !== null) {
            return $this->variantImageColumnsExist;
        }

        $stmt = $this->database->connect()->query("
            SELECT COUNT(*) = 4
            FROM information_schema.columns
            WHERE table_name = 'character_variants'
              AND column_name IN ('image_fit', 'image_focus_x', 'image_focus_y', 'image_zoom')
        ");
        $this->variantImageColumnsExist = (bool)$stmt->fetchColumn();
        return $this->variantImageColumnsExist;
    }

    private function characterVariantDescriptionColumnExist(): bool
    {
        if ($this->variantDescriptionColumnExist !== null) {
            return $this->variantDescriptionColumnExist;
        }

        $stmt = $this->database->connect()->query("
            SELECT COUNT(*) = 1
            FROM information_schema.columns
            WHERE table_name = 'character_variants'
              AND column_name = 'description'
        ");
        $this->variantDescriptionColumnExist = (bool)$stmt->fetchColumn();
        return $this->variantDescriptionColumnExist;
    }

    public function saveCharacterFieldValues(int $characterId, array $fieldValues): void
    {
        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $stmtDel = $db->prepare('DELETE FROM character_field_values WHERE id_character = :charId');
            $stmtDel->bindParam(':charId', $characterId, PDO::PARAM_INT);
            $stmtDel->execute();

            $stmtInsert = $db->prepare('
                INSERT INTO character_field_values (id_character, id_template_field, value)
                VALUES (?, ?, ?)
            ');

            foreach ($fieldValues as $fieldId => $value) {
                if ($value !== null && $value !== '') {
                    $stmtInsert->execute([$characterId, $fieldId, $value]);
                }
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function blockedContentClause(string $objectIdExpression, string $objectType, array $blockedFilterIds): string
    {
        $condition = $this->blockedContentCondition($objectIdExpression, $objectType, $blockedFilterIds);
        return $condition === 'TRUE' ? '' : ' AND ' . $condition;
    }

    private function blockedContentCondition(string $objectIdExpression, string $objectType, array $blockedFilterIds): string
    {
        $blockedFilterIds = array_values(array_unique(array_filter(array_map('intval', $blockedFilterIds))));
        if (empty($blockedFilterIds)) {
            return 'TRUE';
        }

        $ids = implode(',', $blockedFilterIds);
        return "NOT EXISTS (
            SELECT 1 FROM content_filters blocked_cf
            WHERE blocked_cf.object_type = '{$objectType}'
              AND blocked_cf.object_id = {$objectIdExpression}
              AND blocked_cf.id_filter IN ({$ids})
        ) AND NOT EXISTS (
            SELECT 1 FROM character_filters blocked_legacy_cf
            WHERE '{$objectType}' = 'character'
              AND blocked_legacy_cf.id_character = {$objectIdExpression}
              AND blocked_legacy_cf.id_filter IN ({$ids})
        )";
    }

    private function characterListVisibilityClause(array $blockedFilterIds, bool $includeHidden, bool $includeAdult): string
    {
        $baseAllowed = $this->blockedContentCondition('c.id', 'character', $blockedFilterIds);
        if (!$includeHidden) {
            $baseAllowed = "COALESCE(c.is_hidden, FALSE) = FALSE AND ({$baseAllowed})";
        }

        $variantAllowed = $this->blockedContentCondition('cv.id', 'character_variant', $blockedFilterIds);
        $variantHiddenClause = '';
        if (!$includeHidden && $this->characterVariantVisibilityColumnsExist()) {
            $variantHiddenClause = ' AND COALESCE(cv.is_hidden, FALSE) = FALSE';
        }
        if (!$includeAdult && $this->characterVariantVisibilityColumnsExist()) {
            $variantHiddenClause .= ' AND COALESCE(cv.is_adult, FALSE) = FALSE';
        }

        $worldHiddenClause = '';
        if (!$includeHidden) {
            $worldHiddenClause = " AND NOT EXISTS (
                WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                    SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                    FROM worlds w
                    WHERE w.id = c.id_world
                    UNION ALL
                    SELECT w2.id, w2.parent_id, COALESCE(w2.is_hidden, FALSE)
                    FROM worlds w2
                    JOIN ancestors a ON w2.id = a.parent_id
                )
                SELECT 1 FROM ancestors WHERE is_hidden = TRUE
            )";
        }

        return $worldHiddenClause . " AND (
            ({$baseAllowed})
            OR EXISTS (
                SELECT 1
                FROM character_variants cv
                WHERE cv.id_character = c.id
                  {$variantHiddenClause}
                  AND ({$variantAllowed})
            )
        )";
    }

    private function hydrate(array $char): Character
    {
        return new Character(
            $char['name'],
            $char['description'],
            $char['image'],
            $char['id_user'],
            $char['id'],
            $char['id_template'],
            $char['id_world'] ?? null,
            $char['status_id'] ?? null,
            $char['image_display_mode'] ?? 'square',
            $char['image_fit'] ?? 'cover',
            $char['image_focus_x'] ?? 50,
            $char['image_focus_y'] ?? 50,
            $char['image_zoom'] ?? 1,
            $char['intro'] ?? '',
            $char['is_hidden'] ?? false,
            $char['public_id'] ?? null,
            $char['is_main_character'] ?? false,
            $char['is_pinned'] ?? false
        );
    }

    private function hiddenContentClause(string $alias, bool $includeHidden): string
    {
        if ($includeHidden) {
            return '';
        }

        return " AND COALESCE({$alias}.is_hidden, FALSE) = FALSE
            AND NOT EXISTS (
                WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                    SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                    FROM worlds w
                    WHERE w.id = {$alias}.id_world
                    UNION ALL
                    SELECT w2.id, w2.parent_id, COALESCE(w2.is_hidden, FALSE)
                    FROM worlds w2
                    JOIN ancestors a ON w2.id = a.parent_id
                )
                SELECT 1 FROM ancestors WHERE is_hidden = TRUE
            )";
    }

    private function adultContentClause(string $objectIdExpression, string $objectType, string $imageExpression = "''"): string
    {
        return " AND NOT EXISTS (
            SELECT 1
            FROM content_filters adult_cf
            JOIN filters adult_f ON adult_f.id = adult_cf.id_filter
            WHERE adult_cf.object_type = '{$objectType}'
              AND adult_cf.object_id = {$objectIdExpression}
              AND LOWER(COALESCE(adult_f.slug, adult_f.name, adult_f.label, '')) IN ('adult', 'nsfw', '+18', '18+')
        ) AND NOT EXISTS (
            SELECT 1
            FROM character_filters adult_legacy_cf
            JOIN filters adult_legacy_f ON adult_legacy_f.id = adult_legacy_cf.id_filter
            WHERE '{$objectType}' = 'character'
              AND adult_legacy_cf.id_character = {$objectIdExpression}
              AND LOWER(COALESCE(adult_legacy_f.slug, adult_legacy_f.name, adult_legacy_f.label, '')) IN ('adult', 'nsfw', '+18', '18+')
        ) AND NOT EXISTS (
            WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT w.id, w.parent_id
                FROM worlds w
                WHERE w.id = c.id_world
                UNION ALL
                SELECT w2.id, w2.parent_id
                FROM worlds w2
                JOIN ancestors a ON w2.id = a.parent_id
            )
            SELECT 1
            FROM ancestors
            LEFT JOIN world_filters adult_wf ON adult_wf.id_world = ancestors.id
            LEFT JOIN content_filters adult_wcf ON adult_wcf.object_type = 'world' AND adult_wcf.object_id = ancestors.id
            JOIN filters adult_world_f ON adult_world_f.id = COALESCE(adult_wf.id_filter, adult_wcf.id_filter)
            WHERE '{$objectType}' = 'character'
              AND (
                LOWER(COALESCE(adult_world_f.slug, '')) IN ('adult', 'nsfw', '+18', '18+')
                OR LOWER(COALESCE(adult_world_f.name, '')) IN ('adult', 'nsfw', '+18', '18+')
                OR LOWER(COALESCE(adult_world_f.label, '')) IN ('adult', 'nsfw', '+18', '18+')
              )
        ) AND NOT EXISTS (
            SELECT 1
            FROM image_assets adult_img
            WHERE adult_img.filename = {$imageExpression}
              AND adult_img.visibility = 'adult'
        )";
    }

    private function normalizeImageDisplay(array $display): array
    {
        $rawMode = $display['mode'] ?? 'square';
        $rawFit = $display['fit'] ?? 'cover';
        $mode = in_array($rawMode, ['square', 'natural'], true) ? $rawMode : 'square';
        $fit = in_array($rawFit, ['cover', 'contain'], true) ? $rawFit : 'cover';
        $focusX = max(0, min(100, (int)($display['focusX'] ?? 50)));
        $focusY = max(0, min(100, (int)($display['focusY'] ?? 50)));
        $zoom = max(1, min(6, (float)($display['zoom'] ?? 1)));

        return [
            'mode' => $mode,
            'fit' => $fit,
            'focusX' => $focusX,
            'focusY' => $focusY,
            'zoom' => $zoom,
        ];
    }
}
