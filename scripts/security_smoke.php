<?php

declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../src/services/PublicationService.php';

final class SecuritySmokeTest
{
    private PDO $db;
    private string $baseUrl;
    private string $runId;
    private array $createdUserIds = [];
    private array $createdFiles = [];
    private array $originalFeatureSettings = [];
    private int $assertions = 0;
    private PublicationService $publicationService;

    public function __construct()
    {
        $this->db = (new Database())->connect();
        $this->baseUrl = rtrim(getenv('APP_BASE_URL') ?: 'http://nginx', '/');
        $this->runId = 'smoke_' . bin2hex(random_bytes(4));
        $this->publicationService = new PublicationService();
    }

    public function run(): void
    {
        try {
            $this->snapshotFeatureSettings();
            $this->configureSmokeFeatureSettings();
            $fixture = $this->seedFixture();
            $this->assertCustom404Page();
            $owner = $this->login($fixture['ownerEmail'], $fixture['password']);
            $stranger = $this->login($fixture['strangerEmail'], $fixture['password']);
            $admin = $this->login($fixture['adminEmail'], $fixture['password']);

            $this->assertStatus('default media is public', 'GET', '/media/default.png', 200);
            $this->assertStatus('private media is not public', 'GET', '/media/' . rawurlencode($fixture['filename']), 404);
            $this->assertStatus('legacy upload URL is blocked', 'GET', '/public/uploads/' . rawurlencode($fixture['filename']), 404);
            $this->assertStatus('owner can read own media', 'GET', '/media/' . rawurlencode($fixture['filename']), 200, $owner);
            $this->assertStatus('stranger cannot read owner media', 'GET', '/media/' . rawurlencode($fixture['filename']), 404, $stranger);

            $this->assertStatus('owner can view own legacy published story source', 'GET', '/story/' . $fixture['storyPublicId'], 200, $owner);
            $this->assertStatus('stranger cannot view legacy published story source', 'GET', '/story/' . $fixture['storyPublicId'], 404, $stranger);
            $this->assertStatus('anonymous user cannot view legacy published story source', 'GET', '/story/' . $fixture['storyPublicId'], 404);

            $this->assertStatus('story source data endpoint blocks stranger', 'GET', '/getStoryData?id=' . rawurlencode($fixture['storyPublicId']), 403, $stranger);
            $this->assertStatus('story field mutation blocks GET', 'GET', '/saveStoryField', 405, $owner);
            $this->assertDeleteConfirmations($fixture, $owner);
            $this->assertCharacterVariantPublicationSnapshot($fixture, $owner, $stranger, $admin);
            $this->assertStorageQuotaControls($fixture, $owner, $admin);
            $this->assertOfflineModeHidesSocialSurface($fixture);

            echo "Security smoke tests passed ({$this->assertions} assertions).\n";
        } finally {
            $this->cleanup();
        }
    }

    private function seedFixture(): array
    {
        $password = 'SmokePass123!';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $ownerEmail = "{$this->runId}_owner@example.test";
        $strangerEmail = "{$this->runId}_stranger@example.test";
        $adminEmail = "{$this->runId}_admin@example.test";

        $ownerId = $this->insertUser($ownerEmail, "{$this->runId}_owner", $hash);
        $strangerId = $this->insertUser($strangerEmail, "{$this->runId}_stranger", $hash);
        $adminId = $this->insertUser($adminEmail, "{$this->runId}_admin", $hash);
        $this->db->prepare('UPDATE users SET account_type = 1 WHERE id = :id')->execute([':id' => $adminId]);

        $worldId = $this->insertReturningId(
            'INSERT INTO worlds (name, id_user) VALUES (:name, :userId) RETURNING id',
            [':name' => $this->runId . ' world', ':userId' => $ownerId]
        );

        $story = $this->insertReturningRow(
            "INSERT INTO stories (id_user, id_world, title, description, status)
             VALUES (:userId, :worldId, :title, :description, 'published')
             RETURNING id, public_id",
            [
                ':userId' => $ownerId,
                ':worldId' => $worldId,
                ':title' => $this->runId . ' private story',
                ':description' => 'Legacy published source must remain private.',
            ]
        );

        $characterId = $this->insertReturningId(
            'INSERT INTO characters (name, intro, description, image, id_user, id_world)
             VALUES (:name, :intro, :description, :image, :userId, :worldId)
             RETURNING id',
            [
                ':name' => $this->runId . ' base character',
                ':intro' => 'Base intro',
                ':description' => 'Base description',
                ':image' => 'default.png',
                ':userId' => $ownerId,
                ':worldId' => $worldId,
            ]
        );
        $publishedVariantId = $this->insertReturningId(
            'INSERT INTO character_variants (id_character, name, description, image, order_number)
             VALUES (:characterId, :name, :description, :image, :orderNumber)
             RETURNING id',
            [
                ':characterId' => $characterId,
                ':name' => $this->runId . ' published variant',
                ':description' => 'Visible variant description',
                ':image' => null,
                ':orderNumber' => 0,
            ]
        );
        $privateVariantName = $this->runId . ' private other variant';
        $this->insertReturningId(
            'INSERT INTO character_variants (id_character, name, description, image, order_number)
             VALUES (:characterId, :name, :description, :image, :orderNumber)
             RETURNING id',
            [
                ':characterId' => $characterId,
                ':name' => $privateVariantName,
                ':description' => 'This variant must not appear in the selected snapshot',
                ':image' => null,
                ':orderNumber' => 1,
            ]
        );
        $storyTextFieldId = $this->insertReturningId(
            "INSERT INTO story_fields (id_story, label, field_type, order_number, placeholder)
             VALUES (:storyId, :label, 'textarea', 0, '')
             RETURNING id",
            [
                ':storyId' => (int)$story['id'],
                ':label' => $this->runId . ' story scene',
            ]
        );
        $this->insertReturningId(
            "INSERT INTO story_field_values (id_story, id_story_field, value)
             VALUES (:storyId, :fieldId, :value)
             RETURNING id",
            [
                ':storyId' => (int)$story['id'],
                ':fieldId' => $storyTextFieldId,
                ':value' => 'Scene mentions ' . $this->runId . ' base character and should redact it publicly.',
            ]
        );
        $this->insertReturningId(
            "INSERT INTO story_characters (id_story, id_character, order_number)
             VALUES (:storyId, :characterId, 0)
             RETURNING id",
            [
                ':storyId' => (int)$story['id'],
                ':characterId' => $characterId,
            ]
        );
        $relationBoard = $this->insertReturningRow(
            "INSERT INTO relation_boards (id_user, name, description)
             VALUES (:userId, :name, :description)
             RETURNING id, public_id",
            [
                ':userId' => $ownerId,
                ':name' => $this->runId . ' public relation board',
                ':description' => 'Relation board smoke description.',
            ]
        );
        $this->insertReturningId(
            "INSERT INTO relation_board_characters (id_board, id_character)
             VALUES (:boardId, :characterId)
             RETURNING id",
            [
                ':boardId' => (int)$relationBoard['id'],
                ':characterId' => $characterId,
            ]
        );
        $this->insertReturningId(
            "INSERT INTO relation_tree_nodes (id_user, id_board, id_character, id_variant, position_x, position_y)
             VALUES (:userId, :boardId, :characterId, NULL, 0, 0)
             RETURNING id",
            [
                ':userId' => $ownerId,
                ':boardId' => (int)$relationBoard['id'],
                ':characterId' => $characterId,
            ]
        );
        $this->insertReturningId(
            "INSERT INTO relation_tree_nodes (id_user, id_board, id_character, id_variant, position_x, position_y)
             VALUES (:userId, :boardId, :characterId, :variantId, 220, 0)
             RETURNING id",
            [
                ':userId' => $ownerId,
                ':boardId' => (int)$relationBoard['id'],
                ':characterId' => $characterId,
                ':variantId' => $publishedVariantId,
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_relations (
                id_user, character_a_id, character_a_variant_id, character_b_id, character_b_variant_id,
                relation_type_id, note
             )
             VALUES (
                :userId, :characterId, NULL, :characterId, :variantId,
                (SELECT id FROM relation_types ORDER BY id ASC LIMIT 1), :note
             )
             RETURNING id",
            [
                ':userId' => $ownerId,
                ':characterId' => $characterId,
                ':variantId' => $publishedVariantId,
                ':note' => 'Smoke relation note.',
            ]
        );

        $filename = $this->runId . '.png';
        $path = $this->uploadPath($filename);
        $bytes = $this->samplePngBytes();
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('Could not create smoke-test image.');
        }
        $this->createdFiles[] = $path;

        $this->insertReturningId(
            'INSERT INTO image_assets (id_user, filename, original_name, mime_type, size_bytes, sha256, visibility)
             VALUES (:userId, :filename, :originalName, :mimeType, :sizeBytes, :sha256, :visibility)
             RETURNING id',
            [
                ':userId' => $ownerId,
                ':filename' => $filename,
                ':originalName' => $filename,
                ':mimeType' => 'image/png',
                ':sizeBytes' => strlen($bytes),
                ':sha256' => hash('sha256', $bytes),
                ':visibility' => 'normal',
            ]
        );
        $publishedImage = $this->runId . '_published.png';
        $publishedImagePath = $this->uploadPath($publishedImage);
        if (file_put_contents($publishedImagePath, $bytes) === false) {
            throw new RuntimeException('Could not create smoke-test publication image.');
        }
        $this->createdFiles[] = $publishedImagePath;
        $publishedImageAssetId = $this->insertReturningId(
            'INSERT INTO image_assets (id_user, filename, original_name, mime_type, size_bytes, sha256, visibility)
             VALUES (:userId, :filename, :originalName, :mimeType, :sizeBytes, :sha256, :visibility)
             RETURNING id',
            [
                ':userId' => $ownerId,
                ':filename' => $publishedImage,
                ':originalName' => $publishedImage,
                ':mimeType' => 'image/png',
                ':sizeBytes' => strlen($bytes),
                ':sha256' => hash('sha256', $bytes . 'published'),
                ':visibility' => 'normal',
            ]
        );
        $this->db->prepare('UPDATE character_variants SET image = :image WHERE id = :id')
            ->execute([':image' => $publishedImage, ':id' => $publishedVariantId]);

        $avatarImage = $this->runId . '_avatar.png';
        $avatarImagePath = $this->uploadPath($avatarImage);
        if (file_put_contents($avatarImagePath, $bytes) === false) {
            throw new RuntimeException('Could not create smoke-test avatar image.');
        }
        $this->createdFiles[] = $avatarImagePath;
        $avatarImageAssetId = $this->insertReturningId(
            'INSERT INTO image_assets (id_user, filename, original_name, mime_type, size_bytes, sha256, visibility)
             VALUES (:userId, :filename, :originalName, :mimeType, :sizeBytes, :sha256, :visibility)
             RETURNING id',
            [
                ':userId' => $ownerId,
                ':filename' => $avatarImage,
                ':originalName' => $avatarImage,
                ':mimeType' => 'image/png',
                ':sizeBytes' => strlen($bytes),
                ':sha256' => hash('sha256', $bytes . 'avatar'),
                ':visibility' => 'normal',
            ]
        );

        $strangerWorldId = $this->insertReturningId(
            'INSERT INTO worlds (name, id_user) VALUES (:name, :userId) RETURNING id',
            [':name' => $this->runId . ' stranger world', ':userId' => $strangerId]
        );
        $strangerCharacterId = $this->insertReturningId(
            'INSERT INTO characters (name, intro, description, image, id_user, id_world)
             VALUES (:name, :intro, :description, :image, :userId, :worldId)
             RETURNING id',
            [
                ':name' => $this->runId . ' stranger base character',
                ':intro' => 'Other public intro',
                ':description' => 'Other public description',
                ':image' => 'default.png',
                ':userId' => $strangerId,
                ':worldId' => $strangerWorldId,
            ]
        );
        $strangerVariantId = $this->insertReturningId(
            'INSERT INTO character_variants (id_character, name, description, image, order_number)
             VALUES (:characterId, :name, :description, :image, :orderNumber)
             RETURNING id',
            [
                ':characterId' => $strangerCharacterId,
                ':name' => $this->runId . ' stranger public variant',
                ':description' => 'Other visible variant description',
                ':image' => null,
                ':orderNumber' => 0,
            ]
        );

        $template = $this->insertReturningRow(
            "INSERT INTO templates (name, description, id_user, txt_export_enabled, txt_export_template)
             VALUES (:name, :description, :userId, TRUE, :txtExportTemplate)
             RETURNING id, public_id",
            [
                ':name' => $this->runId . ' public template',
                ':description' => 'Template publication smoke description.',
                ':userId' => $ownerId,
                ':txtExportTemplate' => "NAME={{variant.name}}\nFIELD={{field:" . $this->runId . " template field}}\n{{all_fields}}",
            ]
        );
        $templateFieldId = $this->insertReturningId(
            "INSERT INTO template_fields (id_template, label, field_type, location, order_number, placeholder)
             VALUES (:templateId, :label, 'text', 'left', 0, :placeholder)
             RETURNING id",
            [
                ':templateId' => (int)$template['id'],
                ':label' => $this->runId . ' template field',
                ':placeholder' => 'Smoke placeholder',
            ]
        );
        $templateRightFieldId = $this->insertReturningId(
            "INSERT INTO template_fields (id_template, label, field_type, location, order_number, placeholder)
             VALUES (:templateId, :label, 'date', 'right', 0, :placeholder)
             RETURNING id",
            [
                ':templateId' => (int)$template['id'],
                ':label' => $this->runId . ' right date field',
                ':placeholder' => '',
            ]
        );
        $templateRightTextFieldId = $this->insertReturningId(
            "INSERT INTO template_fields (id_template, label, field_type, location, order_number, placeholder)
             VALUES (:templateId, :label, 'text', 'right', 1, :placeholder)
             RETURNING id",
            [
                ':templateId' => (int)$template['id'],
                ':label' => $this->runId . ' right panel text',
                ':placeholder' => '',
            ]
        );
        $templateImageFieldId = $this->insertReturningId(
            "INSERT INTO template_fields (id_template, label, field_type, location, order_number, placeholder)
             VALUES (:templateId, :label, 'image', 'left', 1, :placeholder)
             RETURNING id",
            [
                ':templateId' => (int)$template['id'],
                ':label' => $this->runId . ' image field',
                ':placeholder' => json_encode(['type' => 'image', 'size' => 'full'], JSON_UNESCAPED_UNICODE),
            ]
        );
        $templateTableFieldId = $this->insertReturningId(
            "INSERT INTO template_fields (id_template, label, field_type, location, order_number, placeholder)
             VALUES (:templateId, :label, 'table', 'left', 2, :placeholder)
             RETURNING id",
            [
                ':templateId' => (int)$template['id'],
                ':label' => $this->runId . ' table field',
                ':placeholder' => json_encode([
                    'rows' => [
                        ['key' => 'portrait', 'label' => 'Portret w tabeli', 'type' => 'image'],
                        ['key' => 'note', 'label' => 'Notatka', 'type' => 'text'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]
        );
        $this->db->prepare('UPDATE characters SET id_template = :templateId WHERE id = :characterId')
            ->execute([
                ':templateId' => (int)$template['id'],
                ':characterId' => $characterId,
            ]);
        $this->insertReturningId(
            "INSERT INTO character_field_values (id_character, id_template_field, value)
             VALUES (:characterId, :fieldId, :value)
             RETURNING id",
            [
                ':characterId' => $characterId,
                ':fieldId' => $templateFieldId,
                ':value' => 'Base export field value.',
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_field_values (id_character, id_template_field, value)
             VALUES (:characterId, :fieldId, :value)
             RETURNING id",
            [
                ':characterId' => $characterId,
                ':fieldId' => $templateRightFieldId,
                ':value' => json_encode(['day' => '20', 'monthName' => 'Lipiec', 'year' => '2026'], JSON_UNESCAPED_UNICODE),
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_field_values (id_character, id_template_field, value)
             VALUES (:characterId, :fieldId, :value)
             RETURNING id",
            [
                ':characterId' => $characterId,
                ':fieldId' => $templateRightTextFieldId,
                ':value' => 'Right panel base value.',
            ]
        );
        $imageFieldValue = json_encode(['url' => '/media/' . $publishedImage, 'filename' => $publishedImage], JSON_UNESCAPED_UNICODE);
        $this->insertReturningId(
            "INSERT INTO character_field_values (id_character, id_template_field, value)
             VALUES (:characterId, :fieldId, :value)
             RETURNING id",
            [
                ':characterId' => $characterId,
                ':fieldId' => $templateImageFieldId,
                ':value' => $imageFieldValue,
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_field_values (id_character, id_template_field, value)
             VALUES (:characterId, :fieldId, :value)
             RETURNING id",
            [
                ':characterId' => $characterId,
                ':fieldId' => $templateTableFieldId,
                ':value' => json_encode([
                    'portrait' => ['type' => 'image', 'value' => ['url' => '/media/' . $publishedImage, 'filename' => $publishedImage]],
                    'note' => ['type' => 'text', 'value' => 'Tabela zawiera polskie znaki i obraz.'],
                ], JSON_UNESCAPED_UNICODE),
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_variant_field_values (id_variant, id_template_field, value)
             VALUES (:variantId, :fieldId, :value)
             RETURNING id",
            [
                ':variantId' => $publishedVariantId,
                ':fieldId' => $templateFieldId,
                ':value' => 'Variant export field value.',
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_variant_field_values (id_variant, id_template_field, value)
             VALUES (:variantId, :fieldId, :value)
             RETURNING id",
            [
                ':variantId' => $publishedVariantId,
                ':fieldId' => $templateRightFieldId,
                ':value' => json_encode(['day' => '21', 'monthName' => 'Lipiec', 'year' => '2026'], JSON_UNESCAPED_UNICODE),
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_variant_field_values (id_variant, id_template_field, value)
             VALUES (:variantId, :fieldId, :value)
             RETURNING id",
            [
                ':variantId' => $publishedVariantId,
                ':fieldId' => $templateRightTextFieldId,
                ':value' => 'Right panel variant value.',
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_variant_field_values (id_variant, id_template_field, value)
             VALUES (:variantId, :fieldId, :value)
             RETURNING id",
            [
                ':variantId' => $publishedVariantId,
                ':fieldId' => $templateImageFieldId,
                ':value' => $imageFieldValue,
            ]
        );
        $this->insertReturningId(
            "INSERT INTO character_variant_field_values (id_variant, id_template_field, value)
             VALUES (:variantId, :fieldId, :value)
             RETURNING id",
            [
                ':variantId' => $publishedVariantId,
                ':fieldId' => $templateTableFieldId,
                ':value' => json_encode([
                    'portrait' => ['type' => 'image', 'value' => ['url' => '/media/' . $publishedImage, 'filename' => $publishedImage]],
                    'note' => ['type' => 'text', 'value' => 'Wariant tabeli zawiera zdjęcie.'],
                ], JSON_UNESCAPED_UNICODE),
            ]
        );

        return [
            'ownerEmail' => $ownerEmail,
            'ownerUsername' => "{$this->runId}_owner",
            'strangerEmail' => $strangerEmail,
            'strangerId' => $strangerId,
            'strangerUsername' => "{$this->runId}_stranger",
            'adminEmail' => $adminEmail,
            'adminId' => $adminId,
            'password' => $password,
            'worldId' => $worldId,
            'storyPublicId' => (string)$story['public_id'],
            'storyId' => (int)$story['id'],
            'storyTitle' => $this->runId . ' private story',
            'storyPrivateCharacterName' => $this->runId . ' base character',
            'relationBoardId' => (int)$relationBoard['id'],
            'relationBoardTitle' => $this->runId . ' public relation board',
            'filename' => $filename,
            'ownerId' => $ownerId,
            'characterId' => $characterId,
            'publishedVariantId' => $publishedVariantId,
            'privateVariantName' => $privateVariantName,
            'publishedImage' => $publishedImage,
            'publishedImageAssetId' => $publishedImageAssetId,
            'avatarImage' => $avatarImage,
            'avatarImageAssetId' => $avatarImageAssetId,
            'strangerCharacterId' => $strangerCharacterId,
            'strangerVariantId' => $strangerVariantId,
            'strangerVariantName' => $this->runId . ' stranger public variant',
            'templateId' => (int)$template['id'],
            'templateName' => $this->runId . ' public template',
        ];
    }

    private function assertCustom404Page(): void
    {
        $response = $this->request('GET', '/missing-' . rawurlencode($this->runId));
        $this->assertTrue('missing route returns HTTP 404', $response['status'] === 404);
        $this->assertTrue('custom 404 page renders branded shell', str_contains((string)$response['raw'], 'error-page-shell'));
        $this->assertTrue('custom 404 page explains missing page', str_contains((string)$response['raw'], 'Nie znaleziono strony'));
    }

    private function assertDeleteConfirmations(array $fixture, string $ownerCookie): void
    {
        $templateName = $this->runId . ' delete template';
        $templateId = $this->insertReturningId(
            'INSERT INTO templates (name, description, id_user)
             VALUES (:name, :description, :userId)
             RETURNING id',
            [
                ':name' => $templateName,
                ':description' => 'Disposable delete-confirmation template.',
                ':userId' => (int)$fixture['ownerId'],
            ]
        );
        $templateWrong = $this->formPost('/deleteTemplate', [
            'id' => $templateId,
            'confirmation' => 'wrong',
        ], $ownerCookie);
        $this->assertTrue('template delete rejects wrong name', $templateWrong['status'] === 400);
        $templateOk = $this->formPost('/deleteTemplate', [
            'id' => $templateId,
            'confirmation' => $templateName,
        ], $ownerCookie);
        $this->assertTrue('template delete accepts exact name', in_array($templateOk['status'], [302, 303], true));
        $this->assertRowCount('template is deleted after exact name', 'templates', $templateId, 0);

        $storyTitle = $this->runId . ' delete story';
        $storyId = $this->insertReturningId(
            "INSERT INTO stories (id_user, id_world, title, description, status)
             VALUES (:userId, :worldId, :title, :description, 'draft')
             RETURNING id",
            [
                ':userId' => (int)$fixture['ownerId'],
                ':worldId' => (int)$fixture['worldId'],
                ':title' => $storyTitle,
                ':description' => 'Disposable delete-confirmation story.',
            ]
        );
        $storyWrong = $this->jsonPost('/deleteStory', [
            'storyId' => $storyId,
            'confirmation' => 'wrong',
        ], $ownerCookie);
        $this->assertTrue('story delete rejects wrong title', $storyWrong['status'] === 400);
        $storyOk = $this->jsonPost('/deleteStory', [
            'storyId' => $storyId,
            'confirmation' => $storyTitle,
        ], $ownerCookie);
        $this->assertTrue('story delete accepts exact title', $storyOk['status'] === 200 && !empty($storyOk['json']['success']));
        $this->assertRowCount('story is deleted after exact title', 'stories', $storyId, 0);

        $boardName = $this->runId . ' delete relation board';
        $boardId = $this->insertReturningId(
            'INSERT INTO relation_boards (id_user, name, description)
             VALUES (:userId, :name, :description)
             RETURNING id',
            [
                ':userId' => (int)$fixture['ownerId'],
                ':name' => $boardName,
                ':description' => 'Disposable delete-confirmation relation board.',
            ]
        );
        $boardWrong = $this->jsonPost('/api/relation-boards/delete', [
            'boardId' => $boardId,
            'confirmation' => 'wrong',
        ], $ownerCookie);
        $this->assertTrue('relation delete rejects wrong name', $boardWrong['status'] === 400);
        $boardOk = $this->jsonPost('/api/relation-boards/delete', [
            'boardId' => $boardId,
            'confirmation' => $boardName,
        ], $ownerCookie);
        $this->assertTrue('relation delete accepts exact name', $boardOk['status'] === 200 && !empty($boardOk['json']['success']));
        $this->assertRowCount('relation board is deleted after exact name', 'relation_boards', $boardId, 0);

        $deleteImage = $this->runId . '_delete.png';
        $deleteImagePath = $this->uploadPath($deleteImage);
        $bytes = $this->samplePngBytes();
        if (file_put_contents($deleteImagePath, $bytes) === false) {
            throw new RuntimeException('Could not create smoke-test delete image.');
        }
        $this->createdFiles[] = $deleteImagePath;
        $imageId = $this->insertReturningId(
            'INSERT INTO image_assets (id_user, filename, original_name, mime_type, size_bytes, sha256, visibility)
             VALUES (:userId, :filename, :originalName, :mimeType, :sizeBytes, :sha256, :visibility)
             RETURNING id',
            [
                ':userId' => (int)$fixture['ownerId'],
                ':filename' => $deleteImage,
                ':originalName' => $deleteImage,
                ':mimeType' => 'image/png',
                ':sizeBytes' => strlen($bytes),
                ':sha256' => hash('sha256', $bytes . 'delete-confirmation'),
                ':visibility' => 'normal',
            ]
        );
        $imageWrong = $this->jsonPost('/api/images/delete', [
            'imageId' => $imageId,
            'forceMissing' => true,
            'confirmation' => 'wrong',
        ], $ownerCookie);
        $this->assertTrue('image delete rejects wrong code', $imageWrong['status'] === 400);
        $imageOk = $this->jsonPost('/api/images/delete', [
            'imageId' => $imageId,
            'forceMissing' => true,
            'confirmation' => '123456',
        ], $ownerCookie);
        $this->assertTrue('image delete accepts required code', $imageOk['status'] === 200 && !empty($imageOk['json']['success']));
        $this->assertRowCount('image asset is deleted after code', 'image_assets', $imageId, 0);
    }

    private function assertCharacterVariantPublicationSnapshot(array $fixture, string $ownerCookie, string $strangerCookie, string $adminCookie): void
    {
        $first = $this->publicationService->publishCharacter(
            (int)$fixture['ownerId'],
            (int)$fixture['characterId'],
            (int)$fixture['publishedVariantId']
        );
        $this->assertTrue('character variant publication creates first revision', ($first['revisionNumber'] ?? null) === 1);

        $second = $this->publicationService->publishCharacter(
            (int)$fixture['ownerId'],
            (int)$fixture['characterId'],
            (int)$fixture['publishedVariantId']
        );
        $this->assertTrue('refreshing same publication creates second revision', ($second['revisionNumber'] ?? null) === 2);

        $payload = $this->currentPublicationPayload((int)$second['id']);
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertTrue('selected variant snapshot does not include other variants', !str_contains($encodedPayload, (string)$fixture['privateVariantName']));
        $this->assertTrue('selected variant snapshot keeps selected variant name', str_contains($encodedPayload, (string)$this->runId . ' published variant'));
        $this->assertStatus('public publication page is visible anonymously', 'GET', '/p/' . rawurlencode((string)$second['publicId']), 200);
        $this->assertResponseContains(
            'logged public publication keeps app return link',
            'GET',
            '/p/' . rawurlencode((string)$second['publicId']),
            'href="/community"',
            $ownerCookie
        );
        $this->assertResponseContains(
            'public profile lists visible publication anonymously',
            'GET',
            '/u/' . rawurlencode((string)$fixture['ownerUsername']),
            (string)$this->runId . ' published variant'
        );
        $this->assertResponseNotContains(
            'public profile omits redundant public label',
            'GET',
            '/u/' . rawurlencode((string)$fixture['ownerUsername']),
            'Profil publiczny'
        );
        $this->assertResponseContains(
            'logged own public preview returns to profile',
            'GET',
            '/u/' . rawurlencode((string)$fixture['ownerUsername']),
            'href="/profile"',
            $ownerCookie
        );
        $this->assertResponseContains(
            'dashboard links to own profile',
            'GET',
            '/dashboard',
            'href="/profile"',
            $ownerCookie
        );
        $this->assertResponseContains(
            'own profile lists only shared publication',
            'GET',
            '/profile',
            (string)$this->runId . ' published variant',
            $ownerCookie
        );
        $this->assertResponseContains(
            'own profile marks own publication card',
            'GET',
            '/profile',
            'public-profile-card is-own',
            $ownerCookie
        );
        $this->assertResponseContains(
            'own profile local search finds shared publication',
            'GET',
            '/profile?q=' . rawurlencode((string)$this->runId . ' published') . '&type=character',
            (string)$this->runId . ' published variant',
            $ownerCookie
        );
        $bioUpdate = $this->formPost('/profile/bio', [
            'bio' => $this->runId . ' updated profile bio',
        ], $ownerCookie);
        $this->assertTrue('owner can update profile bio', in_array($bioUpdate['status'], [302, 303], true));
        $this->assertResponseContains(
            'own profile shows updated bio',
            'GET',
            '/profile',
            $this->runId . ' updated profile bio',
            $ownerCookie
        );
        $avatarUpdate = $this->formPost('/profile/avatar', [
            'avatar_image_id' => (int)$fixture['avatarImageAssetId'],
        ], $ownerCookie);
        $this->assertTrue('owner can update profile avatar', in_array($avatarUpdate['status'], [302, 303], true));
        $this->assertResponseContains(
            'own profile shows avatar image',
            'GET',
            '/profile',
            '/media/' . rawurlencode((string)$fixture['avatarImage']),
            $ownerCookie
        );
        $this->assertResponseContains(
            'public profile shows avatar image',
            'GET',
            '/u/' . rawurlencode((string)$fixture['ownerUsername']),
            '/media/' . rawurlencode((string)$fixture['avatarImage'])
        );
        $avatarRemove = $this->formPost('/profile/avatar', [
            'remove_avatar' => '1',
        ], $ownerCookie);
        $this->assertTrue('owner can remove profile avatar', in_array($avatarRemove['status'], [302, 303], true));
        $this->assertResponseContains(
            'community page lists visible publication',
            'GET',
            '/community?q=' . rawurlencode((string)$this->runId . ' published') . '&scope=content&type=character',
            (string)$this->runId . ' published variant',
            $strangerCookie
        );
        $this->assertResponseContains(
            'community page marks own publication card',
            'GET',
            '/community?q=' . rawurlencode((string)$this->runId . ' published') . '&scope=content&type=character',
            'community-publication-card is-own',
            $ownerCookie
        );
        $this->assertResponseContains(
            'community users directory lists publication owner',
            'GET',
            '/community?scope=users',
            'href="/u/' . rawurlencode((string)$fixture['ownerUsername']) . '"',
            $strangerCookie
        );
        $this->assertResponseContains(
            'community users directory marks current user card',
            'GET',
            '/community?scope=users&q=' . rawurlencode((string)$fixture['ownerUsername']),
            'community-user-card is-own',
            $ownerCookie
        );
        $this->db->prepare('UPDATE users SET promote_public_profile = FALSE WHERE id = :id')
            ->execute([':id' => (int)$fixture['ownerId']]);
        $this->assertResponseNotContains(
            'community users directory hides unpromoted owner',
            'GET',
            '/community?scope=users&q=' . rawurlencode((string)$fixture['ownerUsername']),
            'href="/u/' . rawurlencode((string)$fixture['ownerUsername']) . '"',
            $strangerCookie
        );
        $this->db->prepare('UPDATE users SET promote_public_profile = TRUE WHERE id = :id')
            ->execute([':id' => (int)$fixture['ownerId']]);
        $templatePublication = $this->jsonPost('/api/publications/template/publish', [
            'templateId' => (int)$fixture['templateId'],
        ], $ownerCookie);
        $templatePublicId = (string)($templatePublication['json']['publication']['publicId'] ?? '');
        $this->assertTrue(
            'owner can publish template',
            $templatePublication['status'] === 200
                && !empty($templatePublication['json']['success'])
                && $templatePublicId !== ''
        );
        $this->assertResponseContains(
            'public template publication page is visible',
            'GET',
            '/p/' . rawurlencode($templatePublicId),
            (string)$fixture['templateName'],
            $strangerCookie
        );
        $this->assertResponseContains(
            'community template filter lists template publication',
            'GET',
            '/community?q=' . rawurlencode((string)$this->runId . ' public template') . '&scope=content&type=template',
            (string)$fixture['templateName'],
            $strangerCookie
        );
        $imagePublication = $this->jsonPost('/api/publications/image/publish', [
            'imageAssetId' => (int)$fixture['publishedImageAssetId'],
        ], $ownerCookie);
        $imagePublicId = (string)($imagePublication['json']['publication']['publicId'] ?? '');
        $this->assertTrue(
            'owner can publish image',
            $imagePublication['status'] === 200
                && !empty($imagePublication['json']['success'])
                && ($imagePublication['json']['publication']['contentType'] ?? '') === 'image'
                && $imagePublicId !== ''
        );
        $this->assertResponseContains(
            'public image publication page is visible',
            'GET',
            '/p/' . rawurlencode($imagePublicId),
            (string)$fixture['publishedImage'],
            $strangerCookie
        );
        $this->assertResponseContains(
            'community image filter lists image publication',
            'GET',
            '/community?q=' . rawurlencode((string)$fixture['publishedImage']) . '&scope=content&type=image',
            (string)$fixture['publishedImage'],
            $strangerCookie
        );
        $imageCopy = $this->jsonPost('/api/publications/copy', [
            'publicId' => $imagePublicId,
        ], $strangerCookie);
        $imageCopyPublicId = (string)($imageCopy['json']['publication']['publicId'] ?? '');
        $this->assertTrue(
            'stranger can copy public image with origin',
            $imageCopy['status'] === 200
                && !empty($imageCopy['json']['success'])
                && !empty($imageCopy['json']['publication']['isCopy'])
                && ($imageCopy['json']['publication']['contentType'] ?? '') === 'image'
                && ($imageCopy['json']['publication']['status'] ?? '') === 'unpublished'
                && ($imageCopy['json']['publication']['privateUrl'] ?? '') === '/gallery'
                && $imageCopyPublicId !== ''
        );
        $this->assertStatus(
            'image copy is not public immediately',
            'GET',
            '/p/' . rawurlencode($imageCopyPublicId),
            404,
            $ownerCookie
        );
        $storyPublication = $this->jsonPost('/api/publications/story/publish', [
            'storyId' => (int)$fixture['storyId'],
        ], $ownerCookie);
        $storyPublicPublicationId = (string)($storyPublication['json']['publication']['publicId'] ?? '');
        $this->assertTrue(
            'owner can publish story snapshot',
            $storyPublication['status'] === 200
                && !empty($storyPublication['json']['success'])
                && ($storyPublication['json']['publication']['contentType'] ?? '') === 'story'
                && $storyPublicPublicationId !== ''
        );
        $this->assertResponseContains(
            'public story publication page is visible',
            'GET',
            '/p/' . rawurlencode($storyPublicPublicationId),
            (string)$fixture['storyTitle'],
            $strangerCookie
        );
        $this->assertResponseNotContains(
            'public story snapshot redacts private character name',
            'GET',
            '/p/' . rawurlencode($storyPublicPublicationId),
            (string)$fixture['storyPrivateCharacterName'],
            $strangerCookie
        );
        $this->assertResponseContains(
            'public story snapshot shows redaction marker',
            'GET',
            '/p/' . rawurlencode($storyPublicPublicationId),
            'UKRYTE',
            $strangerCookie
        );
        $this->assertResponseContains(
            'community story filter lists story publication',
            'GET',
            '/community?q=' . rawurlencode((string)$fixture['storyTitle']) . '&scope=content&type=story',
            (string)$fixture['storyTitle'],
            $strangerCookie
        );
        $storyCopy = $this->jsonPost('/api/publications/copy', [
            'publicId' => $storyPublicPublicationId,
        ], $strangerCookie);
        $storyCopyPublicId = (string)($storyCopy['json']['publication']['publicId'] ?? '');
        $this->assertTrue(
            'stranger can copy public story with origin',
            $storyCopy['status'] === 200
                && !empty($storyCopy['json']['success'])
                && !empty($storyCopy['json']['publication']['isCopy'])
                && ($storyCopy['json']['publication']['contentType'] ?? '') === 'story'
                && ($storyCopy['json']['publication']['status'] ?? '') === 'unpublished'
                && str_starts_with((string)($storyCopy['json']['publication']['privateUrl'] ?? ''), '/story/')
                && $storyCopyPublicId !== ''
        );
        $this->assertStatus(
            'story copy is not public immediately',
            'GET',
            '/p/' . rawurlencode($storyCopyPublicId),
            404,
            $ownerCookie
        );
        $relationPublication = $this->jsonPost('/api/publications/relation-board/publish', [
            'boardId' => (int)$fixture['relationBoardId'],
        ], $ownerCookie);
        $relationPublicId = (string)($relationPublication['json']['publication']['publicId'] ?? '');
        $this->assertTrue(
            'owner can publish relation board snapshot',
            $relationPublication['status'] === 200
                && !empty($relationPublication['json']['success'])
                && ($relationPublication['json']['publication']['contentType'] ?? '') === 'relation_board'
                && $relationPublicId !== ''
        );
        $this->assertResponseContains(
            'public relation board page is visible',
            'GET',
            '/p/' . rawurlencode($relationPublicId),
            (string)$fixture['relationBoardTitle'],
            $strangerCookie
        );
        $this->assertResponseNotContains(
            'public relation board redacts private base character',
            'GET',
            '/p/' . rawurlencode($relationPublicId),
            (string)$fixture['storyPrivateCharacterName'],
            $strangerCookie
        );
        $this->assertResponseContains(
            'community relation filter lists relation publication',
            'GET',
            '/community?q=' . rawurlencode((string)$fixture['relationBoardTitle']) . '&scope=content&type=relation_board',
            (string)$fixture['relationBoardTitle'],
            $strangerCookie
        );
        $relationCopy = $this->jsonPost('/api/publications/copy', [
            'publicId' => $relationPublicId,
        ], $strangerCookie);
        $relationCopyPublicId = (string)($relationCopy['json']['publication']['publicId'] ?? '');
        $this->assertTrue(
            'stranger can copy public relation board with origin',
            $relationCopy['status'] === 200
                && !empty($relationCopy['json']['success'])
                && !empty($relationCopy['json']['publication']['isCopy'])
                && ($relationCopy['json']['publication']['contentType'] ?? '') === 'relation_board'
                && ($relationCopy['json']['publication']['status'] ?? '') === 'unpublished'
                && str_starts_with((string)($relationCopy['json']['publication']['privateUrl'] ?? ''), '/relations/')
                && $relationCopyPublicId !== ''
        );
        $this->assertStatus(
            'relation copy is not public immediately',
            'GET',
            '/p/' . rawurlencode($relationCopyPublicId),
            404,
            $ownerCookie
        );
        $strangerPublication = $this->publicationService->publishCharacter(
            (int)$fixture['strangerId'],
            (int)$fixture['strangerCharacterId'],
            (int)$fixture['strangerVariantId']
        );
        $this->assertTrue('second public user can publish character', ($strangerPublication['revisionNumber'] ?? null) === 1);
        $this->assertResponseOrder(
            'community users desc sorts by publication count',
            'GET',
            '/community?scope=users&sort=desc&q=' . rawurlencode((string)$this->runId),
            'href="/u/' . rawurlencode((string)$fixture['ownerUsername']) . '"',
            'href="/u/' . rawurlencode((string)$fixture['strangerUsername']) . '"',
            $strangerCookie
        );
        $this->assertResponseOrder(
            'community users asc sorts by publication count',
            'GET',
            '/community?scope=users&sort=asc&q=' . rawurlencode((string)$this->runId),
            'href="/u/' . rawurlencode((string)$fixture['strangerUsername']) . '"',
            'href="/u/' . rawurlencode((string)$fixture['ownerUsername']) . '"',
            $strangerCookie
        );
        $this->assertStatus(
            'community users random sort is available',
            'GET',
            '/community?scope=users&sort=random&q=' . rawurlencode((string)$this->runId),
            200,
            $strangerCookie
        );
        $this->assertResponseContains(
            'public profile local search finds template',
            'GET',
            '/u/' . rawurlencode((string)$fixture['ownerUsername']) . '?q=' . rawurlencode((string)$this->runId . ' public template') . '&type=template',
            (string)$fixture['templateName'],
            $strangerCookie
        );
        $followResponse = $this->jsonPost('/api/follows/toggle', [
            'userId' => (int)$fixture['ownerId'],
        ], $strangerCookie);
        $this->assertTrue(
            'stranger can follow publication owner',
            $followResponse['status'] === 200
                && !empty($followResponse['json']['success'])
                && !empty($followResponse['json']['following'])
                && (int)($followResponse['json']['followerCount'] ?? 0) === 1
        );
        $this->assertResponseContains(
            'public profile shows followed state',
            'GET',
            '/u/' . rawurlencode((string)$fixture['ownerUsername']),
            'Obserwujesz',
            $strangerCookie
        );
        $this->assertResponseContains(
            'community following feed lists followed publication',
            'GET',
            '/community?scope=following&type=character&q=' . rawurlencode((string)$this->runId . ' published'),
            (string)$this->runId . ' published variant',
            $strangerCookie
        );
        $apiRefresh = $this->jsonPost('/api/publications/character/publish', [
            'characterId' => (int)$fixture['characterId'],
            'variantId' => (int)$fixture['publishedVariantId'],
            'changeReason' => 'refresh',
        ], $ownerCookie);
        $this->assertTrue(
            'publishing update notifies followers',
            $apiRefresh['status'] === 200
                && !empty($apiRefresh['json']['success'])
                && (int)($apiRefresh['json']['publication']['revisionNumber'] ?? 0) === 3
        );
        $this->assertResponseContains(
            'follower receives publication notification',
            'GET',
            '/api/notifications',
            'Aktualizacja publikacji obserwowanego',
            $strangerCookie
        );
        $this->assertResponseContains(
            'stranger global search finds public publication',
            'GET',
            '/api/search?q=' . rawurlencode((string)$this->runId . ' published'),
            (string)$this->runId . ' published variant',
            $strangerCookie
        );
        $this->assertResponseNotContains(
            'stranger global search does not expose private variant',
            'GET',
            '/api/search?q=' . rawurlencode((string)$fixture['privateVariantName']),
            (string)$fixture['privateVariantName'],
            $strangerCookie
        );
        $messageSearch = $this->request(
            'GET',
            '/api/messages/search?q=' . rawurlencode((string)$fixture['ownerUsername']),
            [],
            $strangerCookie
        );
        $this->assertTrue(
            'stranger can find message recipient',
            $messageSearch['status'] === 200
                && str_contains((string)$messageSearch['raw'], (string)$fixture['ownerUsername'])
        );
        $conversationResponse = $this->jsonPost('/api/messages/start', [
            'userId' => (int)$fixture['ownerId'],
        ], $strangerCookie);
        $conversationUuid = (string)($conversationResponse['json']['conversation']['uuid'] ?? '');
        $this->assertTrue(
            'stranger can start direct conversation',
            $conversationResponse['status'] === 200
                && !empty($conversationResponse['json']['success'])
                && $conversationUuid !== ''
        );
        $messageBody = $this->runId . ' direct message body';
        $sendResponse = $this->jsonPost('/api/messages/send', [
            'conversationId' => $conversationUuid,
            'body' => $messageBody,
        ], $strangerCookie);
        $this->assertTrue(
            'stranger can send direct message',
            $sendResponse['status'] === 200
                && !empty($sendResponse['json']['success'])
                && ($sendResponse['json']['message']['body'] ?? '') === $messageBody
        );
        $this->assertResponseContains(
            'owner can read direct message thread',
            'GET',
            '/api/messages/thread?conversation=' . rawurlencode($conversationUuid),
            $messageBody,
            $ownerCookie
        );
        $reactionResponse = $this->jsonPost('/api/publications/reaction', [
            'publicationId' => (int)$second['id'],
            'reactionType' => 'love',
        ], $strangerCookie);
        $this->assertTrue(
            'stranger can react to visible publication',
            $reactionResponse['status'] === 200
                && !empty($reactionResponse['json']['success'])
                && ($reactionResponse['json']['reactions']['currentReaction'] ?? null) === 'love'
                && (int)($reactionResponse['json']['reactions']['total'] ?? 0) === 1
        );
        $this->assertResponseContains(
            'anonymous public page shows reaction count',
            'GET',
            '/p/' . rawurlencode((string)$second['publicId']),
            '1 razem'
        );
        $this->assertResponseOrder(
            'community content desc sorts by reaction count',
            'GET',
            '/community?scope=content&type=all&sort=desc&q=' . rawurlencode((string)$this->runId),
            (string)$this->runId . ' published variant',
            (string)$fixture['templateName'],
            $strangerCookie
        );
        $this->assertResponseOrder(
            'community content asc sorts by reaction count',
            'GET',
            '/community?scope=content&type=all&sort=asc&q=' . rawurlencode((string)$this->runId),
            (string)$fixture['templateName'],
            (string)$this->runId . ' published variant',
            $strangerCookie
        );
        $this->assertStatus(
            'community content random sort is available',
            'GET',
            '/community?scope=content&type=all&sort=random&q=' . rawurlencode((string)$this->runId),
            200,
            $strangerCookie
        );
        $copyResponse = $this->jsonPost('/api/publications/copy', [
            'publicId' => (string)$second['publicId'],
        ], $strangerCookie);
        $copiedPublicId = (string)($copyResponse['json']['publication']['publicId'] ?? '');
        $this->assertTrue(
            'stranger can copy public publication with origin',
            $copyResponse['status'] === 200
                && !empty($copyResponse['json']['success'])
                && !empty($copyResponse['json']['publication']['isCopy'])
                && ($copyResponse['json']['publication']['status'] ?? '') === 'unpublished'
                && str_starts_with((string)($copyResponse['json']['publication']['privateUrl'] ?? ''), '/character/')
                && $copiedPublicId !== ''
        );
        $this->assertStatus(
            'copy is not public immediately',
            'GET',
            '/p/' . rawurlencode($copiedPublicId),
            404,
            $ownerCookie
        );
        $disableAttribution = $this->formPost('/settings', [
            'locale' => 'pl',
            'theme' => 'light',
            'accent' => 'orange',
            'columns' => '4',
            'promote_public_profile' => '1',
            'blocked_tags' => '',
        ], $ownerCookie);
        $this->assertTrue('owner can disable copy attribution', in_array($disableAttribution['status'], [302, 303], true));
        $enableAttribution = $this->formPost('/settings', [
            'locale' => 'pl',
            'theme' => 'light',
            'accent' => 'orange',
            'columns' => '4',
            'promote_public_profile' => '1',
            'copy_attribution_enabled' => '1',
            'blocked_tags' => '',
        ], $ownerCookie);
        $this->assertTrue('owner can enable copy attribution again', in_array($enableAttribution['status'], [302, 303], true));
        $reactionToggleResponse = $this->jsonPost('/api/publications/reaction', [
            'publicationId' => (int)$second['id'],
            'reactionType' => 'love',
        ], $strangerCookie);
        $this->assertTrue(
            'clicking same reaction removes it',
            $reactionToggleResponse['status'] === 200
                && !empty($reactionToggleResponse['json']['success'])
                && ($reactionToggleResponse['json']['reactions']['currentReaction'] ?? null) === null
                && (int)($reactionToggleResponse['json']['reactions']['total'] ?? -1) === 0
        );
        $commentBody = $this->runId . ' public comment body';
        $commentResponse = $this->jsonPost('/api/publications/comment', [
            'publicationId' => (int)$second['id'],
            'body' => $commentBody,
        ], $strangerCookie);
        $this->assertTrue(
            'stranger can comment visible publication',
            $commentResponse['status'] === 200
                && !empty($commentResponse['json']['success'])
                && ($commentResponse['json']['comment']['body'] ?? '') === $commentBody
        );
        $this->assertResponseContains(
            'anonymous public page shows comment',
            'GET',
            '/p/' . rawurlencode((string)$second['publicId']),
            $commentBody
        );
        $duplicateCommentResponse = $this->jsonPost('/api/publications/comment', [
            'publicationId' => (int)$second['id'],
            'body' => $commentBody,
        ], $strangerCookie);
        $this->assertTrue(
            'duplicate comment under same publication is rejected',
            $duplicateCommentResponse['status'] === 409
        );
        $this->assertResponseContains(
            'owner receives comment notification',
            'GET',
            '/api/notifications',
            'Nowy komentarz pod publikacja',
            $ownerCookie
        );
        $readAll = $this->jsonPost('/api/notifications/read-all', [], $ownerCookie);
        $this->assertTrue(
            'owner can mark notifications read',
            $readAll['status'] === 200 && (int)($readAll['json']['unreadCount'] ?? -1) === 0
        );
        $reportResponse = $this->jsonPost('/api/publications/report', [
            'publicationId' => (int)$second['id'],
            'reasonCategory' => 'adult',
            'details' => 'Smoke moderation report.',
        ], $strangerCookie);
        $this->assertTrue(
            'stranger can report visible publication',
            $reportResponse['status'] === 200
                && !empty($reportResponse['json']['success'])
                && (int)($reportResponse['json']['report']['openReportCount'] ?? 0) === 1
        );
        $duplicateReportResponse = $this->jsonPost('/api/publications/report', [
            'publicationId' => (int)$second['id'],
            'reasonCategory' => 'adult',
            'details' => 'Smoke moderation report.',
        ], $strangerCookie);
        $this->assertTrue('duplicate publication report is rejected', $duplicateReportResponse['status'] === 409);
        $this->assertResponseContains(
            'admin receives report notification',
            'GET',
            '/api/notifications',
            'Nowe zgloszenie publikacji',
            $adminCookie
        );
        $reportQueue = (new PublicationRepository())->adminReportQueue();
        $this->assertTrue(
            'admin report queue contains publication report',
            count(array_filter($reportQueue, static fn(array $item): bool => (int)($item['publicationId'] ?? 0) === (int)$second['id'])) === 1
        );
        $adminPanel = $this->request('GET', '/admin', [], $adminCookie);
        $this->assertTrue(
            'admin panel exposes report section',
            $adminPanel['status'] === 200 && str_contains((string)$adminPanel['raw'], 'Zgloszenia publikacji')
        );
        $this->assertTrue(
            'admin panel exposes backup reminder settings',
            $adminPanel['status'] === 200 && str_contains((string)$adminPanel['raw'], 'Przypominaj o backupie')
        );
        $backupReminder = $this->formPost('/admin/backup-reminder', [
            'backup_reminder_enabled' => '1',
            'backup_reminder_interval_days' => '14',
        ], $adminCookie);
        $this->assertTrue('admin can save backup reminder settings', in_array($backupReminder['status'], [302, 303], true));
        $adminPanelAfterBackupReminder = $this->request('GET', '/admin?backupReminder=1', [], $adminCookie);
        $this->assertTrue(
            'admin panel confirms backup reminder settings',
            $adminPanelAfterBackupReminder['status'] === 200
                && str_contains((string)$adminPanelAfterBackupReminder['raw'], 'Ustawienia przypomnien backupu zapisane.')
        );
        $moderation = $this->formPost('/admin/publications/moderate', [
            'publication_id' => (int)$second['id'],
            'moderation_action' => 'mark_adult',
        ], $adminCookie);
        $this->assertTrue('admin can mark reported publication adult', in_array($moderation['status'], [302, 303], true));
        $ageRating = $this->db->prepare('SELECT age_rating FROM publications WHERE id = :id');
        $ageRating->execute([':id' => (int)$second['id']]);
        $this->assertTrue('admin moderation changes publication age rating', $ageRating->fetchColumn() === 'adult');
        $this->assertResponseContains(
            'owner receives moderation notification',
            'GET',
            '/api/notifications',
            'Decyzja administracji',
            $ownerCookie
        );
        $blockResponse = $this->jsonPost('/api/blocks/block', [
            'userId' => (int)$fixture['strangerId'],
        ], $ownerCookie);
        $this->assertTrue(
            'owner can block stranger interactions',
            $blockResponse['status'] === 200
                && !empty($blockResponse['json']['success'])
                && !empty($blockResponse['json']['viewerBlocksTarget'])
                && (int)($blockResponse['json']['followerCount'] ?? -1) === 0
        );
        $blockedMessage = $this->jsonPost('/api/messages/send', [
            'conversationId' => $conversationUuid,
            'body' => $this->runId . ' blocked direct message',
        ], $strangerCookie);
        $this->assertTrue('blocked stranger cannot send direct message', $blockedMessage['status'] === 403);
        $blockedFollow = $this->jsonPost('/api/follows/toggle', [
            'userId' => (int)$fixture['ownerId'],
        ], $strangerCookie);
        $this->assertTrue('blocked stranger cannot follow owner', $blockedFollow['status'] === 403);
        $blockedComment = $this->jsonPost('/api/publications/comment', [
            'publicationId' => (int)$second['id'],
            'body' => $this->runId . ' blocked comment',
        ], $strangerCookie);
        $this->assertTrue('blocked stranger cannot comment owner publication', $blockedComment['status'] === 403);
        $this->assertStatus('published media is visible anonymously', 'GET', '/media/' . rawurlencode((string)$fixture['publishedImage']), 200);
        $this->assertNotStatus('legacy source character page is not public', 'GET', '/character/' . (int)$fixture['characterId'], 200);
        $txtExport = $this->request(
            'GET',
            '/character/export?id=' . rawurlencode((string)$fixture['characterId'])
                . '&format=txt&scope=current&variant=' . (int)$fixture['publishedVariantId'],
            [],
            $ownerCookie
        );
        $this->assertTrue(
            'owner can export selected character variant to txt',
            $txtExport['status'] === 200
                && str_contains((string)$txtExport['raw'], (string)$this->runId . ' published variant')
                && str_contains((string)$txtExport['raw'], 'FIELD=Variant export field value.')
        );
        $pdfExport = $this->request(
            'GET',
            '/character/export?id=' . rawurlencode((string)$fixture['characterId']) . '&format=pdf&scope=all',
            [],
            $ownerCookie
        );
        $pdfRaw = (string)$pdfExport['raw'];
        $pdfImageCount = substr_count($pdfRaw, '/Subtype /Image');
        $this->assertTrue('owner can export full character to pdf', $pdfExport['status'] === 200 && str_contains($pdfRaw, '%PDF-1.4'));
        $this->assertTrue('character pdf embeds images (' . $pdfImageCount . ')', $pdfImageCount >= 4);
        $this->assertTrue('character pdf includes Polish glyph encoding', str_contains($pdfRaw, '/aogonek'));
        $this->assertTrue('character pdf omits right panel technical labels', !str_contains($pdfRaw, 'Infobox') && !str_contains($pdfRaw, 'Panel prawy'));
        $this->assertTrue('character pdf keeps right panel field value', str_contains($pdfRaw, 'Right panel variant value.'));
        $bulkPdf = $this->formPost('/character/export/bulk', [
            'character_ids' => [(int)$fixture['characterId']],
            'format' => 'pdf',
            'delivery' => 'single',
        ], $ownerCookie);
        $this->assertTrue(
            'owner can bulk export characters to one pdf',
            $bulkPdf['status'] === 200
                && str_contains((string)$bulkPdf['raw'], '%PDF-1.4')
                && str_contains((string)$bulkPdf['raw'], 'Right panel variant value.')
        );
        $bulkZip = $this->formPost('/character/export/bulk', [
            'character_ids' => [(int)$fixture['characterId']],
            'format' => 'txt',
            'delivery' => 'zip',
        ], $ownerCookie);
        $this->assertTrue(
            'owner can bulk export characters as separate txt zip',
            $bulkZip['status'] === 200
                && str_contains((string)$bulkZip['raw'], 'PK')
                && str_contains((string)$bulkZip['raw'], '.txt')
        );
        $strangerBulk = $this->formPost('/character/export/bulk', [
            'character_ids' => [(int)$fixture['characterId']],
            'format' => 'pdf',
            'delivery' => 'single',
        ], $strangerCookie);
        $this->assertTrue('stranger cannot bulk export owner character', $strangerBulk['status'] === 404);
        $this->assertStatus(
            'stranger cannot export owner character',
            'GET',
            '/character/export?id=' . rawurlencode((string)$fixture['characterId']) . '&format=txt&scope=all',
            404,
            $strangerCookie
        );

        $this->db->query('SELECT pg_sleep(2)');
        $this->db->prepare('UPDATE character_variants SET description = :description WHERE id = :id')
            ->execute([
                ':description' => 'Visible variant description changed after publication.',
                ':id' => (int)$fixture['publishedVariantId'],
            ]);

        $mapAfterEdit = (new PublicationRepository())->ownedCharacterPublicationMap(
            (int)$fixture['ownerId'],
            [(int)$fixture['characterId']]
        );
        $publicationAfterEdit = $mapAfterEdit[(int)$fixture['characterId']][(int)$fixture['publishedVariantId']] ?? null;
        $this->assertTrue('editing selected variant marks publication outdated', !empty($publicationAfterEdit['isOutdated']));

        $fourth = $this->publicationService->publishCharacter(
            (int)$fixture['ownerId'],
            (int)$fixture['characterId'],
            (int)$fixture['publishedVariantId']
        );
        $this->assertTrue('refreshing outdated publication creates fourth revision', ($fourth['revisionNumber'] ?? null) === 4);

        $mapAfterRefresh = (new PublicationRepository())->ownedCharacterPublicationMap(
            (int)$fixture['ownerId'],
            [(int)$fixture['characterId']]
        );
        $publicationAfterRefresh = $mapAfterRefresh[(int)$fixture['characterId']][(int)$fixture['publishedVariantId']] ?? null;
        $this->assertTrue('refreshing publication clears outdated flag', empty($publicationAfterRefresh['isOutdated']));
    }

    private function assertStorageQuotaControls(array $fixture, string $ownerCookie, string $adminCookie): void
    {
        $originalUserQuota = $this->accountTypeQuota(0);
        $originalAdminQuota = $this->accountTypeQuota(1);
        $customTypeId = null;
        $largeImagePath = null;

        try {
            $this->setAccountTypeQuota(0, 250);
            $this->setAccountTypeQuota(1, 2048);

            $this->assertResponseContains('user storage quota appears in dashboard', 'GET', '/dashboard', 'z 250 MB', $ownerCookie);
            $this->assertResponseContains('admin storage quota appears in dashboard', 'GET', '/dashboard', 'z 2048 MB', $adminCookie);
            $this->assertResponseContains('admin account type panel is available', 'GET', '/admin', 'Typy kont i limity', $adminCookie);
            $this->assertResponseContains('admin account type quota field is available', 'GET', '/admin', 'name="storage_quota_mb"', $adminCookie);

            $customTypeName = $this->runId . ' Premium';
            $createType = $this->formPost('/admin/account-types/create', [
                'name' => $customTypeName,
                'storage_quota_mb' => '333',
                'features' => $this->accountTypeFeatureKeys(),
            ], $adminCookie);
            $this->assertTrue('admin can create custom account type', in_array($createType['status'], [302, 303], true));
            $customTypeId = $this->accountTypeIdByName($customTypeName);
            $this->assertTrue('custom account type exists', $customTypeId > 1);
            $this->assertResponseContains('custom account type appears in admin panel', 'GET', '/admin', $customTypeName, $adminCookie);

            $assignType = $this->formPost('/admin/users/account-type', [
                'user_id' => (string)$fixture['ownerId'],
                'account_type' => (string)$customTypeId,
            ], $adminCookie);
            $this->assertTrue('admin can assign custom account type to user', in_array($assignType['status'], [302, 303], true));
            $this->assertTrue('user account type changed in database', $this->userAccountType((int)$fixture['ownerId']) === $customTypeId);
            $this->assertResponseContains('custom account type quota affects owner dashboard', 'GET', '/dashboard', 'z 333 MB', $ownerCookie);

            $this->db->prepare('UPDATE users SET account_type = 0 WHERE id = :id')->execute([':id' => (int)$fixture['ownerId']]);
            $this->setAccountTypeQuota(0, 1);
            $largeImagePath = $this->createLargePngTempFile();
            $upload = $this->multipartPost('/uploadFile', [
                'file' => new CURLFile($largeImagePath, 'image/png', $this->runId . '_quota.png'),
            ], $ownerCookie);

            $this->assertTrue('upload over user storage quota is rejected', $upload['status'] === 413);
            $this->assertTrue(
                'storage quota rejection explains limit',
                str_contains((string)$upload['raw'], 'Limit miejsca na zdjecia zostal przekroczony')
            );
        } finally {
            $this->db->prepare('UPDATE users SET account_type = 0 WHERE id = :id')->execute([':id' => (int)$fixture['ownerId']]);
            $this->setAccountTypeQuota(0, $originalUserQuota);
            $this->setAccountTypeQuota(1, $originalAdminQuota);
            if ($customTypeId !== null) {
                $this->db->prepare('DELETE FROM account_type_feature_permissions WHERE account_type_id = :id')->execute([':id' => $customTypeId]);
                $this->db->prepare('DELETE FROM account_types WHERE id = :id AND is_builtin = FALSE')->execute([':id' => $customTypeId]);
            }
            if ($largeImagePath !== null && is_file($largeImagePath)) {
                @unlink($largeImagePath);
            }
        }
    }

    private function assertOfflineModeHidesSocialSurface(array $fixture): void
    {
        try {
            $this->setFeatureSetting('auth.offline_user_id', true, (string)$fixture['adminId']);
            $this->setFeatureSetting('auth.login.enabled', false, '');
            foreach ([
                'community.enabled',
                'publications.enabled',
                'comments.enabled',
                'reactions.enabled',
                'follows.enabled',
                'messages.enabled',
                'reports.enabled',
                'copying.enabled',
                'public_search.enabled',
            ] as $key) {
                $this->setFeatureSetting($key, true, '');
            }

            $adminPage = $this->request('GET', '/admin');
            $this->assertTrue('offline mode auto-opens admin account', $adminPage['status'] === 200);
            $adminHtml = (string)$adminPage['raw'];
            $this->assertTrue('offline mode marks feature flags offline', str_contains($adminHtml, '"offlineMode":true'));
            $this->assertTrue('offline mode forces community flag off', str_contains($adminHtml, '"community":false'));
            $this->assertTrue('offline mode hides community nav link', !str_contains($adminHtml, 'href="/community"'));
            $this->assertTrue('offline mode hides notification root', !str_contains($adminHtml, 'data-notifications-root'));
            $this->assertTrue('offline mode hides logout button', !str_contains($adminHtml, 'logout-header-btn'));
            $this->assertTrue('offline mode hides profile button', !str_contains($adminHtml, 'class="user-profile"'));
            $this->assertTrue('offline mode does not render chat widget', !str_contains($adminHtml, 'data-chat-widget'));
            $this->assertTrue('offline mode does not load chat script', !str_contains($adminHtml, 'chat-widget.js'));
            $this->assertTrue('offline mode does not load publication modal script', !str_contains($adminHtml, 'publication-modal.js'));

            $this->setFeatureSetting('auth.offline_user_id', true, (string)$fixture['ownerId']);
            $this->assertResponseNotContains('offline mode hides template publish buttons', 'GET', '/templates', 'Udostepnij szablon');
            $this->assertResponseNotContains('offline mode hides gallery publish buttons', 'GET', '/gallery', 'gallery-publish-image');
            $this->assertResponseNotContains('offline mode hides story publish buttons', 'GET', '/stories', 'publishStory(');
            $this->assertResponseNotContains('offline mode hides relation publish buttons', 'GET', '/relations', 'publish-board-btn');
            $this->assertResponseNotContains(
                'offline mode hides character publication toolbar',
                'GET',
                '/viewCharacter?id=' . rawurlencode((string)$fixture['characterId']),
                'data-publication-action'
            );

            $messages = $this->request('GET', '/api/messages/conversations');
            $this->assertTrue('offline mode blocks messages API', $messages['status'] === 403);
            $notifications = $this->request('GET', '/api/notifications');
            $this->assertTrue('offline mode blocks notifications API', $notifications['status'] === 403);
            $profile = $this->request('GET', '/profile');
            $this->assertTrue('offline mode blocks own profile route', $profile['status'] === 403);
            $community = $this->request('GET', '/community');
            $this->assertTrue('offline mode shows disabled community page', $community['status'] === 200 && str_contains((string)$community['raw'], 'Spolecznosc jest wylaczona'));
        } finally {
            $this->configureSmokeFeatureSettings();
        }
    }

    private function currentPublicationPayload(int $publicationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pr.payload
             FROM publications p
             JOIN publication_revisions pr ON pr.id = p.current_revision_id
             WHERE p.id = :id'
        );
        $stmt->execute([':id' => $publicationId]);
        $payload = $stmt->fetchColumn();
        $decoded = json_decode((string)$payload, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Publication payload is not valid JSON.');
        }

        return $decoded;
    }

    private function insertUser(string $email, string $username, string $passwordHash): int
    {
        $id = $this->insertReturningId(
            'INSERT INTO users (email, password, firstname, lastname, username, bio, locale)
             VALUES (:email, :password, :firstname, :lastname, :username, :bio, :locale)
             RETURNING id',
            [
                ':email' => $email,
                ':password' => $passwordHash,
                ':firstname' => 'Smoke',
                ':lastname' => 'Test',
                ':username' => $username,
                ':bio' => '',
                ':locale' => 'pl',
            ]
        );
        $this->createdUserIds[] = $id;
        return $id;
    }

    private function login(string $login, string $password): string
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'oc_smoke_cookie_');
        if ($cookieJar === false) {
            throw new RuntimeException('Could not create cookie jar.');
        }
        $this->createdFiles[] = $cookieJar;

        $response = $this->request('POST', '/login', [
            'login' => $login,
            'password' => $password,
        ], $cookieJar);

        if (!in_array($response['status'], [200, 303], true)) {
            throw new RuntimeException("Login failed for {$login}; status {$response['status']}.");
        }

        return $cookieJar;
    }

    private function assertStatus(string $name, string $method, string $path, int $expected, ?string $cookieJar = null): void
    {
        $response = $this->request($method, $path, [], $cookieJar);
        $actual = $response['status'];
        if ($actual !== $expected) {
            throw new RuntimeException("{$name}: expected HTTP {$expected}, got {$actual} for {$method} {$path}.");
        }
        $this->assertions++;
        echo "[ok] {$name}\n";
    }

    private function assertTrue(string $name, bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException($name . ': assertion failed.');
        }
        $this->assertions++;
        echo "[ok] {$name}\n";
    }

    private function assertRowCount(string $name, string $table, int $id, int $expected): void
    {
        $allowedTables = ['templates', 'stories', 'relation_boards', 'image_assets'];
        if (!in_array($table, $allowedTables, true)) {
            throw new InvalidArgumentException('Unsupported smoke-test table.');
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $this->assertTrue($name, (int)$stmt->fetchColumn() === $expected);
    }

    private function assertResponseContains(string $name, string $method, string $path, string $needle, ?string $cookieJar = null): void
    {
        $response = $this->request($method, $path, [], $cookieJar);
        if ($response['status'] !== 200) {
            throw new RuntimeException("{$name}: expected HTTP 200, got {$response['status']} for {$method} {$path}.");
        }

        if (!str_contains((string)$response['raw'], $needle)) {
            throw new RuntimeException("{$name}: response did not contain expected text.");
        }

        $this->assertions++;
        echo "[ok] {$name}\n";
    }

    private function assertResponseNotContains(string $name, string $method, string $path, string $needle, ?string $cookieJar = null): void
    {
        $response = $this->request($method, $path, [], $cookieJar);
        if ($response['status'] !== 200) {
            throw new RuntimeException("{$name}: expected HTTP 200, got {$response['status']} for {$method} {$path}.");
        }

        if (str_contains((string)$response['raw'], $needle)) {
            throw new RuntimeException("{$name}: response contained forbidden text.");
        }

        $this->assertions++;
        echo "[ok] {$name}\n";
    }

    private function assertResponseOrder(
        string $name,
        string $method,
        string $path,
        string $firstNeedle,
        string $secondNeedle,
        ?string $cookieJar = null
    ): void {
        $response = $this->request($method, $path, [], $cookieJar);
        if ($response['status'] !== 200) {
            throw new RuntimeException("{$name}: expected HTTP 200, got {$response['status']} for {$method} {$path}.");
        }

        $raw = (string)$response['raw'];
        $firstPosition = strpos($raw, $firstNeedle);
        $secondPosition = strpos($raw, $secondNeedle);
        if ($firstPosition === false || $secondPosition === false) {
            throw new RuntimeException("{$name}: response did not contain both ordered markers.");
        }
        if ($firstPosition >= $secondPosition) {
            throw new RuntimeException("{$name}: response order was incorrect.");
        }

        $this->assertions++;
        echo "[ok] {$name}\n";
    }

    private function assertNotStatus(string $name, string $method, string $path, int $forbidden, ?string $cookieJar = null): void
    {
        $response = $this->request($method, $path, [], $cookieJar);
        $actual = $response['status'];
        if ($actual === $forbidden) {
            throw new RuntimeException("{$name}: did not expect HTTP {$forbidden} for {$method} {$path}.");
        }
        $this->assertions++;
        echo "[ok] {$name}\n";
    }

    private function jsonPost(string $path, array $data, string $cookieJar): array
    {
        $csrfToken = $this->csrfToken($cookieJar);
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-CSRF-Token: ' . $csrfToken,
            ],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $body = substr((string)$raw, $headerSize);
        $decoded = json_decode($body, true);

        return [
            'status' => $status,
            'json' => is_array($decoded) ? $decoded : [],
            'raw' => $body,
        ];
    }

    private function multipartPost(string $path, array $data, string $cookieJar): array
    {
        $csrfToken = $this->csrfToken($cookieJar);
        $data['csrf_token'] = $csrfToken;
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_HTTPHEADER => [
                'X-CSRF-Token: ' . $csrfToken,
            ],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $body = substr((string)$raw, $headerSize);
        $decoded = json_decode($body, true);

        return [
            'status' => $status,
            'json' => is_array($decoded) ? $decoded : [],
            'raw' => $body,
        ];
    }

    private function formPost(string $path, array $data, string $cookieJar): array
    {
        $data['csrf_token'] = $this->csrfToken($cookieJar);
        return $this->request('POST', $path, $data, $cookieJar);
    }

    private function csrfToken(string $cookieJar): string
    {
        $response = $this->request('GET', '/dashboard', [], $cookieJar);
        if ($response['status'] !== 200) {
            throw new RuntimeException("Could not fetch CSRF token; dashboard status {$response['status']}.");
        }

        if (!preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/', (string)$response['raw'], $matches)) {
            throw new RuntimeException('Could not find CSRF token in dashboard response.');
        }

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    private function request(string $method, string $path, array $data = [], ?string $cookieJar = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ]);

        if ($cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'raw' => $raw];
    }

    private function snapshotFeatureSettings(): void
    {
        $stmt = $this->db->query('SELECT key, enabled, value, description FROM social_feature_settings');
        $this->originalFeatureSettings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->originalFeatureSettings[(string)$row['key']] = [
                'enabled' => $this->dbBool($row['enabled']),
                'value' => (string)($row['value'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
            ];
        }
    }

    private function configureSmokeFeatureSettings(): void
    {
        foreach ([
            'auth.login.enabled',
            'community.enabled',
            'characters.enabled',
            'relations.enabled',
            'stories.enabled',
            'gallery.enabled',
            'publications.enabled',
            'comments.enabled',
            'reactions.enabled',
            'follows.enabled',
            'messages.enabled',
            'reports.enabled',
            'copying.enabled',
            'public_search.enabled',
        ] as $key) {
            $this->setFeatureSetting($key, true, '');
        }

        $this->setFeatureSetting('auth.offline_user_id', true, '0');
        $this->setFeatureSetting('new_publications.require_review', false, '');
        $this->setFeatureSetting('storage.user_quota_mb', true, '500');
        $this->setFeatureSetting('storage.admin_quota_mb', true, '500');
        $this->setAccountTypeQuota(0, 500);
        $this->setAccountTypeQuota(1, 500);
    }

    private function restoreFeatureSettings(): void
    {
        if (empty($this->originalFeatureSettings)) {
            return;
        }

        foreach ($this->originalFeatureSettings as $key => $state) {
            $this->setFeatureSetting(
                (string)$key,
                !empty($state['enabled']),
                (string)($state['value'] ?? ''),
                (string)($state['description'] ?? '')
            );
        }
    }

    private function setFeatureSetting(string $key, bool $enabled, string $value, string $description = ''): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO social_feature_settings (key, enabled, value, description)
             VALUES (:key, :enabled, :value, :description)
             ON CONFLICT (key) DO UPDATE
             SET enabled = EXCLUDED.enabled,
                 value = EXCLUDED.value,
                 description = CASE
                     WHEN EXCLUDED.description = :emptyDescription THEN social_feature_settings.description
                     ELSE EXCLUDED.description
                 END,
                 updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':key', $key);
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':value', $value);
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':emptyDescription', '');
        $stmt->execute();
    }

    private function accountTypeQuota(int $accountType): int
    {
        $stmt = $this->db->prepare('SELECT storage_quota_mb FROM account_types WHERE id = :id');
        $stmt->execute([':id' => $accountType]);
        $value = $stmt->fetchColumn();

        return $value === false ? 500 : (int)$value;
    }

    private function setAccountTypeQuota(int $accountType, int $quotaMb): void
    {
        $stmt = $this->db->prepare('UPDATE account_types SET storage_quota_mb = :quota, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            ':quota' => max(1, min(1048576, $quotaMb)),
            ':id' => $accountType,
        ]);
    }

    private function accountTypeFeatureKeys(): array
    {
        $stmt = $this->db->query(
            "SELECT key
             FROM social_feature_settings
             WHERE key NOT IN ('auth.login.enabled', 'auth.offline_user_id')
               AND key NOT LIKE 'storage.%'
             ORDER BY key ASC"
        );

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function accountTypeIdByName(string $name): int
    {
        $stmt = $this->db->prepare('SELECT id FROM account_types WHERE name = :name ORDER BY id DESC LIMIT 1');
        $stmt->execute([':name' => $name]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int)$value;
    }

    private function userAccountType(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT account_type FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        return (int)$stmt->fetchColumn();
    }

    private function featureValue(string $key, string $fallback): string
    {
        $stmt = $this->db->prepare('SELECT value FROM social_feature_settings WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? (string)($row['value'] ?? $fallback) : $fallback;
    }

    private function dbBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private function createLargePngTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oc_smoke_quota_');
        if ($path === false) {
            throw new RuntimeException('Could not create quota-test temp file.');
        }

        $png = $this->samplePngBytes();

        if (file_put_contents($path, $png . str_repeat("\0", 2 * 1024 * 1024)) === false) {
            @unlink($path);
            throw new RuntimeException('Could not write quota-test PNG.');
        }

        return $path;
    }

    private function samplePngBytes(): string
    {
        $image = imagecreatetruecolor(16, 16);
        if (!$image) {
            throw new RuntimeException('Could not create smoke-test PNG canvas.');
        }
        $bg = imagecolorallocate($image, 124, 92, 255);
        $fg = imagecolorallocate($image, 245, 247, 250);
        imagefilledrectangle($image, 0, 0, 15, 15, $bg);
        imagefilledellipse($image, 8, 8, 8, 8, $fg);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Could not encode smoke-test PNG.');
        }

        return $bytes;
    }

    private function insertReturningId(string $sql, array $params): int
    {
        $row = $this->insertReturningRow($sql, $params);
        return (int)$row['id'];
    }

    private function insertReturningRow(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Insert did not return a row.');
        }
        return $row;
    }

    private function cleanup(): void
    {
        $this->restoreFeatureSettings();

        foreach (array_reverse($this->createdUserIds) as $userId) {
            $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute([':id' => $userId]);
        }

        foreach ($this->createdFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function uploadPath(string $filename): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . $filename;
    }
}

(new SecuritySmokeTest())->run();
