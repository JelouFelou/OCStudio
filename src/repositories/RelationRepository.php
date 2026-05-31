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

    public function getBoards(int $userId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT
                b.*,
                (SELECT COUNT(*) FROM relation_board_characters bc WHERE bc.id_board = b.id) AS character_count,
                (SELECT COUNT(*) FROM relation_tree_nodes n WHERE n.id_board = b.id) AS node_count
            FROM relation_boards b
            WHERE b.id_user = :userId
            ORDER BY b.updated_at DESC, b.id DESC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $boards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($boards as &$board) {
            $board['worldIds'] = $this->getBoardWorldIds((int)$board['id']);
            $board['characterIds'] = $this->getBoardCharacterIds((int)$board['id']);
            $board['relation_count'] = count($this->getTreeRelations($userId, (int)$board['id']));
        }

        return $boards;
    }

    public function getBoard(int $userId, int $boardId): ?array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT *
            FROM relation_boards
            WHERE id = :boardId AND id_user = :userId
        ');
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $board = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$board) {
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

        $characterIds = array_values(array_unique(array_merge(
            $characterIds,
            $this->getCharacterIdsFromWorlds($userId, $worldIds)
        )));

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

            $this->replaceBoardMembership($db, $boardId, $worldIds, $characterIds);

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

    public function getTreeData(int $userId, int $boardId): array
    {
        $board = $this->getBoard($userId, $boardId);
        if (!$board) {
            throw new RuntimeException('Pole relacji nie istnieje.');
        }

        return [
            'board' => $board,
            'types' => $this->getRelationTypes(),
            'nodes' => $this->getTreeNodes($userId, $boardId),
            'relations' => $this->getTreeRelations($userId, $boardId),
            'availableCharacters' => $this->getAvailableCharacters($userId, $boardId),
            'ruleCharacters' => $this->getAllCharactersForRules($userId),
            'worlds' => $this->getWorldOptions($userId),
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

    public function getTreeNodes(int $userId, int $boardId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT
                n.id,
                n.id_character AS character_id,
                n.position_x,
                n.position_y,
                c.name,
                c.image,
                c.id_world
            FROM relation_tree_nodes n
            JOIN characters c ON c.id = n.id_character AND c.id_user = n.id_user
            WHERE n.id_user = :userId
              AND n.id_board = :boardId
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
            WITH tree_chars AS (
                SELECT id_character
                FROM relation_tree_nodes
                WHERE id_user = :userId
                  AND id_board = :boardId
            )
            SELECT
                r.id,
                r.character_a_id,
                r.character_b_id,
                r.relation_type_id,
                r.custom_name,
                r.note,
                t.code,
                t.name AS type_name,
                t.icon,
                t.color_hex,
                t.is_custom
            FROM character_relations r
            JOIN relation_types t ON t.id = r.relation_type_id
            WHERE r.id_user = :userId
              AND r.character_a_id IN (SELECT id_character FROM tree_chars)
              AND r.character_b_id IN (SELECT id_character FROM tree_chars)
            ORDER BY r.updated_at DESC, r.id DESC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAvailableCharacters(int $userId, int $boardId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT
                c.id,
                c.name,
                c.image,
                c.id_world,
                w.name AS world_name,
                CASE WHEN n.id IS NULL THEN FALSE ELSE TRUE END AS on_tree
            FROM characters c
            JOIN relation_board_characters bc ON bc.id_character = c.id AND bc.id_board = :boardId
            LEFT JOIN worlds w ON w.id = c.id_world AND w.id_user = c.id_user
            LEFT JOIN relation_tree_nodes n
                ON n.id_user = c.id_user
               AND n.id_character = c.id
               AND n.id_board = :boardId
            WHERE c.id_user = :userId
            ORDER BY c.name ASC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllCharactersForRules(int $userId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT
                c.id,
                c.name,
                c.image,
                c.id_world,
                w.name AS world_name
            FROM characters c
            LEFT JOIN worlds w ON w.id = c.id_world AND w.id_user = c.id_user
            WHERE c.id_user = :userId
            ORDER BY c.name ASC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getWorldOptions(int $userId): array
    {
        $stmt = $this->database->connect()->prepare('
            SELECT id, name, parent_id
            FROM worlds
            WHERE id_user = :userId
            ORDER BY parent_id NULLS FIRST, name ASC
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addNode(int $userId, int $boardId, int $characterId, float $x, float $y): array
    {
        if (!$this->getBoard($userId, $boardId)) {
            throw new RuntimeException('Pole relacji nie istnieje.');
        }
        $this->assertCharacterBelongsToUser($characterId, $userId);

        $stmt = $this->database->connect()->prepare('
            INSERT INTO relation_tree_nodes (id_user, id_board, id_character, position_x, position_y)
            VALUES (:userId, :boardId, :characterId, :x, :y)
            ON CONFLICT (id_user, id_board, id_character) WHERE id_board IS NOT NULL
            DO UPDATE SET position_x = EXCLUDED.position_x, position_y = EXCLUDED.position_y, updated_at = CURRENT_TIMESTAMP
            RETURNING id, id_character AS character_id, position_x, position_y
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->bindValue(':x', $x);
        $stmt->bindValue(':y', $y);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateNodePosition(int $userId, int $boardId, int $characterId, float $x, float $y): void
    {
        $stmt = $this->database->connect()->prepare('
            UPDATE relation_tree_nodes
            SET position_x = :x, position_y = :y, updated_at = CURRENT_TIMESTAMP
            WHERE id_user = :userId
              AND id_character = :characterId
              AND id_board = :boardId
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->bindValue(':x', $x);
        $stmt->bindValue(':y', $y);
        $stmt->execute();
    }

    public function removeNode(int $userId, int $boardId, int $characterId): void
    {
        $stmt = $this->database->connect()->prepare('
            DELETE FROM relation_tree_nodes
            WHERE id_user = :userId
              AND id_character = :characterId
              AND id_board = :boardId
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':boardId', $boardId, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $characterId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function saveRelation(int $userId, int $characterAId, int $characterBId, int $typeId, ?string $customName, string $note): int
    {
        if ($characterAId === $characterBId) {
            throw new InvalidArgumentException('Nie mozna polaczyc postaci z sama soba.');
        }

        $this->assertCharacterBelongsToUser($characterAId, $userId);
        $this->assertCharacterBelongsToUser($characterBId, $userId);
        $this->assertRelationTypeExists($typeId);

        $a = min($characterAId, $characterBId);
        $b = max($characterAId, $characterBId);

        $stmt = $this->database->connect()->prepare('
            INSERT INTO character_relations (id_user, character_a_id, character_b_id, relation_type_id, custom_name, note)
            VALUES (:userId, :a, :b, :typeId, :customName, :note)
            ON CONFLICT (id_user, (LEAST(character_a_id, character_b_id)), (GREATEST(character_a_id, character_b_id)))
            DO UPDATE SET
                relation_type_id = EXCLUDED.relation_type_id,
                custom_name = EXCLUDED.custom_name,
                note = EXCLUDED.note,
                updated_at = CURRENT_TIMESTAMP
            RETURNING id
        ');
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':a', $a, PDO::PARAM_INT);
        $stmt->bindValue(':b', $b, PDO::PARAM_INT);
        $stmt->bindValue(':typeId', $typeId, PDO::PARAM_INT);
        $stmt->bindValue(':customName', $customName);
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

    private function replaceBoardMembership(PDO $db, int $boardId, array $worldIds, array $characterIds): void
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

        if (!empty($characterIds)) {
            $placeholders = implode(',', array_fill(0, count($characterIds), '?'));
            $db->prepare("DELETE FROM relation_tree_nodes WHERE id_board = ? AND id_character NOT IN ($placeholders)")
                ->execute(array_merge([$boardId], $characterIds));
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
                SELECT character_a_id AS character_id FROM character_relations WHERE id_user = ? AND character_a_id IN ($placeholders)
                UNION ALL
                SELECT character_b_id AS character_id FROM character_relations WHERE id_user = ? AND character_b_id IN ($placeholders)
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
