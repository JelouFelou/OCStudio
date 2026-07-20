<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/CharacterRepository.php';
require_once __DIR__ . '/../repositories/TemplateRepository.php';
require_once __DIR__ . '/../repositories/WorldRepository.php';
require_once __DIR__ . '/../repositories/CharacterStatusRepository.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';
require_once __DIR__ . '/../repositories/RelationRepository.php';
require_once __DIR__ . '/../repositories/ImageRepository.php';
require_once __DIR__ . '/../repositories/StoryRepository.php';
require_once __DIR__ . '/../repositories/PublicationRepository.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../services/CharacterFieldUploadService.php';

class CharacterController extends AppController
{
    private $characterRepository;
    private $templateRepository;
    private $worldRepository;
    private $statusRepository;
    private $filterRepository;
    private $relationRepository;
    private $imageRepository;
    private $storyRepository;
    private $publicationRepository;
    private $socialFeatureSettingsRepository;
    private CharacterFieldUploadService $characterFieldUploadService;

    public function __construct()
    {
        $this->characterRepository = new CharacterRepository();
        $this->templateRepository  = new TemplateRepository();
        $this->worldRepository     = new WorldRepository();
        $this->statusRepository    = new CharacterStatusRepository();
        $this->filterRepository    = new FilterRepository();
        $this->relationRepository  = new RelationRepository();
        $this->imageRepository     = new ImageRepository();
        $this->storyRepository     = new StoryRepository();
        $this->publicationRepository = new PublicationRepository();
        $this->socialFeatureSettingsRepository = new SocialFeatureSettingsRepository();
        $this->characterFieldUploadService = new CharacterFieldUploadService($this->imageRepository, $this->filterRepository);
    }

    /**
     * Zamienia wartość POST na ?int – pusty string i "0" traktuje jako null.
     */
    private function parseTemplateId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === '0') {
            return null;
        }
        $int = (int) $raw;
        return $int > 0 ? $int : null;
    }

    private function visibleTemplatesForUser(int $userId): array
    {
        return $this->templateRepository->getTemplatesByUserId(
            $userId,
            $this->filterRepository->blockedFilterIds($userId),
            !empty($this->getUserInterfaceSettings()['revealHidden'])
        );
    }

    private function imageDisplayFromCharacter(Character $character): array
    {
        return [
            'mode' => $character->getImageDisplayMode(),
            'fit' => $character->getImageFit(),
            'focusX' => $character->getImageFocusX(),
            'focusY' => $character->getImageFocusY(),
            'zoom' => $character->getImageZoom(),
        ];
    }

    private function imageDisplayFromPost(): array
    {
        return [
            'mode' => $_POST['image_display_mode'] ?? 'square',
            'fit' => $_POST['image_fit'] ?? 'cover',
            'focusX' => $_POST['image_focus_x'] ?? 50,
            'focusY' => $_POST['image_focus_y'] ?? 50,
            'zoom' => $_POST['image_zoom'] ?? 1,
        ];
    }

    private function variantFilterIdsFromPost(array $postedVariants): array
    {
        $mapped = [];
        foreach ($postedVariants as $key => $variant) {
            if (!is_array($variant) || trim((string)($variant['name'] ?? '')) === '') {
                continue;
            }
            $rawTags = trim((string)($variant['content_tags'] ?? ''));
            if ($rawTags === '') {
                $mapped[(string)$key] = [];
                continue;
            }

            $resolved = $this->filterRepository->validateMinimumTags($rawTags);
            $mapped[(string)$key] = array_map(fn($tag) => (int)$tag['id'], $resolved);
        }
        return $mapped;
    }

    private function resolvedTagsContainNsfw(array $tags): bool
    {
        foreach ($tags as $tag) {
            foreach (['slug', 'name', 'label'] as $key) {
                $value = mb_strtolower(trim((string)($tag[$key] ?? '')));
                if (in_array($value, ['adult', 'nsfw', '+18', '18+'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function filtersHaveBlocked(array $filters, array $blockedFilterIds): bool
    {
        $blockedFilterIds = array_values(array_unique(array_filter(array_map('intval', $blockedFilterIds))));
        if (empty($blockedFilterIds)) {
            return false;
        }

        foreach ($filters as $filter) {
            if (is_array($filter)) {
                $id = (int)($filter['id'] ?? 0);
            } elseif (is_object($filter) && method_exists($filter, 'getId')) {
                $id = (int)$filter->getId();
            } else {
                $id = 0;
            }
            if ($id > 0 && in_array($id, $blockedFilterIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function safeReturnUrl(?string $url, string $fallback = '/dashboard'): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return $fallback;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $fallback;
        }

        if (isset($parts['host']) && strcasecmp($parts['host'], $_SERVER['HTTP_HOST'] ?? '') !== 0) {
            return $fallback;
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/') {
            return $fallback;
        }

        if (strpos($path, '/createCharacter') === 0 || strpos($path, '/editCharacter') === 0) {
            return $fallback;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $path . $query;
    }

    private function characterReturnUrl(string $fallback = '/dashboard'): string
    {
        return $this->safeReturnUrl(
            $_POST['return_url'] ?? $_GET['return_url'] ?? $_SERVER['HTTP_REFERER'] ?? '',
            $fallback
        );
    }

    private function ownedWorldIdOrNull(mixed $raw, int $userId): ?int
    {
        if ($raw === null || $raw === '' || $raw === '0') {
            return null;
        }

        $world = ctype_digit((string)$raw)
            ? $this->worldRepository->getWorldByIdAndUserId((int)$raw, $userId)
            : $this->worldRepository->getWorldByPublicIdAndUserId((string)$raw, $userId);

        if (!$world) {
            throw new InvalidArgumentException('Nieprawidlowy folder.');
        }

        return $world->getId();
    }

    private function splitTagInput(array|string $tags): array
    {
        $tokens = is_array($tags) ? $tags : explode(',', (string)$tags);
        return array_values(array_filter(array_map(
            fn($tag) => trim((string)$tag),
            $tokens
        )));
    }

    private function filtersContainNsfw(array $filters): bool
    {
        foreach ($filters as $filter) {
            $values = is_array($filter)
                ? [$filter['slug'] ?? '', $filter['name'] ?? '', $filter['label'] ?? '']
                : [
                    is_object($filter) && method_exists($filter, 'getSlug') ? $filter->getSlug() : '',
                    is_object($filter) && method_exists($filter, 'getName') ? $filter->getName() : '',
                    is_object($filter) && method_exists($filter, 'getLabel') ? $filter->getLabel() : '',
                ];
            foreach ($values as $value) {
                if (in_array(mb_strtolower(trim((string)$value)), ['adult', 'nsfw', '+18', '18+'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function characterFromPublicOrLegacyId(mixed $raw, int $userId): ?Character
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }

        return ctype_digit($raw)
            ? $this->characterRepository->getCharacterByIdAndUserId((int)$raw, $userId)
            : $this->characterRepository->getCharacterByPublicIdAndUserId($raw, $userId);
    }

    private function ownedTemplateIdOrNull(mixed $raw, int $userId): ?int
    {
        $templateId = $this->parseTemplateId($raw);
        if ($templateId === null) {
            return null;
        }

        if (!$this->templateRepository->getTemplateByIdAndUserId($templateId, $userId)) {
            throw new InvalidArgumentException('Nieprawidlowy szablon postaci.');
        }

        return $templateId;
    }

    private function validateStatsFieldValues(?int $templateId, array $fieldValues): void
    {
        if (!$templateId) {
            return;
        }

        foreach ($this->templateRepository->getTemplateFields($templateId) as $field) {
            if (($field['field_type'] ?? '') !== 'stats') {
                continue;
            }

            $cfg = json_decode((string)($field['placeholder'] ?? '{}'), true);
            $maxPoints = max(0, (int)($cfg['maxPoints'] ?? 0));
            $rows = [];
            foreach (($cfg['rows'] ?? []) as $row) {
                if (is_array($row)) {
                    $label = (string)($row['label'] ?? $row['name'] ?? '');
                    if ($label !== '') {
                        $rows[] = ['key' => (string)($row['key'] ?? $label), 'label' => $label];
                    }
                } else {
                    $label = (string)$row;
                    if ($label !== '') {
                        $rows[] = ['key' => $label, 'label' => $label];
                    }
                }
            }
            $values = json_decode((string)($fieldValues[$field['id']] ?? '{}'), true);
            if (!is_array($values)) {
                $values = [];
            }

            $sum = 0;
            foreach ($rows as $row) {
                $value = (int)($values[$row['key']] ?? $values[$row['label']] ?? 0);
                if ($value < 0) {
                    throw new InvalidArgumentException('Statystyki nie mogą mieć wartości poniżej 0.');
                }
                $sum += $value;
            }

            if ($sum !== $maxPoints) {
                throw new InvalidArgumentException("Statystyki muszą wykorzystywać dokładnie {$maxPoints} pkt.");
            }
        }
    }

    // -----------------------------------------------------------------------
    //  Widok "Postacie" – nawigacja jak Dysk Google
    // -----------------------------------------------------------------------

    public function characters()
    {
        $this->requireLogin();
        $userId = $_SESSION['user_id'];
        $includeHidden = !empty($this->getUserInterfaceSettings()['revealHidden']);

        // Aktualny folder; null = folder główny
        $worldParam = $_GET['world'] ?? null;
        $worldId = null;

        // Sprawdź czy folder należy do użytkownika (tylko gdy nie-root)
        $currentWorld = null;
        if ($worldParam !== null && $worldParam !== '') {
            $currentWorld = ctype_digit((string)$worldParam)
                ? $this->worldRepository->getWorldByIdAndUserId((int)$worldParam, $userId)
                : $this->worldRepository->getWorldByPublicIdAndUserId((string)$worldParam, $userId);
            $worldId = $currentWorld ? $currentWorld->getId() : null;
            if (!$currentWorld || (!$includeHidden && $this->worldRepository->isHiddenInPath($worldId, (int)$userId))) {
                // Folder nie istnieje lub nie należy do usera – wróć do root
                header('Location: /characters');
                exit();
            }
        }

        // Podfoldery aktualnego folderu
        $subfolders = $this->worldRepository->getChildWorlds($userId, $worldId, $includeHidden);

        // Postacie w aktualnym folderze
        $blockedFilterIds = $this->filterRepository->blockedFilterIds((int)$userId);
        $includeAdult = !empty($this->getUserInterfaceSettings()['revealAdultImages']);
        $characters = $this->characterRepository->getCharactersByWorld($userId, $worldId, $blockedFilterIds, $includeHidden, $includeAdult);
        $variantsByCharacterId = $this->characterRepository->getCharacterVariantsByCharacterIds(
            array_map(fn($character) => $character->getId(), $characters),
            $includeHidden,
            $includeAdult
        );
        foreach ($variantsByCharacterId as $characterId => $variants) {
            $variantsByCharacterId[$characterId] = array_values(array_filter(
                $variants,
                fn($variant) => !$this->filtersHaveBlocked($variant['content_filters'] ?? [], $blockedFilterIds)
            ));
        }
        $characterFiltersById = [];
        foreach ($characters as $character) {
            $characterFiltersById[$character->getId()] = $this->filterRepository->getAllCharacterFilters($character->getId());
        }
        $publicationMap = $this->publicationRepository->ownedCharacterPublicationMap(
            (int)$userId,
            array_map(fn($character) => $character->getId(), $characters)
        );

        // Breadcrumb (pusta tablica gdy jesteśmy w root)
        $breadcrumb = $worldId !== null
            ? $this->worldRepository->getBreadcrumb($worldId, $userId)
            : [];

        $allStatuses = $this->statusRepository->getAllStatuses();
        $relationCounts = $this->relationRepository->countRelationsForCharacters(
            (int)$userId,
            array_map(fn($character) => $character->getId(), $characters)
        );
        $currentWorldFilters = $worldId !== null
            ? $this->filterRepository->getWorldAndAncestorFilters($worldId, (int)$userId)
            : [];
        $currentWorldDirectFilters = $worldId !== null
            ? $this->filterRepository->getWorldFilters($worldId)
            : [];
        $worldFiltersById = [];
        $worldDirectFiltersById = [];
        foreach ($subfolders as $folder) {
            $worldFiltersById[$folder->getId()] = $this->filterRepository->getWorldAndAncestorFilters($folder->getId(), (int)$userId);
            $worldDirectFiltersById[$folder->getId()] = $this->filterRepository->getWorldFilters($folder->getId());
        }
        $currentWorldRelationBoards = $worldId !== null
            ? $this->relationRepository->getBoardsForWorld((int)$userId, $worldId, $includeHidden)
            : [];

        return $this->render('characters', [
            'title'        => 'Postacie - OCStudio',
            'characters'   => $characters,
            'variantsByCharacterId' => $variantsByCharacterId,
            'characterFiltersById' => $characterFiltersById,
            'characterPublicationMap' => $publicationMap,
            'blockedFilterIds' => $blockedFilterIds,
            'subfolders'   => $subfolders,
            'currentWorld' => $currentWorld,
            'breadcrumb'   => $breadcrumb,
            'statuses'     => $allStatuses,
            'relationCounts' => $relationCounts,
            'currentWorldFilters' => $currentWorldFilters,
            'currentWorldDirectFilters' => $currentWorldDirectFilters,
            'worldFiltersById' => $worldFiltersById,
            'worldDirectFiltersById' => $worldDirectFiltersById,
            'currentWorldHasNsfw' => $this->filtersContainNsfw($currentWorldFilters),
            'currentWorldRelationBoards' => $currentWorldRelationBoards,
        ]);
    }

    // -----------------------------------------------------------------------
    //  API: utwórz folder (world)
    // -----------------------------------------------------------------------

    public function createWorld()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Tworzenie postaci i folderow jest obecnie wylaczone.', true);

        $input = $this->requireJsonPost();
        if (!$input || !isset($input['name']) || trim($input['name']) === '') {
            $this->jsonError('Brak nazwy folderu');
        }

        $name     = trim($input['name']);
        $description = trim((string)($input['description'] ?? ''));
        $image = 'default.jpg';
        $imageId = (int)($input['imageId'] ?? 0);
        if ($imageId > 0) {
            $asset = $this->imageRepository->getAsset((int)$_SESSION['user_id'], $imageId);
            if ($asset) {
                $image = $asset['filename'];
            }
        }
        $parentId = isset($input['parentId']) ? (int)$input['parentId'] : null;
        if ($parentId === 0) {
            $parentId = null;
        }

        // Sprawdź czy parent należy do usera
        if ($parentId !== null) {
            $parent = $this->worldRepository->getWorldByIdAndUserId($parentId, $_SESSION['user_id']);
            if (!$parent) {
                $this->jsonError('Nieprawidłowy folder nadrzędny', 403);
            }
        }

        $worldId = $this->worldRepository->addWorld($name, $description, $_SESSION['user_id'], $parentId, $image);
        $this->worldRepository->updateWorldEffect(
            $worldId,
            (int)$_SESSION['user_id'],
            (string)($input['backgroundEffect'] ?? 'none'),
            (string)($input['effectSymbols'] ?? ''),
            (string)($input['effectIntensity'] ?? 'medium'),
            (string)($input['effectSize'] ?? 'medium'),
            (string)($input['effectLayer'] ?? 'under')
        );
        $folderTags = $this->filterRepository->resolveTags($input['filterTags'] ?? []);
        $this->filterRepository->replaceObjectFilters('world', $worldId, array_map(fn($tag) => (int)$tag['id'], $folderTags));

        $this->jsonResponse(['success' => true, 'id' => $worldId, 'name' => $name]);
    }

    public function renameWorld()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja folderow jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        $worldId = (int)($input['worldId'] ?? 0);
        $name = trim($input['name'] ?? '');
        $description = trim((string)($input['description'] ?? ''));

        if ($worldId <= 0 || $name === '') {
            $this->jsonError('Brak wymaganych parametrów');
        }

        $world = $this->worldRepository->getWorldByIdAndUserId($worldId, $_SESSION['user_id']);
        if (!$world) {
            $this->jsonError('Folder nie znaleziony', 404);
        }

        $image = $world->getImage();
        if (array_key_exists('imageId', $input)) {
            $imageId = (int)($input['imageId'] ?? 0);
            if ($imageId > 0) {
                $asset = $this->imageRepository->getAsset((int)$_SESSION['user_id'], $imageId);
                if ($asset) {
                    $image = $asset['filename'];
                }
            } else {
                $image = 'default.jpg';
            }
        }

        $this->worldRepository->updateWorldDetails($worldId, $_SESSION['user_id'], $name, $description, $image);
        if (array_key_exists('backgroundEffect', $input) || array_key_exists('effectSymbols', $input) || array_key_exists('effectIntensity', $input) || array_key_exists('effectSize', $input) || array_key_exists('effectLayer', $input)) {
            $this->worldRepository->updateWorldEffect(
                $worldId,
                (int)$_SESSION['user_id'],
                (string)($input['backgroundEffect'] ?? $world->getBackgroundEffect()),
                (string)($input['effectSymbols'] ?? $world->getEffectSymbols()),
                (string)($input['effectIntensity'] ?? $world->getEffectIntensity()),
                (string)($input['effectSize'] ?? $world->getEffectSize()),
                (string)($input['effectLayer'] ?? $world->getEffectLayer())
            );
        }
        if (array_key_exists('filterTags', $input)) {
            $folderTags = $this->filterRepository->resolveTags($input['filterTags'] ?? []);
            $this->filterRepository->replaceObjectFilters('world', $worldId, array_map(fn($tag) => (int)$tag['id'], $folderTags));
        }
        $this->jsonResponse(['success' => true]);
    }

    public function deleteWorld()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Usuwanie folderow jest obecnie wylaczone.', true);

        $input = $this->requireJsonPost();
        $worldId = (int)($input['worldId'] ?? 0);
        $confirmation = trim($input['confirmation'] ?? '');

        $world = $worldId > 0
            ? $this->worldRepository->getWorldByIdAndUserId($worldId, $_SESSION['user_id'])
            : null;

        if (!$world) {
            $this->jsonError('Folder nie znaleziony', 404);
        }

        if ($confirmation !== $world->getName()) {
            $this->jsonError('Wpisana nazwa folderu nie zgadza się');
        }

        $worldIds = $this->worldRepository->getDescendantWorldIds($worldId, $_SESSION['user_id']);
        $this->worldRepository->moveCharactersFromWorldsToRoot($_SESSION['user_id'], $worldIds);
        $this->worldRepository->deleteWorld($worldId, $_SESSION['user_id']);

        $this->jsonResponse(['success' => true]);
    }

    // -----------------------------------------------------------------------
    //  API: przypisz postać do folderu
    // -----------------------------------------------------------------------

    public function assignCharacterToWorld()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja postaci jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        if (!$input || !array_key_exists('characterId', $input) || !array_key_exists('worldId', $input)) {
            $this->jsonError('Brak wymaganych parametrów');
        }

        $characterId = (int) $input['characterId'];
        try {
            $worldId = $this->ownedWorldIdOrNull($input['worldId'], (int)$_SESSION['user_id']);
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 403);
        }

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);
        if (!$character) {
            $this->jsonError('Postać nie znaleziona', 404);
        }

        $this->characterRepository->updateCharacter(
            $characterId,
            $character->getName(),
            $character->getDescription(),
            $character->getImage(),
            $character->getIdTemplate(),
            $worldId,
            $this->imageDisplayFromCharacter($character),
            $character->getIntro()
        );

        $this->jsonResponse(['success' => true]);
    }

    // -----------------------------------------------------------------------
    //  Pozostałe akcje (niezmienione)
    // -----------------------------------------------------------------------

    public function createCharacter()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Tworzenie postaci jest obecnie wylaczone.');
        $this->characterFieldUploadService->setUploadsEnabled($this->isFeatureEnabled('gallery.enabled'));
        $returnUrl = $this->characterReturnUrl('/characters');

        if ($this->isPost()) {
            $name        = $_POST['character_name']        ?? '';
            $intro       = $_POST['character_intro']       ?? '';
            $description = $_POST['character_description'] ?? '';
            $userId      = $_SESSION['user_id'];
            $worldId     = null;
            $inheritedWorldFilters = [];

            try {
                $templateId = $this->ownedTemplateIdOrNull($_POST['template_id'] ?? null, (int)$userId);
                $worldId = $this->ownedWorldIdOrNull($_POST['world_id'] ?? null, (int)$userId);
                $inheritedWorldFilters = $worldId !== null
                    ? $this->filterRepository->getWorldAndAncestorFilters($worldId, (int)$userId)
                    : [];
                $contentTags = $this->filterRepository->validateMinimumTags(
                    array_merge(
                        $this->splitTagInput($_POST['content_tags'] ?? ''),
                        array_map(fn($filter) => $filter->getName(), $inheritedWorldFilters)
                    )
                );
                $this->validateStatsFieldValues($templateId, $_POST['field_values'] ?? []);
            } catch (Throwable $e) {
                return $this->render('create_character', [
                    'title'                => 'Stworz postac - OCStudio',
                    'templates'            => $this->visibleTemplatesForUser((int)$_SESSION['user_id']),
                    'fields'               => isset($templateId) && $templateId ? $this->templateRepository->getTemplateFields($templateId) : [],
                    'characterFieldValues' => is_array($_POST['field_values'] ?? null) ? $_POST['field_values'] : [],
                    'oldInput'             => $_POST,
                    'returnUrl'            => $returnUrl,
                    'inheritedWorldFilters' => $inheritedWorldFilters,
                    'messages'             => [$e->getMessage()]
                ]);
            }

            try {
                $image = $this->characterFieldUploadService->uploadCharacterImage((int)$userId, 'default.png', $_POST, $_FILES);
            } catch (Throwable $e) {
                http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 400);
                return $this->render('create_character', [
                    'title'                => 'Stworz postac - OCStudio',
                    'templates'            => $this->visibleTemplatesForUser((int)$_SESSION['user_id']),
                    'fields'               => $templateId ? $this->templateRepository->getTemplateFields($templateId) : [],
                    'characterFieldValues' => is_array($_POST['field_values'] ?? null) ? $_POST['field_values'] : [],
                    'oldInput'             => $_POST,
                    'returnUrl'            => $returnUrl,
                    'inheritedWorldFilters' => $inheritedWorldFilters,
                    'messages'             => [$e->getMessage()]
                ]);
            }

            $fieldValues = $this->characterFieldUploadService->processCharacterFieldUploads(
                (int)$userId,
                is_array($_POST['field_values'] ?? null) ? $_POST['field_values'] : [],
                $_POST,
                $_FILES
            );
            $characterId = $this->characterRepository->addCharacter($name, $description, $image, $userId, $templateId, $worldId, $this->imageDisplayFromPost(), $intro);
            $this->characterRepository->setMainCharacter($characterId, (int)$userId, !empty($_POST['is_main_character']));
            $this->filterRepository->replaceObjectFilters('character', $characterId, array_map(fn($tag) => (int)$tag['id'], $contentTags));

            if (!empty($fieldValues)) {
                $this->characterRepository->saveCharacterFieldValues($characterId, $fieldValues);
            }

            header('Location: ' . $returnUrl);
            exit();
        }

        $templates = $this->visibleTemplatesForUser((int)$_SESSION['user_id']);
        $worldId = $this->ownedWorldIdOrNull($_GET['world'] ?? null, (int)$_SESSION['user_id']);
        $inheritedWorldFilters = $worldId !== null
            ? $this->filterRepository->getWorldAndAncestorFilters($worldId, (int)$_SESSION['user_id'])
            : [];

        return $this->render('create_character', [
            'title'     => 'Stwórz postać - OCStudio',
            'templates' => $templates,
            'returnUrl' => $returnUrl,
            'inheritedWorldFilters' => $inheritedWorldFilters,
        ]);
    }

    public function getTemplateData()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        if (!isset($_GET['id'])) {
            echo json_encode(['error' => 'Brak ID szablonu postaci']);
            exit();
        }

        $id       = (int) $_GET['id'];
        $template = $this->templateRepository->getTemplateByIdAndUserId($id, (int)$_SESSION['user_id']);

        if (!$template) {
            http_response_code(404);
            echo json_encode(['error' => 'Nie znaleziono szablonu postaci']);
            exit();
        }

        $fields = $this->templateRepository->getTemplateFields($id);

        echo json_encode([
            'id'          => $template->getId(),
            'name'        => $template->getName(),
            'description' => $template->getDescription(),
            'dateSettings' => [
                'calendarType' => $template->getDateCalendarType(),
                'settings' => json_decode($template->getDateSettings(), true) ?: [],
                'currentWorldDate' => $template->getCurrentWorldDate(),
            ],
            'fields'      => $fields
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    public function viewCharacter()
    {
        $this->requireLogin();
        $returnUrl = $this->safeReturnUrl($_GET['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), '/dashboard');
        $returnLabel = 'Wroc do dashboarda';
        if (strpos($returnUrl, '/characters/') === 0) {
            $returnLabel = 'Wroc do folderu';
        } elseif ($returnUrl === '/characters') {
            $returnLabel = 'Wroc do postaci';
        }

        $character = $this->characterFromPublicOrLegacyId($_GET['id'] ?? '', (int)$_SESSION['user_id']);
        if (!$character) {
            header('Location: ' . $returnUrl);
            exit();
        }

        if (!$this->getUserInterfaceSettings()['revealHidden'] && $this->characterRepository->isHiddenInPath($character->getId(), (int)$_SESSION['user_id'])) {
            http_response_code(404);
            header('Location: ' . $returnUrl);
            exit();
        }

        $template = $character->getIdTemplate()
            ? $this->templateRepository->getTemplateByIdAndUserId($character->getIdTemplate(), (int)$_SESSION['user_id'])
            : null;
        $fields = $character->getIdTemplate()
            ? $this->templateRepository->getTemplateFields($character->getIdTemplate())
            : [];
        $values   = $this->characterRepository->getCharacterFieldValues($character->getId());
        $settings = $this->getUserInterfaceSettings();
        $blockedFilterIds = $this->filterRepository->blockedFilterIds((int)$_SESSION['user_id']);
        $baseCharacterFilters = $this->filterRepository->getAllCharacterFilters($character->getId());
        $baseBlockedByFilters = $this->filtersHaveBlocked($baseCharacterFilters, $blockedFilterIds);
        $variants = array_values(array_filter(
            $this->characterRepository->getCharacterVariants($character->getId()),
            fn($variant) => (!empty($settings['revealHidden']) || empty($variant['is_hidden']))
                && (!empty($settings['revealAdultImages']) || empty($variant['is_adult']))
                && (!empty($settings['revealAdultImages']) || !$this->filtersContainNsfw($variant['content_filters'] ?? []))
                && !$this->filtersHaveBlocked($variant['content_filters'] ?? [], $blockedFilterIds)
        ));
        if ($baseBlockedByFilters && empty($variants)) {
            http_response_code(404);
            header('Location: ' . $returnUrl);
            exit();
        }
        $characterStories = $this->storyRepository->getStoriesForCharacter(
            $character->getId(),
            (int)$_SESSION['user_id'],
            !empty($this->getUserInterfaceSettings()['revealHidden'])
        );

        $selectedVariant = null;
        $variantId = isset($_GET['variant']) ? (int)$_GET['variant'] : null;
        if ($variantId) {
            $selectedVariant = $this->characterRepository->getCharacterVariant($variantId, $character->getId());
            if ($selectedVariant && (
                (empty($settings['revealHidden']) && !empty($selectedVariant['is_hidden']))
                || (empty($settings['revealAdultImages']) && !empty($selectedVariant['is_adult']))
                || (empty($settings['revealAdultImages']) && $this->filtersContainNsfw($selectedVariant['content_filters'] ?? []))
                || $this->filtersHaveBlocked($selectedVariant['content_filters'] ?? [], $blockedFilterIds)
            )) {
                $selectedVariant = null;
            }
            if ($selectedVariant) {
                $values = array_replace($values, $selectedVariant['values']);
            }
        } elseif ($baseBlockedByFilters && !empty($variants)) {
            $selectedVariant = $variants[0];
            $values = array_replace($values, $selectedVariant['values']);
        }

        $currentVariantId = !empty($selectedVariant) ? (int)$selectedVariant['id'] : null;
        $publication = $this->publicationRepository->findOwnedCharacterPublication(
            (int)$_SESSION['user_id'],
            $character->getId(),
            $currentVariantId
        );

        return $this->render('view_character', [
            'title'               => $character->getName() . ' - OCStudio',
            'character'           => $character,
            'template'            => $template,
            'fields'              => $fields,
            'characterFieldValues' => $values,
            'variants'            => $variants,
            'selectedVariant'     => $selectedVariant,
            'baseBlockedByFilters' => $baseBlockedByFilters,
            'blockedFilterIds'    => $blockedFilterIds,
            'imageAssets'         => $this->imageRepository->listAssets((int)$_SESSION['user_id']),
            'returnUrl'           => $returnUrl,
            'returnLabel'         => $returnLabel,
            'characterStories'     => $characterStories,
            'publication'          => $publication,
            'publicationVariantId'  => $currentVariantId,
        ]);
    }

    public function editCharacter()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja postaci jest obecnie wylaczona.');
        $this->characterFieldUploadService->setUploadsEnabled($this->isFeatureEnabled('gallery.enabled'));
        $returnUrl = $this->characterReturnUrl('/dashboard');
        $selectedVariantId = ((int)($_GET['variant'] ?? 0)) ?: null;

        $character = $this->characterFromPublicOrLegacyId($_GET['id'] ?? '', (int)$_SESSION['user_id']);
        if (!$character) {
            header('Location: /dashboard');
            exit();
        }

        $id = $character->getId();
        if (!$this->getUserInterfaceSettings()['revealHidden'] && $this->characterRepository->isHiddenInPath($id, (int)$_SESSION['user_id'])) {
            header('Location: /dashboard');
            exit();
        }

        if ($this->isPost()) {
            $name        = $_POST['character_name']        ?? '';
            $intro       = $_POST['character_intro']       ?? '';
            $description = $_POST['character_description'] ?? '';
            $templateId = null;

            // Jeśli user kliknął "Usuń zdjęcie" – przywracamy default.png
            try {
                $templateId = $this->ownedTemplateIdOrNull($_POST['template_id'] ?? null, (int)$_SESSION['user_id']);
                if (!empty($_POST['character_is_adult'])) {
                    $postedTags = $this->splitTagInput($_POST['content_tags'] ?? '');
                    $tagRows = array_map(fn($tag) => ['slug' => $tag, 'name' => $tag, 'label' => $tag], $postedTags);
                    if (!$this->resolvedTagsContainNsfw($tagRows)) {
                        $postedTags[] = 'adult';
                        $_POST['content_tags'] = implode(', ', $postedTags);
                    }
                }
                $contentTags = $this->filterRepository->validateMinimumTags($_POST['content_tags'] ?? '');
                $variantFilterIdsByKey = array_key_exists('variants_present', $_POST)
                    ? $this->variantFilterIdsFromPost(is_array($_POST['variants'] ?? null) ? $_POST['variants'] : [])
                    : [];
                $this->validateStatsFieldValues($templateId, $_POST['field_values'] ?? []);
            } catch (Throwable $e) {
                return $this->render('create_character', [
                    'title'               => 'Edytuj postac - OCStudio',
                    'character'           => $character,
                    'templates'           => $this->visibleTemplatesForUser((int)$_SESSION['user_id']),
                    'characterFieldValues' => is_array($_POST['field_values'] ?? null) ? $_POST['field_values'] : $this->characterRepository->getCharacterFieldValues($character->getId()),
                    'fields'              => $templateId ? $this->templateRepository->getTemplateFields($templateId) : [],
                    'variants'            => $this->characterRepository->getCharacterVariants($character->getId()),
                    'contentFilters'      => $this->filterRepository->getObjectFilters('character', $character->getId()),
                    'selectedVariantId'   => $selectedVariantId,
                    'oldInput'            => $_POST,
                    'messages'            => [$e->getMessage()]
                ]);
            }

            if (!empty($_POST['remove_image'])) {
                $image = 'default.png';
            } else {
                try {
                    // Fallback: zachowaj aktualne zdjęcie (lub default.png jeśli puste)
                    $currentImage = $character->getImage() ?: 'default.png';
                    $image = $this->characterFieldUploadService->uploadCharacterImage((int)$_SESSION['user_id'], $currentImage, $_POST, $_FILES);
                } catch (Throwable $e) {
                    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 400);
                    return $this->render('create_character', [
                        'title'               => 'Edytuj postac - OCStudio',
                        'character'           => $character,
                        'templates'           => $this->visibleTemplatesForUser((int)$_SESSION['user_id']),
                        'characterFieldValues' => is_array($_POST['field_values'] ?? null) ? $_POST['field_values'] : $this->characterRepository->getCharacterFieldValues($character->getId()),
                        'fields'              => $templateId ? $this->templateRepository->getTemplateFields($templateId) : [],
                        'variants'            => $this->characterRepository->getCharacterVariants($character->getId()),
                        'contentFilters'      => $this->filterRepository->getObjectFilters('character', $character->getId()),
                        'selectedVariantId'   => $selectedVariantId,
                        'oldInput'            => $_POST,
                        'messages'            => [$e->getMessage()]
                    ]);
                }
            }

            // Upewnij się że nigdy nie trafia pusty string do bazy
            if (!$image) {
                $image = 'default.png';
            }

            $fieldValues = $this->characterFieldUploadService->processCharacterFieldUploads(
                (int)$_SESSION['user_id'],
                is_array($_POST['field_values'] ?? null) ? $_POST['field_values'] : [],
                $_POST,
                $_FILES
            );
                $variants = array_key_exists('variants_present', $_POST)
                    ? $this->characterFieldUploadService->prepareVariantsFromPost((int)$_SESSION['user_id'], $_POST, $_FILES)
                    : $this->characterRepository->getCharacterVariants($id);
            $baseHasNsfw = $this->resolvedTagsContainNsfw($contentTags);
            foreach ($variants as &$variant) {
                $key = (string)($variant['key'] ?? '');
                $filterIds = $variantFilterIdsByKey[$key] ?? [];
                $variant['filter_ids'] = $filterIds;
                $variantHasOwnTags = !empty($filterIds);
                $variant['is_adult'] = !empty($variant['is_adult'])
                    || ($variantHasOwnTags
                        ? $this->filtersContainNsfw($this->filterRepository->getFiltersByIds($filterIds))
                        : $baseHasNsfw);
            }
            unset($variant);

            $this->characterRepository->updateCharacter($id, $name, $description, $image, $templateId, $character->getIdWorld(), $this->imageDisplayFromPost(), $intro);
            $this->characterRepository->setMainCharacter($id, (int)$_SESSION['user_id'], !empty($_POST['is_main_character']));
            if (array_key_exists('character_hidden', $_POST)) {
                $this->characterRepository->setHidden($id, (int)$_SESSION['user_id'], !empty($_POST['character_hidden']));
            }
            $this->filterRepository->replaceObjectFilters('character', $id, array_map(fn($tag) => (int)$tag['id'], $contentTags));
            $this->characterRepository->saveCharacterFieldValues($id, $fieldValues);
            $this->characterRepository->replaceCharacterVariants($id, $variants);

            header('Location: ' . $returnUrl);
            exit();
        }

        $templates       = $this->visibleTemplatesForUser((int)$_SESSION['user_id']);
        $characterValues = $this->characterRepository->getCharacterFieldValues($character->getId());
        $fields = $character->getIdTemplate()
            ? $this->templateRepository->getTemplateFields($character->getIdTemplate())
            : [];
        $variants = $this->characterRepository->getCharacterVariants($character->getId());

        return $this->render('create_character', [
            'title'               => 'Edytuj postać - OCStudio',
            'character'           => $character,
            'templates'           => $templates,
            'characterFieldValues' => $characterValues,
            'fields'               => $fields,
            'variants'             => $variants,
            'returnUrl'            => $returnUrl,
            'contentFilters'       => $this->filterRepository->getObjectFilters('character', $character->getId()),
            'selectedVariantId'    => $selectedVariantId,
        ]);
    }

    // -----------------------------------------------------------------------
    //  API: Zmiana statusu postaci/folderu
    // -----------------------------------------------------------------------

    public function updateCharacterStatus()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja postaci jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        if (!$input || !isset($input['characterId'])) {
            $this->jsonError('Brak wymaganych parametrów');
        }

        $characterId = (int) $input['characterId'];
        $statusId = isset($input['statusId']) ? (int)$input['statusId'] : null;

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);
        if (!$character) {
            $this->jsonError('Postać nie znaleziona', 404);
        }

        $this->characterRepository->updateCharacterStatus($characterId, $statusId);

        $this->jsonResponse(['success' => true]);
    }

    // -----------------------------------------------------------------------
    //  API: Dodaj/usuń filtr do postaci
    // -----------------------------------------------------------------------

    public function addCharacterFilter()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja postaci jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        if (!$input || !isset($input['characterId']) || !isset($input['filterName'])) {
            $this->jsonError('Brak wymaganych parametrów');
        }

        $characterId = (int) $input['characterId'];
        $filterName = trim($input['filterName']);

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);
        if (!$character) {
            $this->jsonError('Postać nie znaleziona', 404);
        }

        $filterId = $this->filterRepository->getOrCreateFilter($filterName, $_SESSION['user_id']);
        $this->filterRepository->addCharacterFilter($characterId, $filterId, false);

        $this->jsonResponse(['success' => true, 'filterId' => $filterId]);
    }

    public function removeCharacterFilter()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja postaci jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        if (!$input || !isset($input['characterId']) || !isset($input['filterId'])) {
            $this->jsonError('Brak wymaganych parametrów');
        }

        $characterId = (int) $input['characterId'];
        $filterId = (int) $input['filterId'];

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);
        if (!$character) {
            $this->jsonError('Postać nie znaleziona', 404);
        }

        $this->filterRepository->removeCharacterFilter($characterId, $filterId);

        $this->jsonResponse(['success' => true]);
    }

    // -----------------------------------------------------------------------
    //  API: Wyszukiwanie filtrów
    // -----------------------------------------------------------------------

    public function searchFilters()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        $query = isset($_GET['q']) ? trim($_GET['q']) : '';
        if (strlen($query) < 2) {
            echo json_encode(['filters' => []]);
            exit();
        }

        $allowedBlockedFilterIds = [];
        $worldId = null;
        try {
            $worldId = $this->ownedWorldIdOrNull($_GET['worldId'] ?? null, (int)$_SESSION['user_id']);
        } catch (Throwable $e) {
            $worldId = null;
        }
        if ($worldId !== null) {
            $allowedBlockedFilterIds = array_map(
                fn($filter) => (int)$filter->getId(),
                $this->filterRepository->getWorldAndAncestorFilters($worldId, (int)$_SESSION['user_id'])
            );
        }

        $filters = $this->filterRepository->searchFilters($query, $_SESSION['user_id'], $allowedBlockedFilterIds);

        $result = [];
        foreach ($filters as $filter) {
            $result[] = [
                'id' => $filter->getId(),
                'name' => $filter->getName(),
                'slug' => $filter->getSlug()
            ];
        }

        echo json_encode(['filters' => $result]);
        exit();
    }

    // -----------------------------------------------------------------------
    //  API: Wyszukiwanie postaci (nazwa + filtry)
    // -----------------------------------------------------------------------
    public function searchCharacters()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if ($q === '') {
            echo json_encode(['characters' => []]);
            exit();
        }

        $userId = $_SESSION['user_id'];

        // Split by comma: tokens that exactly match filters are treated as filters,
        // others are name terms.
        $parts = array_filter(array_map('trim', explode(',', $q)));
        $filterNames = [];
        $nameParts = [];

        foreach ($parts as $p) {
            $candidates = $this->filterRepository->searchFilters($p, $userId);
            $matchedExact = false;
            foreach ($candidates as $cand) {
                if (mb_strtolower($cand->getName()) === mb_strtolower($p)) {
                    $filterNames[] = $cand->getName();
                    $matchedExact = true;
                    break;
                }
            }
            if (!$matchedExact) {
                $nameParts[] = $p;
            }
        }

        $nameTerm = count($nameParts) ? implode(' ', $nameParts) : null;

        $characters = $this->characterRepository->searchCharactersByNameAndFilters($userId, $nameTerm, $filterNames, $this->filterRepository->blockedFilterIds((int)$userId));

        $out = [];
        foreach ($characters as $c) {
            $out[] = [
                'id' => $c->getId(),
                'publicId' => $c->getPublicId(),
                'name' => $c->getName(),
                'description' => $c->getDescription(),
                'image' => $c->getImage()
            ];
        }

        echo json_encode(['characters' => $out]);
        exit();
    }

    // -----------------------------------------------------------------------
    //  API: Blokowanie/odblokowywanie filtrów
    // -----------------------------------------------------------------------

    public function toggleBlockFilter()
    {
        $this->requireLogin();

        $input = $this->requireJsonPost();
        if (!$input || !isset($input['filterId']) || !isset($input['block'])) {
            $this->jsonError('Brak wymaganych parametrów');
        }

        $filterId = (int) $input['filterId'];
        $block = (bool) $input['block'];

        if ($block) {
            $this->filterRepository->blockFilter($_SESSION['user_id'], $filterId);
        } else {
            $this->filterRepository->unblockFilter($_SESSION['user_id'], $filterId);
        }

        $this->jsonResponse(['success' => true]);
    }

    // -----------------------------------------------------------------------
    //  API: Globalne wyszukiwanie (header) – postacie + foldery
    // -----------------------------------------------------------------------
    public function globalSearch()
    {
        $this->requireLogin();
        header('Content-Type: application/json');
 
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        if (mb_strlen($q) < 2) {
            echo json_encode(['characters' => [], 'worlds' => [], 'stories' => [], 'templates' => [], 'filters' => [], 'publications' => []]);
            exit();
        }
 
        $userId   = $_SESSION['user_id'];
        $statuses = $this->statusRepository->getAllStatuses();
        $settings = $this->getUserInterfaceSettings();
        $includeHidden = !empty($settings['revealHidden']);
        $includeAdult = !empty($settings['revealAdultImages']);
 
        /**
         * Pomocnicza zamiana obiektu Character na tablicę dla JSON.
         * Dołącza statusName i statusColor jeśli postać ma przypisany status.
         */
        $charToArray = function ($c) use ($statuses): array {
            $statusName  = null;
            $statusColor = null;
            foreach ($statuses as $s) {
                if ($s->getId() === $c->getIdStatus()) {
                    $statusName  = $s->getName();
                    $statusColor = $s->getColorHex();
                    break;
                }
            }
 
            return [
                'id'          => $c->getId(),
                'publicId'    => $c->getPublicId(),
                'name'        => $c->getName(),
                'image'       => $c->getImage() ?: 'default.png',
                'imageDisplayMode' => $c->getImageDisplayMode(),
                'image_display_mode' => $c->getImageDisplayMode(),
                'imageFit'    => $c->getImageFit(),
                'imageFocusX' => $c->getImageFocusX(),
                'imageFocusY' => $c->getImageFocusY(),
                'imageZoom'   => $c->getImageZoom(),
                'image_fit' => $c->getImageFit(),
                'image_focus_x' => $c->getImageFocusX(),
                'image_focus_y' => $c->getImageFocusY(),
                'image_zoom' => $c->getImageZoom(),
                'statusName'  => $statusName,
                'statusColor' => $statusColor,
            ];
        };
 
        // 1. Postacie pasujące bezpośrednio po nazwie
        $blockedFilterIds = $this->filterRepository->blockedFilterIds((int)$userId);
        $chars    = $this->characterRepository->searchGlobalCharacters((int)$userId, $q, $blockedFilterIds, $includeHidden, $includeAdult);
        $charsOut = array_map($charToArray, $chars);
 
        // 2. Foldery pasujące po nazwie + postacie z całego poddrzewa (rekursywnie)
        $worldsOut = [];
        try {
            $matchingWorlds = $this->worldRepository->searchWorldsByName($userId, $q, $includeHidden);
 
            foreach ($matchingWorlds as $world) {
                // Pobierz ID wszystkich podfolderów (włącznie z samym folderem)
                $subtreeIds = $this->worldRepository->getDescendantWorldIds($world->getId(), $userId);
 
                // Zbierz postacie z każdego folderu w poddrzewie
                $wCharsOut = [];
                $seen      = [];
                foreach ($subtreeIds as $wid) {
                    $wChars = $this->characterRepository->getCharactersByWorld($userId, $wid, $blockedFilterIds, $includeHidden, $includeAdult);
                    foreach ($wChars as $c) {
                        if (!$includeAdult && $this->filtersContainNsfw($this->filterRepository->getAllCharacterFilters($c->getId()))) {
                            continue;
                        }
                        if (!isset($seen[$c->getId()])) {
                            $seen[$c->getId()] = true;
                            $wCharsOut[]       = $charToArray($c);
                        }
                    }
                }
 
                $worldsOut[] = [
                    'id'         => $world->getId(),
                    'publicId'   => $world->getPublicId(),
                    'name'       => $world->getName(),
                    'image'      => $world->getImage() ?: 'default.jpg',
                    'iconColor'  => method_exists($world, 'getIconColor') ? $world->getIconColor() : '#7B61FF',
                    'characters' => $wCharsOut,
                ];
            }
        } catch (Throwable $e) {
            // getDescendantWorldIds może nie istnieć na starszych wersjach – ignorujemy
        }
 
        $storiesOut = array_map(fn($story) => [
            'id' => $story->getId(),
            'publicId' => $story->getPublicId(),
            'title' => $story->getTitle(),
            'description' => $story->getDescription(),
            'date' => $story->getStoryDate(),
            'image' => $story->getImage() ?: 'default_story.png',
            'status' => $story->getStatus(),
        ], $this->storyRepository->searchGlobalStories((int)$userId, $q, $blockedFilterIds, $includeHidden, $includeAdult));

        $templatesOut = array_map(fn($template) => [
            'id' => $template->getId(),
            'publicId' => $template->getPublicId(),
            'name' => $template->getName(),
            'description' => $template->getDescription(),
        ], $this->templateRepository->searchGlobalTemplates((int)$userId, $q, $blockedFilterIds, $includeHidden, $includeAdult));

        $searchFilters = $this->filterRepository->searchFilters($q, (int)$userId);
        if (!$includeAdult) {
            $searchFilters = array_values(array_filter($searchFilters, fn($filter) => !$this->filtersContainNsfw([$filter])));
        }
        $filtersOut = array_map(fn($filter) => [
            'id' => $filter->getId(),
            'name' => $filter->getName(),
            'slug' => $filter->getSlug(),
        ], $searchFilters);

        $publicationsOut = [];
        if ($this->socialFeatureSettingsRepository->isEnabled('community.enabled')
            && $this->socialFeatureSettingsRepository->isEnabled('publications.enabled')
            && $this->socialFeatureSettingsRepository->isEnabled('public_search.enabled')) {
            $publicationsOut = array_map(function (array $publication): array {
                $card = is_array($publication['card'] ?? null) ? $publication['card'] : [];
                $author = is_array($publication['author'] ?? null) ? $publication['author'] : [];

                return [
                    'id' => (int)($publication['id'] ?? 0),
                    'publicId' => (string)($publication['publicId'] ?? ''),
                    'contentType' => (string)($publication['contentType'] ?? ''),
                    'ageRating' => (string)($publication['ageRating'] ?? 'general'),
                    'isOwn' => !empty($publication['isOwn']),
                    'title' => (string)($card['title'] ?? 'Publikacja'),
                    'description' => (string)($card['description'] ?? ''),
                    'typeLabel' => (string)($card['typeLabel'] ?? 'Publikacja'),
                    'image' => (string)($card['image'] ?? 'default.png'),
                    'authorName' => (string)($author['displayName'] ?? 'Uzytkownik'),
                    'authorProfileUrl' => (string)($author['profileUrl'] ?? ''),
                ];
            }, $this->publicationRepository->searchVisiblePublications($q, (int)$userId, $includeAdult));
        }

        echo json_encode([
            'characters' => $charsOut,
            'worlds' => $worldsOut,
            'stories' => $storiesOut,
            'templates' => $templatesOut,
            'filters' => $filtersOut,
            'publications' => $publicationsOut,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    public function toggleCharacterHidden(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja postaci jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        $characterId = (int)($input['characterId'] ?? 0);
        if ($characterId <= 0) {
            $this->jsonError('Brak postaci.');
        }

        $this->characterRepository->setHidden(
            $characterId,
            (int)$_SESSION['user_id'],
            !empty($input['hidden'])
        );

        $this->jsonResponse(['success' => true]);
    }

    public function toggleCharacterPinned(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja postaci jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        $characterId = (int)($input['characterId'] ?? 0);
        if ($characterId <= 0) {
            $this->jsonError('Brak postaci.');
        }

        $this->characterRepository->setPinned(
            $characterId,
            (int)$_SESSION['user_id'],
            !empty($input['pinned'])
        );

        $this->jsonResponse(['success' => true]);
    }

    public function toggleWorldHidden(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja folderow jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        $worldId = (int)($input['worldId'] ?? 0);
        if ($worldId <= 0) {
            $this->jsonError('Brak folderu.');
        }

        $this->worldRepository->setHidden(
            $worldId,
            (int)$_SESSION['user_id'],
            !empty($input['hidden'])
        );

        $this->jsonResponse(['success' => true]);
    }

    // -----------------------------------------------------------------------
    //  API: Przywróć domyślne zdjęcie postaci
    // -----------------------------------------------------------------------

    public function restoreDefaultImage()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Edycja postaci jest obecnie wylaczona.', true);

        $input = $this->requireJsonPost();
        if (!$input || !isset($input['characterId'])) {
            $this->jsonError('Brak wymaganych parametrów');
        }

        $characterId = (int) $input['characterId'];
        $character   = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);

        if (!$character) {
            $this->jsonError('Postać nie znaleziona', 404);
        }

        $this->characterRepository->updateCharacter(
            $characterId,
            $character->getName(),
            $character->getDescription(),
            'default.png',
            $character->getIdTemplate(),
            $character->getIdWorld(),
            $this->imageDisplayFromCharacter($character),
            $character->getIntro()
        );

        $this->jsonResponse(['success' => true]);
    }

    public function duplicateCharacter()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Tworzenie postaci jest obecnie wylaczone.', true);

        $input = $this->requireJsonPost();
        $characterId = (int)($input['characterId'] ?? 0);

        if ($characterId <= 0) {
            $this->jsonError('Brak ID postaci');
        }

        $newId = $this->characterRepository->duplicateCharacter($characterId, $_SESSION['user_id']);
        if (!$newId) {
            $this->jsonError('Postać nie znaleziona', 404);
        }

        $this->jsonResponse(['success' => true, 'id' => $newId]);
    }

    public function deleteCharacter()
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Usuwanie postaci jest obecnie wylaczone.', true);

        $input = $this->requireJsonPost();
        $characterId = (int)($input['characterId'] ?? 0);
        $confirmation = trim($input['confirmation'] ?? '');

        $character = $characterId > 0
            ? $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id'])
            : null;

        if (!$character) {
            $this->jsonError('Postać nie znaleziona', 404);
        }

        if ($confirmation !== $character->getName()) {
            $this->jsonError('Wpisana nazwa postaci nie zgadza się');
        }

        $images = $this->getCharacterImageFilenames($character);
        foreach ($this->characterRepository->getCharacterVariants($character->getId()) as $variant) {
            $this->collectUploadFilename($images, $variant['image'] ?? null);
        }

        $this->characterRepository->deleteCharacter($characterId, $_SESSION['user_id']);
        $this->deleteUnusedUploadFiles($images);
        $this->jsonResponse(['success' => true]);
    }

    private function getCharacterImageFilenames(Character $character): array
    {
        $filenames = [];
        $this->collectUploadFilename($filenames, $character->getImage());
        return $filenames;
    }

    private function collectUploadFilename(array &$filenames, ?string $image): void
    {
        $image = trim((string)$image);
        if ($image === '' || in_array($image, ['default.png', 'default.jpg', 'default_dark.png'], true)) {
            return;
        }

        $filename = basename($image);
        if ($filename !== '' && $filename === $image) {
            $filenames[$filename] = true;
        }
    }

    private function deleteUnusedUploadFiles(array $filenames): void
    {
        $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        foreach (array_keys($filenames) as $filename) {
            if ($this->characterRepository->countImageReferences($filename) > 0) {
                continue;
            }

            $path = $uploadDir . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

}
