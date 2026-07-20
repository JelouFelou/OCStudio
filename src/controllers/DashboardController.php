<?php

require_once 'AppController.php';
require_once __DIR__.'/../repositories/UserRepository.php';
require_once __DIR__.'/../repositories/CharacterRepository.php';
require_once __DIR__.'/../repositories/TemplateRepository.php';
require_once __DIR__.'/../repositories/WorldRepository.php';
require_once __DIR__.'/../repositories/CharacterStatusRepository.php';
require_once __DIR__.'/../repositories/FilterRepository.php';
require_once __DIR__.'/../repositories/StoryRepository.php';
require_once __DIR__.'/../repositories/PublicationRepository.php';

class DashboardController extends AppController {
    public function index() {
        $this->requireLogin();

        $usersRepository = new UsersRepository();
        $users = $usersRepository->getUsers();
        $includeHidden = false;
        $includeAdult = false;

        $characterRepository = new CharacterRepository();
        $allCharacters = $characterRepository->getCharactersByUserId($_SESSION['user_id'], $includeHidden);
        $numberOfCharacters = 0;

        $templateRepository = new TemplateRepository();
        $filterRepository = new FilterRepository();
        $blockedFilterIds = $filterRepository->blockedFilterIds((int)$_SESSION['user_id']);
        $templates = $templateRepository->getTemplatesByUserId(
            (int)$_SESSION['user_id'],
            $blockedFilterIds,
            $includeHidden
        );
        $numberOfTemplates = 0;

        $worldRepository = new WorldRepository();
        $worlds = $worldRepository->getWorldsByUserId($_SESSION['user_id'], $includeHidden);
        $numberOfWorlds = count($worlds);

        $storyRepository = new StoryRepository();
        $stories = $storyRepository->getStoriesByUser((int)$_SESSION['user_id'], 0, 0, $includeHidden);
        $numberOfStories = 0;

        $statusRepository = new CharacterStatusRepository();
        $statuses = $statusRepository->getAllStatuses();

        // --- Losowanie 6 postaci z priorytetem "niedokończonych" ---
        // Niedokończona = brak własnego zdjęcia LUB brak przypisanego szablonu
        $incomplete = [];
        $complete   = [];

        $visibleCharacters = [];
        $allCharacterFiltersById = [];
        foreach ($allCharacters as $char) {
            $filters = $filterRepository->getAllCharacterFilters($char->getId());
            if ($char->getIdWorld() !== null) {
                $filters = array_merge($filters, $filterRepository->getWorldAndAncestorFilters($char->getIdWorld(), (int)$_SESSION['user_id']));
            }
            $allCharacterFiltersById[$char->getId()] = $filters;
            if (!$includeAdult && $this->filtersHaveAdult($filters)) {
                continue;
            }
            $visibleCharacters[] = $char;
            $numberOfCharacters++;
            $noImage    = !$char->getImage() || in_array($char->getImage(), ['default.png', 'default.jpg', 'default_dark.png', '']);
            $noTemplate = $char->getIdTemplate() === null;

            if ($noImage || $noTemplate) {
                $incomplete[] = $char;
            } else {
                $complete[] = $char;
            }
        }

        // Losujemy kolejność w obu grupach
        shuffle($incomplete);
        shuffle($complete);

        // Bierzemy najpierw z niedokończonych, dopełniamy ukończonymi, max 6
        $dashboardChars = array_slice(
            array_merge($incomplete, $complete),
            0,
            6
        );
        $variantsByCharacterId = $characterRepository->getCharacterVariantsByCharacterIds(
            array_map(fn($character) => $character->getId(), $dashboardChars),
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
        foreach ($dashboardChars as $character) {
            $characterFiltersById[$character->getId()] = $allCharacterFiltersById[$character->getId()] ?? [];
        }
        $publicationRepository = new PublicationRepository();
        $publicationMap = $publicationRepository->ownedCharacterPublicationMap(
            (int)$_SESSION['user_id'],
            array_map(fn($character) => $character->getId(), $dashboardChars)
        );

        $templateFiltersById = [];
        $dashboardTemplates = [];
        foreach ($templates as $template) {
            $filters = $filterRepository->getObjectFilters('template', $template->getId());
            if (!$includeAdult && $this->filtersHaveAdult($filters)) {
                continue;
            }
            $templateFiltersById[$template->getId()] = $filters;
            $dashboardTemplates[] = $template;
            $numberOfTemplates++;
        }
        $dashboardTemplates = array_slice($dashboardTemplates, 0, 6);

        $storyDirectFiltersById = [];
        $storyIds = array_map(fn($story) => $story->getId(), $stories);
        $storyInheritedFiltersById = $storyRepository->getInheritedFiltersByStoryIds($storyIds, (int)$_SESSION['user_id']);
        $dashboardStories = [];
        foreach ($stories as $story) {
            $filters = array_merge(
                $storyInheritedFiltersById[$story->getId()] ?? [],
                $filterRepository->getObjectFilters('story', $story->getId())
            );
            if (!$includeAdult && $this->filtersHaveAdult($filters)) {
                continue;
            }
            $storyDirectFiltersById[$story->getId()] = $filters;
            $dashboardStories[] = $story;
            $numberOfStories++;
        }
        $dashboardStories = array_slice($dashboardStories, 0, 6);

        return $this->render('dashboard', [
            "title"          => "OCStudio - Dashboard",
            "users"          => $users,
            "characters"     => $dashboardChars,
            "variantsByCharacterId" => $variantsByCharacterId,
            "characterFiltersById" => $characterFiltersById,
            "characterPublicationMap" => $publicationMap,
            "stories"        => $dashboardStories,
            "storyFiltersById" => $storyDirectFiltersById,
            "templates"      => $dashboardTemplates,
            "templateFiltersById" => $templateFiltersById,
            "characterCount" => $numberOfCharacters,
            "templateCount"  => $numberOfTemplates,
            "worldCount"     => $numberOfWorlds,
            "storyCount"     => $numberOfStories,
            "statuses"       => $statuses,
        ]);
    }

    private function filtersHaveAdult(array $filters): bool
    {
        foreach ($filters as $filter) {
            foreach (['getSlug', 'getName', 'getLabel'] as $method) {
                $value = method_exists($filter, $method) ? mb_strtolower(trim((string)$filter->$method())) : '';
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
            $id = is_array($filter)
                ? (int)($filter['id'] ?? 0)
                : (is_object($filter) && method_exists($filter, 'getId') ? (int)$filter->getId() : 0);
            if ($id > 0 && in_array($id, $blockedFilterIds, true)) {
                return true;
            }
        }

        return false;
    }
}
