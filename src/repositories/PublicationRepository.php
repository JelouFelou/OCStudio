<?php

require_once __DIR__ . '/Repository.php';

class PublicationRepository extends Repository
{
    public const REACTION_TYPES = [
        'like' => ['label' => 'Lubie', 'emoji' => '👍'],
        'love' => ['label' => 'Super', 'emoji' => '❤️'],
        'laugh' => ['label' => 'Haha', 'emoji' => '😂'],
        'wow' => ['label' => 'Wow', 'emoji' => '😮'],
        'sad' => ['label' => 'Smutne', 'emoji' => '😢'],
    ];

    public function saveCharacterRevision(
        int $ownerUserId,
        int $characterId,
        ?int $variantId,
        array $payload,
        array $mediaAssetIds,
        array $filters,
        string $searchText,
        string $changeReason,
        string $ageRating = 'general',
        ?array $copyOrigin = null,
        bool $publish = true
    ): array {
        $db = $this->database->connect();
        $variantKey = $variantId ?? 0;
        $changeReason = in_array($changeReason, ['initial', 'refresh', 'variant_switch', 'copy'], true)
            ? $changeReason
            : 'refresh';
        $ageRating = $ageRating === 'adult' ? 'adult' : 'general';
        $status = $publish ? 'published' : 'unpublished';
        $copyOrigin = $this->normalizeCopyOrigin($copyOrigin);

        try {
            $db->beginTransaction();

            $publication = $this->lockCharacterPublication($db, $ownerUserId, $characterId, $variantKey);
            if (!$publication) {
                $insert = $db->prepare(
                    "INSERT INTO publications (
                        owner_user_id, content_type, character_id, selected_variant_id, status, age_rating, age_rating_source,
                        origin_publication_id, origin_owner_user_id, origin_public_id, origin_author_name, origin_title, origin_attribution_visible
                     )
                     VALUES (
                        :ownerUserId, 'character', :characterId, :variantId, :status, :ageRating, 'author',
                        :originPublicationId, :originOwnerUserId, :originPublicId, :originAuthorName, :originTitle, :originAttributionVisible
                     )
                     RETURNING *"
                );
                $insert->execute([
                    ':ownerUserId' => $ownerUserId,
                    ':characterId' => $characterId,
                    ':variantId' => $variantId,
                    ':ageRating' => $ageRating,
                    ':status' => $status,
                    ':originPublicationId' => $copyOrigin['publicationId'],
                    ':originOwnerUserId' => $copyOrigin['ownerUserId'],
                    ':originPublicId' => $copyOrigin['publicId'],
                    ':originAuthorName' => $copyOrigin['authorName'],
                    ':originTitle' => $copyOrigin['title'],
                    ':originAttributionVisible' => $copyOrigin['attributionVisible'] ? 'true' : 'false',
                ]);
                $publication = $insert->fetch(PDO::FETCH_ASSOC);
            } else {
                $update = $db->prepare(
                    "UPDATE publications
                     SET status = 'published',
                         age_rating = :ageRating,
                         unpublished_at = NULL,
                         published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                     RETURNING *"
                );
                $update->execute([
                    ':id' => (int)$publication['id'],
                    ':ageRating' => $ageRating,
                ]);
                $publication = $update->fetch(PDO::FETCH_ASSOC);
            }

            $revisionNumber = $this->nextRevisionNumber($db, (int)$publication['id']);
            $revision = $this->insertRevision(
                $db,
                (int)$publication['id'],
                $revisionNumber,
                $payload,
                $searchText,
                $ownerUserId,
                $changeReason
            );

            $this->replaceRevisionMedia($db, (int)$revision['id'], $mediaAssetIds);
            $this->replaceRevisionFilters($db, (int)$revision['id'], $filters);

            $final = $db->prepare(
                'UPDATE publications
                 SET current_revision_id = :revisionId,
                     status = :status,
                     age_rating = :ageRating,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                 RETURNING *'
            );
            $final->execute([
                ':revisionId' => (int)$revision['id'],
                ':ageRating' => $ageRating,
                ':status' => $status,
                ':id' => (int)$publication['id'],
            ]);
            $publication = $final->fetch(PDO::FETCH_ASSOC);

            $db->commit();

            return $this->decorate($publication, $revision);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function saveTemplateRevision(
        int $ownerUserId,
        int $templateId,
        array $payload,
        array $filters,
        string $searchText,
        string $changeReason = 'refresh',
        string $ageRating = 'general',
        ?array $copyOrigin = null,
        bool $publish = true
    ): array {
        $db = $this->database->connect();
        $changeReason = in_array($changeReason, ['initial', 'refresh', 'variant_switch', 'copy'], true)
            ? $changeReason
            : 'refresh';
        $ageRating = $ageRating === 'adult' ? 'adult' : 'general';
        $status = $publish ? 'published' : 'unpublished';
        $copyOrigin = $this->normalizeCopyOrigin($copyOrigin);

        try {
            $db->beginTransaction();

            $publication = $this->lockTemplatePublication($db, $ownerUserId, $templateId);
            if (!$publication) {
                $insert = $db->prepare(
                    "INSERT INTO publications (
                        owner_user_id, content_type, template_id, status, age_rating, age_rating_source,
                        origin_publication_id, origin_owner_user_id, origin_public_id, origin_author_name, origin_title, origin_attribution_visible
                     )
                     VALUES (
                        :ownerUserId, 'template', :templateId, :status, :ageRating, 'author',
                        :originPublicationId, :originOwnerUserId, :originPublicId, :originAuthorName, :originTitle, :originAttributionVisible
                     )
                     RETURNING *"
                );
                $insert->execute([
                    ':ownerUserId' => $ownerUserId,
                    ':templateId' => $templateId,
                    ':ageRating' => $ageRating,
                    ':status' => $status,
                    ':originPublicationId' => $copyOrigin['publicationId'],
                    ':originOwnerUserId' => $copyOrigin['ownerUserId'],
                    ':originPublicId' => $copyOrigin['publicId'],
                    ':originAuthorName' => $copyOrigin['authorName'],
                    ':originTitle' => $copyOrigin['title'],
                    ':originAttributionVisible' => $copyOrigin['attributionVisible'] ? 'true' : 'false',
                ]);
                $publication = $insert->fetch(PDO::FETCH_ASSOC);
            } else {
                $update = $db->prepare(
                    "UPDATE publications
                     SET status = 'published',
                         age_rating = :ageRating,
                         unpublished_at = NULL,
                         published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                     RETURNING *"
                );
                $update->execute([
                    ':id' => (int)$publication['id'],
                    ':ageRating' => $ageRating,
                ]);
                $publication = $update->fetch(PDO::FETCH_ASSOC);
            }

            $revisionNumber = $this->nextRevisionNumber($db, (int)$publication['id']);
            $revision = $this->insertRevision(
                $db,
                (int)$publication['id'],
                $revisionNumber,
                $payload,
                $searchText,
                $ownerUserId,
                $changeReason
            );

            $this->replaceRevisionFilters($db, (int)$revision['id'], $filters);

            $final = $db->prepare(
                'UPDATE publications
                 SET current_revision_id = :revisionId,
                     status = :status,
                     age_rating = :ageRating,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                 RETURNING *'
            );
            $final->execute([
                ':revisionId' => (int)$revision['id'],
                ':status' => $status,
                ':ageRating' => $ageRating,
                ':id' => (int)$publication['id'],
            ]);
            $publication = $final->fetch(PDO::FETCH_ASSOC);

            $db->commit();

            return $this->decorate($publication, $revision);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function saveImageRevision(
        int $ownerUserId,
        int $imageAssetId,
        array $payload,
        array $filters,
        string $searchText,
        string $changeReason = 'refresh',
        string $ageRating = 'general',
        ?array $copyOrigin = null,
        bool $publish = true
    ): array {
        $db = $this->database->connect();
        $changeReason = in_array($changeReason, ['initial', 'refresh', 'variant_switch', 'copy'], true)
            ? $changeReason
            : 'refresh';
        $ageRating = $ageRating === 'adult' ? 'adult' : 'general';
        $status = $publish ? 'published' : 'unpublished';
        $copyOrigin = $this->normalizeCopyOrigin($copyOrigin);

        try {
            $db->beginTransaction();

            $publication = $this->lockImagePublication($db, $ownerUserId, $imageAssetId);
            if (!$publication) {
                $insert = $db->prepare(
                    "INSERT INTO publications (
                        owner_user_id, content_type, image_asset_id, status, age_rating, age_rating_source,
                        origin_publication_id, origin_owner_user_id, origin_public_id, origin_author_name, origin_title, origin_attribution_visible
                     )
                     VALUES (
                        :ownerUserId, 'image', :imageAssetId, :status, :ageRating, 'author',
                        :originPublicationId, :originOwnerUserId, :originPublicId, :originAuthorName, :originTitle, :originAttributionVisible
                     )
                     RETURNING *"
                );
                $insert->execute([
                    ':ownerUserId' => $ownerUserId,
                    ':imageAssetId' => $imageAssetId,
                    ':ageRating' => $ageRating,
                    ':status' => $status,
                    ':originPublicationId' => $copyOrigin['publicationId'],
                    ':originOwnerUserId' => $copyOrigin['ownerUserId'],
                    ':originPublicId' => $copyOrigin['publicId'],
                    ':originAuthorName' => $copyOrigin['authorName'],
                    ':originTitle' => $copyOrigin['title'],
                    ':originAttributionVisible' => $copyOrigin['attributionVisible'] ? 'true' : 'false',
                ]);
                $publication = $insert->fetch(PDO::FETCH_ASSOC);
            } else {
                $update = $db->prepare(
                    "UPDATE publications
                     SET status = 'published',
                         age_rating = :ageRating,
                         unpublished_at = NULL,
                         published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                     RETURNING *"
                );
                $update->execute([
                    ':id' => (int)$publication['id'],
                    ':ageRating' => $ageRating,
                ]);
                $publication = $update->fetch(PDO::FETCH_ASSOC);
            }

            $revisionNumber = $this->nextRevisionNumber($db, (int)$publication['id']);
            $revision = $this->insertRevision(
                $db,
                (int)$publication['id'],
                $revisionNumber,
                $payload,
                $searchText,
                $ownerUserId,
                $changeReason
            );

            $this->replaceRevisionMedia($db, (int)$revision['id'], [$imageAssetId]);
            $this->replaceRevisionFilters($db, (int)$revision['id'], $filters);

            $final = $db->prepare(
                'UPDATE publications
                 SET current_revision_id = :revisionId,
                     status = :status,
                     age_rating = :ageRating,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                 RETURNING *'
            );
            $final->execute([
                ':revisionId' => (int)$revision['id'],
                ':status' => $status,
                ':ageRating' => $ageRating,
                ':id' => (int)$publication['id'],
            ]);
            $publication = $final->fetch(PDO::FETCH_ASSOC);

            $db->commit();

            return $this->decorate($publication, $revision);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function saveStoryRevision(
        int $ownerUserId,
        int $storyId,
        array $payload,
        array $mediaAssetIds,
        array $filters,
        string $searchText,
        string $changeReason = 'refresh',
        string $ageRating = 'general',
        ?array $copyOrigin = null,
        bool $publish = true
    ): array {
        $db = $this->database->connect();
        $changeReason = in_array($changeReason, ['initial', 'refresh', 'variant_switch', 'copy'], true)
            ? $changeReason
            : 'refresh';
        $ageRating = $ageRating === 'adult' ? 'adult' : 'general';
        $status = $publish ? 'published' : 'unpublished';
        $copyOrigin = $this->normalizeCopyOrigin($copyOrigin);

        try {
            $db->beginTransaction();

            $publication = $this->lockStoryPublication($db, $ownerUserId, $storyId);
            if (!$publication) {
                $insert = $db->prepare(
                    "INSERT INTO publications (
                        owner_user_id, content_type, story_id, status, age_rating, age_rating_source,
                        origin_publication_id, origin_owner_user_id, origin_public_id, origin_author_name, origin_title, origin_attribution_visible
                     )
                     VALUES (
                        :ownerUserId, 'story', :storyId, :status, :ageRating, 'author',
                        :originPublicationId, :originOwnerUserId, :originPublicId, :originAuthorName, :originTitle, :originAttributionVisible
                     )
                     RETURNING *"
                );
                $insert->execute([
                    ':ownerUserId' => $ownerUserId,
                    ':storyId' => $storyId,
                    ':ageRating' => $ageRating,
                    ':status' => $status,
                    ':originPublicationId' => $copyOrigin['publicationId'],
                    ':originOwnerUserId' => $copyOrigin['ownerUserId'],
                    ':originPublicId' => $copyOrigin['publicId'],
                    ':originAuthorName' => $copyOrigin['authorName'],
                    ':originTitle' => $copyOrigin['title'],
                    ':originAttributionVisible' => $copyOrigin['attributionVisible'] ? 'true' : 'false',
                ]);
                $publication = $insert->fetch(PDO::FETCH_ASSOC);
            } else {
                $update = $db->prepare(
                    "UPDATE publications
                     SET status = 'published',
                         age_rating = :ageRating,
                         unpublished_at = NULL,
                         published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                     RETURNING *"
                );
                $update->execute([
                    ':id' => (int)$publication['id'],
                    ':ageRating' => $ageRating,
                ]);
                $publication = $update->fetch(PDO::FETCH_ASSOC);
            }

            $revisionNumber = $this->nextRevisionNumber($db, (int)$publication['id']);
            $revision = $this->insertRevision(
                $db,
                (int)$publication['id'],
                $revisionNumber,
                $payload,
                $searchText,
                $ownerUserId,
                $changeReason
            );

            $this->replaceRevisionMedia($db, (int)$revision['id'], $mediaAssetIds);
            $this->replaceRevisionFilters($db, (int)$revision['id'], $filters);

            $final = $db->prepare(
                'UPDATE publications
                 SET current_revision_id = :revisionId,
                     status = :status,
                     age_rating = :ageRating,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                 RETURNING *'
            );
            $final->execute([
                ':revisionId' => (int)$revision['id'],
                ':status' => $status,
                ':ageRating' => $ageRating,
                ':id' => (int)$publication['id'],
            ]);
            $publication = $final->fetch(PDO::FETCH_ASSOC);

            $db->commit();

            return $this->decorate($publication, $revision);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function saveRelationBoardRevision(
        int $ownerUserId,
        int $boardId,
        array $payload,
        array $filters,
        string $searchText,
        string $changeReason = 'refresh',
        string $ageRating = 'general',
        ?array $copyOrigin = null,
        bool $publish = true
    ): array {
        $db = $this->database->connect();
        $changeReason = in_array($changeReason, ['initial', 'refresh', 'variant_switch', 'copy'], true)
            ? $changeReason
            : 'refresh';
        $ageRating = $ageRating === 'adult' ? 'adult' : 'general';
        $status = $publish ? 'published' : 'unpublished';
        $copyOrigin = $this->normalizeCopyOrigin($copyOrigin);

        try {
            $db->beginTransaction();

            $publication = $this->lockRelationBoardPublication($db, $ownerUserId, $boardId);
            if (!$publication) {
                $insert = $db->prepare(
                    "INSERT INTO publications (
                        owner_user_id, content_type, relation_board_id, status, age_rating, age_rating_source,
                        origin_publication_id, origin_owner_user_id, origin_public_id, origin_author_name, origin_title, origin_attribution_visible
                     )
                     VALUES (
                        :ownerUserId, 'relation_board', :boardId, :status, :ageRating, 'author',
                        :originPublicationId, :originOwnerUserId, :originPublicId, :originAuthorName, :originTitle, :originAttributionVisible
                     )
                     RETURNING *"
                );
                $insert->execute([
                    ':ownerUserId' => $ownerUserId,
                    ':boardId' => $boardId,
                    ':ageRating' => $ageRating,
                    ':status' => $status,
                    ':originPublicationId' => $copyOrigin['publicationId'],
                    ':originOwnerUserId' => $copyOrigin['ownerUserId'],
                    ':originPublicId' => $copyOrigin['publicId'],
                    ':originAuthorName' => $copyOrigin['authorName'],
                    ':originTitle' => $copyOrigin['title'],
                    ':originAttributionVisible' => $copyOrigin['attributionVisible'] ? 'true' : 'false',
                ]);
                $publication = $insert->fetch(PDO::FETCH_ASSOC);
            } else {
                $update = $db->prepare(
                    "UPDATE publications
                     SET status = 'published',
                         age_rating = :ageRating,
                         unpublished_at = NULL,
                         published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                     RETURNING *"
                );
                $update->execute([
                    ':id' => (int)$publication['id'],
                    ':ageRating' => $ageRating,
                ]);
                $publication = $update->fetch(PDO::FETCH_ASSOC);
            }

            $revisionNumber = $this->nextRevisionNumber($db, (int)$publication['id']);
            $revision = $this->insertRevision(
                $db,
                (int)$publication['id'],
                $revisionNumber,
                $payload,
                $searchText,
                $ownerUserId,
                $changeReason
            );

            $this->replaceRevisionFilters($db, (int)$revision['id'], $filters);

            $final = $db->prepare(
                'UPDATE publications
                 SET current_revision_id = :revisionId,
                     status = :status,
                     age_rating = :ageRating,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                 RETURNING *'
            );
            $final->execute([
                ':revisionId' => (int)$revision['id'],
                ':status' => $status,
                ':ageRating' => $ageRating,
                ':id' => (int)$publication['id'],
            ]);
            $publication = $final->fetch(PDO::FETCH_ASSOC);

            $db->commit();

            return $this->decorate($publication, $revision);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function unpublishOwned(int $ownerUserId, int $publicationId): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "UPDATE publications
             SET status = 'unpublished',
                 unpublished_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND owner_user_id = :ownerUserId
             RETURNING *"
        );
        $stmt->execute([':id' => $publicationId, ':ownerUserId' => $ownerUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->decorate($row) : null;
    }

    public function findVisibleByPublicId(string $publicId, ?int $viewerUserId = null): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.payload,
                    pr.created_at AS revision_created_at,
                    CASE WHEN p.origin_owner_user_id = :viewerUserId THEN TRUE ELSE FALSE END AS copy_origin_is_own,
                    u.username,
                    u.firstname,
                    u.lastname
             FROM publications p
             JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN users u ON u.id = p.owner_user_id
             WHERE p.public_id::text = :publicId
               AND p.status = 'published'
               AND p.moderation_state = 'visible'
             LIMIT 1"
        );
        $stmt->execute([
            ':publicId' => $publicId,
            ':viewerUserId' => $viewerUserId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $publication = $this->decorate($row);
        $payload = json_decode((string)$row['payload'], true);
        if (!is_array($payload)) {
            return null;
        }

        $publication['payload'] = $payload;
        $publication['author'] = [
            'username' => (string)($row['username'] ?? ''),
            'displayName' => trim((string)($row['username'] ?? '')) !== ''
                ? (string)$row['username']
                : trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')),
            'profileUrl' => trim((string)($row['username'] ?? '')) !== ''
                ? '/u/' . rawurlencode((string)$row['username'])
                : '',
        ];
        $publication['isOwn'] = $viewerUserId !== null && (int)$row['owner_user_id'] === $viewerUserId;
        $publication['revisionCreatedAt'] = $row['revision_created_at'] ?? null;

        return $publication;
    }

    public function findCopyableByPublicId(string $publicId): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.id AS revision_id,
                    pr.revision_number,
                    pr.payload,
                    pr.search_text,
                    pr.created_at AS revision_created_at,
                    u.username,
                    u.firstname,
                    u.lastname,
                    u.copy_attribution_enabled AS source_copy_attribution_enabled
             FROM publications p
             JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN users u ON u.id = p.owner_user_id
             WHERE p.public_id::text = :publicId
               AND p.status = 'published'
               AND p.moderation_state = 'visible'
               AND p.allow_copying = TRUE
               AND p.content_type IN ('character', 'template', 'image', 'story', 'relation_board')
             LIMIT 1"
        );
        $stmt->execute([':publicId' => $publicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $payload = json_decode((string)$row['payload'], true);
        if (!is_array($payload)) {
            return null;
        }

        $publication = $this->decorate($row);
        $username = (string)($row['username'] ?? '');
        $publication['payload'] = $payload;
        $publication['card'] = $this->publicationCardFromPayload($publication, $payload);
        $publication['author'] = [
            'username' => $username,
            'displayName' => trim($username) !== ''
                ? $username
                : trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')),
            'profileUrl' => trim($username) !== '' ? '/u/' . rawurlencode($username) : '',
        ];
        $publication['filters'] = $this->revisionFilters((int)$row['revision_id']);
        $publication['mediaAssetIds'] = $this->revisionMediaAssetIds((int)$row['revision_id']);
        $publication['searchText'] = (string)($row['search_text'] ?? '');
        $publication['sourceCopyAttributionEnabled'] = filter_var(
            $row['source_copy_attribution_enabled'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? true;
        $publication['revisionCreatedAt'] = $row['revision_created_at'] ?? null;

        return $publication;
    }

    public function setOriginAttributionVisibleByOwner(int $originOwnerUserId, bool $visible): void
    {
        $stmt = $this->database->connect()->prepare(
            'UPDATE publications
             SET origin_attribution_visible = :visible,
                 updated_at = CURRENT_TIMESTAMP
             WHERE origin_owner_user_id = :originOwnerUserId'
        );
        $stmt->execute([
            ':visible' => $visible ? 'true' : 'false',
            ':originOwnerUserId' => $originOwnerUserId,
        ]);
    }

    public function basicPublicationInfo(int $publicationId): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT p.id,
                    p.public_id,
                    p.owner_user_id,
                    p.content_type,
                    p.age_rating,
                    p.moderation_state,
                    pr.payload
             FROM publications p
             LEFT JOIN publication_revisions pr ON pr.id = p.current_revision_id
             WHERE p.id = :publicationId
             LIMIT 1"
        );
        $stmt->execute([':publicationId' => $publicationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $payload = json_decode((string)($row['payload'] ?? ''), true);
        $card = is_array($payload) ? $this->publicationCardFromPayload([
            'contentType' => (string)$row['content_type'],
        ], $payload) : [];

        return [
            'id' => (int)$row['id'],
            'publicId' => (string)$row['public_id'],
            'ownerUserId' => (int)$row['owner_user_id'],
            'contentType' => (string)$row['content_type'],
            'ageRating' => (string)$row['age_rating'],
            'moderationState' => (string)$row['moderation_state'],
            'title' => (string)($card['title'] ?? 'Publikacja'),
            'url' => '/p/' . (string)$row['public_id'],
        ];
    }

    public function reactionSummary(int $publicationId, ?int $viewerUserId = null): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT reaction_type, COUNT(*) AS count
             FROM publication_reactions
             WHERE publication_id = :publicationId
             GROUP BY reaction_type'
        );
        $stmt->execute([':publicationId' => $publicationId]);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string)$row['reaction_type']] = (int)$row['count'];
        }

        $currentReaction = null;
        if ($viewerUserId !== null) {
            $currentStmt = $this->database->connect()->prepare(
                'SELECT reaction_type
                 FROM publication_reactions
                 WHERE publication_id = :publicationId AND user_id = :userId
                 LIMIT 1'
            );
            $currentStmt->execute([
                ':publicationId' => $publicationId,
                ':userId' => $viewerUserId,
            ]);
            $current = $currentStmt->fetchColumn();
            $currentReaction = $current === false ? null : (string)$current;
        }

        $items = [];
        $total = 0;
        foreach (self::REACTION_TYPES as $type => $definition) {
            $count = $counts[$type] ?? 0;
            $total += $count;
            $items[] = [
                'type' => $type,
                'label' => $definition['label'],
                'emoji' => $definition['emoji'],
                'count' => $count,
                'active' => $currentReaction === $type,
            ];
        }

        return [
            'items' => $items,
            'total' => $total,
            'currentReaction' => $currentReaction,
        ];
    }

    public function toggleReaction(int $publicationId, int $userId, string $reactionType): ?array
    {
        if (!array_key_exists($reactionType, self::REACTION_TYPES)) {
            throw new InvalidArgumentException('Nieprawidlowa reakcja.', 422);
        }

        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $publicationStmt = $db->prepare(
                "SELECT id
                 FROM publications
                 WHERE id = :publicationId
                   AND status = 'published'
                   AND moderation_state = 'visible'
                 FOR SHARE"
            );
            $publicationStmt->execute([':publicationId' => $publicationId]);
            if (!$publicationStmt->fetchColumn()) {
                $db->rollBack();
                return null;
            }

            $currentStmt = $db->prepare(
                'SELECT reaction_type
                 FROM publication_reactions
                 WHERE publication_id = :publicationId AND user_id = :userId
                 FOR UPDATE'
            );
            $currentStmt->execute([
                ':publicationId' => $publicationId,
                ':userId' => $userId,
            ]);
            $current = $currentStmt->fetchColumn();

            if ($current === $reactionType) {
                $delete = $db->prepare(
                    'DELETE FROM publication_reactions
                     WHERE publication_id = :publicationId AND user_id = :userId'
                );
                $delete->execute([
                    ':publicationId' => $publicationId,
                    ':userId' => $userId,
                ]);
            } else {
                $upsert = $db->prepare(
                    'INSERT INTO publication_reactions (publication_id, user_id, reaction_type)
                     VALUES (:publicationId, :userId, :reactionType)
                     ON CONFLICT (publication_id, user_id)
                     DO UPDATE SET reaction_type = EXCLUDED.reaction_type,
                                   updated_at = CURRENT_TIMESTAMP'
                );
                $upsert->execute([
                    ':publicationId' => $publicationId,
                    ':userId' => $userId,
                    ':reactionType' => $reactionType,
                ]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $this->reactionSummary($publicationId, $userId);
    }

    public function visibleComments(int $publicationId, ?int $viewerUserId = null, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->database->connect()->prepare(
            "SELECT pc.id,
                    pc.publication_id,
                    pc.user_id,
                    pc.body,
                    pc.created_at,
                    pc.updated_at,
                    u.username,
                    u.firstname,
                    u.lastname
             FROM publication_comments pc
             JOIN users u ON u.id = pc.user_id
             WHERE pc.publication_id = :publicationId
               AND pc.status = 'visible'
             ORDER BY pc.created_at ASC, pc.id ASC
             LIMIT {$limit}"
        );
        $stmt->execute([':publicationId' => $publicationId]);

        return array_map(
            fn(array $row) => $this->decorateComment($row, $viewerUserId),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function addComment(int $publicationId, int $userId, string $body): ?array
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
        $length = mb_strlen($body);
        if ($length < 2 || $length > 1000) {
            throw new InvalidArgumentException('Komentarz musi miec od 2 do 1000 znakow.', 422);
        }

        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $publicationStmt = $db->prepare(
                "SELECT id
                 FROM publications
                 WHERE id = :publicationId
                   AND status = 'published'
                   AND moderation_state = 'visible'
                 FOR SHARE"
            );
            $publicationStmt->execute([':publicationId' => $publicationId]);
            if (!$publicationStmt->fetchColumn()) {
                $db->rollBack();
                return null;
            }

            $duplicate = $db->prepare(
                "SELECT 1
                 FROM publication_comments
                 WHERE publication_id = :publicationId
                   AND user_id = :userId
                   AND status = 'visible'
                   AND LOWER(TRIM(body)) = LOWER(:body)
                 LIMIT 1"
            );
            $duplicate->execute([
                ':publicationId' => $publicationId,
                ':userId' => $userId,
                ':body' => $body,
            ]);
            if ($duplicate->fetchColumn()) {
                throw new InvalidArgumentException('Taki komentarz juz istnieje pod ta publikacja.', 409);
            }

            $recent = $db->prepare(
                "SELECT COUNT(*)
                 FROM publication_comments
                 WHERE user_id = :userId
                   AND created_at > NOW() - INTERVAL '1 minute'"
            );
            $recent->execute([':userId' => $userId]);
            if ((int)$recent->fetchColumn() >= 5) {
                throw new InvalidArgumentException('Za duzo komentarzy w krotkim czasie. Sprobuj za chwile.', 429);
            }

            $insert = $db->prepare(
                "INSERT INTO publication_comments (publication_id, user_id, body)
                 VALUES (:publicationId, :userId, :body)
                 RETURNING *"
            );
            $insert->execute([
                ':publicationId' => $publicationId,
                ':userId' => $userId,
                ':body' => $body,
            ]);
            $comment = $insert->fetch(PDO::FETCH_ASSOC);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if (!$comment) {
            return null;
        }

        $userStmt = $this->database->connect()->prepare(
            "SELECT pc.*,
                    u.username,
                    u.firstname,
                    u.lastname
             FROM publication_comments pc
             JOIN users u ON u.id = pc.user_id
             WHERE pc.id = :id"
        );
        $userStmt->execute([':id' => (int)$comment['id']]);
        $row = $userStmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->decorateComment($row, $userId) : null;
    }

    public function reportPublication(int $publicationId, int $reporterUserId, string $reasonCategory, string $details, int $autoAdultThreshold): ?array
    {
        $allowedReasons = ['adult', 'violence', 'harassment', 'spam', 'copyright', 'other'];
        $reasonCategory = in_array($reasonCategory, $allowedReasons, true) ? $reasonCategory : 'other';
        $details = mb_substr(trim($details), 0, 1000);

        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $publicationStmt = $db->prepare(
                "SELECT id, age_rating
                 FROM publications
                 WHERE id = :publicationId
                   AND status = 'published'
                   AND moderation_state = 'visible'
                 FOR UPDATE"
            );
            $publicationStmt->execute([':publicationId' => $publicationId]);
            $publication = $publicationStmt->fetch(PDO::FETCH_ASSOC);
            if (!$publication) {
                $db->rollBack();
                return null;
            }

            $insert = $db->prepare(
                "INSERT INTO publication_reports (reporter_user_id, target_type, target_id, reason_category, details)
                 VALUES (:reporterUserId, 'publication', :publicationId, :reasonCategory, :details)
                 RETURNING *"
            );
            $insert->execute([
                ':reporterUserId' => $reporterUserId,
                ':publicationId' => $publicationId,
                ':reasonCategory' => $reasonCategory,
                ':details' => $details,
            ]);
            $report = $insert->fetch(PDO::FETCH_ASSOC);

            $countStmt = $db->prepare(
                "SELECT COUNT(*)
                 FROM publication_reports
                 WHERE target_type = 'publication'
                   AND target_id = :publicationId
                   AND status = 'open'"
            );
            $countStmt->execute([':publicationId' => $publicationId]);
            $openCount = (int)$countStmt->fetchColumn();

            $autoAdultApplied = false;
            if ($autoAdultThreshold > 0 && $openCount >= $autoAdultThreshold && (string)$publication['age_rating'] !== 'adult') {
                $update = $db->prepare(
                    "UPDATE publications
                     SET age_rating = 'adult',
                         age_rating_source = 'automatic',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :publicationId"
                );
                $update->execute([':publicationId' => $publicationId]);
                $autoAdultApplied = true;
            }

            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23505') {
                throw new InvalidArgumentException('Juz zglosiles te publikacje.', 409);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'report' => $report,
            'openReportCount' => $openCount,
            'autoAdultApplied' => $autoAdultApplied,
        ];
    }

    public function adminReportQueue(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->database->connect()->prepare(
            "SELECT p.id AS publication_id,
                    p.public_id,
                    p.age_rating,
                    p.age_rating_source,
                    p.moderation_state,
                    p.content_type,
                    owner.username AS owner_username,
                    owner.email AS owner_email,
                    pr.payload,
                    COUNT(r.id) AS report_count,
                    MAX(r.created_at) AS latest_report_at,
                    STRING_AGG(DISTINCT r.reason_category, ', ' ORDER BY r.reason_category) AS reasons
             FROM publication_reports r
             JOIN publications p ON p.id = r.target_id AND r.target_type = 'publication'
             JOIN users owner ON owner.id = p.owner_user_id
             LEFT JOIN publication_revisions pr ON pr.id = p.current_revision_id
             WHERE r.status = 'open'
             GROUP BY p.id, owner.id, pr.id
             ORDER BY COUNT(r.id) DESC, MAX(r.created_at) DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string)($row['payload'] ?? ''), true);
            $card = is_array($payload) ? $this->publicationCardFromPayload([
                'contentType' => (string)$row['content_type'],
            ], $payload) : [];
            $items[] = [
                'publicationId' => (int)$row['publication_id'],
                'publicId' => (string)$row['public_id'],
                'title' => (string)($card['title'] ?? 'Publikacja'),
                'description' => (string)($card['description'] ?? ''),
                'contentType' => (string)$row['content_type'],
                'owner' => (string)($row['owner_username'] ?: $row['owner_email'] ?: 'Uzytkownik'),
                'ageRating' => (string)$row['age_rating'],
                'ageRatingSource' => (string)$row['age_rating_source'],
                'moderationState' => (string)$row['moderation_state'],
                'reportCount' => (int)$row['report_count'],
                'latestReportAt' => $row['latest_report_at'] ?? null,
                'reasons' => (string)($row['reasons'] ?? ''),
            ];
        }

        return $items;
    }

    public function moderatePublication(int $publicationId, int $adminUserId, string $action): ?array
    {
        $allowed = ['mark_adult', 'mark_general', 'hide', 'show', 'resolve_reports'];
        if (!in_array($action, $allowed, true)) {
            throw new InvalidArgumentException('Nieprawidlowa akcja moderacji.', 422);
        }

        $db = $this->database->connect();
        try {
            $db->beginTransaction();

            $publicationStmt = $db->prepare('SELECT * FROM publications WHERE id = :id FOR UPDATE');
            $publicationStmt->execute([':id' => $publicationId]);
            $publication = $publicationStmt->fetch(PDO::FETCH_ASSOC);
            if (!$publication) {
                $db->rollBack();
                return null;
            }

            if ($action === 'mark_adult') {
                $update = $db->prepare(
                    "UPDATE publications
                     SET age_rating = 'adult',
                         age_rating_source = 'admin',
                         moderated_by = :adminUserId,
                         moderated_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $update->execute([':id' => $publicationId, ':adminUserId' => $adminUserId]);
            } elseif ($action === 'mark_general') {
                $update = $db->prepare(
                    "UPDATE publications
                     SET age_rating = 'general',
                         age_rating_source = 'admin',
                         moderated_by = :adminUserId,
                         moderated_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $update->execute([':id' => $publicationId, ':adminUserId' => $adminUserId]);
            } elseif ($action === 'hide') {
                $update = $db->prepare(
                    "UPDATE publications
                     SET moderation_state = 'hidden',
                         moderated_by = :adminUserId,
                         moderated_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $update->execute([':id' => $publicationId, ':adminUserId' => $adminUserId]);
            } elseif ($action === 'show') {
                $update = $db->prepare(
                    "UPDATE publications
                     SET moderation_state = 'visible',
                         moderated_by = :adminUserId,
                         moderated_at = CURRENT_TIMESTAMP,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $update->execute([':id' => $publicationId, ':adminUserId' => $adminUserId]);
            }

            if (in_array($action, ['mark_adult', 'mark_general', 'hide', 'show', 'resolve_reports'], true)) {
                $reports = $db->prepare(
                    "UPDATE publication_reports
                     SET status = 'resolved',
                         resolved_by = :adminUserId,
                         resolved_at = CURRENT_TIMESTAMP,
                         resolution_note = :note,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE target_type = 'publication'
                       AND target_id = :id
                       AND status = 'open'"
                );
                $reports->execute([
                    ':id' => $publicationId,
                    ':adminUserId' => $adminUserId,
                    ':note' => $action,
                ]);
            }

            $fresh = $db->prepare('SELECT * FROM publications WHERE id = :id');
            $fresh->execute([':id' => $publicationId]);
            $publication = $fresh->fetch(PDO::FETCH_ASSOC);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $publication ? $this->decorate($publication) : null;
    }

    public function visiblePublicationsByOwner(
        int $ownerUserId,
        int $limit = 48,
        string $query = '',
        string $contentType = 'all',
        bool $includeAdult = true,
        ?int $viewerUserId = null
    ): array
    {
        $query = trim($query);
        $contentType = in_array($contentType, ['all', 'character', 'story', 'image', 'relation_board', 'template'], true)
            ? $contentType
            : 'all';
        $limit = max(1, min(96, $limit));
        $adultClause = $includeAdult ? '' : " AND p.age_rating <> 'adult'";
        $typeClause = $contentType === 'all' ? '' : ' AND p.content_type = :contentType';
        $queryClause = mb_strlen($query) >= 2
            ? " AND (
                    LOWER(pr.search_text) LIKE :query
                    OR LOWER(COALESCE(u.username, '')) LIKE :query
                )"
            : '';
        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.payload,
                    pr.created_at AS revision_created_at,
                    CASE WHEN p.origin_owner_user_id = :viewerUserId THEN TRUE ELSE FALSE END AS copy_origin_is_own
             FROM publications p
             JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN users u ON u.id = p.owner_user_id
             WHERE p.owner_user_id = :ownerUserId
               AND p.status = 'published'
               AND p.moderation_state = 'visible'
               {$adultClause}
               {$typeClause}
               {$queryClause}
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT {$limit}"
        );
        $params = [
            ':ownerUserId' => $ownerUserId,
            ':viewerUserId' => $viewerUserId,
        ];
        if ($contentType !== 'all') {
            $params[':contentType'] = $contentType;
        }
        if (mb_strlen($query) >= 2) {
            $params[':query'] = '%' . mb_strtolower($query) . '%';
        }
        $stmt->execute($params);

        $publications = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $publication = $this->decorate($row);
            $payload = json_decode((string)$row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }

            $publication['payload'] = $payload;
            $publication['card'] = $this->publicationCardFromPayload($publication, $payload);
            $publication['revisionCreatedAt'] = $row['revision_created_at'] ?? null;
            $publications[] = $publication;
        }

        return $publications;
    }

    public function countVisiblePublicationsByOwner(int $ownerUserId): int
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT COUNT(*)
             FROM publications
             WHERE owner_user_id = :ownerUserId
               AND status = 'published'
               AND moderation_state = 'visible'"
        );
        $stmt->execute([':ownerUserId' => $ownerUserId]);

        return (int)$stmt->fetchColumn();
    }

    public function searchVisiblePublications(string $query, int $viewerUserId, bool $includeAdult = false, int $limit = 12): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $adultClause = $includeAdult ? '' : " AND p.age_rating <> 'adult'";
        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.payload,
                    pr.created_at AS revision_created_at,
                    u.username,
                    u.firstname,
                    u.lastname,
                    CASE WHEN p.origin_owner_user_id = :viewerUserId THEN TRUE ELSE FALSE END AS copy_origin_is_own,
                    CASE WHEN p.owner_user_id = :viewerUserId THEN 0 ELSE 1 END AS owner_priority
             FROM publications p
             JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN users u ON u.id = p.owner_user_id
             WHERE p.status = 'published'
               AND p.moderation_state = 'visible'
               {$adultClause}
               AND (
                    LOWER(pr.search_text) LIKE :query
                    OR LOWER(COALESCE(u.username, '')) LIKE :query
               )
             ORDER BY owner_priority ASC, p.published_at DESC, p.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            ':viewerUserId' => $viewerUserId,
            ':query' => '%' . mb_strtolower($query) . '%',
        ]);

        $publications = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $publication = $this->decorate($row);
            $payload = json_decode((string)$row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }

            $username = (string)($row['username'] ?? '');
            $publication['payload'] = $payload;
            $publication['card'] = $this->publicationCardFromPayload($publication, $payload);
            $publication['author'] = [
                'username' => $username,
                'displayName' => trim($username) !== ''
                    ? $username
                    : trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')),
                'profileUrl' => trim($username) !== '' ? '/u/' . rawurlencode($username) : '',
            ];
            $publication['isOwn'] = (int)$row['owner_user_id'] === $viewerUserId;
            $publication['revisionCreatedAt'] = $row['revision_created_at'] ?? null;
            $publications[] = $publication;
        }

        return $publications;
    }

    public function exploreVisiblePublications(
        string $query,
        int $viewerUserId,
        string $contentType = 'all',
        bool $includeAdult = false,
        int $limit = 36,
        string $sort = 'desc'
    ): array {
        $query = trim($query);
        $contentType = in_array($contentType, ['all', 'character', 'story', 'image', 'relation_board', 'template'], true)
            ? $contentType
            : 'all';
        $sort = in_array($sort, ['asc', 'desc', 'random'], true) ? $sort : 'desc';
        $limit = max(1, min(72, $limit));

        $adultClause = $includeAdult ? '' : " AND p.age_rating <> 'adult'";
        $typeClause = $contentType === 'all' ? '' : ' AND p.content_type = :contentType';
        $queryClause = mb_strlen($query) >= 2
            ? " AND (
                    LOWER(pr.search_text) LIKE :query
                    OR LOWER(COALESCE(u.username, '')) LIKE :query
                )"
            : '';

        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.payload,
                    pr.created_at AS revision_created_at,
                    u.username,
                    u.firstname,
                    u.lastname,
                    CASE WHEN p.origin_owner_user_id = :viewerUserId THEN TRUE ELSE FALSE END AS copy_origin_is_own,
                    CASE WHEN p.owner_user_id = :viewerUserId THEN 0 ELSE 1 END AS owner_priority,
                    COALESCE(rc.reaction_count, 0) AS reaction_count
             FROM publications p
             JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN users u ON u.id = p.owner_user_id
             LEFT JOIN (
                SELECT publication_id, COUNT(*) AS reaction_count
                FROM publication_reactions
                GROUP BY publication_id
             ) rc ON rc.publication_id = p.id
             WHERE p.status = 'published'
               AND p.moderation_state = 'visible'
               {$adultClause}
               {$typeClause}
               {$queryClause}
             ORDER BY " . $this->publicationExploreOrder($sort) . "
             LIMIT {$limit}"
        );

        $params = [':viewerUserId' => $viewerUserId];
        if ($contentType !== 'all') {
            $params[':contentType'] = $contentType;
        }
        if (mb_strlen($query) >= 2) {
            $params[':query'] = '%' . mb_strtolower($query) . '%';
        }
        $stmt->execute($params);

        $publications = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $publication = $this->decorate($row);
            $payload = json_decode((string)$row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }

            $username = (string)($row['username'] ?? '');
            $publication['payload'] = $payload;
            $publication['card'] = $this->publicationCardFromPayload($publication, $payload);
            $publication['author'] = [
                'username' => $username,
                'displayName' => trim($username) !== ''
                    ? $username
                    : trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')),
                'profileUrl' => trim($username) !== '' ? '/u/' . rawurlencode($username) : '',
            ];
            $publication['isOwn'] = (int)$row['owner_user_id'] === $viewerUserId;
            $publication['revisionCreatedAt'] = $row['revision_created_at'] ?? null;
            $publications[] = $publication;
        }

        return $publications;
    }

    public function exploreFollowedPublications(
        int $viewerUserId,
        string $query = '',
        string $contentType = 'all',
        bool $includeAdult = false,
        int $limit = 36,
        string $sort = 'desc'
    ): array {
        $query = trim($query);
        $contentType = in_array($contentType, ['all', 'character', 'story', 'image', 'relation_board', 'template'], true)
            ? $contentType
            : 'all';
        $sort = in_array($sort, ['asc', 'desc', 'random'], true) ? $sort : 'desc';
        $limit = max(1, min(72, $limit));

        $adultClause = $includeAdult ? '' : " AND p.age_rating <> 'adult'";
        $typeClause = $contentType === 'all' ? '' : ' AND p.content_type = :contentType';
        $queryClause = mb_strlen($query) >= 2
            ? " AND (
                    LOWER(pr.search_text) LIKE :query
                    OR LOWER(COALESCE(u.username, '')) LIKE :query
                )"
            : '';

        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.payload,
                    pr.created_at AS revision_created_at,
                    u.username,
                    u.firstname,
                    u.lastname,
                    CASE WHEN p.origin_owner_user_id = :viewerUserId THEN TRUE ELSE FALSE END AS copy_origin_is_own,
                    COALESCE(rc.reaction_count, 0) AS reaction_count
             FROM user_follows uf
             JOIN publications p ON p.owner_user_id = uf.followed_user_id
             JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN users u ON u.id = p.owner_user_id
             LEFT JOIN (
                SELECT publication_id, COUNT(*) AS reaction_count
                FROM publication_reactions
                GROUP BY publication_id
             ) rc ON rc.publication_id = p.id
             WHERE uf.follower_user_id = :viewerUserId
               AND p.status = 'published'
               AND p.moderation_state = 'visible'
               {$adultClause}
               {$typeClause}
               {$queryClause}
             ORDER BY " . $this->publicationExploreOrder($sort, false) . "
             LIMIT {$limit}"
        );

        $params = [':viewerUserId' => $viewerUserId];
        if ($contentType !== 'all') {
            $params[':contentType'] = $contentType;
        }
        if (mb_strlen($query) >= 2) {
            $params[':query'] = '%' . mb_strtolower($query) . '%';
        }
        $stmt->execute($params);

        $publications = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $publication = $this->decorate($row);
            $payload = json_decode((string)$row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }

            $username = (string)($row['username'] ?? '');
            $publication['payload'] = $payload;
            $publication['card'] = $this->publicationCardFromPayload($publication, $payload);
            $publication['author'] = [
                'username' => $username,
                'displayName' => trim($username) !== ''
                    ? $username
                    : trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? '')),
                'profileUrl' => trim($username) !== '' ? '/u/' . rawurlencode($username) : '',
            ];
            $publication['isOwn'] = (int)$row['owner_user_id'] === $viewerUserId;
            $publication['revisionCreatedAt'] = $row['revision_created_at'] ?? null;
            $publications[] = $publication;
        }

        return $publications;
    }

    public function findOwnedCharacterPublication(int $ownerUserId, int $characterId, ?int $variantId): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.created_at AS revision_created_at,
                    " . $this->characterSourceUpdatedAtExpression() . " AS source_updated_at
             FROM publications p
             LEFT JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN characters c ON c.id = p.character_id
             LEFT JOIN character_variants cv ON cv.id = p.selected_variant_id
             WHERE p.owner_user_id = :ownerUserId
               AND p.content_type = 'character'
               AND p.character_id = :characterId
               AND COALESCE(p.selected_variant_id, 0) = :variantId
             LIMIT 1"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':characterId' => $characterId,
            ':variantId' => $variantId ?? 0,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->decorate($row) : null;
    }

    public function ownedCharacterPublicationMap(int $ownerUserId, array $characterIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $characterIds))));
        if (empty($ids)) {
            return [];
        }

        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.created_at AS revision_created_at,
                    " . $this->characterSourceUpdatedAtExpression() . " AS source_updated_at
             FROM publications p
             LEFT JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN characters c ON c.id = p.character_id
             LEFT JOIN character_variants cv ON cv.id = p.selected_variant_id
             WHERE p.owner_user_id = :ownerUserId
               AND p.content_type = 'character'
               AND p.character_id IN (" . implode(',', $ids) . ")
             ORDER BY p.updated_at DESC, p.id DESC"
        );
        $stmt->execute([':ownerUserId' => $ownerUserId]);

        $mapped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $characterId = (int)$row['character_id'];
            $variantKey = (int)($row['selected_variant_id'] ?? 0);
            $mapped[$characterId][$variantKey] = $this->decorate($row);
        }

        return $mapped;
    }

    public function findOwnedTemplatePublication(int $ownerUserId, int $templateId): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.created_at AS revision_created_at,
                    " . $this->templateSourceUpdatedAtExpression() . " AS source_updated_at
             FROM publications p
             LEFT JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN templates t ON t.id = p.template_id
             WHERE p.owner_user_id = :ownerUserId
               AND p.content_type = 'template'
               AND p.template_id = :templateId
             LIMIT 1"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':templateId' => $templateId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->decorate($row) : null;
    }

    public function findOwnedStoryPublication(int $ownerUserId, int $storyId): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.created_at AS revision_created_at,
                    s.updated_at AS source_updated_at
             FROM publications p
             LEFT JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN stories s ON s.id = p.story_id
             WHERE p.owner_user_id = :ownerUserId
               AND p.content_type = 'story'
               AND p.story_id = :storyId
             LIMIT 1"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':storyId' => $storyId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->decorate($row) : null;
    }

    public function findOwnedRelationBoardPublication(int $ownerUserId, int $boardId): ?array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.created_at AS revision_created_at,
                    rb.updated_at AS source_updated_at
             FROM publications p
             LEFT JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN relation_boards rb ON rb.id = p.relation_board_id
             WHERE p.owner_user_id = :ownerUserId
               AND p.content_type = 'relation_board'
               AND p.relation_board_id = :boardId
             LIMIT 1"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':boardId' => $boardId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->decorate($row) : null;
    }

    public function ownedTemplatePublicationMap(int $ownerUserId, array $templateIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $templateIds))));
        if (empty($ids)) {
            return [];
        }

        $stmt = $this->database->connect()->prepare(
            "SELECT p.*,
                    pr.revision_number,
                    pr.created_at AS revision_created_at,
                    " . $this->templateSourceUpdatedAtExpression() . " AS source_updated_at
             FROM publications p
             LEFT JOIN publication_revisions pr ON pr.id = p.current_revision_id
             JOIN templates t ON t.id = p.template_id
             WHERE p.owner_user_id = :ownerUserId
               AND p.content_type = 'template'
               AND p.template_id IN (" . implode(',', $ids) . ")
             ORDER BY p.updated_at DESC, p.id DESC"
        );
        $stmt->execute([':ownerUserId' => $ownerUserId]);

        $mapped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mapped[(int)$row['template_id']] = $this->decorate($row);
        }

        return $mapped;
    }

    private function characterSourceUpdatedAtExpression(): string
    {
        return "GREATEST(
                    COALESCE(c.updated_at, c.created_at, TIMESTAMP WITH TIME ZONE 'epoch'),
                    COALESCE((
                        SELECT MAX(cfv.updated_at)
                        FROM character_field_values cfv
                        WHERE cfv.id_character = c.id
                    ), TIMESTAMP WITH TIME ZONE 'epoch'),
                    CASE
                        WHEN p.selected_variant_id IS NULL THEN TIMESTAMP WITH TIME ZONE 'epoch'
                        ELSE COALESCE(cv.updated_at, cv.created_at, TIMESTAMP WITH TIME ZONE 'epoch')
                    END,
                    CASE
                        WHEN p.selected_variant_id IS NULL THEN TIMESTAMP WITH TIME ZONE 'epoch'
                        ELSE COALESCE((
                            SELECT MAX(cvfv.updated_at)
                            FROM character_variant_field_values cvfv
                            WHERE cvfv.id_variant = p.selected_variant_id
                        ), TIMESTAMP WITH TIME ZONE 'epoch')
                    END
                )";
    }

    private function templateSourceUpdatedAtExpression(): string
    {
        return "GREATEST(
                    COALESCE(t.updated_at, t.created_at, TIMESTAMP WITH TIME ZONE 'epoch'),
                    COALESCE((
                        SELECT MAX(tf.created_at)
                        FROM template_fields tf
                        WHERE tf.id_template = t.id
                    ), TIMESTAMP WITH TIME ZONE 'epoch')
                )";
    }

    public function imageAssetIdByFilename(int $ownerUserId, string $filename): ?int
    {
        $filename = basename(trim($filename));
        if ($filename === '' || in_array($filename, ['default.png', 'default.jpg', 'default_dark.png', 'default_story.png', 'default_story.jpg', 'default_story_dark.png'], true)) {
            return null;
        }

        $stmt = $this->database->connect()->prepare(
            'SELECT id FROM image_assets WHERE id_user = :ownerUserId AND filename = :filename LIMIT 1'
        );
        $stmt->execute([':ownerUserId' => $ownerUserId, ':filename' => $filename]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    public function isFilenameInVisiblePublication(string $filename): bool
    {
        $filename = basename(trim($filename));
        if ($filename === '') {
            return false;
        }

        $stmt = $this->database->connect()->prepare(
            "SELECT EXISTS (
                SELECT 1
                FROM publication_media pm
                JOIN image_assets ia ON ia.id = pm.image_asset_id
                JOIN publications p ON p.current_revision_id = pm.revision_id
                WHERE ia.filename = :filename
                  AND p.status = 'published'
                  AND p.moderation_state = 'visible'
            )"
        );
        $stmt->execute([':filename' => $filename]);

        return (bool)$stmt->fetchColumn();
    }

    private function lockCharacterPublication(PDO $db, int $ownerUserId, int $characterId, int $variantKey): ?array
    {
        $stmt = $db->prepare(
            "SELECT *
             FROM publications
             WHERE owner_user_id = :ownerUserId
               AND content_type = 'character'
               AND character_id = :characterId
               AND COALESCE(selected_variant_id, 0) = :variantKey
             FOR UPDATE"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':characterId' => $characterId,
            ':variantKey' => $variantKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function publicationExploreOrder(string $sort, bool $prioritizeViewer = true): string
    {
        $prefix = $prioritizeViewer ? 'owner_priority ASC, ' : '';

        return match ($sort) {
            'asc' => $prefix . 'reaction_count ASC, p.published_at DESC, p.id DESC',
            'random' => 'RANDOM()',
            default => $prefix . 'reaction_count DESC, p.published_at DESC, p.id DESC',
        };
    }

    private function lockTemplatePublication(PDO $db, int $ownerUserId, int $templateId): ?array
    {
        $stmt = $db->prepare(
            "SELECT *
             FROM publications
             WHERE owner_user_id = :ownerUserId
               AND content_type = 'template'
               AND template_id = :templateId
             FOR UPDATE"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':templateId' => $templateId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function lockImagePublication(PDO $db, int $ownerUserId, int $imageAssetId): ?array
    {
        $stmt = $db->prepare(
            "SELECT *
             FROM publications
             WHERE owner_user_id = :ownerUserId
               AND content_type = 'image'
               AND image_asset_id = :imageAssetId
             FOR UPDATE"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':imageAssetId' => $imageAssetId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function lockStoryPublication(PDO $db, int $ownerUserId, int $storyId): ?array
    {
        $stmt = $db->prepare(
            "SELECT *
             FROM publications
             WHERE owner_user_id = :ownerUserId
               AND content_type = 'story'
               AND story_id = :storyId
             FOR UPDATE"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':storyId' => $storyId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function lockRelationBoardPublication(PDO $db, int $ownerUserId, int $boardId): ?array
    {
        $stmt = $db->prepare(
            "SELECT *
             FROM publications
             WHERE owner_user_id = :ownerUserId
               AND content_type = 'relation_board'
               AND relation_board_id = :boardId
             FOR UPDATE"
        );
        $stmt->execute([
            ':ownerUserId' => $ownerUserId,
            ':boardId' => $boardId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function nextRevisionNumber(PDO $db, int $publicationId): int
    {
        $stmt = $db->prepare('SELECT COALESCE(MAX(revision_number), 0) + 1 FROM publication_revisions WHERE publication_id = :id');
        $stmt->execute([':id' => $publicationId]);
        return (int)$stmt->fetchColumn();
    }

    private function insertRevision(PDO $db, int $publicationId, int $revisionNumber, array $payload, string $searchText, int $createdBy, string $changeReason): array
    {
        $stmt = $db->prepare(
            'INSERT INTO publication_revisions (publication_id, revision_number, payload, search_text, created_by, change_reason)
             VALUES (:publicationId, :revisionNumber, CAST(:payload AS jsonb), :searchText, :createdBy, :changeReason)
             RETURNING *'
        );
        $stmt->execute([
            ':publicationId' => $publicationId,
            ':revisionNumber' => $revisionNumber,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':searchText' => $searchText,
            ':createdBy' => $createdBy,
            ':changeReason' => $changeReason,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function replaceRevisionMedia(PDO $db, int $revisionId, array $mediaAssetIds): void
    {
        $insert = $db->prepare(
            'INSERT INTO publication_media (revision_id, image_asset_id, role, order_number)
             VALUES (:revisionId, :imageAssetId, :role, :orderNumber)
             ON CONFLICT DO NOTHING'
        );

        foreach (array_values(array_unique(array_filter(array_map('intval', $mediaAssetIds)))) as $index => $assetId) {
            $insert->execute([
                ':revisionId' => $revisionId,
                ':imageAssetId' => $assetId,
                ':role' => $index === 0 ? 'cover' : 'body',
                ':orderNumber' => $index,
            ]);
        }
    }

    private function replaceRevisionFilters(PDO $db, int $revisionId, array $filters): void
    {
        $insert = $db->prepare(
            'INSERT INTO publication_filters (revision_id, id_filter, label_snapshot)
             VALUES (:revisionId, :filterId, :label)
             ON CONFLICT DO NOTHING'
        );

        $seen = [];
        foreach ($filters as $filter) {
            $id = (int)($filter['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $insert->execute([
                ':revisionId' => $revisionId,
                ':filterId' => $id,
                ':label' => mb_substr((string)($filter['label'] ?? $filter['name'] ?? ''), 0, 100),
            ]);
        }
    }

    private function revisionMediaAssetIds(int $revisionId): array
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT image_asset_id
             FROM publication_media
             WHERE revision_id = :revisionId
             ORDER BY order_number ASC, id ASC'
        );
        $stmt->execute([':revisionId' => $revisionId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function revisionFilters(int $revisionId): array
    {
        $stmt = $this->database->connect()->prepare(
            "SELECT f.id,
                    f.name,
                    f.slug,
                    COALESCE(NULLIF(pf.label_snapshot, ''), f.label, f.name) AS label
             FROM publication_filters pf
             JOIN filters f ON f.id = pf.id_filter
             WHERE pf.revision_id = :revisionId
             ORDER BY label ASC, f.name ASC"
        );
        $stmt->execute([':revisionId' => $revisionId]);

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => (string)($row['name'] ?? ''),
            'slug' => (string)($row['slug'] ?? ''),
            'label' => (string)($row['label'] ?? $row['name'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function publicationCardFromPayload(array $publication, array $payload): array
    {
        if (($publication['contentType'] ?? '') === 'character') {
            $character = is_array($payload['character'] ?? null) ? $payload['character'] : [];
            $imageDisplay = is_array($character['imageDisplay'] ?? null) ? $character['imageDisplay'] : [];

            return [
                'typeLabel' => 'Postac',
                'title' => (string)($character['name'] ?? 'Publikacja'),
                'description' => (string)($character['description'] ?? $character['intro'] ?? ''),
                'image' => basename((string)($character['image'] ?? 'default.png')) ?: 'default.png',
                'fit' => in_array(($imageDisplay['fit'] ?? 'cover'), ['cover', 'contain'], true) ? (string)$imageDisplay['fit'] : 'cover',
                'focusX' => max(0, min(100, (int)($imageDisplay['focusX'] ?? 50))),
                'focusY' => max(0, min(100, (int)($imageDisplay['focusY'] ?? 50))),
                'zoom' => max(1, min(6, (float)($imageDisplay['zoom'] ?? 1))),
                'filters' => is_array($character['filters'] ?? null) ? $character['filters'] : [],
            ];
        }

        if (($publication['contentType'] ?? '') === 'template') {
            $template = is_array($payload['template'] ?? null) ? $payload['template'] : [];

            return [
                'typeLabel' => 'Szablon',
                'title' => (string)($template['name'] ?? 'Szablon'),
                'description' => (string)($template['description'] ?? ''),
                'image' => 'default.png',
                'fit' => 'contain',
                'focusX' => 50,
                'focusY' => 50,
                'zoom' => 1,
                'filters' => is_array($template['filters'] ?? null) ? $template['filters'] : [],
            ];
        }

        if (($publication['contentType'] ?? '') === 'image') {
            $image = is_array($payload['image'] ?? null) ? $payload['image'] : [];
            $filename = basename((string)($image['filename'] ?? 'default.png')) ?: 'default.png';

            return [
                'typeLabel' => 'Zdjecie',
                'title' => (string)($image['title'] ?? $filename),
                'description' => (string)($image['description'] ?? ''),
                'image' => $filename,
                'fit' => 'cover',
                'focusX' => 50,
                'focusY' => 50,
                'zoom' => 1,
                'filters' => is_array($image['filters'] ?? null) ? $image['filters'] : [],
            ];
        }

        if (($publication['contentType'] ?? '') === 'story') {
            $story = is_array($payload['story'] ?? null) ? $payload['story'] : [];
            $imageDisplay = is_array($story['imageDisplay'] ?? null) ? $story['imageDisplay'] : [];

            return [
                'typeLabel' => 'Historia',
                'title' => (string)($story['title'] ?? 'Historia'),
                'description' => (string)($story['description'] ?? ''),
                'image' => basename((string)($story['image'] ?? 'default_story.png')) ?: 'default_story.png',
                'fit' => in_array(($imageDisplay['fit'] ?? 'cover'), ['cover', 'contain'], true) ? (string)$imageDisplay['fit'] : 'cover',
                'focusX' => max(0, min(100, (int)($imageDisplay['focusX'] ?? 50))),
                'focusY' => max(0, min(100, (int)($imageDisplay['focusY'] ?? 50))),
                'zoom' => max(1, min(6, (float)($imageDisplay['zoom'] ?? 1))),
                'filters' => is_array($story['filters'] ?? null) ? $story['filters'] : [],
            ];
        }

        if (($publication['contentType'] ?? '') === 'relation_board') {
            $board = is_array($payload['relationBoard'] ?? null) ? $payload['relationBoard'] : [];

            return [
                'typeLabel' => 'Relacje',
                'title' => (string)($board['name'] ?? 'Relacje'),
                'description' => (string)($board['description'] ?? ''),
                'image' => 'default.png',
                'fit' => 'contain',
                'focusX' => 50,
                'focusY' => 50,
                'zoom' => 1,
                'filters' => is_array($board['filters'] ?? null) ? $board['filters'] : [],
            ];
        }

        return [
            'typeLabel' => 'Publikacja',
            'title' => 'Publikacja',
            'description' => '',
            'image' => 'default.png',
            'fit' => 'cover',
            'focusX' => 50,
            'focusY' => 50,
            'zoom' => 1,
            'filters' => [],
        ];
    }

    private function decorateComment(array $row, ?int $viewerUserId): array
    {
        $username = (string)($row['username'] ?? '');
        $displayName = trim($username) !== ''
            ? $username
            : trim((string)($row['firstname'] ?? '') . ' ' . (string)($row['lastname'] ?? ''));

        return [
            'id' => (int)$row['id'],
            'publicationId' => (int)$row['publication_id'],
            'userId' => (int)$row['user_id'],
            'body' => (string)$row['body'],
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
            'canEdit' => $viewerUserId !== null && (int)$row['user_id'] === $viewerUserId,
            'author' => [
                'username' => $username,
                'displayName' => $displayName !== '' ? $displayName : 'Uzytkownik',
                'profileUrl' => trim($username) !== '' ? '/u/' . rawurlencode($username) : '',
            ],
        ];
    }

    private function decorate(array $publication, ?array $revision = null): array
    {
        $revisionCreatedAt = $revision['created_at'] ?? $publication['revision_created_at'] ?? null;
        $sourceUpdatedAt = $publication['source_updated_at'] ?? null;
        $copyAttributionVisible = filter_var(
            $publication['origin_attribution_visible'] ?? true,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        $copyAttributionVisible = $copyAttributionVisible ?? true;
        $hasVisibleCopyOrigin = !empty($publication['origin_publication_id']) && $copyAttributionVisible;

        return [
            'id' => (int)$publication['id'],
            'publicId' => (string)$publication['public_id'],
            'ownerUserId' => (int)$publication['owner_user_id'],
            'contentType' => (string)$publication['content_type'],
            'characterId' => isset($publication['character_id']) ? (int)$publication['character_id'] : null,
            'storyId' => isset($publication['story_id']) ? (int)$publication['story_id'] : null,
            'templateId' => isset($publication['template_id']) ? (int)$publication['template_id'] : null,
            'imageAssetId' => isset($publication['image_asset_id']) ? (int)$publication['image_asset_id'] : null,
            'relationBoardId' => isset($publication['relation_board_id']) ? (int)$publication['relation_board_id'] : null,
            'selectedVariantId' => isset($publication['selected_variant_id']) ? (int)$publication['selected_variant_id'] : null,
            'status' => (string)$publication['status'],
            'ageRating' => (string)$publication['age_rating'],
            'moderationState' => (string)$publication['moderation_state'],
            'allowCopying' => !array_key_exists('allow_copying', $publication) || !empty($publication['allow_copying']),
            'currentRevisionId' => isset($publication['current_revision_id']) ? (int)$publication['current_revision_id'] : null,
            'revisionNumber' => isset($revision['revision_number'])
                ? (int)$revision['revision_number']
                : (isset($publication['revision_number']) ? (int)$publication['revision_number'] : null),
            'revisionCreatedAt' => $revisionCreatedAt,
            'sourceUpdatedAt' => $sourceUpdatedAt,
            'isOutdated' => (string)$publication['status'] === 'published'
                && $this->timestampAfter($sourceUpdatedAt, $revisionCreatedAt),
            'isCopy' => $hasVisibleCopyOrigin,
            'copyOrigin' => $hasVisibleCopyOrigin ? [
                'publicationId' => (int)$publication['origin_publication_id'],
                'ownerUserId' => isset($publication['origin_owner_user_id']) ? (int)$publication['origin_owner_user_id'] : null,
                'publicId' => (string)($publication['origin_public_id'] ?? ''),
                'username' => (string)($publication['origin_author_name'] ?? ''),
                'title' => (string)($publication['origin_title'] ?? ''),
                'isOwn' => !empty($publication['copy_origin_is_own']),
            ] : null,
            'copyOriginIsOwn' => !empty($publication['copy_origin_is_own']),
            'updatedAt' => $publication['updated_at'] ?? null,
        ];
    }

    private function normalizeCopyOrigin(?array $origin): array
    {
        if (!$origin) {
            return [
                'publicationId' => null,
                'ownerUserId' => null,
                'publicId' => null,
                'authorName' => '',
                'title' => '',
                'attributionVisible' => true,
            ];
        }

        return [
            'publicationId' => !empty($origin['publicationId']) ? (int)$origin['publicationId'] : null,
            'ownerUserId' => !empty($origin['ownerUserId']) ? (int)$origin['ownerUserId'] : null,
            'publicId' => trim((string)($origin['publicId'] ?? '')) !== '' ? (string)$origin['publicId'] : null,
            'authorName' => mb_substr((string)($origin['authorName'] ?? $origin['username'] ?? ''), 0, 255),
            'title' => (string)($origin['title'] ?? ''),
            'attributionVisible' => !array_key_exists('attributionVisible', $origin) || !empty($origin['attributionVisible']),
        ];
    }

    private function timestampAfter(?string $left, ?string $right): bool
    {
        if (!$left || !$right) {
            return false;
        }

        try {
            return new DateTimeImmutable($left) > new DateTimeImmutable($right);
        } catch (Exception $e) {
            return false;
        }
    }
}
