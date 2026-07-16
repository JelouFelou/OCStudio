<?php

require_once 'Repository.php';
require_once __DIR__ . '/../models/Story.php';
require_once __DIR__ . '/../models/StoryCharacter.php';
require_once __DIR__ . '/../models/Filter.php';

class StoryRepository extends Repository {
    private ?bool $storyCharacterVariantColumnExists = null;


    public function getStoriesByUser(int $idUser, int $idWorld = 0, int $idFolder = 0, bool $includeHidden = false): array {
        $query = "SELECT s.* FROM stories s WHERE s.id_user = :idUser";
        $params = [':idUser' => $idUser];
        
        if ($idWorld > 0) {
            $query .= " AND s.id_world = :idWorld";
            $params[':idWorld'] = $idWorld;
        }
        
        if ($idFolder > 0) {
            $query .= " AND s.id_folder = :idFolder";
            $params[':idFolder'] = $idFolder;
        }

        if (!$includeHidden) {
            $query .= " AND COALESCE(s.is_hidden, FALSE) = FALSE
                AND NOT EXISTS (
                    WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                        SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                        FROM worlds w
                        WHERE w.id = s.id_world
                        UNION ALL
                        SELECT w2.id, w2.parent_id, COALESCE(w2.is_hidden, FALSE)
                        FROM worlds w2
                        JOIN ancestors a ON w2.id = a.parent_id
                    )
                    SELECT 1 FROM ancestors WHERE is_hidden = TRUE
                )";
        }
        
        $query .= " ORDER BY s.order_number ASC, s.created_at DESC";
        
        $stmt = $this->database->connect()->prepare($query);
        $stmt->execute($params);
        
        $stories = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stories[] = $this->mapRowToStory($row);
        }
        return $stories;
    }

    public function searchGlobalStories(int $userId, string $query, array $blockedFilterIds = [], bool $includeHidden = false, bool $includeAdult = false, int $limit = 8): array {
        $blockedFilterIds = array_values(array_unique(array_filter(array_map('intval', $blockedFilterIds))));
        $blockedClause = '';
        if (!empty($blockedFilterIds)) {
            $ids = implode(',', $blockedFilterIds);
            $blockedClause = " AND NOT EXISTS (
                SELECT 1 FROM content_filters blocked_cf
                WHERE blocked_cf.object_type = 'story'
                  AND blocked_cf.object_id = s.id
                  AND blocked_cf.id_filter IN ({$ids})
            )";
        }

        $hiddenClause = '';
        if (!$includeHidden) {
            $hiddenClause = " AND COALESCE(s.is_hidden, FALSE) = FALSE
                AND NOT EXISTS (
                    WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                        SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                        FROM worlds w
                        WHERE w.id = s.id_world
                        UNION ALL
                        SELECT w2.id, w2.parent_id, COALESCE(w2.is_hidden, FALSE)
                        FROM worlds w2
                        JOIN ancestors a ON w2.id = a.parent_id
                    )
                    SELECT 1 FROM ancestors WHERE is_hidden = TRUE
                )";
        }

        $baseAdultVariantGuard = '';
        $variantAdultClause = '';
        if (!$includeAdult && $this->storyCharacterVariantColumnExists()) {
            $baseAdultVariantGuard = " AND (
                adult_sc.id_variant IS NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM content_filters adult_variant_any
                    WHERE adult_variant_any.object_type = 'character_variant'
                      AND adult_variant_any.object_id = adult_sc.id_variant
                )
            )";
            $variantAdultClause = " AND NOT EXISTS (
                SELECT 1
                FROM story_characters adult_variant_sc
                JOIN character_variants adult_cv ON adult_cv.id = adult_variant_sc.id_variant
                LEFT JOIN content_filters adult_variant_content
                    ON adult_variant_content.object_type = 'character_variant'
                   AND adult_variant_content.object_id = adult_variant_sc.id_variant
                LEFT JOIN filters adult_variant_f ON adult_variant_f.id = adult_variant_content.id_filter
                WHERE adult_variant_sc.id_story = s.id
                  AND adult_variant_sc.id_variant IS NOT NULL
                  AND (
                    COALESCE(adult_cv.is_adult, FALSE) = TRUE
                    OR LOWER(COALESCE(adult_variant_f.slug, '')) IN ('adult', 'nsfw', '+18', '18+')
                    OR LOWER(COALESCE(adult_variant_f.name, '')) IN ('adult', 'nsfw', '+18', '18+')
                    OR LOWER(COALESCE(adult_variant_f.label, '')) IN ('adult', 'nsfw', '+18', '18+')
                  )
            )";
        }

        $adultClause = $includeAdult ? '' : " AND NOT EXISTS (
            SELECT 1
            FROM content_filters adult_cf
            JOIN filters adult_f ON adult_f.id = adult_cf.id_filter
            WHERE adult_cf.object_type = 'story'
              AND adult_cf.object_id = s.id
              AND (
                LOWER(COALESCE(adult_f.slug, '')) IN ('adult', 'nsfw', '+18', '18+')
                OR LOWER(COALESCE(adult_f.name, '')) IN ('adult', 'nsfw', '+18', '18+')
                OR LOWER(COALESCE(adult_f.label, '')) IN ('adult', 'nsfw', '+18', '18+')
              )
        ) AND NOT EXISTS (
            SELECT 1
            FROM story_characters adult_sc
            JOIN characters adult_c ON adult_c.id = adult_sc.id_character
            LEFT JOIN character_filters adult_char_cf ON adult_char_cf.id_character = adult_c.id
            LEFT JOIN content_filters adult_char_content ON adult_char_content.object_type = 'character' AND adult_char_content.object_id = adult_c.id
            JOIN filters adult_char_f ON adult_char_f.id = COALESCE(adult_char_cf.id_filter, adult_char_content.id_filter)
            WHERE adult_sc.id_story = s.id
              {$baseAdultVariantGuard}
              AND (
                LOWER(COALESCE(adult_char_f.slug, '')) IN ('adult', 'nsfw', '+18', '18+')
                OR LOWER(COALESCE(adult_char_f.name, '')) IN ('adult', 'nsfw', '+18', '18+')
                OR LOWER(COALESCE(adult_char_f.label, '')) IN ('adult', 'nsfw', '+18', '18+')
              )
        ){$variantAdultClause} AND NOT EXISTS (
            WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT w.id, w.parent_id
                FROM worlds w
                WHERE w.id = s.id_world
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
            WHERE LOWER(COALESCE(adult_world_f.slug, '')) IN ('adult', 'nsfw', '+18', '18+')
               OR LOWER(COALESCE(adult_world_f.name, '')) IN ('adult', 'nsfw', '+18', '18+')
               OR LOWER(COALESCE(adult_world_f.label, '')) IN ('adult', 'nsfw', '+18', '18+')
        ) AND NOT EXISTS (
            SELECT 1
            FROM image_assets adult_img
            WHERE adult_img.filename = s.image
              AND adult_img.visibility = 'adult'
        )";

        $stmt = $this->database->connect()->prepare("
            SELECT DISTINCT s.*
            FROM stories s
            LEFT JOIN worlds w ON w.id = s.id_world
            LEFT JOIN story_fields sf ON sf.id_story = s.id
            LEFT JOIN story_field_values sfv ON sfv.id_story = s.id AND sfv.id_story_field = sf.id
            LEFT JOIN story_characters sc ON sc.id_story = s.id
            LEFT JOIN characters c ON c.id = sc.id_character
            LEFT JOIN content_filters cf ON cf.object_type = 'story' AND cf.object_id = s.id
            LEFT JOIN filters f ON f.id = cf.id_filter
            WHERE s.id_user = :userId
              {$blockedClause}
              {$hiddenClause}
              {$adultClause}
              AND (
                LOWER(s.title) LIKE :q
                OR LOWER(COALESCE(s.description, '')) LIKE :q
                OR LOWER(COALESCE(s.story_date, '')) LIKE :q
                OR LOWER(COALESCE(w.name, '')) LIKE :q
                OR LOWER(COALESCE(sf.label, '')) LIKE :q
                OR LOWER(COALESCE(sfv.value, '')) LIKE :q
                OR LOWER(COALESCE(c.name, '')) LIKE :q
                OR LOWER(COALESCE(f.name, '')) LIKE :q
                OR LOWER(COALESCE(f.slug, '')) LIKE :q
                OR LOWER(COALESCE(f.label, '')) LIKE :q
              )
            ORDER BY s.updated_at DESC, s.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':q', '%' . mb_strtolower(trim($query)) . '%');
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn($row) => $this->mapRowToStory($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getStoryById(int $id): ?Story {
        $stmt = $this->database->connect()->prepare("SELECT * FROM stories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRowToStory($row) : null;
    }

    public function getStoryByPublicId(string $publicId): ?Story {
        $stmt = $this->database->connect()->prepare("SELECT * FROM stories WHERE public_id::text = :publicId");
        $stmt->execute([':publicId' => $publicId]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRowToStory($row) : null;
    }

    public function createStory(Story $story): ?Story {
        $query = "INSERT INTO stories (id_user, id_world, id_folder, title, description, story_date, image, image_fit, image_focus_x, image_focus_y, image_zoom, card_image_fit, card_image_focus_x, card_image_focus_y, card_image_zoom, timeline_branch_name, timeline_split_date, timeline_split_unknown, timeline_merge_date, timeline_merge_unknown, timeline_position_x, timeline_position_y, status) 
                  VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22, $23) 
                  RETURNING *";
        
        $params = [
            $story->getIdUser(),
            $story->getIdWorld(),
            $story->getIdFolder(),
            $story->getTitle(),
            $story->getDescription(),
            $story->getStoryDate(),
            $story->getImage(),
            $story->getImageFit(),
            $story->getImageFocusX(),
            $story->getImageFocusY(),
            $story->getImageZoom(),
            $story->getCardImageFit(),
            $story->getCardImageFocusX(),
            $story->getCardImageFocusY(),
            $story->getCardImageZoom(),
            $story->getTimelineBranchName(),
            $story->getTimelineSplitDate(),
            $story->isTimelineSplitUnknown() ? 'true' : 'false',
            $story->getTimelineMergeDate(),
            $story->isTimelineMergeUnknown() ? 'true' : 'false',
            $story->getTimelinePositionX(),
            $story->getTimelinePositionY(),
            $story->getStatus()
        ];
        
        $stmt = $this->database->connect()->prepare($this->toNamedPlaceholders($query));
        $stmt->execute($params);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRowToStory($row) : null;
    }

    public function updateStory(Story $story): bool {
        $stmt = $this->database->connect()->prepare(
            "UPDATE stories
             SET title = :title,
                 description = :description,
                 story_date = :storyDate,
                 image = :image,
                 image_fit = :imageFit,
                 image_focus_x = :imageFocusX,
                 image_focus_y = :imageFocusY,
                 image_zoom = :imageZoom,
                 card_image_fit = :cardImageFit,
                 card_image_focus_x = :cardImageFocusX,
                 card_image_focus_y = :cardImageFocusY,
                 card_image_zoom = :cardImageZoom,
                 timeline_branch_name = :timelineBranchName,
                 timeline_split_date = :timelineSplitDate,
                 timeline_split_unknown = :timelineSplitUnknown,
                 timeline_merge_date = :timelineMergeDate,
                 timeline_merge_unknown = :timelineMergeUnknown,
                 timeline_position_x = :timelinePositionX,
                 timeline_position_y = :timelinePositionY,
                 status = :status,
                 id_folder = :idFolder,
                 is_hidden = :isHidden,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->bindValue(':title', $story->getTitle());
        $stmt->bindValue(':description', $story->getDescription());
        $stmt->bindValue(':storyDate', $story->getStoryDate());
        $stmt->bindValue(':image', $story->getImage());
        $stmt->bindValue(':imageFit', $story->getImageFit());
        $stmt->bindValue(':imageFocusX', $story->getImageFocusX(), PDO::PARAM_INT);
        $stmt->bindValue(':imageFocusY', $story->getImageFocusY(), PDO::PARAM_INT);
        $stmt->bindValue(':imageZoom', $story->getImageZoom());
        $stmt->bindValue(':cardImageFit', $story->getCardImageFit());
        $stmt->bindValue(':cardImageFocusX', $story->getCardImageFocusX(), PDO::PARAM_INT);
        $stmt->bindValue(':cardImageFocusY', $story->getCardImageFocusY(), PDO::PARAM_INT);
        $stmt->bindValue(':cardImageZoom', $story->getCardImageZoom());
        $stmt->bindValue(':timelineBranchName', $story->getTimelineBranchName());
        $stmt->bindValue(':timelineSplitDate', $story->getTimelineSplitDate());
        $stmt->bindValue(':timelineSplitUnknown', $story->isTimelineSplitUnknown(), PDO::PARAM_BOOL);
        $stmt->bindValue(':timelineMergeDate', $story->getTimelineMergeDate());
        $stmt->bindValue(':timelineMergeUnknown', $story->isTimelineMergeUnknown(), PDO::PARAM_BOOL);
        $stmt->bindValue(':timelinePositionX', $story->getTimelinePositionX(), $story->getTimelinePositionX() === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':timelinePositionY', $story->getTimelinePositionY(), $story->getTimelinePositionY() === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':status', $story->getStatus());
        $stmt->bindValue(':idFolder', $story->getIdFolder(), $story->getIdFolder() === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':isHidden', $story->isHidden(), PDO::PARAM_BOOL);
        $stmt->bindValue(':id', $story->getId(), PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function updateTimelinePosition(int $id, int $userId, float $x, float $y): bool
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE stories
             SET timeline_position_x = :x,
                 timeline_position_y = :y,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND id_user = :userId'
        );
        $stmt->bindValue(':x', $x);
        $stmt->bindValue(':y', $y);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function updateStoryStatus(int $id, string $status): bool {
        $stmt = $this->database->connect()->prepare(
            "UPDATE stories SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }

    public function setHidden(int $id, int $userId, bool $hidden): bool {
        $stmt = $this->database->connect()->prepare(
            "UPDATE stories SET is_hidden = :hidden, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND id_user = :userId"
        );
        return $stmt->execute([
            ':hidden' => $hidden,
            ':id' => $id,
            ':userId' => $userId,
        ]);
    }

    public function duplicateStory(int $id, int $userId): ?Story {
        $db = $this->database->connect();

        try {
            $db->beginTransaction();

            $storyStmt = $db->prepare("SELECT * FROM stories WHERE id = :id AND id_user = :userId");
            $storyStmt->execute([':id' => $id, ':userId' => $userId]);
            $story = $storyStmt->fetch(PDO::FETCH_ASSOC);
            if (!$story) {
                $db->rollBack();
                return null;
            }

            $insertStory = $db->prepare("
                INSERT INTO stories (id_user, id_world, id_folder, title, description, story_date, image, image_fit, image_focus_x, image_focus_y, image_zoom, card_image_fit, card_image_focus_x, card_image_focus_y, card_image_zoom, status, order_number, is_hidden)
                VALUES (:idUser, :idWorld, :idFolder, :title, :description, :storyDate, :image, :imageFit, :imageFocusX, :imageFocusY, :imageZoom, :cardImageFit, :cardImageFocusX, :cardImageFocusY, :cardImageZoom, :status, :orderNumber, :isHidden)
                RETURNING *
            ");
            $insertStory->execute([
                ':idUser' => $story['id_user'],
                ':idWorld' => $story['id_world'],
                ':idFolder' => $story['id_folder'],
                ':title' => $story['title'] . ' (kopia)',
                ':description' => $story['description'],
                ':storyDate' => $story['story_date'] ?? '',
                ':image' => $story['image'],
                ':imageFit' => $story['image_fit'] ?? 'cover',
                ':imageFocusX' => $story['image_focus_x'] ?? 50,
                ':imageFocusY' => $story['image_focus_y'] ?? 50,
                ':imageZoom' => $story['image_zoom'] ?? 1,
                ':cardImageFit' => $story['card_image_fit'] ?? ($story['image_fit'] ?? 'cover'),
                ':cardImageFocusX' => $story['card_image_focus_x'] ?? ($story['image_focus_x'] ?? 50),
                ':cardImageFocusY' => $story['card_image_focus_y'] ?? ($story['image_focus_y'] ?? 50),
                ':cardImageZoom' => $story['card_image_zoom'] ?? ($story['image_zoom'] ?? 1),
                ':status' => $story['status'],
                ':orderNumber' => $story['order_number'],
                ':isHidden' => $story['is_hidden'],
            ]);
            $newStory = $insertStory->fetch(PDO::FETCH_ASSOC);
            $newStoryId = (int)$newStory['id'];

            $fieldStmt = $db->prepare("SELECT * FROM story_fields WHERE id_story = :idStory ORDER BY order_number ASC, id ASC");
            $fieldStmt->execute([':idStory' => $id]);
            $insertField = $db->prepare("
                INSERT INTO story_fields (id_story, label, field_type, order_number, placeholder)
                VALUES (:idStory, :label, :fieldType, :orderNumber, :placeholder)
                RETURNING id
            ");
            $valueStmt = $db->prepare("SELECT value FROM story_field_values WHERE id_story = :idStory AND id_story_field = :idField");
            $insertValue = $db->prepare("
                INSERT INTO story_field_values (id_story, id_story_field, value)
                VALUES (:idStory, :idField, :value)
            ");

            foreach ($fieldStmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
                $insertField->execute([
                    ':idStory' => $newStoryId,
                    ':label' => $field['label'],
                    ':fieldType' => $field['field_type'],
                    ':orderNumber' => $field['order_number'],
                    ':placeholder' => $field['placeholder'],
                ]);
                $newFieldId = (int)$insertField->fetchColumn();

                $valueStmt->execute([':idStory' => $id, ':idField' => $field['id']]);
                $value = $valueStmt->fetchColumn();
                if ($value !== false) {
                    $insertValue->execute([':idStory' => $newStoryId, ':idField' => $newFieldId, ':value' => $value]);
                }
            }

            $charactersStmt = $db->prepare("SELECT * FROM story_characters WHERE id_story = :idStory ORDER BY order_number ASC, id ASC");
            $charactersStmt->execute([':idStory' => $id]);
            $insertCharacter = $db->prepare("
                INSERT INTO story_characters (id_story, id_character, pseudonym_field_id, order_number)
                VALUES (:idStory, :idCharacter, :pseudonymFieldId, :orderNumber)
                RETURNING id
            ");
            $pseudonymStmt = $db->prepare("SELECT * FROM story_character_pseudonym_mapping WHERE id_story_character = :idStoryCharacter");
            $insertPseudonym = $db->prepare("
                INSERT INTO story_character_pseudonym_mapping (id_story_character, pseudonym, is_excluded)
                VALUES (:idStoryCharacter, :pseudonym, :isExcluded)
            ");

            foreach ($charactersStmt->fetchAll(PDO::FETCH_ASSOC) as $character) {
                $insertCharacter->execute([
                    ':idStory' => $newStoryId,
                    ':idCharacter' => $character['id_character'],
                    ':pseudonymFieldId' => $character['pseudonym_field_id'],
                    ':orderNumber' => $character['order_number'],
                ]);
                $newStoryCharacterId = (int)$insertCharacter->fetchColumn();

                $pseudonymStmt->execute([':idStoryCharacter' => $character['id']]);
                foreach ($pseudonymStmt->fetchAll(PDO::FETCH_ASSOC) as $pseudonym) {
                    $insertPseudonym->execute([
                        ':idStoryCharacter' => $newStoryCharacterId,
                        ':pseudonym' => $pseudonym['pseudonym'],
                        ':isExcluded' => $pseudonym['is_excluded'],
                    ]);
                }
            }

            $db->commit();
            return $this->mapRowToStory($newStory);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function deleteStory(int $id): bool {
        $query = "DELETE FROM stories WHERE id = $1";
        return $this->executeNumberedQuery($query, [$id]);
    }

    public function getStoryFields(int $idStory): array {
        $stmt = $this->database->connect()->prepare(
            "SELECT * FROM story_fields WHERE id_story = :idStory ORDER BY order_number ASC"
        );
        $stmt->execute([':idStory' => $idStory]);
        
        $fields = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fields[] = [
                'id' => (int)$row['id'],
                'id_story' => (int)$row['id_story'],
                'label' => $row['label'],
                'field_type' => $row['field_type'],
                'order_number' => (int)$row['order_number'],
                'placeholder' => $row['placeholder']
            ];
        }
        return $fields;
    }

    public function createStoryField(int $idStory, string $label, string $fieldType, int $orderNumber = 0, string $placeholder = ''): ?array {
        $query = "INSERT INTO story_fields (id_story, label, field_type, order_number, placeholder) 
                  VALUES ($1, $2, $3, $4, $5) RETURNING *";
        
        $stmt = $this->database->connect()->prepare($this->toNamedPlaceholders($query));
        $stmt->execute([$idStory, $label, $fieldType, $orderNumber, $placeholder]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'id' => (int)$row['id'],
            'id_story' => (int)$row['id_story'],
            'label' => $row['label'],
            'field_type' => $row['field_type'],
            'order_number' => (int)$row['order_number'],
            'placeholder' => $row['placeholder']
        ] : null;
    }

    public function getStoryFieldByClientKey(int $idStory, string $clientKey): ?array {
        $stmt = $this->database->connect()->prepare(
            "SELECT * FROM story_fields
             WHERE id_story = :idStory AND placeholder = :placeholder
             LIMIT 1"
        );
        $stmt->execute([
            ':idStory' => $idStory,
            ':placeholder' => 'client_key:' . $clientKey,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'id' => (int)$row['id'],
            'id_story' => (int)$row['id_story'],
            'label' => $row['label'],
            'field_type' => $row['field_type'],
            'order_number' => (int)$row['order_number'],
            'placeholder' => $row['placeholder'],
        ] : null;
    }

    public function updateStoryFieldValue(int $idStory, int $idStoryField, string $value): bool {
        $query = "INSERT INTO story_field_values (id_story, id_story_field, value) 
                  VALUES ($1, $2, $3) 
                  ON CONFLICT (id_story, id_story_field) DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP";
        
        return $this->executeNumberedQuery($query, [$idStory, $idStoryField, $value]);
    }

    public function deleteStoryField(int $idStory, int $idStoryField): bool {
        $stmt = $this->database->connect()->prepare(
            "DELETE FROM story_fields WHERE id = :idField AND id_story = :idStory"
        );
        return $stmt->execute([':idField' => $idStoryField, ':idStory' => $idStory]);
    }

    public function updateStoryFieldMeta(int $idStory, int $idStoryField, string $label, int $orderNumber): bool {
        $stmt = $this->database->connect()->prepare(
            "UPDATE story_fields
             SET label = :label, order_number = :orderNumber
             WHERE id = :idField AND id_story = :idStory"
        );
        $stmt->execute([
            ':label' => $label,
            ':orderNumber' => $orderNumber,
            ':idField' => $idStoryField,
            ':idStory' => $idStory,
        ]);
        if ($stmt->rowCount() > 0) {
            return true;
        }

        $exists = $this->database->connect()->prepare(
            "SELECT 1 FROM story_fields WHERE id = :idField AND id_story = :idStory"
        );
        $exists->execute([':idField' => $idStoryField, ':idStory' => $idStory]);
        return (bool)$exists->fetchColumn();
    }

    public function cleanupMalformedDuplicateStoryFields(int $idStory): void {
        $db = $this->database->connect();
        $stmt = $db->prepare(
            "SELECT f.id, f.field_type, f.label, COALESCE(v.value, '') AS value
             FROM story_fields f
             LEFT JOIN story_field_values v ON v.id_story = f.id_story AND v.id_story_field = f.id
             WHERE f.id_story = :idStory
             ORDER BY f.order_number ASC, f.id ASC"
        );
        $stmt->execute([':idStory' => $idStory]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $delete = $db->prepare("DELETE FROM story_fields WHERE id = :id AND id_story = :idStory");
        foreach ($fields as $field) {
            $label = (string)$field['label'];
            if (!preg_match('/^Typ:\\s*.+Typ:/', $label)) {
                continue;
            }

            foreach ($fields as $candidate) {
                if ((int)$candidate['id'] === (int)$field['id']) {
                    continue;
                }
                if ($candidate['field_type'] === $field['field_type'] && (string)$candidate['value'] === (string)$field['value']) {
                    $delete->execute([':id' => (int)$field['id'], ':idStory' => $idStory]);
                    break;
                }
            }
        }
    }

    public function cleanupClientKeyDuplicateStoryFields(int $idStory): void {
        $db = $this->database->connect();
        $stmt = $db->prepare(
            "SELECT f.id, f.field_type, f.label, f.placeholder, COALESCE(v.value, '') AS value
             FROM story_fields f
             LEFT JOIN story_field_values v ON v.id_story = f.id_story AND v.id_story_field = f.id
             WHERE f.id_story = :idStory
             ORDER BY f.order_number ASC, f.id ASC"
        );
        $stmt->execute([':idStory' => $idStory]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $delete = $db->prepare("DELETE FROM story_fields WHERE id = :id AND id_story = :idStory");
        foreach ($fields as $field) {
            if (!str_starts_with((string)$field['placeholder'], 'client_key:')) {
                continue;
            }

            foreach ($fields as $candidate) {
                if ((int)$candidate['id'] === (int)$field['id']) {
                    continue;
                }
                if (str_starts_with((string)$candidate['placeholder'], 'client_key:')) {
                    continue;
                }
                if (
                    $candidate['field_type'] === $field['field_type']
                    && (string)$candidate['label'] === (string)$field['label']
                    && (string)$candidate['value'] === (string)$field['value']
                ) {
                    $delete->execute([':id' => (int)$field['id'], ':idStory' => $idStory]);
                    break;
                }
            }
        }
    }

    public function getStoryFieldValues(int $idStory): array {
        $stmt = $this->database->connect()->prepare("SELECT * FROM story_field_values WHERE id_story = :idStory");
        $stmt->execute([':idStory' => $idStory]);
        
        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(int)$row['id_story_field']] = $row['value'];
        }
        return $values;
    }

    public function getStoryCharacters(int $idStory): array {
        $variantSelect = $this->storyCharacterVariantColumnExists()
            ? 'sc.id_variant, cv.name AS variant_name, cv.description AS variant_description, cv.image AS variant_image, cv.image_fit AS variant_image_fit, cv.image_focus_x AS variant_image_focus_x, cv.image_focus_y AS variant_image_focus_y, cv.image_zoom AS variant_image_zoom,'
            : 'NULL AS id_variant, NULL AS variant_name, NULL AS variant_description, NULL AS variant_image, NULL AS variant_image_fit, NULL AS variant_image_focus_x, NULL AS variant_image_focus_y, NULL AS variant_image_zoom,';
        $variantJoin = $this->storyCharacterVariantColumnExists()
            ? 'LEFT JOIN character_variants cv ON cv.id = sc.id_variant AND cv.id_character = c.id'
            : '';
        $query = "SELECT sc.*, {$variantSelect} c.name, c.public_id, c.image, c.image_display_mode, c.image_fit, c.image_focus_x, c.image_focus_y, c.image_zoom FROM story_characters sc 
                  JOIN characters c ON sc.id_character = c.id 
                  {$variantJoin}
                  WHERE sc.id_story = $1 
                  ORDER BY sc.order_number ASC";
        
        $stmt = $this->database->connect()->prepare($this->toNamedPlaceholders($query));
        $stmt->execute([$idStory]);
        
        $characters = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $characters[] = [
                'id' => (int)$row['id'],
                'id_story' => (int)$row['id_story'],
                'id_character' => (int)$row['id_character'],
                'id_variant' => $row['id_variant'] ? (int)$row['id_variant'] : null,
                'pseudonym_field_id' => $row['pseudonym_field_id'] ? (int)$row['pseudonym_field_id'] : null,
                'order_number' => (int)$row['order_number'],
                'character_name' => $row['variant_name'] ?: $row['name'],
                'base_character_name' => $row['name'],
                'variant_name' => $row['variant_name'],
                'character_public_id' => $row['public_id'],
                'character_image' => $row['variant_image'] ?: $row['image'],
                'character_image_display_mode' => $row['image_display_mode'] ?? 'square',
                'character_image_fit' => $row['variant_image_fit'] ?? $row['image_fit'] ?? 'cover',
                'character_image_focus_x' => (int)($row['variant_image_focus_x'] ?? $row['image_focus_x'] ?? 50),
                'character_image_focus_y' => (int)($row['variant_image_focus_y'] ?? $row['image_focus_y'] ?? 50),
                'character_image_zoom' => (float)($row['variant_image_zoom'] ?? $row['image_zoom'] ?? 1)
            ];
        }
        return $characters;
    }

    public function getStoriesForCharacter(int $idCharacter, int $idUser, bool $includeHidden = false): array {
        $query = "SELECT DISTINCT s.*
                  FROM stories s
                  JOIN story_characters sc ON sc.id_story = s.id
                  WHERE sc.id_character = :idCharacter
                    AND s.id_user = :idUser";

        if (!$includeHidden) {
            $query .= " AND COALESCE(s.is_hidden, FALSE) = FALSE
                AND NOT EXISTS (
                    WITH RECURSIVE ancestors(id, parent_id, is_hidden) AS (
                        SELECT w.id, w.parent_id, COALESCE(w.is_hidden, FALSE)
                        FROM worlds w
                        WHERE w.id = s.id_world
                        UNION ALL
                        SELECT w2.id, w2.parent_id, COALESCE(w2.is_hidden, FALSE)
                        FROM worlds w2
                        JOIN ancestors a ON w2.id = a.parent_id
                    )
                    SELECT 1 FROM ancestors WHERE is_hidden = TRUE
                )";
        }

        $query .= " ORDER BY s.order_number ASC, s.updated_at DESC";

        $stmt = $this->database->connect()->prepare($query);
        $stmt->execute([
            ':idCharacter' => $idCharacter,
            ':idUser' => $idUser,
        ]);

        $stories = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stories[] = $this->mapRowToStory($row);
        }
        return $stories;
    }

    public function getInheritedFiltersByStoryIds(array $storyIds, int $userId): array {
        $storyIds = array_values(array_unique(array_filter(array_map('intval', $storyIds))));
        if (empty($storyIds)) {
            return [];
        }

        $placeholders = [];
        $params = [':userId' => $userId];
        foreach ($storyIds as $index => $storyId) {
            $key = ':storyId' . $index;
            $placeholders[] = $key;
            $params[$key] = $storyId;
        }
        $variantColumnExists = $this->storyCharacterVariantColumnExists();
        $baseFilterVariantGuard = $variantColumnExists
            ? "AND (
                sc.id_variant IS NULL
                OR NOT EXISTS (
                    SELECT 1
                    FROM content_filters variant_any
                    WHERE variant_any.object_type = 'character_variant'
                      AND variant_any.object_id = sc.id_variant
                )
            )"
            : '';
        $variantFilterUnion = $variantColumnExists
            ? "
                UNION

                SELECT sc.id_story AS story_id, variant_content.id_filter
                FROM story_characters sc
                JOIN characters c ON c.id = sc.id_character AND c.id_user = :userId
                JOIN content_filters variant_content ON variant_content.object_type = 'character_variant' AND variant_content.object_id = sc.id_variant
                WHERE sc.id_story IN (" . implode(',', $placeholders) . ")
            "
            : '';

        $stmt = $this->database->connect()->prepare(
            "WITH RECURSIVE story_ancestors(story_id, world_id, parent_id) AS (
                SELECT s.id, w.id, w.parent_id
                FROM stories s
                JOIN worlds w ON w.id = s.id_world
                WHERE s.id_user = :userId
                  AND s.id IN (" . implode(',', $placeholders) . ")
                UNION ALL
                SELECT sa.story_id, w.id, w.parent_id
                FROM worlds w
                JOIN story_ancestors sa ON w.id = sa.parent_id
                WHERE w.id_user = :userId
            ),
            inherited_filters AS (
                SELECT sc.id_story AS story_id, cf.id_filter
                FROM story_characters sc
                JOIN characters c ON c.id = sc.id_character AND c.id_user = :userId
                JOIN character_filters cf ON cf.id_character = c.id
                WHERE sc.id_story IN (" . implode(',', $placeholders) . ")
                  {$baseFilterVariantGuard}

                UNION

                SELECT sc.id_story AS story_id, content.id_filter
                FROM story_characters sc
                JOIN characters c ON c.id = sc.id_character AND c.id_user = :userId
                JOIN content_filters content ON content.object_type = 'character' AND content.object_id = c.id
                WHERE sc.id_story IN (" . implode(',', $placeholders) . ")
                  {$baseFilterVariantGuard}

                {$variantFilterUnion}

                UNION

                SELECT sa.story_id, wf.id_filter
                FROM story_ancestors sa
                JOIN world_filters wf ON wf.id_world = sa.world_id

                UNION

                SELECT sa.story_id, content.id_filter
                FROM story_ancestors sa
                JOIN content_filters content ON content.object_type = 'world' AND content.object_id = sa.world_id
            )
            SELECT DISTINCT inherited_filters.story_id, f.*
            FROM inherited_filters
            JOIN filters f ON f.id = inherited_filters.id_filter
            WHERE f.is_active = TRUE
            ORDER BY inherited_filters.story_id ASC, f.label ASC, f.name ASC"
        );
        $stmt->execute($params);

        $filtersByStory = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $storyId = (int)$row['story_id'];
            $filtersByStory[$storyId] ??= [];
            $filtersByStory[$storyId][] = new Filter(
                $row['label'] ?? $row['name'],
                isset($row['id']) ? (int)$row['id'] : null,
                $row['slug'] ?? $row['name'],
                $this->toBool($row['is_active'] ?? true)
            );
        }

        return $filtersByStory;
    }

    public function addCharacterToStory(int $idStory, int $idCharacter, ?int $pseudonymFieldId = null, ?int $idVariant = null): ?StoryCharacter {
        if ($this->storyCharacterVariantColumnExists()) {
            $query = "INSERT INTO story_characters (id_story, id_character, id_variant, pseudonym_field_id) 
                      VALUES ($1, $2, $3, $4) RETURNING *";
            $params = [$idStory, $idCharacter, $idVariant, $pseudonymFieldId];
        } else {
            $query = "INSERT INTO story_characters (id_story, id_character, pseudonym_field_id) 
                      VALUES ($1, $2, $3) RETURNING *";
            $params = [$idStory, $idCharacter, $pseudonymFieldId];
        }

        $stmt = $this->database->connect()->prepare($this->toNamedPlaceholders($query));
        $stmt->execute($params);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new StoryCharacter(
            (int)$row['id'],
            (int)$row['id_story'],
            (int)$row['id_character'],
            !empty($row['id_variant']) ? (int)$row['id_variant'] : null,
            $row['pseudonym_field_id'] ? (int)$row['pseudonym_field_id'] : null,
            (int)$row['order_number'],
            new DateTime($row['created_at'])
        ) : null;
    }

    public function updateStoryCharacterPseudonymField(int $idStory, int $idCharacter, ?int $idTemplateField, ?int $idVariant = null): bool {
        $variantClause = '';
        if ($this->storyCharacterVariantColumnExists()) {
            $variantClause = $idVariant ? ' AND id_variant = :variantId' : ' AND id_variant IS NULL';
        }

        $stmt = $this->database->connect()->prepare(
            "UPDATE story_characters
             SET pseudonym_field_id = :fieldId
             WHERE id_story = :storyId AND id_character = :characterId{$variantClause}"
        );
        $stmt->bindValue(':fieldId', $idTemplateField, $idTemplateField === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':storyId', $idStory, PDO::PARAM_INT);
        $stmt->bindValue(':characterId', $idCharacter, PDO::PARAM_INT);
        if ($this->storyCharacterVariantColumnExists() && $idVariant) {
            $stmt->bindValue(':variantId', $idVariant, PDO::PARAM_INT);
        }
        return $stmt->execute();
    }

    public function removeCharacterFromStory(int $idStory, int $idCharacter, ?int $idVariant = null): bool {
        if (!$this->storyCharacterVariantColumnExists()) {
            $query = "DELETE FROM story_characters WHERE id_story = $1 AND id_character = $2";
            return $this->executeNumberedQuery($query, [$idStory, $idCharacter]);
        }

        if ($idVariant) {
            $query = "DELETE FROM story_characters WHERE id_story = $1 AND id_character = $2 AND id_variant = $3";
            return $this->executeNumberedQuery($query, [$idStory, $idCharacter, $idVariant]);
        }

        $query = "DELETE FROM story_characters WHERE id_story = $1 AND id_character = $2 AND id_variant IS NULL";
        return $this->executeNumberedQuery($query, [$idStory, $idCharacter]);
    }

    private function storyCharacterVariantColumnExists(): bool
    {
        if ($this->storyCharacterVariantColumnExists !== null) {
            return $this->storyCharacterVariantColumnExists;
        }

        $stmt = $this->database->connect()->query("
            SELECT COUNT(*) = 1
            FROM information_schema.columns
            WHERE table_name = 'story_characters'
              AND column_name = 'id_variant'
        ");
        $this->storyCharacterVariantColumnExists = (bool)$stmt->fetchColumn();
        return $this->storyCharacterVariantColumnExists;
    }

    public function getStoryPseudonyms(int $idStoryCharacter): array {
        $stmt = $this->database->connect()->prepare(
            "SELECT * FROM story_character_pseudonym_mapping WHERE id_story_character = :idStoryCharacter ORDER BY created_at ASC"
        );
        $stmt->execute([':idStoryCharacter' => $idStoryCharacter]);
        
        $pseudonyms = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pseudonyms[] = [
                'id' => (int)$row['id'],
                'pseudonym' => $row['pseudonym'],
                'is_excluded' => $this->toBool($row['is_excluded'])
            ];
        }
        return $pseudonyms;
    }

    public function replaceStoryCharacterPseudonyms(int $idStoryCharacter, array $pseudonyms): void {
        $db = $this->database->connect();
        $delete = $db->prepare("DELETE FROM story_character_pseudonym_mapping WHERE id_story_character = :idStoryCharacter");
        $delete->execute([':idStoryCharacter' => $idStoryCharacter]);

        $insert = $db->prepare(
            "INSERT INTO story_character_pseudonym_mapping (id_story_character, pseudonym)
             VALUES (:idStoryCharacter, :pseudonym)"
        );
        foreach ($this->uniquePseudonyms($pseudonyms) as $pseudonym) {
            $insert->execute([':idStoryCharacter' => $idStoryCharacter, ':pseudonym' => $pseudonym]);
        }
    }

    public function getCharacterPseudonymSources(int $idCharacter): array {
        $stmt = $this->database->connect()->prepare(
            "SELECT tf.id, tf.label, tf.field_type, tf.placeholder, cfv.value
             FROM characters c
             JOIN template_fields tf ON tf.id_template = c.id_template
             LEFT JOIN character_field_values cfv ON cfv.id_character = c.id AND cfv.id_template_field = tf.id
             WHERE c.id = :idCharacter
               AND tf.field_type IN ('text', 'textarea', 'list', 'table', 'select')
             ORDER BY tf.location ASC, tf.order_number ASC, tf.id ASC"
        );
        $stmt->execute([':idCharacter' => $idCharacter]);

        $sources = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pseudonyms = $this->extractPseudonymsFromTemplateField($row);
            $isLikelyAlias = $this->looksLikeAliasLabel((string)$row['label'])
                || ((string)$row['field_type'] === 'table' && $this->tableHasAliasRow((string)($row['placeholder'] ?? '')))
                || !empty($pseudonyms);
            if (!$isLikelyAlias) {
                continue;
            }

            $sources[] = [
                'id' => (int)$row['id'],
                'label' => (string)$row['label'],
                'type' => (string)$row['field_type'],
                'pseudonyms' => $pseudonyms,
            ];
        }
        return $sources;
    }

    public function extractPseudonymsForCharacterField(int $idCharacter, int $idTemplateField): array {
        $stmt = $this->database->connect()->prepare(
            "SELECT tf.id, tf.label, tf.field_type, tf.placeholder, cfv.value
             FROM template_fields tf
             JOIN characters c ON c.id_template = tf.id_template
             LEFT JOIN character_field_values cfv ON cfv.id_character = c.id AND cfv.id_template_field = tf.id
             WHERE c.id = :idCharacter AND tf.id = :idTemplateField"
        );
        $stmt->execute([':idCharacter' => $idCharacter, ':idTemplateField' => $idTemplateField]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->extractPseudonymsFromTemplateField($row) : [];
    }

    public function addPseudonym(int $idStoryCharacter, string $pseudonym): bool {
        $query = "INSERT INTO story_character_pseudonym_mapping (id_story_character, pseudonym) 
                  VALUES ($1, $2)";
        
        return $this->executeNumberedQuery($query, [$idStoryCharacter, $pseudonym]);
    }

    public function togglePseudonymExclusion(int $idPseudonymMapping): bool {
        $query = "UPDATE story_character_pseudonym_mapping SET is_excluded = NOT is_excluded WHERE id = $1";
        return $this->executeNumberedQuery($query, [$idPseudonymMapping]);
    }

    private function mapRowToStory(array $row): Story {
        return new Story(
            (int)$row['id'],
            $row['public_id'],
            (int)$row['id_user'],
            (int)$row['id_world'],
            $row['id_folder'] ? (int)$row['id_folder'] : null,
            $row['title'],
            $row['description'],
            $row['story_date'] ?? '',
            $row['image'],
            $row['image_fit'] ?? 'cover',
            (int)($row['image_focus_x'] ?? 50),
            (int)($row['image_focus_y'] ?? 50),
            (float)($row['image_zoom'] ?? 1),
            $row['card_image_fit'] ?? ($row['image_fit'] ?? 'cover'),
            (int)($row['card_image_focus_x'] ?? ($row['image_focus_x'] ?? 50)),
            (int)($row['card_image_focus_y'] ?? ($row['image_focus_y'] ?? 50)),
            (float)($row['card_image_zoom'] ?? ($row['image_zoom'] ?? 1)),
            $row['timeline_branch_name'] ?? '',
            $row['timeline_split_date'] ?? '',
            trim((string)($row['timeline_split_date'] ?? '')) === '' || $this->toBool($row['timeline_split_unknown'] ?? false),
            $row['timeline_merge_date'] ?? '',
            trim((string)($row['timeline_merge_date'] ?? '')) === '' || $this->toBool($row['timeline_merge_unknown'] ?? false),
            isset($row['timeline_position_x']) && $row['timeline_position_x'] !== null ? (float)$row['timeline_position_x'] : null,
            isset($row['timeline_position_y']) && $row['timeline_position_y'] !== null ? (float)$row['timeline_position_y'] : null,
            $row['status'],
            (int)$row['order_number'],
            $this->toBool($row['is_hidden']),
            new DateTime($row['created_at']),
            new DateTime($row['updated_at'])
        );
    }

    private function executeNumberedQuery(string $query, array $params): bool {
        $stmt = $this->database->connect()->prepare($this->toNamedPlaceholders($query));
        return $stmt->execute($params);
    }

    private function toNamedPlaceholders(string $query): string {
        return preg_replace('/\$(\d+)/', '?', $query);
    }

    private function toBool($value): bool {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function extractPseudonymsFromTemplateField(array $field): array {
        $type = (string)($field['field_type'] ?? 'text');
        $value = (string)($field['value'] ?? '');

        if ($type === 'table') {
            return $this->extractPseudonymsFromTable((string)($field['placeholder'] ?? ''), $value);
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->extractStringsFromMixed($decoded);
        }

        return $this->splitPseudonymText($value);
    }

    private function extractPseudonymsFromTable(string $placeholder, string $value): array {
        $config = json_decode($placeholder, true);
        $rows = is_array($config['rows'] ?? null) ? $config['rows'] : (is_array($config) ? $config : []);
        $savedRows = json_decode($value, true);
        if (!is_array($savedRows)) {
            return [];
        }

        $pseudonyms = [];
        foreach ($rows as $row) {
            $rowLabel = is_array($row) ? (string)($row['label'] ?? $row['name'] ?? '') : (string)$row;
            $rowKey = is_array($row) ? (string)($row['key'] ?? $rowLabel) : $rowLabel;
            if (!$this->looksLikeAliasLabel($rowLabel) && !$this->looksLikeAliasLabel($rowKey)) {
                continue;
            }

            $cell = $savedRows[$rowKey] ?? $savedRows[$rowLabel] ?? null;
            $cellValue = is_array($cell) && array_key_exists('value', $cell) ? $cell['value'] : $cell;
            $pseudonyms = array_merge($pseudonyms, $this->extractStringsFromMixed($cellValue));
        }

        return $this->uniquePseudonyms($pseudonyms);
    }

    private function tableHasAliasRow(string $placeholder): bool {
        $config = json_decode($placeholder, true);
        $rows = is_array($config['rows'] ?? null) ? $config['rows'] : (is_array($config) ? $config : []);

        foreach ($rows as $row) {
            $rowLabel = is_array($row) ? (string)($row['label'] ?? $row['name'] ?? '') : (string)$row;
            $rowKey = is_array($row) ? (string)($row['key'] ?? $rowLabel) : $rowLabel;
            if ($this->looksLikeAliasLabel($rowLabel) || $this->looksLikeAliasLabel($rowKey)) {
                return true;
            }
        }

        return false;
    }

    private function extractStringsFromMixed(mixed $value): array {
        if (is_string($value)) {
            return $this->splitPseudonymText($value);
        }

        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_array($item) && array_key_exists('value', $item)) {
                $result = array_merge($result, $this->extractStringsFromMixed($item['value']));
                continue;
            }
            $result = array_merge($result, $this->extractStringsFromMixed($item));
        }
        return $this->uniquePseudonyms($result);
    }

    private function splitPseudonymText(string $text): array {
        $parts = preg_split('/[,;\/\n\r]+/u', $text) ?: [];
        return $this->uniquePseudonyms($parts);
    }

    private function uniquePseudonyms(array $pseudonyms): array {
        $seen = [];
        $result = [];
        foreach ($pseudonyms as $pseudonym) {
            $clean = trim((string)$pseudonym);
            $clean = preg_replace('/\s+/u', ' ', $clean) ?: '';
            if ($clean === '') {
                continue;
            }
            $key = mb_strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $clean;
        }
        return $result;
    }

    private function looksLikeAliasLabel(string $label): bool {
        $normalized = mb_strtolower(trim($label));
        $normalized = strtr($normalized, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        foreach (['rowniez znany jako', 'znany jako', 'alias', 'aliasy', 'pseudonim', 'pseudonimy', 'aka', 'also known as'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }
}
