<?php

require_once __DIR__ . '/../repositories/CharacterRepository.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';
require_once __DIR__ . '/../repositories/ImageRepository.php';
require_once __DIR__ . '/../repositories/PublicationRepository.php';
require_once __DIR__ . '/../repositories/RelationRepository.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../repositories/StoryRepository.php';
require_once __DIR__ . '/../repositories/TemplateRepository.php';
require_once __DIR__ . '/../repositories/WorldRepository.php';

class PublicationService
{
    private CharacterRepository $characterRepository;
    private FilterRepository $filterRepository;
    private ImageRepository $imageRepository;
    private PublicationRepository $publicationRepository;
    private RelationRepository $relationRepository;
    private SocialFeatureSettingsRepository $featureSettingsRepository;
    private StoryRepository $storyRepository;
    private TemplateRepository $templateRepository;
    private WorldRepository $worldRepository;

    public function __construct()
    {
        $this->characterRepository = new CharacterRepository();
        $this->filterRepository = new FilterRepository();
        $this->imageRepository = new ImageRepository();
        $this->publicationRepository = new PublicationRepository();
        $this->relationRepository = new RelationRepository();
        $this->featureSettingsRepository = new SocialFeatureSettingsRepository();
        $this->storyRepository = new StoryRepository();
        $this->templateRepository = new TemplateRepository();
        $this->worldRepository = new WorldRepository();
    }

    public function publishCharacter(int $userId, int $characterId, ?int $variantId = null, string $changeReason = 'initial'): array
    {
        $this->assertPublicationsEnabled();

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $userId);
        if (!$character) {
            throw new InvalidArgumentException('Postac nie zostala znaleziona.', 404);
        }

        if ($this->characterRepository->isHiddenInPath($characterId, $userId)) {
            throw new InvalidArgumentException('Ukrytej postaci nie mozna jeszcze publikowac w pierwszej wersji.', 403);
        }

        $variant = null;
        if ($variantId !== null) {
            $variant = $this->characterRepository->getCharacterVariant($variantId, $characterId);
            if (!$variant) {
                throw new InvalidArgumentException('Wariant nie zostal znaleziony.', 404);
            }
            if (!empty($variant['is_hidden'])) {
                throw new InvalidArgumentException('Ukrytego wariantu nie mozna jeszcze publikowac w pierwszej wersji.', 403);
            }
        }

        $snapshot = $this->buildCharacterSnapshot($userId, $character, $variant);
        $current = $this->publicationRepository->findOwnedCharacterPublication($userId, $characterId, $variantId);
        $reason = $current ? ($current['status'] === 'published' ? 'refresh' : 'refresh') : 'initial';
        if ($changeReason === 'variant_switch') {
            $reason = 'variant_switch';
        }

        return $this->publicationRepository->saveCharacterRevision(
            $userId,
            $characterId,
            $variantId,
            $snapshot['payload'],
            $snapshot['mediaAssetIds'],
            $snapshot['filters'],
            $snapshot['searchText'],
            $reason,
            $snapshot['ageRating']
        );
    }

    public function publishTemplate(int $userId, int $templateId, string $changeReason = 'initial'): array
    {
        $this->assertPublicationsEnabled();

        $template = $this->templateRepository->getTemplateWithFieldsByUserId($templateId, $userId);
        if (!$template) {
            throw new InvalidArgumentException('Szablon nie zostal znaleziony.', 404);
        }

        if (!empty($template['is_hidden'])) {
            throw new InvalidArgumentException('Ukrytego szablonu nie mozna jeszcze publikowac w pierwszej wersji.', 403);
        }

        $snapshot = $this->buildTemplateSnapshot($template);
        $current = $this->publicationRepository->findOwnedTemplatePublication($userId, $templateId);
        $reason = $current ? 'refresh' : 'initial';
        if (in_array($changeReason, ['initial', 'refresh', 'copy'], true)) {
            $reason = $current ? ($changeReason === 'initial' ? 'refresh' : $changeReason) : 'initial';
        }

        return $this->publicationRepository->saveTemplateRevision(
            $userId,
            $templateId,
            $snapshot['payload'],
            $snapshot['filters'],
            $snapshot['searchText'],
            $reason,
            $snapshot['ageRating']
        );
    }

    public function publishImage(int $userId, int $imageAssetId, string $changeReason = 'initial'): array
    {
        $this->assertPublicationsEnabled();

        $image = $this->imageRepository->getAsset($userId, $imageAssetId);
        if (!$image) {
            throw new InvalidArgumentException('Zdjecie nie zostalo znalezione.', 404);
        }

        if (($image['visibility'] ?? 'normal') === 'hidden') {
            throw new InvalidArgumentException('Ukrytego zdjecia nie mozna publikowac.', 403);
        }

        $snapshot = $this->buildImageSnapshot($image);
        $reason = in_array($changeReason, ['initial', 'refresh', 'copy'], true) ? $changeReason : 'initial';

        return $this->publicationRepository->saveImageRevision(
            $userId,
            $imageAssetId,
            $snapshot['payload'],
            $snapshot['filters'],
            $snapshot['searchText'],
            $reason === 'copy' ? 'copy' : 'refresh',
            $snapshot['ageRating']
        );
    }

    public function publishStory(int $userId, int $storyId, string $changeReason = 'initial'): array
    {
        $this->assertPublicationsEnabled();

        $story = $this->storyRepository->getStoryById($storyId);
        if (!$story || $story->getIdUser() !== $userId) {
            throw new InvalidArgumentException('Historia nie zostala znaleziona.', 404);
        }

        if ($story->isHidden()) {
            throw new InvalidArgumentException('Ukrytej historii nie mozna publikowac.', 403);
        }

        $snapshot = $this->buildStorySnapshot($userId, $story);
        $current = $this->publicationRepository->findOwnedStoryPublication($userId, $storyId);
        $reason = $current ? 'refresh' : 'initial';
        if (in_array($changeReason, ['initial', 'refresh', 'copy'], true)) {
            $reason = $current ? ($changeReason === 'initial' ? 'refresh' : $changeReason) : 'initial';
        }

        return $this->publicationRepository->saveStoryRevision(
            $userId,
            $storyId,
            $snapshot['payload'],
            $snapshot['mediaAssetIds'],
            $snapshot['filters'],
            $snapshot['searchText'],
            $reason,
            $snapshot['ageRating']
        );
    }

    public function publishRelationBoard(int $userId, int $boardId, string $changeReason = 'initial'): array
    {
        $this->assertPublicationsEnabled();

        $board = $this->relationRepository->getBoard($userId, $boardId, false);
        if (!$board) {
            throw new InvalidArgumentException('Tablica relacji nie zostala znaleziona albo zawiera ukryte tresci.', 404);
        }

        $snapshot = $this->buildRelationBoardSnapshot($userId, $board);
        $current = $this->publicationRepository->findOwnedRelationBoardPublication($userId, $boardId);
        $reason = $current ? 'refresh' : 'initial';
        if (in_array($changeReason, ['initial', 'refresh', 'copy'], true)) {
            $reason = $current ? ($changeReason === 'initial' ? 'refresh' : $changeReason) : 'initial';
        }

        return $this->publicationRepository->saveRelationBoardRevision(
            $userId,
            $boardId,
            $snapshot['payload'],
            $snapshot['filters'],
            $snapshot['searchText'],
            $reason,
            $snapshot['ageRating']
        );
    }

    public function unpublish(int $userId, int $publicationId): array
    {
        $publication = $this->publicationRepository->unpublishOwned($userId, $publicationId);
        if (!$publication) {
            throw new InvalidArgumentException('Publikacja nie zostala znaleziona.', 404);
        }

        return $publication;
    }

    public function copyPublication(int $userId, string $publicId): array
    {
        $this->assertCopyingEnabled();

        $source = $this->publicationRepository->findCopyableByPublicId($publicId);
        if (!$source) {
            throw new InvalidArgumentException('Publikacja nie zostala znaleziona albo nie mozna jej kopiowac.', 404);
        }

        $payload = is_array($source['payload'] ?? null) ? $source['payload'] : [];
        $contentType = (string)($source['contentType'] ?? $payload['contentType'] ?? '');
        $copyOrigin = $this->copyOriginFromPublication($source);
        $filters = is_array($source['filters'] ?? null) ? $source['filters'] : [];
        $mediaAssetIds = is_array($source['mediaAssetIds'] ?? null) ? $source['mediaAssetIds'] : [];
        $ageRating = (string)($source['ageRating'] ?? 'general') === 'adult' ? 'adult' : 'general';

        if ($contentType === 'character') {
            $characterId = $this->createCharacterFromPublicationPayload($userId, $payload);
            $payload = $this->markCopiedPayload($payload, $copyOrigin);

            $publication = $this->publicationRepository->saveCharacterRevision(
                $userId,
                $characterId,
                null,
                $payload,
                $mediaAssetIds,
                $filters,
                $this->copySearchText($payload, $filters),
                'copy',
                $ageRating,
                $copyOrigin,
                false
            );

            return $this->withPrivateCopyLink($publication, 'character', $characterId, $userId);
        }

        if ($contentType === 'template') {
            $templateId = $this->createTemplateFromPublicationPayload($userId, $payload);
            $payload = $this->markCopiedPayload($payload, $copyOrigin);

            $publication = $this->publicationRepository->saveTemplateRevision(
                $userId,
                $templateId,
                $payload,
                $filters,
                $this->copySearchText($payload, $filters),
                'copy',
                $ageRating,
                $copyOrigin,
                false
            );

            return $this->withPrivateCopyLink($publication, 'template', $templateId, $userId);
        }

        if ($contentType === 'image') {
            $imageAssetId = $this->createImageFromPublicationPayload($userId, $payload, $filters);
            $payload = $this->markCopiedPayload($payload, $copyOrigin);

            $publication = $this->publicationRepository->saveImageRevision(
                $userId,
                $imageAssetId,
                $payload,
                $filters,
                $this->copySearchText($payload, $filters),
                'copy',
                $ageRating,
                $copyOrigin,
                false
            );

            return $this->withPrivateCopyLink($publication, 'image', $imageAssetId, $userId);
        }

        if ($contentType === 'story') {
            $storyId = $this->createStoryFromPublicationPayload($userId, $payload, $filters);
            $payload = $this->markCopiedPayload($payload, $copyOrigin);
            $mediaAssetIds = $this->storyMediaAssetIds($userId, $payload);

            $publication = $this->publicationRepository->saveStoryRevision(
                $userId,
                $storyId,
                $payload,
                $mediaAssetIds,
                $filters,
                $this->copySearchText($payload, $filters),
                'copy',
                $ageRating,
                $copyOrigin,
                false
            );

            return $this->withPrivateCopyLink($publication, 'story', $storyId, $userId);
        }

        if ($contentType === 'relation_board') {
            $boardId = $this->createRelationBoardFromPublicationPayload($userId, $payload);
            $payload = $this->markCopiedPayload($payload, $copyOrigin);

            $publication = $this->publicationRepository->saveRelationBoardRevision(
                $userId,
                $boardId,
                $payload,
                $filters,
                $this->copySearchText($payload, $filters),
                'copy',
                $ageRating,
                $copyOrigin,
                false
            );

            return $this->withPrivateCopyLink($publication, 'relation_board', $boardId, $userId);
        }

        throw new InvalidArgumentException('Ten typ publikacji nie obsluguje jeszcze kopiowania.', 422);
    }

    private function withPrivateCopyLink(array $publication, string $contentType, int $entityId, int $userId): array
    {
        $url = match ($contentType) {
            'character' => $this->privateCharacterUrl($entityId),
            'template' => $this->privateTemplateUrl($entityId),
            'story' => $this->privateStoryUrl($entityId),
            'relation_board' => $this->privateRelationBoardUrl($entityId, $userId),
            'image' => '/gallery',
            default => '',
        };

        if ($url !== '') {
            $publication['privateUrl'] = $url;
            $publication['privateCopyUrl'] = $url;
        }

        return $publication;
    }

    private function privateCharacterUrl(int $characterId): string
    {
        $character = $this->characterRepository->getCharacterById($characterId);
        return $character ? '/character/' . rawurlencode($character->getPublicId()) : '';
    }

    private function privateTemplateUrl(int $templateId): string
    {
        $template = $this->templateRepository->getTemplate($templateId);
        return $template ? '/templates/' . rawurlencode($template->getPublicId()) . '/edit' : '';
    }

    private function privateStoryUrl(int $storyId): string
    {
        $story = $this->storyRepository->getStoryById($storyId);
        return $story ? '/story/' . rawurlencode($story->getPublicId()) : '';
    }

    private function privateRelationBoardUrl(int $boardId, int $userId): string
    {
        $board = $this->relationRepository->getBoard($userId, $boardId);
        $publicId = trim((string)($board['public_id'] ?? ''));
        return $publicId !== '' ? '/relations/' . rawurlencode($publicId) : '';
    }

    private function createCharacterFromPublicationPayload(int $userId, array $payload): int
    {
        $character = is_array($payload['character'] ?? null) ? $payload['character'] : [];
        $name = $this->copiedName((string)($character['name'] ?? 'Skopiowana postac'));
        $fields = is_array($character['fields'] ?? null) ? array_values($character['fields']) : [];
        $templateId = null;
        $fieldValues = [];

        if (!empty($fields)) {
            $templateFields = array_map(static fn(array $field): array => [
                'label' => (string)($field['label'] ?? ''),
                'type' => (string)($field['type'] ?? 'text'),
                'location' => (string)($field['location'] ?? 'left'),
                'placeholder' => '',
            ], $fields);
            $templateId = $this->templateRepository->addTemplate(
                $name . ' - szablon kopii',
                'Szablon utworzony automatycznie z publicznej kopii.',
                $userId,
                $templateFields
            );
        }

        $characterId = $this->characterRepository->addCharacter(
            $name,
            (string)($character['description'] ?? ''),
            basename((string)($character['image'] ?? 'default.png')) ?: 'default.png',
            $userId,
            $templateId,
            null,
            is_array($character['imageDisplay'] ?? null) ? $character['imageDisplay'] : [],
            (string)($character['intro'] ?? '')
        );

        if ($templateId !== null && !empty($fields)) {
            foreach ($this->templateRepository->getTemplateFields($templateId) as $field) {
                $index = (int)($field['order_number'] ?? -1);
                if ($index < 0 || !isset($fields[$index])) {
                    continue;
                }
                $fieldValues[(int)$field['id']] = (string)($fields[$index]['value'] ?? '');
            }

            if (!empty($fieldValues)) {
                $this->characterRepository->saveCharacterFieldValues($characterId, $fieldValues);
            }
        }

        return $characterId;
    }

    private function createTemplateFromPublicationPayload(int $userId, array $payload): int
    {
        $template = is_array($payload['template'] ?? null) ? $payload['template'] : [];
        $fields = array_map(static fn(array $field): array => [
            'label' => (string)($field['label'] ?? ''),
            'type' => (string)($field['type'] ?? 'text'),
            'location' => (string)($field['location'] ?? 'left'),
            'placeholder' => (string)($field['placeholder'] ?? ''),
        ], is_array($template['fields'] ?? null) ? $template['fields'] : []);

        return $this->templateRepository->addTemplate(
            $this->copiedName((string)($template['name'] ?? 'Skopiowany szablon')),
            (string)($template['description'] ?? ''),
            $userId,
            $fields,
            (string)($template['dateCalendarType'] ?? 'real'),
            is_array($template['dateSettings'] ?? null)
                ? json_encode($template['dateSettings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)($template['dateSettings'] ?? ''),
            (string)($template['currentWorldDate'] ?? '')
        );
    }

    private function createImageFromPublicationPayload(int $userId, array $payload, array $filters): int
    {
        $image = is_array($payload['image'] ?? null) ? $payload['image'] : [];
        if (($image['visibility'] ?? 'normal') === 'hidden') {
            throw new InvalidArgumentException('Ukrytego zdjecia nie mozna kopiowac.', 403);
        }

        $asset = $this->imageRepository->copyAssetReferenceForUser($userId, $image, $filters);

        return (int)($asset['id'] ?? 0);
    }

    private function createStoryFromPublicationPayload(int $userId, array $payload, array $filters): int
    {
        $storyPayload = is_array($payload['story'] ?? null) ? $payload['story'] : [];
        $this->copyStoryMediaReferences($userId, $storyPayload, $filters);
        $worldId = $this->copyTargetWorldId($userId);
        $story = new Story(
            idUser: $userId,
            idWorld: $worldId,
            title: $this->copiedName((string)($storyPayload['title'] ?? 'Skopiowana historia')),
            description: (string)($storyPayload['description'] ?? ''),
            storyDate: (string)($storyPayload['storyDate'] ?? ''),
            image: basename((string)($storyPayload['image'] ?? 'default_story.png')) ?: 'default_story.png',
            imageFit: (string)($storyPayload['imageDisplay']['fit'] ?? 'cover'),
            imageFocusX: (int)($storyPayload['imageDisplay']['focusX'] ?? 50),
            imageFocusY: (int)($storyPayload['imageDisplay']['focusY'] ?? 50),
            imageZoom: (float)($storyPayload['imageDisplay']['zoom'] ?? 1),
            cardImageFit: (string)($storyPayload['imageDisplay']['fit'] ?? 'cover'),
            cardImageFocusX: (int)($storyPayload['imageDisplay']['focusX'] ?? 50),
            cardImageFocusY: (int)($storyPayload['imageDisplay']['focusY'] ?? 50),
            cardImageZoom: (float)($storyPayload['imageDisplay']['zoom'] ?? 1),
            status: 'draft'
        );
        $created = $this->storyRepository->createStory($story);
        if (!$created) {
            throw new InvalidArgumentException('Nie udalo sie utworzyc kopii historii.', 500);
        }

        foreach (is_array($storyPayload['fields'] ?? null) ? $storyPayload['fields'] : [] as $index => $field) {
            $fieldType = in_array(($field['type'] ?? 'text'), ['text', 'textarea', 'image', 'dialog', 'section'], true)
                ? (string)$field['type']
                : 'text';
            $createdField = $this->storyRepository->createStoryField(
                $created->getId(),
                (string)($field['label'] ?? 'Pole'),
                $fieldType,
                (int)($field['order'] ?? $index),
                ''
            );
            if ($createdField) {
                $this->storyRepository->updateStoryFieldValue(
                    $created->getId(),
                    (int)$createdField['id'],
                    (string)($field['value'] ?? '')
                );
            }
        }

        return $created->getId();
    }

    private function createRelationBoardFromPublicationPayload(int $userId, array $payload): int
    {
        $board = is_array($payload['relationBoard'] ?? null) ? $payload['relationBoard'] : [];

        return $this->relationRepository->saveBoard(
            $userId,
            null,
            $this->copiedName((string)($board['name'] ?? 'Skopiowane relacje')),
            (string)($board['description'] ?? ''),
            [],
            []
        );
    }

    private function copyOriginFromPublication(array $source): array
    {
        $existingOrigin = is_array($source['copyOrigin'] ?? null) ? $source['copyOrigin'] : [];
        if (!empty($existingOrigin['publicationId'])) {
            return [
                'publicationId' => (int)$existingOrigin['publicationId'],
                'ownerUserId' => !empty($existingOrigin['ownerUserId']) ? (int)$existingOrigin['ownerUserId'] : null,
                'publicId' => (string)($existingOrigin['publicId'] ?? ''),
                'authorName' => (string)($existingOrigin['username'] ?? ''),
                'title' => (string)($existingOrigin['title'] ?? ''),
                'attributionVisible' => true,
            ];
        }

        $author = is_array($source['author'] ?? null) ? $source['author'] : [];
        $card = is_array($source['card'] ?? null) ? $source['card'] : [];

        return [
            'publicationId' => (int)($source['id'] ?? 0),
            'ownerUserId' => (int)($source['ownerUserId'] ?? 0),
            'publicId' => (string)($source['publicId'] ?? ''),
            'authorName' => (string)($author['displayName'] ?? $author['username'] ?? ''),
            'title' => (string)($card['title'] ?? 'Publikacja'),
            'attributionVisible' => !array_key_exists('sourceCopyAttributionEnabled', $source) || !empty($source['sourceCopyAttributionEnabled']),
        ];
    }

    private function markCopiedPayload(array $payload, array $origin): array
    {
        $payload['copy'] = [
            'originPublicationPublicId' => (string)($origin['publicId'] ?? ''),
            'originAuthorName' => (string)($origin['authorName'] ?? ''),
            'originTitle' => (string)($origin['title'] ?? ''),
            'copiedAt' => gmdate('c'),
        ];

        return $payload;
    }

    private function copiedName(string $name): string
    {
        $name = trim($name) !== '' ? trim($name) : 'Kopia';
        return mb_substr($name . ' (kopia)', 0, 180);
    }

    private function copySearchText(array $payload, array $filters): string
    {
        $parts = [];
        $walk = static function ($value) use (&$walk, &$parts): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
                return;
            }

            if (is_scalar($value)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        };

        $walk($payload);
        foreach ($filters as $filter) {
            $parts[] = (string)($filter['name'] ?? '');
            $parts[] = (string)($filter['slug'] ?? '');
            $parts[] = (string)($filter['label'] ?? '');
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, static fn($part) => trim((string)$part) !== ''))));
    }

    private function buildCharacterSnapshot(int $userId, Character $character, ?array $variant): array
    {
        $baseValues = $this->characterRepository->getCharacterFieldValues((int)$character->getId());
        $variantValues = is_array($variant) ? ($variant['values'] ?? []) : [];
        $values = array_replace($baseValues, $variantValues);

        $fields = [];
        if ($character->getIdTemplate()) {
            foreach ($this->templateRepository->getTemplateFields($character->getIdTemplate()) as $field) {
                $fieldId = (int)$field['id'];
                $value = (string)($values[$fieldId] ?? '');
                if ($value === '') {
                    continue;
                }
                $fields[] = [
                    'label' => (string)($field['label'] ?? ''),
                    'type' => (string)($field['field_type'] ?? 'text'),
                    'location' => (string)($field['location'] ?? 'left'),
                    'order' => (int)($field['order_number'] ?? 0),
                    'value' => $value,
                ];
            }
        }

        $image = $this->selectedImage($character, $variant);
        $imageDisplay = $this->selectedImageDisplay($character, $variant);
        $filters = $this->snapshotFilters($character, $variant);
        $ageRating = $this->hasAdultSignal($variant, $filters) ? 'adult' : 'general';

        $payload = [
            'schemaVersion' => 1,
            'contentType' => 'character',
            'source' => [
                'characterPublicId' => $character->getPublicId(),
                'variantName' => is_array($variant) ? (string)($variant['name'] ?? '') : null,
            ],
            'character' => [
                'name' => is_array($variant) && trim((string)($variant['name'] ?? '')) !== ''
                    ? (string)$variant['name']
                    : $character->getName(),
                'baseName' => $character->getName(),
                'intro' => $character->getIntro(),
                'description' => is_array($variant) && array_key_exists('description', $variant) && trim((string)$variant['description']) !== ''
                    ? (string)$variant['description']
                    : $character->getDescription(),
                'image' => $image,
                'imageUrl' => '/media/' . rawurlencode($image),
                'imageDisplay' => $imageDisplay,
                'fields' => $fields,
                'filters' => array_map(fn(array $filter) => [
                    'id' => $filter['id'],
                    'name' => $filter['name'],
                    'slug' => $filter['slug'],
                    'label' => $filter['label'],
                ], $filters),
            ],
            'publishedAt' => gmdate('c'),
        ];

        $mediaAssetIds = [];
        $imageAssetId = $this->publicationRepository->imageAssetIdByFilename($userId, $image);
        if ($imageAssetId !== null) {
            $mediaAssetIds[] = $imageAssetId;
        }

        return [
            'payload' => $payload,
            'mediaAssetIds' => $mediaAssetIds,
            'filters' => $filters,
            'searchText' => $this->searchText($payload, $filters),
            'ageRating' => $ageRating,
        ];
    }

    private function buildTemplateSnapshot(array $template): array
    {
        $filters = $this->snapshotTemplateFilters((int)$template['id']);
        $fields = [];
        foreach (($template['fields'] ?? []) as $field) {
            $fields[] = [
                'label' => (string)($field['label'] ?? ''),
                'type' => (string)($field['field_type'] ?? 'text'),
                'location' => (string)($field['location'] ?? 'left'),
                'order' => (int)($field['order_number'] ?? 0),
                'placeholder' => (string)($field['placeholder'] ?? ''),
            ];
        }

        $payload = [
            'schemaVersion' => 1,
            'contentType' => 'template',
            'source' => [
                'templatePublicId' => (string)($template['public_id'] ?? ''),
            ],
            'template' => [
                'name' => (string)($template['name'] ?? 'Szablon'),
                'description' => (string)($template['description'] ?? ''),
                'dateCalendarType' => (string)($template['date_calendar_type'] ?? 'real'),
                'dateSettings' => (string)($template['date_settings'] ?? ''),
                'currentWorldDate' => (string)($template['current_world_date'] ?? ''),
                'fields' => $fields,
                'filters' => array_map(fn(array $filter) => [
                    'id' => $filter['id'],
                    'name' => $filter['name'],
                    'slug' => $filter['slug'],
                    'label' => $filter['label'],
                ], $filters),
            ],
            'publishedAt' => gmdate('c'),
        ];

        return [
            'payload' => $payload,
            'filters' => $filters,
            'searchText' => $this->templateSearchText($payload, $filters),
            'ageRating' => $this->hasAdultSignal(null, $filters) ? 'adult' : 'general',
        ];
    }

    private function buildImageSnapshot(array $image): array
    {
        $filters = $this->imageRepository->getAssetFilters((int)$image['id']);
        $filename = basename((string)($image['filename'] ?? 'default.png')) ?: 'default.png';
        $title = $this->imageTitle($filename, (string)($image['description'] ?? ''));

        $payload = [
            'schemaVersion' => 1,
            'contentType' => 'image',
            'source' => [
                'imageAssetId' => (int)$image['id'],
            ],
            'image' => [
                'title' => $title,
                'description' => (string)($image['description'] ?? ''),
                'filename' => $filename,
                'imageUrl' => '/media/' . rawurlencode($filename),
                'thumbnailUrl' => (string)($image['thumbnailUrl'] ?? '/media/' . rawurlencode($filename)),
                'mimeType' => (string)($image['mimeType'] ?? ''),
                'sizeBytes' => (int)($image['sizeBytes'] ?? 0),
                'sha256' => (string)($image['sha256'] ?? ''),
                'visibility' => (string)($image['visibility'] ?? 'normal'),
                'filters' => array_map(fn(array $filter) => [
                    'id' => $filter['id'],
                    'name' => $filter['name'],
                    'slug' => $filter['slug'],
                    'label' => $filter['label'],
                ], $filters),
            ],
            'publishedAt' => gmdate('c'),
        ];

        return [
            'payload' => $payload,
            'filters' => $filters,
            'searchText' => $this->imageSearchText($payload, $filters),
            'ageRating' => (string)($image['visibility'] ?? 'normal') === 'adult' || $this->hasAdultSignal(null, $filters)
                ? 'adult'
                : 'general',
        ];
    }

    private function buildStorySnapshot(int $userId, Story $story): array
    {
        $filters = $this->snapshotStoryFilters($userId, $story);
        $redactions = $this->privateStoryCharacterNames($userId, $story->getId());
        $fields = [];
        $imageFilenames = [$story->getImage()];
        $values = $this->storyRepository->getStoryFieldValues($story->getId());

        foreach ($this->storyRepository->getStoryFields($story->getId()) as $field) {
            $fieldType = in_array(($field['field_type'] ?? 'text'), ['text', 'textarea', 'image', 'dialog', 'section'], true)
                ? (string)$field['field_type']
                : 'text';
            $value = (string)($values[(int)$field['id']] ?? '');
            if ($fieldType === 'image' && trim($value) !== '') {
                $imageFilenames[] = basename($value);
            } elseif ($fieldType !== 'image') {
                $value = $this->redactStoryText($value, $redactions);
            }

            $fields[] = [
                'label' => (string)($field['label'] ?? 'Pole'),
                'type' => $fieldType,
                'order' => (int)($field['order_number'] ?? 0),
                'value' => $value,
            ];
        }

        $characters = [];
        foreach ($this->storyRepository->getStoryCharacters($story->getId()) as $character) {
            $characterId = (int)$character['id_character'];
            $variantId = !empty($character['id_variant']) ? (int)$character['id_variant'] : null;
            $public = $this->storyCharacterIsPublic($userId, $characterId, $variantId);
            $basePublic = $this->storyCharacterIsPublic($userId, $characterId, null);
            $publicName = $variantId !== null && trim((string)($character['variant_name'] ?? '')) !== ''
                ? (string)$character['variant_name']
                : (string)($character['character_name'] ?? '');
            $characters[] = [
                'name' => $public ? $publicName : 'UKRYTE',
                'baseName' => $public && $basePublic ? (string)($character['base_character_name'] ?? '') : 'UKRYTE',
                'isRedacted' => !$public,
            ];
        }

        $storyTitle = $this->redactStoryText($story->getTitle(), $redactions);
        $storyDescription = $this->redactStoryText($story->getDescription(), $redactions);
        $image = basename($story->getImage() ?: 'default_story.png') ?: 'default_story.png';
        $payload = [
            'schemaVersion' => 1,
            'contentType' => 'story',
            'source' => [
                'storyPublicId' => $story->getPublicId(),
            ],
            'story' => [
                'title' => $storyTitle,
                'description' => $storyDescription,
                'storyDate' => $story->getStoryDate(),
                'image' => $image,
                'imageUrl' => '/media/' . rawurlencode($image),
                'imageDisplay' => [
                    'fit' => $story->getImageFit(),
                    'focusX' => $story->getImageFocusX(),
                    'focusY' => $story->getImageFocusY(),
                    'zoom' => $story->getImageZoom(),
                ],
                'fields' => $fields,
                'characters' => $characters,
                'filters' => array_map(fn(array $filter) => [
                    'id' => $filter['id'],
                    'name' => $filter['name'],
                    'slug' => $filter['slug'],
                    'label' => $filter['label'],
                ], $filters),
                'mediaAssets' => $this->storyMediaPayload($userId, $imageFilenames),
            ],
            'publishedAt' => gmdate('c'),
        ];

        return [
            'payload' => $payload,
            'mediaAssetIds' => $this->storyMediaAssetIds($userId, $payload),
            'filters' => $filters,
            'searchText' => $this->storySearchText($payload, $filters),
            'ageRating' => $this->hasAdultSignal(null, $filters) ? 'adult' : 'general',
        ];
    }

    private function buildRelationBoardSnapshot(int $userId, array $board): array
    {
        $boardId = (int)$board['id'];
        $nodes = [];
        $nodeMap = [];
        $filters = [];
        $hasAdult = false;

        foreach ($this->relationRepository->getTreeNodes($userId, $boardId, false) as $node) {
            $characterId = (int)$node['character_id'];
            $variantId = !empty($node['variant_id']) ? (int)$node['variant_id'] : null;
            $public = $this->storyCharacterIsPublic($userId, $characterId, $variantId);
            $basePublic = $this->storyCharacterIsPublic($userId, $characterId, null);
            $publicName = $variantId !== null && trim((string)($node['variant_name'] ?? '')) !== ''
                ? (string)$node['variant_name']
                : (string)($node['name'] ?? '');
            $key = (string)($node['entity_key'] ?? ($characterId . ':' . ($variantId ?? 0)));
            $nodeMap[$key] = true;
            $hasAdult = $hasAdult || !empty($node['is_nsfw']);

            $nodes[] = [
                'key' => $key,
                'name' => $public ? $publicName : 'UKRYTE',
                'baseName' => $public && $basePublic ? (string)($node['base_name'] ?? '') : 'UKRYTE',
                'isRedacted' => !$public,
                'image' => $public ? (basename((string)($node['image'] ?? 'default.png')) ?: 'default.png') : 'default.png',
                'imageDisplay' => [
                    'fit' => in_array(($node['image_fit'] ?? 'cover'), ['cover', 'contain'], true) ? (string)$node['image_fit'] : 'cover',
                    'focusX' => max(0, min(100, (int)($node['image_focus_x'] ?? 50))),
                    'focusY' => max(0, min(100, (int)($node['image_focus_y'] ?? 50))),
                    'zoom' => max(1, min(6, (float)($node['image_zoom'] ?? 1))),
                ],
                'x' => (float)($node['position_x'] ?? 0),
                'y' => (float)($node['position_y'] ?? 0),
            ];
        }

        $relations = [];
        foreach ($this->relationRepository->getTreeRelations($userId, $boardId) as $relation) {
            $a = (string)($relation['character_a_key'] ?? '');
            $b = (string)($relation['character_b_key'] ?? '');
            if (!isset($nodeMap[$a], $nodeMap[$b])) {
                continue;
            }
            $hasAdult = $hasAdult || !empty($relation['is_nsfw']);
            $relations[] = [
                'from' => $a,
                'to' => $b,
                'label' => trim((string)($relation['custom_name'] ?? '')) !== ''
                    ? (string)$relation['custom_name']
                    : (string)($relation['type_name'] ?? 'Relacja'),
                'icon' => trim((string)($relation['custom_icon'] ?? '')) !== ''
                    ? (string)$relation['custom_icon']
                    : (string)($relation['icon'] ?? ''),
                'color' => (string)($relation['custom_color_hex'] ?? $relation['color_hex'] ?? '#7B61FF'),
                'note' => (string)($relation['note'] ?? ''),
                'isAdult' => !empty($relation['is_nsfw']),
            ];
        }

        $payload = [
            'schemaVersion' => 1,
            'contentType' => 'relation_board',
            'source' => [
                'boardPublicId' => (string)($board['public_id'] ?? ''),
            ],
            'relationBoard' => [
                'name' => (string)($board['name'] ?? 'Relacje'),
                'description' => (string)($board['description'] ?? ''),
                'nodes' => $nodes,
                'relations' => $relations,
                'filters' => $filters,
            ],
            'publishedAt' => gmdate('c'),
        ];

        return [
            'payload' => $payload,
            'filters' => $filters,
            'searchText' => $this->relationBoardSearchText($payload, $filters),
            'ageRating' => $hasAdult ? 'adult' : 'general',
        ];
    }

    private function imageTitle(string $filename, string $description): string
    {
        $description = trim($description);
        if ($description !== '') {
            return mb_substr($description, 0, 80);
        }

        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = trim(str_replace(['_', '-'], ' ', $name));

        return $name !== '' ? mb_substr($name, 0, 80) : 'Zdjecie';
    }

    private function selectedImage(Character $character, ?array $variant): string
    {
        $variantImage = is_array($variant) ? trim((string)($variant['image'] ?? '')) : '';
        if ($variantImage !== '') {
            return basename($variantImage);
        }

        return basename($character->getImage() ?: 'default.png');
    }

    private function selectedImageDisplay(Character $character, ?array $variant): array
    {
        if (is_array($variant)) {
            return [
                'fit' => in_array(($variant['image_fit'] ?? 'cover'), ['cover', 'contain'], true) ? (string)$variant['image_fit'] : 'cover',
                'focusX' => max(0, min(100, (int)($variant['image_focus_x'] ?? 50))),
                'focusY' => max(0, min(100, (int)($variant['image_focus_y'] ?? 50))),
                'zoom' => max(1, min(6, (float)($variant['image_zoom'] ?? 1))),
            ];
        }

        return [
            'mode' => $character->getImageDisplayMode(),
            'fit' => $character->getImageFit(),
            'focusX' => $character->getImageFocusX(),
            'focusY' => $character->getImageFocusY(),
            'zoom' => $character->getImageZoom(),
        ];
    }

    private function snapshotFilters(Character $character, ?array $variant): array
    {
        $filters = [];
        foreach ($this->filterRepository->getAllCharacterFilters((int)$character->getId()) as $filter) {
            $filters[] = [
                'id' => (int)$filter->getId(),
                'name' => $filter->getName(),
                'slug' => $filter->getSlug(),
                'label' => $filter->getName(),
            ];
        }

        if (is_array($variant)) {
            foreach (($variant['content_filters'] ?? []) as $filter) {
                $filters[] = [
                    'id' => (int)($filter['id'] ?? 0),
                    'name' => (string)($filter['name'] ?? $filter['label'] ?? ''),
                    'slug' => (string)($filter['slug'] ?? $filter['name'] ?? ''),
                    'label' => (string)($filter['label'] ?? $filter['name'] ?? ''),
                ];
            }
        }

        $unique = [];
        foreach ($filters as $filter) {
            if (($filter['id'] ?? 0) <= 0 || isset($unique[$filter['id']])) {
                continue;
            }
            $unique[$filter['id']] = $filter;
        }

        return array_values($unique);
    }

    private function snapshotTemplateFilters(int $templateId): array
    {
        $filters = [];
        foreach ($this->filterRepository->getObjectFilters('template', $templateId) as $filter) {
            $filters[] = [
                'id' => (int)$filter->getId(),
                'name' => $filter->getName(),
                'slug' => $filter->getSlug(),
                'label' => $filter->getName(),
            ];
        }

        return $filters;
    }

    private function snapshotStoryFilters(int $userId, Story $story): array
    {
        $filters = [];
        $inherited = $this->storyRepository->getInheritedFiltersByStoryIds([$story->getId()], $userId);
        foreach ($inherited[$story->getId()] ?? [] as $filter) {
            $filters[] = $this->filterObjectToArray($filter);
        }
        foreach ($this->filterRepository->getObjectFilters('story', $story->getId()) as $filter) {
            $filters[] = $this->filterObjectToArray($filter);
        }
        foreach ($this->filterRepository->getWorldAndAncestorFilters($story->getIdWorld(), $userId) as $filter) {
            $filters[] = $this->filterObjectToArray($filter);
        }

        return $this->uniqueFilterArrays($filters);
    }

    private function filterObjectToArray(object $filter): array
    {
        return [
            'id' => method_exists($filter, 'getId') ? (int)$filter->getId() : 0,
            'name' => method_exists($filter, 'getName') ? (string)$filter->getName() : '',
            'slug' => method_exists($filter, 'getSlug') ? (string)$filter->getSlug() : '',
            'label' => method_exists($filter, 'getName') ? (string)$filter->getName() : '',
        ];
    }

    private function uniqueFilterArrays(array $filters): array
    {
        $unique = [];
        foreach ($filters as $filter) {
            $id = (int)($filter['id'] ?? 0);
            $key = $id > 0 ? 'id:' . $id : 'label:' . mb_strtolower((string)($filter['label'] ?? $filter['name'] ?? ''));
            if ($key === 'label:' || isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $filter;
        }

        return array_values($unique);
    }

    private function privateStoryCharacterNames(int $userId, int $storyId): array
    {
        $names = [];
        foreach ($this->storyRepository->getStoryCharacters($storyId) as $character) {
            $characterId = (int)$character['id_character'];
            $variantId = !empty($character['id_variant']) ? (int)$character['id_variant'] : null;
            $entityPublic = $this->storyCharacterIsPublic($userId, $characterId, $variantId);
            $basePublic = $this->storyCharacterIsPublic($userId, $characterId, null);

            if ($entityPublic && $basePublic) {
                continue;
            }

            if (!$entityPublic) {
                foreach (['character_name', 'base_character_name', 'variant_name'] as $key) {
                    $name = trim((string)($character[$key] ?? ''));
                    if (mb_strlen($name) >= 2) {
                        $names[] = $name;
                    }
                }
                continue;
            }

            if ($variantId !== null && !$basePublic) {
                $name = trim((string)($character['base_character_name'] ?? ''));
                if (mb_strlen($name) >= 2) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function storyCharacterIsPublic(int $userId, int $characterId, ?int $variantId): bool
    {
        $publication = $this->publicationRepository->findOwnedCharacterPublication($userId, $characterId, $variantId);

        return $publication !== null
            && ($publication['status'] ?? '') === 'published'
            && ($publication['moderationState'] ?? '') === 'visible';
    }

    private function redactStoryText(string $text, array $redactions): string
    {
        foreach ($redactions as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }
            $text = str_ireplace($name, 'UKRYTE', $text);
        }

        return $text;
    }

    private function storyMediaPayload(int $userId, array $filenames): array
    {
        $assets = [];
        foreach (array_values(array_unique(array_filter(array_map(
            static fn($filename): string => basename((string)$filename),
            $filenames
        )))) as $filename) {
            $asset = $this->imageRepository->getAssetByFilename($userId, $filename);
            if (!$asset) {
                continue;
            }
            $assets[] = [
                'filename' => (string)$asset['filename'],
                'mimeType' => (string)($asset['mimeType'] ?? ''),
                'sizeBytes' => (int)($asset['sizeBytes'] ?? 0),
                'sha256' => (string)($asset['sha256'] ?? ''),
                'visibility' => (string)($asset['visibility'] ?? 'normal'),
            ];
        }

        return $assets;
    }

    private function storyMediaAssetIds(int $userId, array $payload): array
    {
        $story = is_array($payload['story'] ?? null) ? $payload['story'] : [];
        $filenames = [(string)($story['image'] ?? '')];
        foreach (is_array($story['fields'] ?? null) ? $story['fields'] : [] as $field) {
            if (($field['type'] ?? '') === 'image') {
                $filenames[] = (string)($field['value'] ?? '');
            }
        }

        $ids = [];
        foreach ($filenames as $filename) {
            $id = $this->publicationRepository->imageAssetIdByFilename($userId, basename($filename));
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function copyStoryMediaReferences(int $userId, array $storyPayload, array $filters): void
    {
        foreach (is_array($storyPayload['mediaAssets'] ?? null) ? $storyPayload['mediaAssets'] : [] as $asset) {
            if (is_array($asset)) {
                $this->imageRepository->copyAssetReferenceForUser($userId, $asset, $filters);
            }
        }
    }

    private function copyTargetWorldId(int $userId): int
    {
        $worlds = $this->worldRepository->getWorldsByUserId($userId, true);
        if (!empty($worlds)) {
            return (int)$worlds[0]->getId();
        }

        return $this->worldRepository->addWorld(
            'Kopie spolecznosciowe',
            'Automatyczny folder dla tresci skopiowanych ze spolecznosci.',
            $userId,
            null,
            'default.jpg'
        );
    }

    private function hasAdultSignal(?array $variant, array $filters): bool
    {
        if (is_array($variant) && !empty($variant['is_adult'])) {
            return true;
        }

        foreach ($filters as $filter) {
            foreach (['name', 'slug', 'label'] as $key) {
                $value = mb_strtolower(trim((string)($filter[$key] ?? '')));
                if (in_array($value, ['adult', 'nsfw', '+18', '18+'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function searchText(array $payload, array $filters): string
    {
        $parts = [
            $payload['character']['name'] ?? '',
            $payload['character']['baseName'] ?? '',
            $payload['character']['intro'] ?? '',
            $payload['character']['description'] ?? '',
            $payload['source']['variantName'] ?? '',
        ];

        foreach (($payload['character']['fields'] ?? []) as $field) {
            $parts[] = (string)($field['label'] ?? '');
            $parts[] = (string)($field['value'] ?? '');
        }

        foreach ($filters as $filter) {
            $parts[] = (string)($filter['name'] ?? '');
            $parts[] = (string)($filter['slug'] ?? '');
            $parts[] = (string)($filter['label'] ?? '');
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, fn($part) => trim((string)$part) !== ''))));
    }

    private function templateSearchText(array $payload, array $filters): string
    {
        $template = is_array($payload['template'] ?? null) ? $payload['template'] : [];
        $parts = [
            $template['name'] ?? '',
            $template['description'] ?? '',
            $template['dateCalendarType'] ?? '',
            $template['currentWorldDate'] ?? '',
        ];

        foreach (($template['fields'] ?? []) as $field) {
            $parts[] = (string)($field['label'] ?? '');
            $parts[] = (string)($field['type'] ?? '');
            $parts[] = (string)($field['placeholder'] ?? '');
        }

        foreach ($filters as $filter) {
            $parts[] = (string)($filter['name'] ?? '');
            $parts[] = (string)($filter['slug'] ?? '');
            $parts[] = (string)($filter['label'] ?? '');
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, fn($part) => trim((string)$part) !== ''))));
    }

    private function imageSearchText(array $payload, array $filters): string
    {
        $image = is_array($payload['image'] ?? null) ? $payload['image'] : [];
        $parts = [
            $image['title'] ?? '',
            $image['description'] ?? '',
            $image['filename'] ?? '',
            $image['visibility'] ?? '',
        ];

        foreach ($filters as $filter) {
            $parts[] = (string)($filter['name'] ?? '');
            $parts[] = (string)($filter['slug'] ?? '');
            $parts[] = (string)($filter['label'] ?? '');
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, fn($part) => trim((string)$part) !== ''))));
    }

    private function storySearchText(array $payload, array $filters): string
    {
        $story = is_array($payload['story'] ?? null) ? $payload['story'] : [];
        $parts = [
            $story['title'] ?? '',
            $story['description'] ?? '',
            $story['storyDate'] ?? '',
        ];

        foreach (($story['fields'] ?? []) as $field) {
            $parts[] = (string)($field['label'] ?? '');
            if (($field['type'] ?? '') !== 'image') {
                $parts[] = (string)($field['value'] ?? '');
            }
        }
        foreach (($story['characters'] ?? []) as $character) {
            if (empty($character['isRedacted'])) {
                $parts[] = (string)($character['name'] ?? '');
                $parts[] = (string)($character['baseName'] ?? '');
            }
        }
        foreach ($filters as $filter) {
            $parts[] = (string)($filter['name'] ?? '');
            $parts[] = (string)($filter['slug'] ?? '');
            $parts[] = (string)($filter['label'] ?? '');
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, fn($part) => trim((string)$part) !== ''))));
    }

    private function relationBoardSearchText(array $payload, array $filters): string
    {
        $board = is_array($payload['relationBoard'] ?? null) ? $payload['relationBoard'] : [];
        $parts = [
            $board['name'] ?? '',
            $board['description'] ?? '',
        ];

        foreach (($board['nodes'] ?? []) as $node) {
            if (empty($node['isRedacted'])) {
                $parts[] = (string)($node['name'] ?? '');
                $parts[] = (string)($node['baseName'] ?? '');
            }
        }
        foreach (($board['relations'] ?? []) as $relation) {
            $parts[] = (string)($relation['label'] ?? '');
            $parts[] = (string)($relation['note'] ?? '');
        }
        foreach ($filters as $filter) {
            $parts[] = (string)($filter['name'] ?? '');
            $parts[] = (string)($filter['slug'] ?? '');
            $parts[] = (string)($filter['label'] ?? '');
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts, fn($part) => trim((string)$part) !== ''))));
    }

    private function assertPublicationsEnabled(): void
    {
        if (!$this->featureSettingsRepository->isEnabled('community.enabled')
            || !$this->featureSettingsRepository->isEnabled('publications.enabled')) {
            throw new InvalidArgumentException('Publikacje sa obecnie wylaczone przez administracje.', 403);
        }
    }

    private function assertCopyingEnabled(): void
    {
        $this->assertPublicationsEnabled();

        if (!$this->featureSettingsRepository->isEnabled('copying.enabled')) {
            throw new InvalidArgumentException('Kopiowanie jest obecnie wylaczone przez administracje.', 403);
        }
    }
}
