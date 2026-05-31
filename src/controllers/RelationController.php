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
        $userId = (int)$_SESSION['user_id'];
        $boards = $this->relationRepository->getBoards($userId);
        $worlds = $this->worldRepository->getWorldsByUserId($userId);
        $characters = $this->characterRepository->getCharactersByUserId($userId);

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
        $userId = (int)$_SESSION['user_id'];
        $boardId = (int)($_GET['board'] ?? 0);
        $focusCharacterId = isset($_GET['focusCharacter']) ? (int)$_GET['focusCharacter'] : null;

        $board = $boardId > 0 ? $this->relationRepository->getBoard($userId, $boardId) : null;
        if (!$board) {
            header('Location: /relations');
            exit();
        }

        $this->render('relations_editor', [
            'title' => 'Edytor relacji - OCStudio',
            'board' => $board,
            'boardId' => $boardId,
            'focusCharacterId' => $focusCharacterId,
        ]);
    }

    public function tree(): void
    {
        $this->requireLogin();
        $this->jsonResponse(function () {
            $boardId = (int)($_GET['boardId'] ?? 0);
            if ($boardId <= 0) {
                throw new InvalidArgumentException('Brak pola relacji.');
            }
            return $this->relationRepository->getTreeData((int)$_SESSION['user_id'], $boardId);
        });
    }

    public function saveBoard(): void
    {
        $this->requireLogin();
        $this->requirePost();
        $this->jsonResponse(function () {
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

            return ['success' => true, 'id' => $id];
        });
    }

    public function duplicateBoard(): void
    {
        $this->requireLogin();
        $this->requirePost();
        $this->jsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);
            if ($boardId <= 0) {
                throw new InvalidArgumentException('Brak pola relacji.');
            }

            return ['success' => true, 'id' => $this->relationRepository->duplicateBoard((int)$_SESSION['user_id'], $boardId)];
        });
    }

    public function deleteBoard(): void
    {
        $this->requireLogin();
        $this->requirePost();
        $this->jsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);
            if ($boardId <= 0) {
                throw new InvalidArgumentException('Brak pola relacji.');
            }

            $this->relationRepository->deleteBoard((int)$_SESSION['user_id'], $boardId);
            return ['success' => true];
        });
    }

    public function addNode(): void
    {
        $this->requireLogin();
        $this->requirePost();
        $this->jsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);

            $characterId = (int)($input['characterId'] ?? 0);
            if ($characterId <= 0) {
                throw new InvalidArgumentException('Brak postaci.');
            }

            return [
                'success' => true,
                'node' => $this->relationRepository->addNode(
                    (int)$_SESSION['user_id'],
                    $boardId,
                    $characterId,
                    (float)($input['x'] ?? 0),
                    (float)($input['y'] ?? 0)
                ),
            ];
        });
    }

    public function updateNodePosition(): void
    {
        $this->requireLogin();
        $this->requirePost();
        $this->jsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);

            $characterId = (int)($input['characterId'] ?? 0);
            if ($characterId <= 0) {
                throw new InvalidArgumentException('Brak postaci.');
            }

            $this->relationRepository->updateNodePosition(
                (int)$_SESSION['user_id'],
                $boardId,
                $characterId,
                (float)($input['x'] ?? 0),
                (float)($input['y'] ?? 0)
            );

            return ['success' => true];
        });
    }

    public function removeNode(): void
    {
        $this->requireLogin();
        $this->requirePost();
        $this->jsonResponse(function () {
            $input = $this->jsonInput();
            $boardId = (int)($input['boardId'] ?? 0);

            $characterId = (int)($input['characterId'] ?? 0);
            if ($characterId <= 0) {
                throw new InvalidArgumentException('Brak postaci.');
            }

            $this->relationRepository->removeNode((int)$_SESSION['user_id'], $boardId, $characterId);
            return ['success' => true];
        });
    }

    public function saveRelation(): void
    {
        $this->requireLogin();
        $this->requirePost();
        $this->jsonResponse(function () {
            $input = $this->jsonInput();
            $relationId = $this->relationRepository->saveRelation(
                (int)$_SESSION['user_id'],
                (int)($input['characterAId'] ?? 0),
                (int)($input['characterBId'] ?? 0),
                (int)($input['relationTypeId'] ?? 0),
                $this->cleanOptionalString($input['customName'] ?? null, 100),
                $this->cleanOptionalString($input['note'] ?? '', 1000) ?? ''
            );

            return ['success' => true, 'id' => $relationId];
        });
    }

    public function deleteRelation(): void
    {
        $this->requireLogin();
        $this->requirePost();
        $this->jsonResponse(function () {
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
        $this->requirePost();
        $this->jsonResponse(function () {
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

    private function jsonResponse(callable $callback): void
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
}
