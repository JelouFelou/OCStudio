<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/RelationRepository.php';
require_once __DIR__ . '/../repositories/WorldRepository.php';
require_once __DIR__ . '/../repositories/CharacterRepository.php';

class RelationController extends AppController
{
    private RelationRepository $relationRepository;
    private WorldRepository $worldRepository;
    private CharacterRepository $characterRepository;

    public function __construct()
    {
        $this->relationRepository = new RelationRepository();
        $this->worldRepository = new WorldRepository();
        $this->characterRepository = new CharacterRepository();
    }

    public function index(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Relacje sa obecnie wylaczone.');
        $userId = (int)$_SESSION['user_id'];
        $includeHidden = !empty($this->getUserInterfaceSettings()['revealHidden']);
        $boards = $this->relationRepository->getBoards($userId, $includeHidden);
        $worlds = $this->worldRepository->getWorldsByUserId($userId, $includeHidden);
        $characters = $this->characterRepository->getCharactersByUserId($userId, $includeHidden);

        $this->render('relations', [
            'title' => 'Relacje - OCStudio',
            'relationBoards' => $boards,
            'relationWorlds' => $worlds,
            'relationCharacters' => $characters,
        ]);
    }

    public function editor(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Relacje sa obecnie wylaczone.');
        $userId = (int)$_SESSION['user_id'];
        $includeHidden = !empty($this->getUserInterfaceSettings()['revealHidden']);
        $boardParam = trim((string)($_GET['board'] ?? ''));
        $focusCharacterId = isset($_GET['focusCharacter']) ? (int)$_GET['focusCharacter'] : null;
        $returnUrl = $this->safeReturnUrl($_GET['return_url'] ?? '', '/relations');
        $locale = $this->currentLocale();
        $returnLabel = strpos($returnUrl, '/characters/') === 0
            ? LocaleService::translate('characters.back_to_folder', $locale)
            : LocaleService::translate('relations.editor.back_to_list', $locale);

        $board = $boardParam !== ''
            ? (ctype_digit($boardParam)
                ? $this->relationRepository->getBoard($userId, (int)$boardParam, $includeHidden)
                : $this->relationRepository->getBoardByPublicId($userId, $boardParam, $includeHidden))
            : null;
        if (!$board) {
            header('Location: /relations');
            exit();
        }
        $boardId = (int)$board['id'];

        $this->render('relations_editor', [
            'title' => 'Edytor relacji - OCStudio',
            'board' => $board,
            'boardId' => $boardId,
            'focusCharacterId' => $focusCharacterId,
            'returnUrl' => $returnUrl,
            'returnLabel' => $returnLabel,
        ]);
    }

    private function safeReturnUrl(?string $url, string $fallback): string
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

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return $path . $query;
    }

    public function tree(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Relacje sa obecnie wylaczone.', true);
        $this->relationJsonResponse(function () {
            $boardId = (int)($_GET['boardId'] ?? 0);
            if ($boardId <= 0) {
                throw new InvalidArgumentException('Brak pola relacji.');
            }
            return $this->relationRepository->getTreeData(
                (int)$_SESSION['user_id'],
                $boardId,
                !empty($this->getUserInterfaceSettings()['revealHidden'])
            );
        });
    }

    public function saveBoard(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Tworzenie relacji jest obecnie wylaczone.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('Podaj nazwe relacji.');
            }

            $id = $this->relationRepository->saveBoard(
                (int)$_SESSION['user_id'],
                isset($input['boardId']) && (int)$input['boardId'] > 0 ? (int)$input['boardId'] : null,
                mb_substr($name, 0, 120),
                $this->cleanOptionalString($input['description'] ?? '', 1000) ?? '',
                is_array($input['worldIds'] ?? null) ? $input['worldIds'] : [],
                is_array($input['characterIds'] ?? null) ? $input['characterIds'] : []
            );

            $board = $this->relationRepository->getBoard((int)$_SESSION['user_id'], $id);
            return ['success' => true, 'id' => $id, 'publicId' => $board['public_id'] ?? (string)$id];
        });
    }

    public function duplicateBoard(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Tworzenie relacji jest obecnie wylaczone.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);
            if ($boardId <= 0) {
                throw new InvalidArgumentException('Brak pola relacji.');
            }

            $newId = $this->relationRepository->duplicateBoard((int)$_SESSION['user_id'], $boardId);
            $board = $this->relationRepository->getBoard((int)$_SESSION['user_id'], $newId);
            return ['success' => true, 'id' => $newId, 'publicId' => $board['public_id'] ?? (string)$newId];
        });
    }

    public function deleteBoard(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Usuwanie relacji jest obecnie wylaczone.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);
            if ($boardId <= 0) {
                throw new InvalidArgumentException('Brak pola relacji.');
            }

            $board = $this->relationRepository->getBoard((int)$_SESSION['user_id'], $boardId);
            if (!$board) {
                throw new InvalidArgumentException('Pole relacji nie istnieje.');
            }

            $confirmation = trim((string)($input['confirmation'] ?? ''));
            if ($confirmation !== (string)($board['name'] ?? '')) {
                throw new InvalidArgumentException('Nazwa relacji nie zgadza sie.');
            }

            $this->relationRepository->deleteBoard((int)$_SESSION['user_id'], $boardId);
            return ['success' => true];
        });
    }

    public function toggleBoardHidden(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Edycja relacji jest obecnie wylaczona.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);
            if ($boardId <= 0) {
                throw new InvalidArgumentException('Brak pola relacji.');
            }

            $this->relationRepository->setBoardHidden(
                (int)$_SESSION['user_id'],
                $boardId,
                !empty($input['hidden'])
            );

            return ['success' => true];
        });
    }

    public function addNode(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Edycja relacji jest obecnie wylaczona.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);

            $characterId = (int)($input['characterId'] ?? 0);
            $variantId = ((int)($input['variantId'] ?? 0)) ?: null;
            if ($characterId <= 0) {
                throw new InvalidArgumentException('Brak postaci.');
            }

            return [
                'success' => true,
                'node' => $this->relationRepository->addNode(
                    (int)$_SESSION['user_id'],
                    $boardId,
                    $characterId,
                    $variantId,
                    (float)($input['x'] ?? 0),
                    (float)($input['y'] ?? 0)
                ),
            ];
        });
    }

    public function updateNodePosition(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Edycja relacji jest obecnie wylaczona.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);

            $characterId = (int)($input['characterId'] ?? 0);
            $variantId = ((int)($input['variantId'] ?? 0)) ?: null;
            if ($characterId <= 0) {
                throw new InvalidArgumentException('Brak postaci.');
            }

            $this->relationRepository->updateNodePosition(
                (int)$_SESSION['user_id'],
                $boardId,
                $characterId,
                $variantId,
                (float)($input['x'] ?? 0),
                (float)($input['y'] ?? 0)
            );

            return ['success' => true];
        });
    }

    public function removeNode(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Edycja relacji jest obecnie wylaczona.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);

            $characterId = (int)($input['characterId'] ?? 0);
            $variantId = ((int)($input['variantId'] ?? 0)) ?: null;
            if ($characterId <= 0) {
                throw new InvalidArgumentException('Brak postaci.');
            }

            $this->relationRepository->removeNode((int)$_SESSION['user_id'], $boardId, $characterId, $variantId);
            return ['success' => true];
        });
    }

    public function saveRelation(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Edycja relacji jest obecnie wylaczona.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $relationId = $this->relationRepository->saveRelation(
                (int)$_SESSION['user_id'],
                (int)($input['characterAId'] ?? 0),
                ((int)($input['characterAVariantId'] ?? 0)) ?: null,
                (int)($input['characterBId'] ?? 0),
                ((int)($input['characterBVariantId'] ?? 0)) ?: null,
                (int)($input['relationTypeId'] ?? 0),
                $this->cleanOptionalString($input['customName'] ?? null, 100),
                $this->cleanOptionalString($input['customIcon'] ?? null, 16),
                $this->cleanColorHex($input['customColorHex'] ?? null),
                $this->cleanOptionalString($input['note'] ?? '', 1000) ?? ''
            );

            return ['success' => true, 'id' => $relationId];
        });
    }

    public function deleteRelation(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Edycja relacji jest obecnie wylaczona.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $relationId = (int)($input['relationId'] ?? 0);
            if ($relationId <= 0) {
                throw new InvalidArgumentException('Brak relacji.');
            }

            $this->relationRepository->deleteRelation((int)$_SESSION['user_id'], $relationId);
            return ['success' => true];
        });
    }

    public function saveRules(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('relations.enabled', 'Edycja relacji jest obecnie wylaczona.', true);
        $this->requirePost();
        $this->relationJsonResponse(function () {
            $input = $this->jsonInput();
            $worldId = $this->parseNullableWorldId($input['worldId'] ?? null);
            $this->assertWorldAccess($worldId);

            $this->relationRepository->saveRules(
                (int)$_SESSION['user_id'],
                $worldId,
                is_array($input['excludedWorldIds'] ?? null) ? $input['excludedWorldIds'] : [],
                is_array($input['exceptionCharacterIds'] ?? null) ? $input['exceptionCharacterIds'] : []
            );

            return ['success' => true];
        });
    }

    private function relationJsonResponse(callable $callback): void
    {
        header('Content-Type: application/json');
        try {
            echo json_encode($callback());
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(403);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Nie udalo sie wykonac operacji relacji.']);
        }
        exit();
    }

    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }

        $this->validateCsrfRequest(true);
    }

    private function jsonInput(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new InvalidArgumentException('Nieprawidlowe dane.');
        }

        return $input;
    }

    private function parseNullableWorldId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === '0') {
            return null;
        }

        $worldId = (int)$raw;
        return $worldId > 0 ? $worldId : null;
    }

    private function assertWorldAccess(?int $worldId): void
    {
        if ($worldId === null) {
            return;
        }

        if (!$this->worldRepository->getWorldByIdAndUserId($worldId, (int)$_SESSION['user_id'])) {
            throw new RuntimeException('Folder nie nalezy do uzytkownika.');
        }
    }

    private function cleanOptionalString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function cleanColorHex(mixed $value): ?string
    {
        $value = trim((string)$value);
        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtoupper($value) : null;
    }
}
