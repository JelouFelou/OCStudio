<?php

require_once 'AppController.php';
require_once 'src/models/Story.php';
require_once 'src/models/StoryFolder.php';
require_once 'src/models/StoryCharacter.php';
require_once 'src/repositories/StoryRepository.php';
require_once 'src/repositories/CharacterRepository.php';
require_once 'src/repositories/WorldRepository.php';
require_once 'src/repositories/FilterRepository.php';

class StoryController extends AppController
{
    private StoryRepository $storyRepository;
    private CharacterRepository $characterRepository;
    private WorldRepository $worldRepository;
    private FilterRepository $filterRepository;
    private int $currentUserId;

    public function __construct()
    {
        $this->storyRepository = new StoryRepository();
        $this->characterRepository = new CharacterRepository();
        $this->worldRepository = new WorldRepository();
        $this->filterRepository = new FilterRepository();
        $this->currentUserId = (int)($_SESSION['user_id'] ?? 0);
    }

    public function stories(): void
    {
        $this->requireStoriesLogin();

        $includeHidden = $this->includeHidden();
        $worldParam = trim((string)($_GET['world'] ?? ''));
        $world = $this->worldFromParam($worldParam);
        $worldId = $world ? $world->getId() : 0;

        if ($worldParam !== '' && (!$world || !$this->canAccessWorld($world, $includeHidden))) {
            http_response_code(404);
            $this->render('stories', [
                'title' => 'Historie - OCStudio',
                'messages' => ['Folder nie zostal znaleziony.'],
            ]);
            return;
        }

        $stories = $this->storyRepository->getStoriesByUser($this->currentUserId, $worldId, 0, $includeHidden);

        $this->render('stories', [
            'title' => 'Historie - OCStudio',
            'stories' => $stories,
            'storyFiltersById' => $this->storyFilterMap($stories),
            'worlds' => $this->worldRepository->getWorldsByUserId($this->currentUserId, $includeHidden),
            'worldId' => $worldId,
            'world' => $world,
        ]);
    }

    public function createStory(): void
    {
        $this->requireStoriesLogin();

        $includeHidden = $this->includeHidden();
        $worlds = $this->worldRepository->getWorldsByUserId($this->currentUserId, $includeHidden);
        $messages = [];

        $worldParam = trim((string)(
            ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['world_id'] ?? '') : '')
            ?: ($_GET['world'] ?? '')
        ));
        $world = $this->worldFromParam($worldParam);

        if (!$world && count($worlds) === 1 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $world = $worlds[0];
        }

        $worldId = $world ? $world->getId() : 0;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = trim((string)($_POST['title'] ?? ''));
            $description = (string)($_POST['description'] ?? '');
            $storyDate = trim((string)($_POST['story_date'] ?? ''));
            $image = trim((string)($_POST['image'] ?? 'default_story.png'));
            $status = (string)($_POST['status'] ?? 'draft');

            if (!$world || !$this->canAccessWorld($world, $includeHidden)) {
                $messages[] = 'Wybierz folder, w ktorym ma powstac historia.';
            }

            if ($title === '') {
                $messages[] = 'Tytul historii jest wymagany.';
            }

            if (!in_array($status, ['draft', 'published', 'archived'], true)) {
                $status = 'draft';
            }

            if (empty($messages)) {
                try {
                    $timelineSplitDate = trim((string)($_POST['timeline_split_date'] ?? ''));
                    $timelineMergeDate = trim((string)($_POST['timeline_merge_date'] ?? ''));
                    $created = $this->storyRepository->createStory(new Story(
                        idUser: $this->currentUserId,
                        idWorld: $world->getId(),
                        title: $title,
                        description: $description,
                        storyDate: $storyDate,
                        image: $image !== '' ? $image : 'default_story.png',
                        imageFit: $this->cleanImageFit($_POST['image_fit'] ?? 'cover'),
                        imageFocusX: $this->cleanPercent($_POST['image_focus_x'] ?? 50),
                        imageFocusY: $this->cleanPercent($_POST['image_focus_y'] ?? 50),
                        imageZoom: $this->cleanZoom($_POST['image_zoom'] ?? 1),
                        cardImageFit: $this->cleanImageFit($_POST['card_image_fit'] ?? ($_POST['image_fit'] ?? 'cover')),
                        cardImageFocusX: $this->cleanPercent($_POST['card_image_focus_x'] ?? ($_POST['image_focus_x'] ?? 50)),
                        cardImageFocusY: $this->cleanPercent($_POST['card_image_focus_y'] ?? ($_POST['image_focus_y'] ?? 50)),
                        cardImageZoom: $this->cleanZoom($_POST['card_image_zoom'] ?? ($_POST['image_zoom'] ?? 1)),
                        timelineBranchName: trim((string)($_POST['timeline_branch_name'] ?? '')),
                        timelineSplitDate: $timelineSplitDate,
                        timelineSplitUnknown: $timelineSplitDate === '',
                        timelineMergeDate: $timelineMergeDate,
                        timelineMergeUnknown: $timelineMergeDate === '',
                        status: $status
                    ));

                    if ($created) {
                        $this->saveStoryFilters($created->getId());
                        $this->saveStoryFields($created);
                        $this->saveStoryCharacters($created);
                        header('Location: /editStory/' . $created->getPublicId());
                        exit;
                    }

                    $messages[] = 'Nie udalo sie utworzyc historii.';
                } catch (Throwable $e) {
                    $messages[] = 'Nie udalo sie utworzyc historii.';
                }
            }
        } elseif ($worldParam !== '' && (!$world || !$this->canAccessWorld($world, $includeHidden))) {
            http_response_code(404);
            $messages[] = 'Folder nie zostal znaleziony.';
        } elseif (empty($worlds)) {
            $messages[] = 'Najpierw utworz folder swiata, aby dodac historie.';
        }

        $availableCharacters = $this->characterRepository->getCharactersByUserId($this->currentUserId, $includeHidden);
        $availableCharacterVariantsById = $this->characterRepository->getCharacterVariantsByCharacterIds(
            array_map(fn($character) => $character->getId(), $availableCharacters),
            $includeHidden,
            !empty($this->getUserInterfaceSettings()['revealAdultImages'])
        );
        $pseudonymSources = [];
        foreach ($availableCharacters as $availableCharacter) {
            $pseudonymSources[$availableCharacter->getId()] = $this->storyRepository->getCharacterPseudonymSources($availableCharacter->getId());
        }

        $this->render('create_story', [
            'title' => 'Nowa historia - OCStudio',
            'world' => $world,
            'worldId' => $worldId,
            'worlds' => $worlds,
            'availableCharacters' => $availableCharacters,
            'availableCharacterVariantsById' => $availableCharacterVariantsById,
            'pseudonymSources' => $pseudonymSources,
            'worldInheritedFiltersById' => $this->worldInheritedFilterMap($worlds),
            'currentInheritedFilters' => $world ? $this->filterRepository->getWorldAndAncestorFilters($world->getId(), $this->currentUserId) : [],
            'storyDirectFilterTags' => (string)($_POST['filter_tags'] ?? ''),
            'messages' => $messages,
        ]);
    }

    public function editStory(): void
    {
        $this->requireStoriesLogin();

        $includeHidden = $this->includeHidden();
        $story = $this->storyRepository->getStoryByPublicId((string)($_GET['id'] ?? ''));

        if (!$this->canAccessOwnedStory($story, $includeHidden)) {
            http_response_code(403);
            $this->render('stories', [
                'title' => 'Historie - OCStudio',
                'messages' => ['Nie masz dostepu do tej historii.'],
            ]);
            return;
        }

        $message = '';
        $messages = [];
        $quickSave = $_SERVER['REQUEST_METHOD'] === 'POST'
            && (($_POST['quick_save'] ?? '') === '1' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                $messages[] = 'Tytul historii jest wymagany.';
            } else {
                $story->setTitle($title);
                $story->setDescription((string)($_POST['description'] ?? ''));
                $story->setStoryDate(trim((string)($_POST['story_date'] ?? '')));
                $story->setImage($this->cleanImageName($_POST['image'] ?? 'default_story.png'));
                $story->setImageFit($this->cleanImageFit($_POST['image_fit'] ?? 'cover'));
                $story->setImageFocusX($this->cleanPercent($_POST['image_focus_x'] ?? 50));
                $story->setImageFocusY($this->cleanPercent($_POST['image_focus_y'] ?? 50));
                $story->setImageZoom($this->cleanZoom($_POST['image_zoom'] ?? 1));
                $story->setCardImageFit($this->cleanImageFit($_POST['card_image_fit'] ?? ($_POST['image_fit'] ?? 'cover')));
                $story->setCardImageFocusX($this->cleanPercent($_POST['card_image_focus_x'] ?? ($_POST['image_focus_x'] ?? 50)));
                $story->setCardImageFocusY($this->cleanPercent($_POST['card_image_focus_y'] ?? ($_POST['image_focus_y'] ?? 50)));
                $story->setCardImageZoom($this->cleanZoom($_POST['card_image_zoom'] ?? ($_POST['image_zoom'] ?? 1)));
                $timelineSplitDate = trim((string)($_POST['timeline_split_date'] ?? ''));
                $timelineMergeDate = trim((string)($_POST['timeline_merge_date'] ?? ''));
                $story->setTimelineBranchName(trim((string)($_POST['timeline_branch_name'] ?? '')));
                $story->setTimelineSplitDate($timelineSplitDate);
                $story->setTimelineSplitUnknown($timelineSplitDate === '');
                $story->setTimelineMergeDate($timelineMergeDate);
                $story->setTimelineMergeUnknown($timelineMergeDate === '');

                if (isset($_POST['status']) && in_array($_POST['status'], ['draft', 'published', 'archived'], true)) {
                    $story->setStatus($_POST['status']);
                }

                try {
                    $this->storyRepository->updateStory($story);
                    $this->saveStoryFilters($story->getId());
                    $this->saveStoryFields($story);
                    if ($quickSave) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'Zapisano']);
                        exit;
                    }
                    header('Location: /stories?world=' . $story->getIdWorld());
                    exit;
                } catch (Throwable $e) {
                    error_log('Story save failed: ' . $e->getMessage());
                    $messages[] = 'Nie udalo sie zapisac historii.';
                }
            }

            if ($quickSave) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $messages[0] ?? 'Nie udalo sie zapisac historii.']);
                exit;
            }
        }

        $availableCharacters = $this->characterRepository->getCharactersByUserId($this->currentUserId, $includeHidden);
        $availableCharacterVariantsById = $this->characterRepository->getCharacterVariantsByCharacterIds(
            array_map(fn($character) => $character->getId(), $availableCharacters),
            $includeHidden,
            !empty($this->getUserInterfaceSettings()['revealAdultImages'])
        );
        $pseudonymSources = [];
        foreach ($availableCharacters as $availableCharacter) {
            $pseudonymSources[$availableCharacter->getId()] = $this->storyRepository->getCharacterPseudonymSources($availableCharacter->getId());
        }
        $storyCharacters = $this->storyRepository->getStoryCharacters($story->getId());

        $this->render('edit_story', [
            'title' => 'Edycja historii - OCStudio',
            'story' => $story,
            'fields' => $this->storyRepository->getStoryFields($story->getId()),
            'fieldValues' => $this->storyRepository->getStoryFieldValues($story->getId()),
            'characters' => $storyCharacters,
            'availableCharacters' => $availableCharacters,
            'availableCharacterVariantsById' => $availableCharacterVariantsById,
            'pseudonymSources' => $pseudonymSources,
            'storyInheritedFilters' => $this->storyRepository->getInheritedFiltersByStoryIds([$story->getId()], $this->currentUserId)[$story->getId()] ?? [],
            'storyDirectFilters' => $this->filterRepository->getObjectFilters('story', $story->getId()),
            'storyDirectFilterTags' => $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['filter_tags'] ?? '') : null,
            'message' => $message,
            'messages' => $messages,
        ]);
    }

    public function viewStory(): void
    {
        $story = $this->storyRepository->getStoryByPublicId((string)($_GET['id'] ?? ''));
        $includeHidden = $this->includeHidden();

        if (!$story || (!$this->canViewStory($story, $includeHidden))) {
            http_response_code(404);
            $this->render('view_story', [
                'title' => 'Historia nie znaleziona - OCStudio',
                'story' => null,
            ]);
            return;
        }

        $characters = $this->storyRepository->getStoryCharacters($story->getId());
        $pseudonyms = [];
        foreach ($characters as $char) {
            $charPseudonyms = $this->getFreshStoryCharacterPseudonyms($char);
            $pseudonyms[$char['id_character']] = !empty($charPseudonyms)
                ? array_map(fn($p) => $p['pseudonym'], $charPseudonyms)
                : [$char['character_name']];
        }

        $this->render('view_story', [
            'title' => $story->getTitle() . ' - OCStudio',
            'story' => $story,
            'fields' => $this->storyRepository->getStoryFields($story->getId()),
            'fieldValues' => $this->storyRepository->getStoryFieldValues($story->getId()),
            'characters' => $characters,
            'pseudonyms' => $pseudonyms,
        ]);
    }

    public function updateTimelinePosition(): void
    {
        $this->requireLogin();
        $input = $this->requireJsonPost();

        $storyId = (int)($input['storyId'] ?? 0);
        if ($storyId <= 0) {
            $this->jsonError('Brak historii.');
        }

        $story = $this->storyRepository->getStoryById($storyId);
        if (!$story || $story->getIdUser() !== $this->currentUserId) {
            $this->jsonError('Historia nie znaleziona.', 404);
        }

        $this->storyRepository->updateTimelinePosition(
            $storyId,
            $this->currentUserId,
            (float)($input['x'] ?? 0),
            (float)($input['y'] ?? 0)
        );

        $this->jsonResponse(['success' => true]);
    }

    public function deleteStory(): void
    {
        $this->jsonForPost();

        $input = $this->jsonInput();
        $storyId = (int)($input['storyId'] ?? 0);
        $story = $storyId > 0
            ? $this->storyRepository->getStoryById($storyId)
            : $this->storyRepository->getStoryByPublicId((string)($_POST['id'] ?? ''));
        if (!$this->canAccessOwnedStory($story, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Brak dostepu.']);
            exit;
        }

        $confirmation = trim((string)($input['confirmation'] ?? ''));
        if ($storyId > 0 && $confirmation !== $story->getTitle()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Tytul historii nie zgadza sie.']);
            exit;
        }

        $deleted = $this->storyRepository->deleteStory($story->getId());
        echo json_encode([
            'success' => $deleted,
            'redirect' => $deleted ? '/stories?world=' . $story->getIdWorld() : null,
            'message' => $deleted ? null : 'Nie udalo sie usunac historii.',
        ]);
        exit;
    }

    public function getStoryData(): void
    {
        $this->requireStoriesLogin();

        $story = $this->storyRepository->getStoryByPublicId((string)($_GET['id'] ?? ''));
        if (!$this->canAccessOwnedStory($story, $this->includeHidden())) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        $characters = [];
        foreach ($this->storyRepository->getStoryCharacters($story->getId()) as $char) {
            $pseudonyms = $this->getFreshStoryCharacterPseudonyms($char);
            $char['pseudonyms'] = !empty($pseudonyms)
                ? $pseudonyms
                : [['pseudonym' => $char['character_name'], 'is_excluded' => false]];
            $characters[] = $char;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'story' => [
                'id' => $story->getId(),
                'title' => $story->getTitle(),
                'description' => $story->getDescription(),
                'image' => $story->getImage(),
                'status' => $story->getStatus(),
            ],
            'fields' => $this->storyRepository->getStoryFields($story->getId()),
            'fieldValues' => $this->storyRepository->getStoryFieldValues($story->getId()),
            'characters' => $characters,
        ]);
        exit;
    }

    public function saveStoryField(): void
    {
        $this->jsonForPost();

        $storyId = (int)($_POST['story_id'] ?? 0);
        $story = $this->storyRepository->getStoryById($storyId);
        if (!$this->canAccessOwnedStory($story, $this->includeHidden())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }

        $saved = $this->storyRepository->updateStoryFieldValue(
            $storyId,
            (int)($_POST['field_id'] ?? 0),
            (string)($_POST['value'] ?? '')
        );

        echo json_encode(['success' => $saved]);
        exit;
    }

    public function addCharacterToStory(): void
    {
        $this->jsonForPost();

        $story = $this->storyRepository->getStoryById((int)($_POST['story_id'] ?? 0));
        if (!$this->canAccessOwnedStory($story, $this->includeHidden())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Brak dostepu do historii.']);
            exit;
        }

        $characterId = (int)($_POST['character_id'] ?? 0);
        $variantId = ((int)($_POST['variant_id'] ?? 0)) ?: null;
        $character = $this->characterRepository->getCharacterById($characterId);
        if (!$character || $character->getIdUser() !== $this->currentUserId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Postac nie zostala znaleziona.']);
            exit;
        }
        if ($variantId !== null && !$this->characterRepository->getCharacterVariant($variantId, $characterId)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Nieprawidlowy wariant postaci.']);
            exit;
        }

        if (!$this->includeHidden() && $this->characterRepository->isHiddenInPath($characterId, $this->currentUserId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Postac jest ukryta.']);
            exit;
        }

        try {
            $added = $this->storyRepository->addCharacterToStory(
                $story->getId(),
                $characterId,
                ((int)($_POST['pseudonym_field_id'] ?? 0)) ?: null,
                $variantId
            );
            if ($added && $added->getPseudonymFieldId()) {
                $this->storyRepository->replaceStoryCharacterPseudonyms(
                    $added->getId(),
                    $this->storyRepository->extractPseudonymsForCharacterField($characterId, $added->getPseudonymFieldId())
                );
            }
            echo json_encode(['success' => $added !== null]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Ta postac jest juz w historii.']);
        }
        exit;
    }

    public function updateStoryCharacterPseudonyms(): void
    {
        $this->jsonForPost();

        $story = $this->storyRepository->getStoryById((int)($_POST['story_id'] ?? 0));
        if (!$this->canAccessOwnedStory($story, $this->includeHidden())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Brak dostepu do historii.']);
            exit;
        }

        $characterId = (int)($_POST['character_id'] ?? 0);
        $fieldId = ((int)($_POST['pseudonym_field_id'] ?? 0)) ?: null;
        $variantId = ((int)($_POST['variant_id'] ?? 0)) ?: null;
        $this->storyRepository->updateStoryCharacterPseudonymField($story->getId(), $characterId, $fieldId, $variantId);

        foreach ($this->storyRepository->getStoryCharacters($story->getId()) as $storyCharacter) {
            if ((int)$storyCharacter['id_character'] !== $characterId
                || (((int)($storyCharacter['id_variant'] ?? 0)) ?: null) !== $variantId) {
                continue;
            }

            $pseudonyms = $fieldId
                ? $this->storyRepository->extractPseudonymsForCharacterField($characterId, $fieldId)
                : [];
            $this->storyRepository->replaceStoryCharacterPseudonyms((int)$storyCharacter['id'], $pseudonyms);
            echo json_encode(['success' => true, 'pseudonyms' => $pseudonyms]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Postac nie jest dodana do historii.']);
        exit;
    }

    public function removeCharacterFromStory(): void
    {
        $this->jsonForPost();

        $story = $this->storyRepository->getStoryById((int)($_POST['story_id'] ?? 0));
        if (!$this->canAccessOwnedStory($story, $this->includeHidden())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Brak dostepu do historii.']);
            exit;
        }

        echo json_encode([
            'success' => $this->storyRepository->removeCharacterFromStory(
                $story->getId(),
                (int)($_POST['character_id'] ?? 0),
                ((int)($_POST['variant_id'] ?? 0)) ?: null
            ),
        ]);
        exit;
    }

    public function updateStoryStatus(): void
    {
        $this->jsonForPost();
        $input = $this->jsonInput();
        $story = $this->storyRepository->getStoryById((int)($input['storyId'] ?? 0));

        if (!$this->canAccessOwnedStory($story, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Brak dostepu.']);
            exit;
        }

        $status = $this->storyStatusFromStatusId($input['statusId'] ?? null);
        $this->storyRepository->updateStoryStatus($story->getId(), $status);
        echo json_encode(['success' => true]);
        exit;
    }

    public function duplicateStory(): void
    {
        $this->jsonForPost();
        $input = $this->jsonInput();
        $story = $this->storyRepository->getStoryById((int)($input['storyId'] ?? 0));

        if (!$this->canAccessOwnedStory($story, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Brak dostepu.']);
            exit;
        }

        $duplicate = $this->storyRepository->duplicateStory($story->getId(), $this->currentUserId);
        if (!$duplicate) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Historia nie znaleziona.']);
            exit;
        }

        echo json_encode(['success' => true, 'id' => $duplicate->getId(), 'publicId' => $duplicate->getPublicId()]);
        exit;
    }

    public function toggleStoryHidden(): void
    {
        $this->jsonForPost();
        $input = $this->jsonInput();
        $story = $this->storyRepository->getStoryById((int)($input['storyId'] ?? 0));

        if (!$this->canAccessOwnedStory($story, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Brak dostepu.']);
            exit;
        }

        $this->storyRepository->setHidden($story->getId(), $this->currentUserId, !empty($input['hidden']));
        echo json_encode(['success' => true]);
        exit;
    }

    private function saveStoryFields(Story $story): void
    {
        $this->storyRepository->cleanupMalformedDuplicateStoryFields($story->getId());

        if (isset($_POST['story_deleted_fields']) && is_array($_POST['story_deleted_fields'])) {
            foreach ($_POST['story_deleted_fields'] as $deletedFieldId) {
                if (ctype_digit((string)$deletedFieldId)) {
                    $this->storyRepository->deleteStoryField($story->getId(), (int)$deletedFieldId);
                }
            }
        }

        if (!isset($_POST['story_fields']) || !is_array($_POST['story_fields'])) {
            return;
        }

        $fieldOrder = 0;
        foreach ($_POST['story_fields'] as $fieldId => $value) {
            if (strpos((string)$fieldId, 'new_') === 0) {
                $fieldType = (string)($_POST['story_field_types'][$fieldId] ?? 'text');
                $fieldLabel = trim((string)($_POST['story_field_labels'][$fieldId] ?? ''));
                if (!in_array($fieldType, ['text', 'textarea', 'image', 'dialog', 'section'], true)) {
                    $fieldType = 'text';
                }

                $existingField = $this->storyRepository->getStoryFieldByClientKey($story->getId(), (string)$fieldId);
                if ($existingField) {
                    $this->storyRepository->updateStoryFieldMeta($story->getId(), (int)$existingField['id'], $fieldLabel, $fieldOrder++);
                    $this->storyRepository->updateStoryFieldValue($story->getId(), (int)$existingField['id'], (string)$value);
                } else {
                    $newField = $this->storyRepository->createStoryField(
                        $story->getId(),
                        $fieldLabel,
                        $fieldType,
                        $fieldOrder++,
                        'client_key:' . (string)$fieldId
                    );
                    if ($newField) {
                        $this->storyRepository->updateStoryFieldValue($story->getId(), (int)$newField['id'], (string)$value);
                    }
                }
                continue;
            }

            $fieldLabel = trim((string)($_POST['story_field_labels'][$fieldId] ?? ''));
            if (!$this->storyRepository->updateStoryFieldMeta($story->getId(), (int)$fieldId, $fieldLabel, $fieldOrder)) {
                continue;
            }
            $this->storyRepository->updateStoryFieldValue($story->getId(), (int)$fieldId, (string)$value);
            $fieldOrder++;
        }

        $this->storyRepository->cleanupClientKeyDuplicateStoryFields($story->getId());
    }

    private function saveStoryCharacters(Story $story): void
    {
        if (!isset($_POST['story_character_ids']) || !is_array($_POST['story_character_ids'])) {
            return;
        }

        $seen = [];
        foreach ($_POST['story_character_ids'] as $characterRefRaw) {
            [$characterIdRaw, $variantIdRaw] = array_pad(explode(':', (string)$characterRefRaw, 2), 2, '0');
            $characterId = (int)$characterIdRaw;
            $variantId = ((int)$variantIdRaw) ?: null;
            $entryKey = $characterId . ':' . ($variantId ?? 0);
            if ($characterId <= 0 || isset($seen[$entryKey])) {
                continue;
            }
            $seen[$entryKey] = true;

            $character = $this->characterRepository->getCharacterById($characterId);
            if (!$character || $character->getIdUser() !== $this->currentUserId) {
                continue;
            }
            if ($variantId !== null && !$this->characterRepository->getCharacterVariant($variantId, $characterId)) {
                continue;
            }

            if (!$this->includeHidden() && $this->characterRepository->isHiddenInPath($characterId, $this->currentUserId)) {
                continue;
            }

            $fieldId = ((int)(
                $_POST['story_character_pseudonym_fields'][$entryKey]
                ?? $_POST['story_character_pseudonym_fields'][$characterId]
                ?? 0
            )) ?: null;
            try {
                $added = $this->storyRepository->addCharacterToStory($story->getId(), $characterId, $fieldId, $variantId);
                if ($added && $added->getPseudonymFieldId()) {
                    $this->storyRepository->replaceStoryCharacterPseudonyms(
                        $added->getId(),
                        $this->storyRepository->extractPseudonymsForCharacterField($characterId, $added->getPseudonymFieldId())
                    );
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    private function saveStoryFilters(int $storyId): void
    {
        $filters = $this->filterRepository->resolveTags($this->splitTagInput($_POST['filter_tags'] ?? ''));
        $this->filterRepository->replaceObjectFilters(
            'story',
            $storyId,
            array_map(fn($filter) => (int)$filter['id'], $filters)
        );
    }

    private function storyFilterMap(array $stories): array
    {
        $inherited = $this->storyRepository->getInheritedFiltersByStoryIds(
            array_map(fn($story) => $story->getId(), $stories),
            $this->currentUserId
        );

        $result = [];
        foreach ($stories as $story) {
            $result[$story->getId()] = $this->uniqueFilters(array_merge(
                $inherited[$story->getId()] ?? [],
                $this->filterRepository->getObjectFilters('story', $story->getId())
            ));
        }
        return $result;
    }

    private function worldInheritedFilterMap(array $worlds): array
    {
        $result = [];
        foreach ($worlds as $world) {
            $result[$world->getId()] = $this->filterRepository->getWorldAndAncestorFilters($world->getId(), $this->currentUserId);
        }
        return $result;
    }

    private function uniqueFilters(array $filters): array
    {
        $result = [];
        $seen = [];
        foreach ($filters as $filter) {
            $id = method_exists($filter, 'getId') ? $filter->getId() : null;
            $key = $id !== null ? 'id:' . $id : 'name:' . mb_strtolower((string)(method_exists($filter, 'getName') ? $filter->getName() : ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $filter;
        }
        return $result;
    }

    private function splitTagInput(array|string $tags): array
    {
        $tokens = is_array($tags) ? $tags : explode(',', (string)$tags);
        return array_values(array_filter(array_map(
            fn($tag) => trim((string)$tag),
            $tokens
        )));
    }

    private function includeHidden(): bool
    {
        return !empty($this->getUserInterfaceSettings()['revealHidden']);
    }

    private function worldFromParam(string $param): ?World
    {
        if ($param === '' || $param === '0') {
            return null;
        }

        return ctype_digit($param)
            ? $this->worldRepository->getWorldByIdAndUserId((int)$param, $this->currentUserId)
            : $this->worldRepository->getWorldByPublicIdAndUserId($param, $this->currentUserId);
    }

    private function canAccessWorld(World $world, bool $includeHidden): bool
    {
        return $world->getId() > 0
            && ($includeHidden || !$this->worldRepository->isHiddenInPath($world->getId(), $this->currentUserId));
    }

    private function canAccessOwnedStory(?Story $story, bool $includeHidden): bool
    {
        return $story !== null
            && $story->getIdUser() === $this->currentUserId
            && ($includeHidden || !$story->isHidden())
            && ($includeHidden || !$this->worldRepository->isHiddenInPath($story->getIdWorld(), $this->currentUserId));
    }

    private function canViewStory(Story $story, bool $includeHidden): bool
    {
        if ($story->getIdUser() === $this->currentUserId) {
            return $this->canAccessOwnedStory($story, $includeHidden);
        }

        return $story->getStatus() === 'published' && !$story->isHidden();
    }

    private function requireStoriesLogin(): void
    {
        $this->requireLogin();
        $this->currentUserId = (int)$_SESSION['user_id'];
    }

    private function jsonForPost(): void
    {
        $this->requireStoriesLogin();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }
    }

    private function jsonInput(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        return is_array($input) ? $input : [];
    }

    private function getFreshStoryCharacterPseudonyms(array $character): array
    {
        if (!empty($character['pseudonym_field_id'])) {
            $pseudonyms = $this->storyRepository->extractPseudonymsForCharacterField(
                (int)$character['id_character'],
                (int)$character['pseudonym_field_id']
            );
            return array_map(
                fn($pseudonym) => ['pseudonym' => $pseudonym, 'is_excluded' => false],
                $pseudonyms
            );
        }

        return $this->storyRepository->getStoryPseudonyms((int)$character['id']);
    }

    private function storyStatusFromStatusId(mixed $statusId): string
    {
        return match ((int)$statusId) {
            2 => 'in_progress',
            3 => 'published',
            default => 'draft',
        };
    }

    private function cleanImageName(mixed $image): string
    {
        $image = trim((string)$image);
        return $image !== '' ? $image : 'default_story.png';
    }

    private function cleanImageFit(mixed $fit): string
    {
        $fit = (string)$fit;
        return in_array($fit, ['cover', 'contain'], true) ? $fit : 'cover';
    }

    private function cleanPercent(mixed $value): int
    {
        return max(0, min(100, (int)$value));
    }

    private function cleanZoom(mixed $value): float
    {
        return max(1, min(6, (float)$value));
    }
}
