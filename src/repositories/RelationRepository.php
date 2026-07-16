<?php

require_once 'Repository.php';

class RelationRepository extends Repository
{
    public function getRelationTypes(): array
    {
        $stmt = $this->database->connect()->query('
            SELECT id, code, name, icon, color_hex, is_custom
            FROM relation_types
            ORDER BY order_number ASC, id ASC
        ');

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCustomRelationPresets(int $userId): array
    {
        $stmt = $this->database->connect()->prepare("
            SELECT DISTINCT ON (LOWER(custom_name), COALESCE(is_nsfw, FALSE))
                custom_name,
                COALESCE(custom_icon, '') AS custom_icon,
                COALESCE(custom_color_hex, '#8E44AD') AS custom_color_hex,
                COALESCE(is_nsfw, FALSE) AS is_nsfw
            FROM character_relations
            WHERE id_user = :userId
              AND custom_name IS NOT NULL
              AND BTRIM(custom_name) <> ''
            ORDER BY LOWER(custom_name), COALESCE(is_nsfw, FALSE), updated_at DESC, id DESC
        ");
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBoards(int $userId, bool $includeHidden = false): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT
                b.*,
                (SELECT COUNT(*) FROM relation_board_characters bc WHERE bc.id_board = b.id) AS character_count,
                (SELECT COUNT(*) FROM relation_tree_nodes n WHERE n.id_board = b.id) AS node_count
            FROM relation_boards b
            WHERE b.id_user = :userId
              ' . $this->hiddenBoardClause('b', $includeHidden) . '
            ORDER BY b.updated_at DESC, b.id DESC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$includeHidden) {
            $boards = array_values(array_filter(
                $boards,
                fn($board) => !$this->boardHasHiddenContent($userId, (int)$board['id'])
            ));
        }

        foreach ($boards as &$board) {
            $board['worldIds'] = $this->getBoardWorldIds((int)$board['id']);
            $board['characterIds'] = $this->getBoardCharacterIds((int)$board['id']);
            $board['relation_count'] = count($this->getTreeRelations($userId, (int)$board['id']));
        }

        return $boards;
    }

    public function getBoard(int $userId, int $boardId, bool $includeHidden = true): ?array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT *
            FROM relation_boards
            WHERE id = :boardId AND id_user = :userId
              ' . $this->hiddenBoardClause('', $includeHidden) . '
        ');
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $board = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$board) {
            return null;
        }

        if (!$includeHidden && $this->boardHasHiddenContent($userId, $boardId)) {
            return null;
        }

        $board['worldIds'] = $this->getBoardWorldIds($boardId);
        $board['characterIds'] = $this->getBoardCharacterIds($boardId);
        return $board;
    }

    public function getBoardByPublicId(int $userId, string $publicId, bool $includeHidden = true): ?array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT *
            FROM relation_boards
            WHERE public_id::text = :publicId AND id_user = :userId
              ' . $this->hiddenBoardClause('', $includeHidden) . '
        ');
        $stmt->bindValue(':publicId', $publicId);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $board = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$board) {
            return null;
        }

        $boardId = (int)$board['id'];
        if (!$includeHidden && $this->boardHasHiddenContent($userId, $boardId)) {
            return null;
        }

        $board['worldIds'] = $this->getBoardWorldIds($boardId);
        $board['characterIds'] = $this->getBoardCharacterIds($boardId);
        return $board;
    }

    public function saveBoard(int $userId, ?int $boardId, string $name, string $description, array $worldIds, array $characterIds): int
    {
        $db = $this->database->connect();
        $worldIds = array_values(array_unique(array_filter(array_map('intval', $worldIds))));
        $characterIds = array_values(array_unique(array_filter(array_map('intval', $characterIds))));

        foreach ($worldIds as $worldId) {
            $this->assertWorldBelongsToUser($worldId, $userId);
        }

        foreach ($characterIds as $characterId) {
            $this->assertCharacterBelongsToUser($characterId, $userId);
        }

        try {
            $db->beginTransaction();

            if ($boardId) {
                $existing = $this->getBoard($userId, $boardId);
                if (!$existing) {
                    throw new RuntimeException('Pole relacji nie istnieje.');
                }
                $stmt = $db->prepare('
                    UPDATE relation_boards
                    SET name = :name, description = :description, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :boardId AND id_user = :userId
                ');
                $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
                $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':description', $description);
                $stmt->execute();
            } else {
                $stmt = $db->prepare('
                    INSERT INTO relation_boards (id_user, name, description)
                    VALUES (:userId, :name, :description)
                    RETURNING id
                ');
                $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':description', $description);
                $stmt->execute();
                $boardId = (int)$stmt->fetchColumn();
            }

            $this->replaceBoardMembership($db, $boardId, $worldIds, $characterIds, $userId);

            $db->commit();
            return $boardId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function duplicateBoard(int $userId, int $boardId): int
    {
        $board = $this->getBoard($userId, $boardId);
        if (!$board) {
            throw new RuntimeException('Pole relacji nie istnieje.');
        }

        $newId = $this->saveBoard(
            $userId,
            null,
            $board['name'] . ' (kopia)',
            $board['description'] ?? '',
            $board['worldIds'],
            $board['characterIds']
        );

        $db = $this->database->connect();
        $stmt = $db->prepare('
            UPDATE relation_tree_nodes target
            SET position_x = source.position_x,
                position_y = source.position_y,
                updated_at = CURRENT_TIMESTAMP
            FROM relation_tree_nodes source
            WHERE target.id_board = :newId
              AND source.id_board = :oldId
              AND target.id_character = source.id_character
              AND target.id_user = :userId
        ');
        $stmt->bindValue(':newId', $newId, PDO::PARAM_INT);
        $stmt->bindValue(':oldId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $newId;
    }

    public function deleteBoard(int $userId, int $boardId): void
    {
        $stmt = $this->database->connect()->prepare('
            DELETE FROM relation_boards
            WHERE id = :boardId AND id_user = :userId
        ');
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function setBoardHidden(int $userId, int $boardId, bool $hidden): void
    {
        $stmt = $this->database->connect()->prepare('
            UPDATE relation_boards
            SET is_hidden = :hidden, updated_at = CURRENT_TIMESTAMP
            WHERE id = :boardId AND id_user = :userId
        ');
        $stmt->bindValue(':hidden', $hidden, PDO::PARAM_BOOL);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function getTreeData(int $userId, int $boardId, bool $includeHidden = false): array
    {
        $board = $this->getBoard($userId, $boardId, $includeHidden);
        if (!$board) {
            throw new RuntimeException('Pole relacji nie istnieje.');
        }

        return [
            'board' => $board,
            'types' => $this->getRelationTypes(),
            'nodes' => $this->getTreeNodes($userId, $boardId, $includeHidden),
            'relations' => $this->getTreeRelations($userId, $boardId),
            'availableCharacters' => $this->getAvailableCharacters($userId, $boardId, $includeHidden),
            'customRelationPresets' => $this->getCustomRelationPresets($userId),
            'ruleCharacters' => $this->getAllCharactersForRules($userId, $includeHidden),
            'worlds' => $this->getWorldOptions($userId, $includeHidden),
            'rules' => ['excludedWorldIds' => [], 'exceptionCharacterIds' => []],
        ];
    }

    public function getTreeOverview(int $userId): array
    {
        $overview = [];
        foreach ($this->getBoards($userId) as $board) {
            $overview[(int)$board['id']] = [
                'nodeCount' => (int)$board['node_count'],
                'relationCount' => (int)$board['relation_count'],
            ];
        }

        return $overview;
    }

    public function getTreeNodes(int $userId, int $boardId, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : $this->visibleCharacterClause('c');
        $variantHiddenClause = $includeHidden ? '' : ' AND (n.id_variant IS NULL OR COALESCE(cv.is_hidden, FALSE) = FALSE)';
        $stmt = $this->database->connect()->prepare('
            SELECT
                n.id,
                n.id_character AS character_id,
                n.id_variant AS variant_id,
                n.id_character::TEXT || \':\' || COALESCE(n.id_variant, 0)::TEXT AS entity_key,
                n.position_x,
                n.position_y,
                CASE WHEN cv.id IS NULL THEN c.name ELSE c.name || \' - \' || cv.name END AS name,
                c.name AS base_name,
                cv.name AS variant_name,
                COALESCE(NULLIF(cv.image, \'\'), c.image) AS image,
                COALESCE(cv.image_fit, c.image_fit) AS image_fit,
                COALESCE(cv.image_focus_x, c.image_focus_x) AS image_focus_x,
                COALESCE(cv.image_focus_y, c.image_focus_y) AS image_focus_y,
                COALESCE(cv.image_zoom, c.image_zoom) AS image_zoom,
                c.id_world,
                ' . $this->variantAwareNsfwSelect('c', 'cv') . ' AS is_nsfw
            FROM relation_tree_nodes n
            JOIN characters c ON c.id = n.id_character AND c.id_user = n.id_user
            LEFT JOIN character_variants cv ON cv.id = n.id_variant AND cv.id_character = c.id
            WHERE n.id_user = :userId
              AND n.id_board = :boardId
              ' . $hiddenClause . '
              ' . $variantHiddenClause . '
            ORDER BY n.created_at ASC, n.id ASC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTreeRelations(int $userId, int $boardId): array
    {
        $stmt = $this->database->connect()->prepare('
            WITH tree_entities AS (
                SELECT
                    id_character,
                    id_variant,
                    id_character::TEXT || \':\' || COALESCE(id_variant, 0)::TEXT AS entity_key
                FROM relation_tree_nodes
                WHERE id_user = :userId
                  AND id_board = :boardId
            )
            SELECT
                r.id,
                r.character_a_id,
                r.character_a_variant_id,
                r.character_a_id::TEXT || \':\' || COALESCE(r.character_a_variant_id, 0)::TEXT AS character_a_key,
                r.character_b_id,
                r.character_b_variant_id,
                r.character_b_id::TEXT || \':\' || COALESCE(r.character_b_variant_id, 0)::TEXT AS character_b_key,
                r.relation_type_id,
                r.custom_name,
                r.custom_icon,
                r.custom_color_hex,
                COALESCE(r.is_nsfw, FALSE) AS is_nsfw,
                r.note,
                t.code,
                t.name AS type_name,
                t.icon,
                t.color_hex,
                t.is_custom
            FROM character_relations r
            JOIN relation_types t ON t.id = r.relation_type_id
            WHERE r.id_user = :userId
              AND EXISTS (
                  SELECT 1 FROM tree_entities te
                  WHERE te.id_character = r.character_a_id
                    AND COALESCE(te.id_variant, 0) = COALESCE(r.character_a_variant_id, 0)
              )
              AND EXISTS (
                  SELECT 1 FROM tree_entities te
                  WHERE te.id_character = r.character_b_id
                    AND COALESCE(te.id_variant, 0) = COALESCE(r.character_b_variant_id, 0)
              )
            ORDER BY r.updated_at DESC, r.id DESC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAvailableCharacters(int $userId, int $boardId, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : $this->visibleCharacterClause('c');
        $variantHiddenClause = $includeHidden ? '' : ' AND COALESCE(cv.is_hidden, FALSE) = FALSE';
        $stmt = $this->database->connect()->prepare('
            WITH RECURSIVE selected_worlds(id) AS (
                SELECT rbw.id_world
                FROM relation_board_worlds rbw
                WHERE rbw.id_board = :boardId
                UNION
                SELECT w.id
                FROM worlds w
                JOIN selected_worlds sw ON w.parent_id = sw.id
                WHERE w.id_user = :userId
            ),
            selected_characters(id) AS (
                SELECT id_character
                FROM relation_board_characters
                WHERE id_board = :boardId
                UNION
                SELECT c2.id
                FROM characters c2
                WHERE c2.id_user = :userId
                  AND c2.id_world IN (SELECT id FROM selected_worlds)
            ),
            base_characters AS (
                SELECT c.*, w.name AS world_name
                FROM characters c
                JOIN selected_characters sc ON sc.id = c.id
                LEFT JOIN worlds w ON w.id = c.id_world AND w.id_user = c.id_user
                WHERE c.id_user = :userId
                  ' . $hiddenClause . '
            ),
            entities AS (
                SELECT
                    c.id AS character_id,
                    NULL::INTEGER AS variant_id,
                    c.id::TEXT || \':0\' AS entity_key,
                    c.name,
                    c.name AS base_name,
                    NULL::VARCHAR AS variant_name,
                    c.image,
                    c.image_fit,
                    c.image_focus_x,
                    c.image_focus_y,
                    c.image_zoom,
                    c.id_world,
                    c.world_name,
                    ' . $this->characterNsfwSelect('c') . ' AS is_nsfw
                FROM base_characters c

                UNION ALL

                SELECT
                    c.id AS character_id,
                    cv.id AS variant_id,
                    c.id::TEXT || \':\' || cv.id::TEXT AS entity_key,
                    c.name || \' - \' || cv.name AS name,
                    c.name AS base_name,
                    cv.name AS variant_name,
                    COALESCE(NULLIF(cv.image, \'\'), c.image) AS image,
                    COALESCE(cv.image_fit, c.image_fit) AS image_fit,
                    COALESCE(cv.image_focus_x, c.image_focus_x) AS image_focus_x,
                    COALESCE(cv.image_focus_y, c.image_focus_y) AS image_focus_y,
                    COALESCE(cv.image_zoom, c.image_zoom) AS image_zoom,
                    c.id_world,
                    c.world_name,
                    ' . $this->variantAwareNsfwSelect('c', 'cv') . ' AS is_nsfw
                FROM base_characters c
                JOIN character_variants cv ON cv.id_character = c.id
                WHERE TRUE
                  ' . $variantHiddenClause . '
            )
            SELECT
                e.character_id AS id,
                e.character_id,
                e.variant_id,
                e.entity_key,
                e.name,
                e.base_name,
                e.variant_name,
                e.image,
                e.image_fit,
                e.image_focus_x,
                e.image_focus_y,
                e.image_zoom,
                e.id_world,
                e.world_name,
                e.is_nsfw,
                CASE WHEN n.id IS NULL THEN FALSE ELSE TRUE END AS on_tree
            FROM entities e
            LEFT JOIN relation_tree_nodes n
                ON n.id_user = :userId
               AND n.id_character = e.character_id
               AND COALESCE(n.id_variant, 0) = COALESCE(e.variant_id, 0)
               AND n.id_board = :boardId
            ORDER BY e.name ASC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllCharactersForRules(int $userId, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : $this->visibleCharacterClause('c');
        $stmt = $this->database->connect()->prepare('
            SELECT
                c.id,
                c.name,
                c.image,
                c.image_fit,
                c.image_focus_x,
                c.image_focus_y,
                c.image_zoom,
                c.id_world,
                w.name AS world_name
            FROM characters c
            LEFT JOIN worlds w ON w.id = c.id_world AND w.id_user = c.id_user
            WHERE c.id_user = :userId
              ' . $hiddenClause . '
            ORDER BY c.name ASC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBoardsForWorld(int $userId, int $worldId, bool $includeHidden = false): array
    {
        $stmt = $this->database->connect()->prepare('
            WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT id, parent_id FROM worlds WHERE id = :worldId AND id_user = :userId
                UNION ALL
                SELECT w.id, w.parent_id
                FROM worlds w
                JOIN ancestors a ON w.id = a.parent_id
                WHERE w.id_user = :userId
            )
            SELECT DISTINCT b.id, b.public_id, b.name, b.description, b.is_hidden, b.updated_at
            FROM relation_boards b
            JOIN relation_board_worlds rbw ON rbw.id_board = b.id
            WHERE b.id_user = :userId
              AND rbw.id_world IN (SELECT id FROM ancestors)
              ' . $this->hiddenBoardClause('b', $includeHidden) . '
            ORDER BY b.updated_at DESC, b.id DESC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':worldId', $worldId, PDO::PARAM_INT);
        $stmt->execute();

        $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($includeHidden) {
            return $boards;
        }

        return array_values(array_filter(
            $boards,
            fn($board) => !$this->boardHasHiddenContent($userId, (int)$board['id'])
        ));
    }

    public function getWorldOptions(int $userId, bool $includeHidden = false): array
    {
        $hiddenClause = $includeHidden ? '' : $this->visibleWorldClause('worlds');
        $stmt = $this->database->connect()->prepare('
            SELECT id, name, parent_id
            FROM worlds
            WHERE id_user = :userId
              ' . $hiddenClause . '
            ORDER BY parent_id NULLS FIRST, name ASC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addNode(int $userId, int $boardId, int $characterId, ?int $variantId, float $x, float $y): array
    {
        if (!$this->getBoard($userId, $boardId)) {
            throw new RuntimeException('Pole relacji nie istnieje.');
        }
        $this->assertCharacterBelongsToUser($characterId, $userId);
        $this->assertVariantBelongsToCharacter($variantId, $characterId, $userId);

        $stmt = $this->database->connect()->prepare('
            INSERT INTO relation_tree_nodes (id_user, id_board, id_character, id_variant, position_x, position_y)
            VALUES (:userId, :boardId, :characterId, :variantId, :x, :y)
            ON CONFLICT (id_user, id_board, id_character, COALESCE(id_variant, 0)) WHERE id_board IS NOT NULL
            DO UPDATE SET position_x = EXCLUDED.position_x, position_y = EXCLUDED.position_y, updated_at = CURRENT_TIMESTAMP
            RETURNING id, id_character AS character_id, id_variant AS variant_id, id_character::TEXT || \':\' || COALESCE(id_variant, 0)::TEXT AS entity_key, position_x, position_y
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':variantId', $variantId);
        $stmt->bindValue(':x', $x);
        $stmt->bindValue(':y', $y);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateNodePosition(int $userId, int $boardId, int $characterId, ?int $variantId, float $x, float $y): void
    {
        $stmt = $this->database->connect()->prepare('
            UPDATE relation_tree_nodes
            SET position_x = :x, position_y = :y, updated_at = CURRENT_TIMESTAMP
            WHERE id_user = :userId
              AND id_character = :characterId
              AND COALESCE(id_variant, 0) = COALESCE(:variantId, 0)
              AND id_board = :boardId
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':variantId', $variantId);
        $stmt->bindValue(':x', $x);
        $stmt->bindValue(':y', $y);
        $stmt->execute();
    }

    public function removeNode(int $userId, int $boardId, int $characterId, ?int $variantId): void
    {
        $stmt = $this->database->connect()->prepare('
            DELETE FROM relation_tree_nodes
            WHERE id_user = :userId
              AND id_character = :characterId
              AND COALESCE(id_variant, 0) = COALESCE(:variantId, 0)
              AND id_board = :boardId
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':variantId', $variantId);
        $stmt->execute();
    }

    public function saveRelation(int $userId, int $characterAId, ?int $characterAVariantId, int $characterBId, ?int $characterBVariantId, int $typeId, ?string $customName, ?string $customIcon, ?string $customColorHex, string $note): int
    {
        if ($this->entityKey($characterAId, $characterAVariantId) === $this->entityKey($characterBId, $characterBVariantId)) {
            throw new InvalidArgumentException('Nie mozna polaczyc postaci z sama soba.');
        }

        $this->assertCharacterBelongsToUser($characterAId, $userId);
        $this->assertCharacterBelongsToUser($characterBId, $userId);
        $this->assertVariantBelongsToCharacter($characterAVariantId, $characterAId, $userId);
        $this->assertVariantBelongsToCharacter($characterBVariantId, $characterBId, $userId);
        $this->assertRelationTypeExists($typeId);
        $isNsfw = $this->characterEntityHasNsfw($userId, $characterAId, $characterAVariantId)
            && $this->characterEntityHasNsfw($userId, $characterBId, $characterBVariantId);
        $customIcon = $this->cleanCustomIcon($customIcon);
        $customColorHex = preg_match('/^#[0-9a-f]{6}$/i', (string)$customColorHex) ? strtoupper((string)$customColorHex) : null;

        $a = [
            'characterId' => $characterAId,
            'variantId' => $characterAVariantId,
            'key' => $this->entityKey($characterAId, $characterAVariantId),
        ];
        $b = [
            'characterId' => $characterBId,
            'variantId' => $characterBVariantId,
            'key' => $this->entityKey($characterBId, $characterBVariantId),
        ];
        if (strcmp($a['key'], $b['key']) > 0) {
            [$a, $b] = [$b, $a];
        }

        $stmt = $this->database->connect()->prepare('
            INSERT INTO character_relations (id_user, character_a_id, character_a_variant_id, character_b_id, character_b_variant_id, relation_type_id, custom_name, custom_icon, custom_color_hex, is_nsfw, note)
            VALUES (:userId, :a, :aVariant, :b, :bVariant, :typeId, :customName, :customIcon, :customColorHex, :isNsfw, :note)
            ON CONFLICT (
                id_user,
                (LEAST(
                    character_a_id::TEXT || \':\' || COALESCE(character_a_variant_id, 0)::TEXT,
                    character_b_id::TEXT || \':\' || COALESCE(character_b_variant_id, 0)::TEXT
                )),
                (GREATEST(
                    character_a_id::TEXT || \':\' || COALESCE(character_a_variant_id, 0)::TEXT,
                    character_b_id::TEXT || \':\' || COALESCE(character_b_variant_id, 0)::TEXT
                ))
            )
            DO UPDATE SET
                relation_type_id = EXCLUDED.relation_type_id,
                custom_name = EXCLUDED.custom_name,
                custom_icon = EXCLUDED.custom_icon,
                custom_color_hex = EXCLUDED.custom_color_hex,
                is_nsfw = EXCLUDED.is_nsfw,
                note = EXCLUDED.note,
                updated_at = CURRENT_TIMESTAMP
            RETURNING id
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':a', $a['characterId'], PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':aVariant', $a['variantId']);
        $stmt->bindValue(':b', $b['characterId'], PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':bVariant', $b['variantId']);
        $stmt->bindValue(':typeId', $typeId, PDO::PARAM_INT);
        $stmt->bindValue(':customName', $customName);
        $stmt->bindValue(':customIcon', $customIcon);
        $stmt->bindValue(':customColorHex', $customColorHex);
        $stmt->bindValue(':isNsfw', $isNsfw, PDO::PARAM_BOOL);
        $stmt->bindValue(':note', $note);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    public function deleteRelation(int $userId, int $relationId): void
    {
        $stmt = $this->database->connect()->prepare('
            DELETE FROM character_relations
            WHERE id = :relationId AND id_user = :userId
        ');
        $stmt->bindValue(':relationId', $relationId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function getBoardWorldIds(int $boardId): array
    {
        $stmt = $this->database->connect()->prepare('SELECT id_world FROM relation_board_worlds WHERE id_board = :boardId');
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function getBoardCharacterIds(int $boardId): array
    {
        $stmt = $this->database->connect()->prepare('SELECT id_character FROM relation_board_characters WHERE id_board = :boardId');
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function replaceBoardMembership(PDO $db, int $boardId, array $worldIds, array $characterIds, int $userId): void
    {
        $db->prepare('DELETE FROM relation_board_worlds WHERE id_board = ?')->execute([$boardId]);
        $db->prepare('DELETE FROM relation_board_characters WHERE id_board = ?')->execute([$boardId]);

        $insertWorld = $db->prepare('INSERT INTO relation_board_worlds (id_board, id_world) VALUES (?, ?) ON CONFLICT DO NOTHING');
        foreach ($worldIds as $worldId) {
            $insertWorld->execute([$boardId, $worldId]);
        }

        $insertCharacter = $db->prepare('INSERT INTO relation_board_characters (id_board, id_character) VALUES (?, ?) ON CONFLICT DO NOTHING');
        foreach ($characterIds as $characterId) {
            $insertCharacter->execute([$boardId, $characterId]);
        }

        $allowedCharacterIds = array_values(array_unique(array_merge(
            $characterIds,
            $this->getCharacterIdsFromWorlds($userId, $worldIds)
        )));

        if (!empty($allowedCharacterIds)) {
            $placeholders = implode(',', array_fill(0, count($allowedCharacterIds), '?'));
            $db->prepare("DELETE FROM relation_tree_nodes WHERE id_board = ? AND id_character NOT IN ($placeholders)")
                ->execute(array_merge([$boardId], $allowedCharacterIds));
        } else {
            $db->prepare('DELETE FROM relation_tree_nodes WHERE id_board = ?')->execute([$boardId]);
        }
    }

    private function getCharacterIdsFromWorlds(int $userId, array $worldIds): array
    {
        if (empty($worldIds)) {
            return [];
        }

        $expanded = $this->expandWorldIdsWithDescendants($userId, $worldIds);
        if (empty($expanded)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($expanded), '?'));
        $stmt = $this->database->connect()->prepare("SELECT id FROM characters WHERE id_user = ? AND id_world IN ($placeholders)");
        $stmt->execute(array_merge([$userId], $expanded));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function saveRules(int $userId, ?int $worldId, array $excludedWorldIds, array $exceptionCharacterIds): void
    {
        $db = $this->database->connect();
        $excludedWorldIds = array_values(array_unique(array_map('intval', $excludedWorldIds)));
        $exceptionCharacterIds = array_values(array_unique(array_map('intval', $exceptionCharacterIds)));

        try {
            $db->beginTransaction();

            $deleteRules = $db->prepare('
                DELETE FROM relation_tree_rules
                WHERE id_user = :userId
                  AND ((CAST(:worldId AS integer) IS NULL AND id_world IS NULL) OR id_world = :worldId)
            ');
            $deleteRules->bindValue(':userId', $userId, PDO::PARAM_INT);
            $this->bindNullableInt($deleteRules, ':worldId', $worldId);
            $deleteRules->execute();

            $insertRule = $db->prepare('
                INSERT INTO relation_tree_rules (id_user, id_world, excluded_world_id)
                VALUES (:userId, :worldId, :excludedWorldId)
            ');
            foreach ($excludedWorldIds as $excludedWorldId) {
                $this->assertWorldBelongsToUser($excludedWorldId, $userId);
                if ($worldId !== null && $excludedWorldId === $worldId) {
                    continue;
                }
                $insertRule->bindValue(':userId', $userId, PDO::PARAM_INT);
                $this->bindNullableInt($insertRule, ':worldId', $worldId);
                $insertRule->bindValue(':excludedWorldId', $excludedWorldId, PDO::PARAM_INT);
                $insertRule->execute();
            }

            $deleteExceptions = $db->prepare('
                DELETE FROM relation_tree_character_exceptions
                WHERE id_user = :userId
                  AND ((CAST(:worldId AS integer) IS NULL AND id_world IS NULL) OR id_world = :worldId)
            ');
            $deleteExceptions->bindValue(':userId', $userId, PDO::PARAM_INT);
            $this->bindNullableInt($deleteExceptions, ':worldId', $worldId);
            $deleteExceptions->execute();

            $insertException = $db->prepare('
                INSERT INTO relation_tree_character_exceptions (id_user, id_world, id_character)
                VALUES (:userId, :worldId, :characterId)
            ');
            foreach ($exceptionCharacterIds as $characterId) {
                $this->assertCharacterBelongsToUser($characterId, $userId);
                $insertException->bindValue(':userId', $userId, PDO::PARAM_INT);
                $this->bindNullableInt($insertException, ':worldId', $worldId);
                $insertException->bindValue(':characterId', $characterId, PDO::PARAM_INT);
                $insertException->execute();
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function countRelationsForCharacters(int $userId, array $characterIds): array
    {
        $characterIds = array_values(array_unique(array_map('intval', $characterIds)));
        if (empty($characterIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
        $stmt = $this->database->connect()->prepare("
            SELECT character_id, COUNT(*) AS relation_count
            FROM (
                SELECT character_a_id AS character_id
                FROM character_relations
                WHERE id_user = ?
                  AND character_a_id IN ($placeholders)
                  AND character_a_variant_id IS NULL
                UNION ALL
                SELECT character_b_id AS character_id
                FROM character_relations
                WHERE id_user = ?
                  AND character_b_id IN ($placeholders)
                  AND character_b_variant_id IS NULL
            ) rel
            GROUP BY character_id
        ");
        $stmt->execute(array_merge([$userId], $characterIds, [$userId], $characterIds));

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int)$row['character_id']] = (int)$row['relation_count'];
        }

        return $counts;
    }

    private function getExcludedWorldIds(int $userId, ?int $worldId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT excluded_world_id
            FROM relation_tree_rules
            WHERE id_user = :userId
              AND ((CAST(:worldId AS integer) IS NULL AND id_world IS NULL) OR id_world = :worldId)
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':worldId', $worldId);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function getExceptionCharacterIds(int $userId, ?int $worldId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT id_character
            FROM relation_tree_character_exceptions
            WHERE id_user = :userId
              AND ((CAST(:worldId AS integer) IS NULL AND id_world IS NULL) OR id_world = :worldId)
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':worldId', $worldId);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function expandWorldIdsWithDescendants(int $userId, array $worldIds): array
    {
        $worldIds = array_values(array_unique(array_map('intval', $worldIds)));
        if (empty($worldIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($worldIds), '?'));
        $stmt = $this->database->connect()->prepare("
            WITH RECURSIVE descendants(id) AS (
                SELECT id FROM worlds WHERE id_user = ? AND id IN ($placeholders)
                UNION ALL
                SELECT w.id
                FROM worlds w
                JOIN descendants d ON w.parent_id = d.id
                WHERE w.id_user = ?
            )
            SELECT DISTINCT id FROM descendants
        ");
        $stmt->execute(array_merge([$userId], $worldIds, [$userId]));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function boardHasHiddenContent(int $userId, int $boardId): bool
    {
        $stmt = $this->database->connect()->prepare("
            SELECT EXISTS (
                SELECT 1
                FROM relation_board_characters bc
                JOIN characters c ON c.id = bc.id_character AND c.id_user = :userId
                LEFT JOIN character_variants cv ON cv.id = bc.id_variant AND cv.id_character = c.id
                WHERE bc.id_board = :boardId
                  AND (
                    NOT (" . $this->visibleCharacterCondition('c') . ")
                    OR COALESCE(cv.is_hidden, FALSE) = TRUE
                  )
            )
            OR EXISTS (
                SELECT 1
                FROM relation_tree_nodes n
                JOIN characters c ON c.id = n.id_character AND c.id_user = n.id_user
                LEFT JOIN character_variants cv ON cv.id = n.id_variant AND cv.id_character = c.id
                WHERE n.id_user = :userId
                  AND n.id_board = :boardId
                  AND (
                    NOT (" . $this->visibleCharacterCondition('c') . ")
                    OR COALESCE(cv.is_hidden, FALSE) = TRUE
                  )
            )
            OR EXISTS (
                SELECT 1
                FROM relation_board_worlds rbw
                JOIN worlds w ON w.id = rbw.id_world AND w.id_user = :userId
                WHERE rbw.id_board = :boardId
                  AND NOT (" . $this->visibleWorldCondition('w') . ")
            )
            OR EXISTS (
                WITH RECURSIVE selected_worlds(id) AS (
                    SELECT rbw.id_world
                    FROM relation_board_worlds rbw
                    JOIN worlds root ON root.id = rbw.id_world AND root.id_user = :userId
                    WHERE rbw.id_board = :boardId
                    UNION
                    SELECT child.id
                    FROM worlds child
                    JOIN selected_worlds sw ON child.parent_id = sw.id
                    WHERE child.id_user = :userId
                )
                SELECT 1
                FROM characters c
                WHERE c.id_user = :userId
                  AND c.id_world IN (SELECT id FROM selected_worlds)
                  AND NOT (" . $this->visibleCharacterCondition('c') . ")
            )
        ");
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }

    private function visibleCharacterClause(string $alias): string
    {
        return ' AND ' . $this->visibleCharacterCondition($alias);
    }

    private function hiddenBoardClause(string $alias, bool $includeHidden): string
    {
        if ($includeHidden) {
            return '';
        }

        $prefix = $alias !== '' ? $alias . '.' : '';
        return " AND COALESCE({$prefix}is_hidden, FALSE) = FALSE";
    }

    private function characterNsfwSelect(string $alias): string
    {
        return "EXISTS (
            SELECT 1
            FROM content_filters cf
            JOIN filters f ON f.id = cf.id_filter
            WHERE cf.object_type = 'character'
              AND cf.object_id = {$alias}.id
              AND LOWER(f.slug) IN ('nsfw', '+18', '18+')
        )
        OR EXISTS (
            SELECT 1
            FROM character_filters legacy_cf
            JOIN filters f ON f.id = legacy_cf.id_filter
            WHERE legacy_cf.id_character = {$alias}.id
              AND LOWER(f.slug) IN ('nsfw', '+18', '18+')
        )
        OR EXISTS (
            WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT w.id, w.parent_id
                FROM worlds w
                WHERE w.id = {$alias}.id_world
                UNION ALL
                SELECT parent.id, parent.parent_id
                FROM worlds parent
                JOIN ancestors a ON parent.id = a.parent_id
            )
            SELECT 1
            FROM content_filters cf
            JOIN filters f ON f.id = cf.id_filter
            WHERE cf.object_type = 'world'
              AND cf.object_id IN (SELECT id FROM ancestors)
              AND LOWER(f.slug) IN ('nsfw', '+18', '18+')
        )
        OR EXISTS (
            WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT w.id, w.parent_id
                FROM worlds w
                WHERE w.id = {$alias}.id_world
                UNION ALL
                SELECT parent.id, parent.parent_id
                FROM worlds parent
                JOIN ancestors a ON parent.id = a.parent_id
            )
            SELECT 1
            FROM world_filters wf
            JOIN filters f ON f.id = wf.id_filter
            WHERE wf.id_world IN (SELECT id FROM ancestors)
              AND LOWER(f.slug) IN ('nsfw', '+18', '18+')
        )";
    }

    private function variantAwareNsfwSelect(string $characterAlias, string $variantAlias): string
    {
        return "(
            {$variantAlias}.id IS NULL
            AND (" . $this->characterNsfwSelect($characterAlias) . ")
        ) OR (
            {$variantAlias}.id IS NOT NULL
            AND (
                COALESCE({$variantAlias}.is_adult, FALSE) = TRUE
                OR EXISTS (
                    SELECT 1
                    FROM content_filters variant_cf
                    JOIN filters variant_f ON variant_f.id = variant_cf.id_filter
                    WHERE variant_cf.object_type = 'character_variant'
                      AND variant_cf.object_id = {$variantAlias}.id
                      AND LOWER(variant_f.slug) IN ('adult', 'nsfw', '+18', '18+')
                )
                OR (
                    NOT EXISTS (
                        SELECT 1
                        FROM content_filters variant_any_cf
                        WHERE variant_any_cf.object_type = 'character_variant'
                          AND variant_any_cf.object_id = {$variantAlias}.id
                    )
                    AND (" . $this->characterNsfwSelect($characterAlias) . ")
                )
            )
        )";
    }

    private function characterEntityHasNsfw(int $userId, int $characterId, ?int $variantId): bool
    {
        if ($variantId !== null) {
            $stmt = $this->database->connect()->prepare("
                SELECT EXISTS (
                    SELECT 1
                    FROM characters c
                    JOIN character_variants cv ON cv.id_character = c.id
                    WHERE c.id = :characterId
                      AND c.id_user = :userId
                      AND cv.id = :variantId
                      AND (" . $this->variantAwareNsfwSelect('c', 'cv') . ")
                )
            ");
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
            $stmt->bindValue(':variantId', $variantId, PDO::PARAM_INT);
            $stmt->execute();
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $this->database->connect()->prepare("
            SELECT EXISTS (
                SELECT 1 FROM characters c
                WHERE c.id = :characterId
                  AND c.id_user = :userId
                  AND (" . $this->characterNsfwSelect('c') . ")
            )
        ");
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    }

    private function entityKey(int $characterId, ?int $variantId): string
    {
        return $characterId . ':' . ($variantId ?? 0);
    }

    private function cleanCustomIcon(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, 8);
    }

    private function visibleCharacterCondition(string $alias): string
    {
        return "COALESCE({$alias}.is_hidden, FALSE) = FALSE
            AND NOT EXISTS (
                WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                    SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                    FROM worlds w
                    WHERE w.id = {$alias}.id_world
                    UNION ALL
                    SELECT parent.id, parent.parent_id, COALESCE(parent.is_hidden, FALSE)
                    FROM worlds parent
                    JOIN ancestors a ON parent.id = a.parent_id
                )
                SELECT 1 FROM ancestors WHERE is_hidden = TRUE
            )";
    }

    private function visibleWorldClause(string $alias): string
    {
        return ' AND ' . $this->visibleWorldCondition($alias);
    }

    private function visibleWorldCondition(string $alias): string
    {
        return "COALESCE({$alias}.is_hidden, FALSE) = FALSE
            AND NOT EXISTS (
                WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                    SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                    FROM worlds w
                    WHERE w.id = {$alias}.parent_id
                    UNION ALL
                    SELECT parent.id, parent.parent_id, COALESCE(parent.is_hidden, FALSE)
                    FROM worlds parent
                    JOIN ancestors a ON parent.id = a.parent_id
                )
                SELECT 1 FROM ancestors WHERE is_hidden = TRUE
            )";
    }

    private function assertCharacterBelongsToUser(int $characterId, int $userId): void
    {
        $stmt = $this->database->connect()->prepare('SELECT 1 FROM characters WHERE id = :id AND id_user = :userId');
        $stmt->bindValue(':id', $characterId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Postac nie nalezy do uzytkownika.');
        }
    }

    private function assertVariantBelongsToCharacter(?int $variantId, int $characterId, int $userId): void
    {
        if ($variantId === null) {
            return;
        }

        $stmt = $this->database->connect()->prepare('
            SELECT 1
            FROM character_variants cv
            JOIN characters c ON c.id = cv.id_character
            WHERE cv.id = :variantId
              AND cv.id_character = :characterId
              AND c.id_user = :userId
        ');
        $stmt->bindValue(':variantId', $variantId, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Wariant postaci nie nalezy do postaci.');
        }
    }

    private function assertWorldBelongsToUser(int $worldId, int $userId): void
    {
        $stmt = $this->database->connect()->prepare('SELECT 1 FROM worlds WHERE id = :id AND id_user = :userId');
        $stmt->bindValue(':id', $worldId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Folder nie nalezy do uzytkownika.');
        }
    }

    private function assertRelationTypeExists(int $typeId): void
    {
        $stmt = $this->database->connect()->prepare('SELECT 1 FROM relation_types WHERE id = :id');
        $stmt->bindValue(':id', $typeId, PDO::PARAM_INT);
        $stmt->execute();
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Nieprawidlowy typ relacji.');
        }
    }

    private function bindNullableInt(PDOStatement $stmt, string $name, ?int $value): void
    {
        if ($value === null) {
            $stmt->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($name, $value, PDO::PARAM_INT);
    }
}
