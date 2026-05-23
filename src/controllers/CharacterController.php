<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/CharacterRepository.php';
require_once __DIR__ . '/../repositories/TemplateRepository.php';
require_once __DIR__ . '/../repositories/WorldRepository.php';
require_once __DIR__ . '/../repositories/CharacterStatusRepository.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';
require_once __DIR__ . '/../services/ImageUploadService.php';

class CharacterController extends AppController
{
    private $characterRepository;
    private $templateRepository;
    private $worldRepository;
    private $statusRepository;
    private $filterRepository;

    public function __construct()
    {
        $this->characterRepository = new CharacterRepository();
        $this->templateRepository  = new TemplateRepository();
        $this->worldRepository     = new WorldRepository();
        $this->statusRepository    = new CharacterStatusRepository();
        $this->filterRepository    = new FilterRepository();
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

    // -----------------------------------------------------------------------
    //  Widok "Postacie" – nawigacja jak Dysk Google
    // -----------------------------------------------------------------------

    public function characters()
    {
        $this->requireLogin();
        $userId = $_SESSION['user_id'];

        // Aktualny folder; null = folder główny
        $worldId = isset($_GET['world']) ? (int)$_GET['world'] : null;

        // Sprawdź czy folder należy do użytkownika (tylko gdy nie-root)
        $currentWorld = null;
        if ($worldId !== null) {
            $currentWorld = $this->worldRepository->getWorldByIdAndUserId($worldId, $userId);
            if (!$currentWorld) {
                // Folder nie istnieje lub nie należy do usera – wróć do root
                header('Location: /characters');
                exit();
            }
        }

        // Podfoldery aktualnego folderu
        $subfolders = $this->worldRepository->getChildWorlds($userId, $worldId);

        // Postacie w aktualnym folderze
        $characters = $this->characterRepository->getCharactersByWorld($userId, $worldId);

        // Breadcrumb (pusta tablica gdy jesteśmy w root)
        $breadcrumb = $worldId !== null
            ? $this->worldRepository->getBreadcrumb($worldId, $userId)
            : [];

        $allStatuses = $this->statusRepository->getAllStatuses();

        return $this->render('characters', [
            'title'        => 'Postacie - OCStudio',
            'characters'   => $characters,
            'subfolders'   => $subfolders,
            'currentWorld' => $currentWorld,
            'breadcrumb'   => $breadcrumb,
            'statuses'     => $allStatuses,
        ]);
    }

    // -----------------------------------------------------------------------
    //  API: utwórz folder (world)
    // -----------------------------------------------------------------------

    public function createWorld()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['name']) || trim($input['name']) === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Brak nazwy folderu']);
            exit();
        }

        $name     = trim($input['name']);
        $parentId = isset($input['parentId']) ? (int)$input['parentId'] : null;
        if ($parentId === 0) {
            $parentId = null;
        }

        // Sprawdź czy parent należy do usera
        if ($parentId !== null) {
            $parent = $this->worldRepository->getWorldByIdAndUserId($parentId, $_SESSION['user_id']);
            if (!$parent) {
                http_response_code(403);
                echo json_encode(['error' => 'Nieprawidłowy folder nadrzędny']);
                exit();
            }
        }

        $worldId = $this->worldRepository->addWorld($name, '', $_SESSION['user_id'], $parentId);

        echo json_encode(['success' => true, 'id' => $worldId, 'name' => $name]);
        exit();
    }

    // -----------------------------------------------------------------------
    //  API: przypisz postać do folderu
    // -----------------------------------------------------------------------

    public function assignCharacterToWorld()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !array_key_exists('characterId', $input) || !array_key_exists('worldId', $input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Brak wymaganych parametrów']);
            exit();
        }

        $characterId = (int) $input['characterId'];
        $worldId     = $input['worldId'] === null ? null : (int) $input['worldId'];

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);
        if (!$character) {
            http_response_code(404);
            echo json_encode(['error' => 'Postać nie znaleziona']);
            exit();
        }

        $this->characterRepository->updateCharacter(
            $characterId,
            $character->getName(),
            $character->getDescription(),
            $character->getImage(),
            $character->getIdTemplate(),
            $worldId
        );

        echo json_encode(['success' => true]);
        exit();
    }

    // -----------------------------------------------------------------------
    //  Pozostałe akcje (niezmienione)
    // -----------------------------------------------------------------------

    public function createCharacter()
    {
        $this->requireLogin();

        if ($this->isPost()) {
            $name        = $_POST['character_name']        ?? '';
            $description = $_POST['character_description'] ?? '';
            $templateId  = $this->parseTemplateId($_POST['template_id'] ?? null);
            $userId      = $_SESSION['user_id'];

            try {
                $image = $this->uploadCharacterImage('default.png');
            } catch (Throwable $e) {
                http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 400);
                return $this->render('create_character', [
                    'title'     => 'Stworz postac - OCStudio',
                    'templates' => $this->templateRepository->getTemplatesByUserId($_SESSION['user_id']),
                    'messages'  => [$e->getMessage()]
                ]);
            }

            $worldId     = isset($_POST['world_id']) ? (int) $_POST['world_id'] : null;
            $characterId = $this->characterRepository->addCharacter($name, $description, $image, $userId, $templateId, $worldId);

            if (isset($_POST['field_values']) && is_array($_POST['field_values'])) {
                $this->characterRepository->saveCharacterFieldValues($characterId, $_POST['field_values']);
            }

            header('Location: /viewCharacter?id=' . $characterId);
            exit();
        }

        $templates = $this->templateRepository->getTemplatesByUserId($_SESSION['user_id']);

        return $this->render('create_character', [
            'title'     => 'Stwórz postać - OCStudio',
            'templates' => $templates
        ]);
    }

    public function getTemplateData()
    {
        header('Content-Type: application/json');

        if (!isset($_GET['id'])) {
            echo json_encode(['error' => 'Brak ID szablonu']);
            exit();
        }

        $id       = (int) $_GET['id'];
        $template = $this->templateRepository->getTemplate($id);

        if (!$template) {
            echo json_encode(['error' => 'Nie znaleziono szablonu']);
            exit();
        }

        $fields = $this->templateRepository->getTemplateFields($id);

        echo json_encode([
            'id'          => $template->getId(),
            'name'        => $template->getName(),
            'description' => $template->getDescription(),
            'fields'      => $fields
        ]);
        exit();
    }

    public function viewCharacter()
    {
        $this->requireLogin();

        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
        if (!$id) {
            header('Location: /dashboard');
            exit();
        }

        $character = $this->characterRepository->getCharacterByIdAndUserId($id, $_SESSION['user_id']);
        if (!$character) {
            http_response_code(404);
            header('Location: /dashboard');
            exit();
        }

        $template = $character->getIdTemplate()
            ? $this->templateRepository->getTemplate($character->getIdTemplate())
            : null;
        $fields = $character->getIdTemplate()
            ? $this->templateRepository->getTemplateFields($character->getIdTemplate())
            : [];
        $values   = $this->characterRepository->getCharacterFieldValues($character->getId());
        $variants = $this->characterRepository->getCharacterVariants($character->getId());

        $selectedVariant = null;
        $variantId = isset($_GET['variant']) ? (int)$_GET['variant'] : null;
        if ($variantId) {
            $selectedVariant = $this->characterRepository->getCharacterVariant($variantId, $character->getId());
            if ($selectedVariant) {
                $values = array_replace($values, $selectedVariant['values']);
            }
        }

        return $this->render('view_character', [
            'title'               => $character->getName() . ' - OCStudio',
            'character'           => $character,
            'template'            => $template,
            'fields'              => $fields,
            'characterFieldValues' => $values,
            'variants'            => $variants,
            'selectedVariant'     => $selectedVariant
        ]);
    }

    public function editCharacter()
    {
        $this->requireLogin();

        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
        if (!$id) {
            header('Location: /dashboard');
            exit();
        }

        $character = $this->characterRepository->getCharacterByIdAndUserId($id, $_SESSION['user_id']);
        if (!$character) {
            header('Location: /dashboard');
            exit();
        }

        if ($this->isPost()) {
            $name        = $_POST['character_name']        ?? '';
            $description = $_POST['character_description'] ?? '';
            $templateId  = $this->parseTemplateId($_POST['template_id'] ?? null);

            // Jeśli user kliknął "Usuń zdjęcie" – przywracamy default.png
            if (!empty($_POST['remove_image'])) {
                $image = 'default.png';
            } else {
                try {
                    // Fallback: zachowaj aktualne zdjęcie (lub default.png jeśli puste)
                    $currentImage = $character->getImage() ?: 'default.png';
                    $image = $this->uploadCharacterImage($currentImage);
                } catch (Throwable $e) {
                    http_response_code(($e->getCode() >= 400 && $e->getCode() <= 599) ? $e->getCode() : 400);
                    return $this->render('create_character', [
                        'title'               => 'Edytuj postac - OCStudio',
                        'character'           => $character,
                        'templates'           => $this->templateRepository->getTemplatesByUserId($_SESSION['user_id']),
                        'characterFieldValues' => $this->characterRepository->getCharacterFieldValues($character->getId()),
                        'messages'            => [$e->getMessage()]
                    ]);
                }
            }

            // Upewnij się że nigdy nie trafia pusty string do bazy
            if (!$image) {
                $image = 'default.png';
            }

            $this->characterRepository->updateCharacter($id, $name, $description, $image, $templateId);

            if (isset($_POST['field_values']) && is_array($_POST['field_values'])) {
                $this->characterRepository->saveCharacterFieldValues($id, $_POST['field_values']);
            }

            $this->characterRepository->replaceCharacterVariants($id, $this->prepareVariantsFromPost());

            header('Location: /dashboard');
            exit();
        }

        $templates       = $this->templateRepository->getTemplatesByUserId($_SESSION['user_id']);
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
            'variants'             => $variants
        ]);
    }

    // -----------------------------------------------------------------------
    //  API: Zmiana statusu postaci/folderu
    // -----------------------------------------------------------------------

    public function updateCharacterStatus()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['characterId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Brak wymaganych parametrów']);
            exit();
        }

        $characterId = (int) $input['characterId'];
        $statusId = isset($input['statusId']) ? (int)$input['statusId'] : null;

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);
        if (!$character) {
            http_response_code(404);
            echo json_encode(['error' => 'Postać nie znaleziona']);
            exit();
        }

        $this->characterRepository->updateCharacterStatus($characterId, $statusId);

        echo json_encode(['success' => true]);
        exit();
    }

    // -----------------------------------------------------------------------
    //  API: Dodaj/usuń filtr do postaci
    // -----------------------------------------------------------------------

    public function addCharacterFilter()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['characterId']) || !isset($input['filterName'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Brak wymaganych parametrów']);
            exit();
        }

        $characterId = (int) $input['characterId'];
        $filterName = trim($input['filterName']);

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);
        if (!$character) {
            http_response_code(404);
            echo json_encode(['error' => 'Postać nie znaleziona']);
            exit();
        }

        $filterId = $this->filterRepository->getOrCreateFilter($filterName, $_SESSION['user_id']);
        $this->filterRepository->addCharacterFilter($characterId, $filterId, false);

        echo json_encode(['success' => true, 'filterId' => $filterId]);
        exit();
    }

    public function removeCharacterFilter()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['characterId']) || !isset($input['filterId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Brak wymaganych parametrów']);
            exit();
        }

        $characterId = (int) $input['characterId'];
        $filterId = (int) $input['filterId'];

        $character = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);
        if (!$character) {
            http_response_code(404);
            echo json_encode(['error' => 'Postać nie znaleziona']);
            exit();
        }

        $this->filterRepository->removeCharacterFilter($characterId, $filterId);

        echo json_encode(['success' => true]);
        exit();
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

        $filters = $this->filterRepository->searchFilters($query, $_SESSION['user_id']);

        $result = [];
        foreach ($filters as $filter) {
            $result[] = [
                'id' => $filter->getId(),
                'name' => $filter->getName()
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

        $characters = $this->characterRepository->searchCharactersByNameAndFilters($userId, $nameTerm, $filterNames);

        $out = [];
        foreach ($characters as $c) {
            $out[] = [
                'id' => $c->getId(),
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
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['filterId']) || !isset($input['block'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Brak wymaganych parametrów']);
            exit();
        }

        $filterId = (int) $input['filterId'];
        $block = (bool) $input['block'];

        if ($block) {
            $this->filterRepository->blockFilter($_SESSION['user_id'], $filterId);
        } else {
            $this->filterRepository->unblockFilter($_SESSION['user_id'], $filterId);
        }

        echo json_encode(['success' => true]);
        exit();
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
            echo json_encode(['characters' => [], 'worlds' => []]);
            exit();
        }
 
        $userId   = $_SESSION['user_id'];
        $statuses = $this->statusRepository->getAllStatuses();
 
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
                'name'        => $c->getName(),
                'image'       => $c->getImage() ?: 'default.png',
                'statusName'  => $statusName,
                'statusColor' => $statusColor,
            ];
        };
 
        // 1. Postacie pasujące bezpośrednio po nazwie
        $chars    = $this->characterRepository->searchCharactersByNameAndFilters($userId, $q, []);
        $charsOut = array_map($charToArray, $chars);
 
        // 2. Foldery pasujące po nazwie + postacie z całego poddrzewa (rekursywnie)
        $worldsOut = [];
        try {
            $matchingWorlds = $this->worldRepository->searchWorldsByName($userId, $q);
 
            foreach ($matchingWorlds as $world) {
                // Pobierz ID wszystkich podfolderów (włącznie z samym folderem)
                $subtreeIds = $this->worldRepository->getDescendantWorldIds($world->getId(), $userId);
 
                // Zbierz postacie z każdego folderu w poddrzewie
                $wCharsOut = [];
                $seen      = [];
                foreach ($subtreeIds as $wid) {
                    $wChars = $this->characterRepository->getCharactersByWorld($userId, $wid);
                    foreach ($wChars as $c) {
                        if (!isset($seen[$c->getId()])) {
                            $seen[$c->getId()] = true;
                            $wCharsOut[]       = $charToArray($c);
                        }
                    }
                }
 
                $worldsOut[] = [
                    'id'         => $world->getId(),
                    'name'       => $world->getName(),
                    'characters' => $wCharsOut,
                ];
            }
        } catch (Throwable $e) {
            // getDescendantWorldIds może nie istnieć na starszych wersjach – ignorujemy
        }
 
        echo json_encode(['characters' => $charsOut, 'worlds' => $worldsOut]);
        exit();
    }

    // -----------------------------------------------------------------------
    //  API: Przywróć domyślne zdjęcie postaci
    // -----------------------------------------------------------------------

    public function restoreDefaultImage()
    {
        $this->requireLogin();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['characterId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Brak wymaganych parametrów']);
            exit();
        }

        $characterId = (int) $input['characterId'];
        $character   = $this->characterRepository->getCharacterByIdAndUserId($characterId, $_SESSION['user_id']);

        if (!$character) {
            http_response_code(404);
            echo json_encode(['error' => 'Postać nie znaleziona']);
            exit();
        }

        $this->characterRepository->updateCharacter(
            $characterId,
            $character->getName(),
            $character->getDescription(),
            'default.png',
            $character->getIdTemplate(),
            $character->getIdWorld()
        );

        echo json_encode(['success' => true]);
        exit();
    }

    private function uploadCharacterImage(string $fallback): string
    {
        if (empty($fallback)) {
            $fallback = 'default.png';
        }

        $file = $_FILES['character_image'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $fallback;
        }

        $uploaded = (new ImageUploadService())->upload($file);
        return $uploaded['filename'] ?: $fallback;
    }

    private function prepareVariantsFromPost(): array
    {
        $postedVariants = $_POST['variants'] ?? [];
        if (!is_array($postedVariants)) {
            return [];
        }

        $variants = [];
        foreach ($postedVariants as $key => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $name = trim($variant['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $image = $variant['existing_image'] ?? null;
            $file  = $this->getVariantUploadFile((string)$key);
            if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploaded = (new ImageUploadService())->upload($file);
                $image    = $uploaded['filename'];
            }

            $variants[] = [
                'name'   => $name,
                'image'  => $image ?: null,
                'values' => is_array($variant['values'] ?? null) ? $variant['values'] : []
            ];
        }

        return $variants;
    }

    private function getVariantUploadFile(string $key): ?array
    {
        if (!isset($_FILES['variant_images']['name'][$key])) {
            return null;
        }

        return [
            'name'     => $_FILES['variant_images']['name'][$key],
            'type'     => $_FILES['variant_images']['type'][$key],
            'tmp_name' => $_FILES['variant_images']['tmp_name'][$key],
            'error'    => $_FILES['variant_images']['error'][$key],
            'size'     => $_FILES['variant_images']['size'][$key],
        ];
    }
}