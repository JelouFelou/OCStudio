<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';

class SettingsController extends AppController
{
    private const DEFAULT_UPLOADS = ['default.png', 'default.jpg', 'default_dark.png', 'default_story.png', 'default_story.jpg', 'default_story_dark.png'];
    private const ACCOUNT_TABLES = [
        'filters',
        'templates',
        'template_fields',
        'worlds',
        'characters',
        'character_variants',
        'character_field_values',
        'character_variant_field_values',
        'character_filters',
        'world_filters',
        'user_blocked_filters',
        'image_assets',
        'image_asset_filters',
        'content_filters',
        'relation_boards',
        'relation_board_worlds',
        'relation_board_characters',
        'character_relations',
        'relation_tree_nodes',
        'relation_tree_rules',
        'relation_tree_character_exceptions',
        'story_folders',
        'stories',
        'story_fields',
        'story_field_values',
        'story_characters',
        'story_character_pseudonym_mapping',
    ];
    private const USER_OWNED_TABLES = [
        'templates',
        'worlds',
        'characters',
        'user_blocked_filters',
        'image_assets',
        'relation_boards',
        'character_relations',
        'relation_tree_nodes',
        'relation_tree_rules',
        'relation_tree_character_exceptions',
        'story_folders',
        'stories',
    ];

    private FilterRepository $filterRepository;

    public function __construct()
    {
        $this->filterRepository = new FilterRepository();
    }

    public function index(): void
    {
        $this->requireLogin();

        if ($this->isPost()) {
            $theme = $_POST['theme'] ?? 'light';
            $accent = $_POST['accent'] ?? 'orange';
            $columns = (int)($_POST['columns'] ?? 4);
            $revealHidden = isset($_POST['reveal_hidden']) ? '1' : '0';
            $revealAdultImages = isset($_POST['reveal_adult_images']) ? '1' : '0';
            $rememberCharacterVariant = isset($_POST['remember_character_variant']) ? '1' : '0';

            if (!in_array($theme, ['light', 'dark'], true)) {
                $theme = 'light';
            }
            if (!in_array($accent, ['orange', 'green', 'blue', 'purple', 'rose'], true)) {
                $accent = 'orange';
            }
            $columns = max(4, min(10, $columns));

            $expires = time() + 60 * 60 * 24 * 365;
            setcookie('oc_theme', $theme, ['expires' => $expires, 'path' => '/', 'samesite' => 'Lax']);
            setcookie('oc_accent', $accent, ['expires' => $expires, 'path' => '/', 'samesite' => 'Lax']);
            setcookie('oc_columns', (string)$columns, ['expires' => $expires, 'path' => '/', 'samesite' => 'Lax']);
            setcookie('oc_reveal_hidden', $revealHidden, ['expires' => $expires, 'path' => '/', 'samesite' => 'Lax']);
            setcookie('oc_reveal_adult_images', $revealAdultImages, ['expires' => $expires, 'path' => '/', 'samesite' => 'Lax']);
            setcookie('oc_remember_character_variant', $rememberCharacterVariant, ['expires' => $expires, 'path' => '/', 'samesite' => 'Lax']);

            $blockedTags = trim($_POST['blocked_tags'] ?? '');
            if ($blockedTags !== '') {
                $filters = $this->filterRepository->resolveTags($blockedTags);
                $this->filterRepository->replaceUserBlockedFilters(
                    (int)$_SESSION['user_id'],
                    array_map(fn($filter) => (int)$filter['id'], $filters)
                );
            } else {
                $this->filterRepository->replaceUserBlockedFilters((int)$_SESSION['user_id'], []);
            }

            header('Location: /settings?saved=1');
            exit();
        }

        $blockedFilters = $this->filterRepository->getUserBlockedFilters((int)$_SESSION['user_id']);

        $this->render('settings', [
            'title' => 'Settings - OCStudio',
            'settingsSaved' => isset($_GET['saved']),
            'importMessage' => $this->importMessage($_GET['imported'] ?? null),
            'blockedTags' => implode(', ', array_map(fn($filter) => $filter->getName(), $blockedFilters)),
        ]);
    }

    public function exportAccount(): void
    {
        $this->requireLogin();

        $userId = (int)$_SESSION['user_id'];
        $db = (new Database())->connect();
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
        $baseName = 'ocstudio_account_' . $userId . '_' . $now->format('Ymd_His');
        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $baseName . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            echo 'Nie mozna utworzyc pliku eksportu.';
            exit();
        }

        $imageRows = $this->accountRows($db, 'image_assets', 'id_user = :userId', ['userId' => $userId]);
        $filenames = array_values(array_unique(array_filter(array_map(fn($row) => basename((string)$row['filename']), $imageRows))));

        $zip->addFromString('manifest.json', json_encode([
            'format' => 'ocstudio-account-export',
            'version' => 1,
            'createdAt' => $now->format(DateTimeInterface::ATOM),
            'userId' => $userId,
            'notes' => 'Eksport zawiera prywatne dane konta oraz pliki uploadow. Przechowuj go bezpiecznie.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('data.json', json_encode(
            $this->buildAccountJsonExport($db, $userId, $now),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $zip->addFromString('data.sql', $this->buildAccountSqlExport($db, $userId));

        $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        foreach ($filenames as $filename) {
            if ($filename === '' || in_array($filename, ['default.png', 'default.jpg', 'default_dark.png', 'default_story.png', 'default_story.jpg', 'default_story_dark.png'], true)) {
                continue;
            }
            $path = $uploadDir . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                $zip->addFile($path, 'uploads/' . $filename);
            }
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $baseName . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit();
    }

    public function importAccount(): void
    {
        $this->requireLogin();

        if (!$this->isPost()) {
            header('Location: /settings');
            exit();
        }

        $file = $_FILES['account_import'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            header('Location: /settings?imported=missing_file');
            exit();
        }

        $mode = $_POST['import_mode'] ?? 'missing';
        if (!in_array($mode, ['missing', 'merge', 'replace'], true)) {
            $mode = 'missing';
        }

        $zip = new ZipArchive();
        if ($zip->open((string)$file['tmp_name']) !== true) {
            header('Location: /settings?imported=bad_zip');
            exit();
        }

        $json = $zip->getFromName('data.json');
        if ($json === false) {
            $zip->close();
            header('Location: /settings?imported=no_json');
            exit();
        }

        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'ocstudio-account-export') {
            $zip->close();
            header('Location: /settings?imported=bad_json');
            exit();
        }

        try {
            $this->importAccountData((new Database())->connect(), (int)$_SESSION['user_id'], $data, $zip, $mode);
            $zip->close();
            header('Location: /settings?imported=ok');
            exit();
        } catch (Throwable $e) {
            $zip->close();
            error_log('Account import failed: ' . $e->getMessage());
            header('Location: /settings?imported=error');
            exit();
        }
    }

    private function importMessage(?string $code): string
    {
        return match ($code) {
            'ok' => 'Import zakonczony.',
            'missing_file' => 'Nie wybrano pliku importu.',
            'bad_zip' => 'Nie udalo sie otworzyc pliku ZIP.',
            'no_json' => 'Ten eksport nie zawiera danych importu aplikacyjnego. Wykonaj nowy eksport konta.',
            'bad_json' => 'Plik importu ma nieprawidlowy format.',
            'error' => 'Import nie powiodl sie. Dane nie zostaly w pelni wczytane.',
            default => '',
        };
    }

    private function buildAccountSqlExport(PDO $db, int $userId): string
    {
        $tables = [
            ['users', 'id = :userId', ['userId' => $userId]],
            ['templates', 'id_user = :userId', ['userId' => $userId]],
            ['template_fields', 'id_template IN (SELECT id FROM templates WHERE id_user = :userId)', ['userId' => $userId]],
            ['worlds', 'id_user = :userId', ['userId' => $userId]],
            ['characters', 'id_user = :userId', ['userId' => $userId]],
            ['character_variants', 'id_character IN (SELECT id FROM characters WHERE id_user = :userId)', ['userId' => $userId]],
            ['character_field_values', 'id_character IN (SELECT id FROM characters WHERE id_user = :userId)', ['userId' => $userId]],
            ['character_variant_field_values', 'id_variant IN (SELECT cv.id FROM character_variants cv JOIN characters c ON c.id = cv.id_character WHERE c.id_user = :userId)', ['userId' => $userId]],
            ['character_filters', 'id_character IN (SELECT id FROM characters WHERE id_user = :userId)', ['userId' => $userId]],
            ['world_filters', 'id_world IN (SELECT id FROM worlds WHERE id_user = :userId)', ['userId' => $userId]],
            ['user_blocked_filters', 'id_user = :userId', ['userId' => $userId]],
            ['image_assets', 'id_user = :userId', ['userId' => $userId]],
            ['image_asset_filters', 'id_image IN (SELECT id FROM image_assets WHERE id_user = :userId)', ['userId' => $userId]],
            ['character_relations', 'id_user = :userId', ['userId' => $userId]],
            ['relation_boards', 'id_user = :userId', ['userId' => $userId]],
            ['relation_board_worlds', 'id_board IN (SELECT id FROM relation_boards WHERE id_user = :userId)', ['userId' => $userId]],
            ['relation_board_characters', 'id_board IN (SELECT id FROM relation_boards WHERE id_user = :userId)', ['userId' => $userId]],
            ['relation_tree_nodes', 'id_user = :userId', ['userId' => $userId]],
            ['relation_tree_rules', 'id_user = :userId', ['userId' => $userId]],
            ['relation_tree_character_exceptions', 'id_user = :userId', ['userId' => $userId]],
            ['story_folders', 'id_user = :userId', ['userId' => $userId]],
            ['stories', 'id_user = :userId', ['userId' => $userId]],
            ['story_fields', 'id_story IN (SELECT id FROM stories WHERE id_user = :userId)', ['userId' => $userId]],
            ['story_field_values', 'id_story IN (SELECT id FROM stories WHERE id_user = :userId)', ['userId' => $userId]],
            ['story_characters', 'id_story IN (SELECT id FROM stories WHERE id_user = :userId)', ['userId' => $userId]],
            ['story_character_pseudonym_mapping', 'id_story_character IN (SELECT sc.id FROM story_characters sc JOIN stories s ON s.id = sc.id_story WHERE s.id_user = :userId)', ['userId' => $userId]],
            ['content_filters', "(object_type = 'character' AND object_id IN (SELECT id FROM characters WHERE id_user = :userId)) OR (object_type = 'world' AND object_id IN (SELECT id FROM worlds WHERE id_user = :userId)) OR (object_type = 'story' AND object_id IN (SELECT id FROM stories WHERE id_user = :userId)) OR (object_type = 'template' AND object_id IN (SELECT id FROM templates WHERE id_user = :userId))", ['userId' => $userId]],
        ];

        $lines = [
            '-- OCStudio account export',
            '-- User id: ' . $userId,
            '-- Created at: ' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format(DateTimeInterface::ATOM),
            '-- Ten plik zawiera prywatne dane konta.',
            '',
            'BEGIN;',
            '',
        ];

        foreach ($tables as [$table, $where, $params]) {
            if (!$this->tableExists($db, $table)) {
                continue;
            }
            $rows = $this->accountRows($db, $table, $where, $params);
            $lines[] = '-- Table: ' . $table;
            $columnTypes = $this->tableColumnTypes($db, $table);
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $columnSql = implode(', ', array_map(fn($column) => $this->quoteIdentifier($column), $columns));
                $values = array_map(fn($column) => $this->sqlLiteral($db, $row[$column], $columnTypes[$column] ?? null), $columns);
                $lines[] = 'INSERT INTO ' . $this->quoteIdentifier($table) . ' (' . $columnSql . ') VALUES (' . implode(', ', $values) . ');';
            }
            $lines[] = '';
        }

        $lines[] = 'COMMIT;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function buildAccountJsonExport(PDO $db, int $userId, DateTimeImmutable $createdAt): array
    {
        $tables = [];
        foreach (self::ACCOUNT_TABLES as $table) {
            if (!$this->tableExists($db, $table)) {
                continue;
            }
            $tables[$table] = $this->exportRowsForTable($db, $table, $userId);
        }

        return [
            'format' => 'ocstudio-account-export',
            'version' => 2,
            'createdAt' => $createdAt->format(DateTimeInterface::ATOM),
            'sourceUserId' => $userId,
            'tables' => $tables,
        ];
    }

    private function exportRowsForTable(PDO $db, string $table, int $userId): array
    {
        return match ($table) {
            'filters' => $this->accountRows($db, 'filters', "id_user = :userId OR id IN (
                SELECT id_filter FROM character_filters WHERE id_character IN (SELECT id FROM characters WHERE id_user = :userId)
                UNION SELECT id_filter FROM world_filters WHERE id_world IN (SELECT id FROM worlds WHERE id_user = :userId)
                UNION SELECT id_filter FROM user_blocked_filters WHERE id_user = :userId
                UNION SELECT id_filter FROM image_asset_filters WHERE id_image IN (SELECT id FROM image_assets WHERE id_user = :userId)
                UNION SELECT id_filter FROM content_filters WHERE
                    (object_type = 'character' AND object_id IN (SELECT id FROM characters WHERE id_user = :userId))
                    OR (object_type = 'world' AND object_id IN (SELECT id FROM worlds WHERE id_user = :userId))
                    OR (object_type = 'story' AND object_id IN (SELECT id FROM stories WHERE id_user = :userId))
                    OR (object_type = 'template' AND object_id IN (SELECT id FROM templates WHERE id_user = :userId))
            )", ['userId' => $userId]),
            'template_fields' => $this->accountRows($db, $table, 'id_template IN (SELECT id FROM templates WHERE id_user = :userId)', ['userId' => $userId]),
            'character_variants' => $this->accountRows($db, $table, 'id_character IN (SELECT id FROM characters WHERE id_user = :userId)', ['userId' => $userId]),
            'character_field_values' => $this->accountRows($db, $table, 'id_character IN (SELECT id FROM characters WHERE id_user = :userId)', ['userId' => $userId]),
            'character_variant_field_values' => $this->accountRows($db, $table, 'id_variant IN (SELECT cv.id FROM character_variants cv JOIN characters c ON c.id = cv.id_character WHERE c.id_user = :userId)', ['userId' => $userId]),
            'character_filters' => $this->accountRows($db, $table, 'id_character IN (SELECT id FROM characters WHERE id_user = :userId)', ['userId' => $userId]),
            'world_filters' => $this->accountRows($db, $table, 'id_world IN (SELECT id FROM worlds WHERE id_user = :userId)', ['userId' => $userId]),
            'image_asset_filters' => $this->accountRows($db, $table, 'id_image IN (SELECT id FROM image_assets WHERE id_user = :userId)', ['userId' => $userId]),
            'content_filters' => $this->accountRows($db, $table, "(object_type = 'character' AND object_id IN (SELECT id FROM characters WHERE id_user = :userId)) OR (object_type = 'world' AND object_id IN (SELECT id FROM worlds WHERE id_user = :userId)) OR (object_type = 'story' AND object_id IN (SELECT id FROM stories WHERE id_user = :userId)) OR (object_type = 'template' AND object_id IN (SELECT id FROM templates WHERE id_user = :userId))", ['userId' => $userId]),
            'relation_board_worlds' => $this->accountRows($db, $table, 'id_board IN (SELECT id FROM relation_boards WHERE id_user = :userId)', ['userId' => $userId]),
            'relation_board_characters' => $this->accountRows($db, $table, 'id_board IN (SELECT id FROM relation_boards WHERE id_user = :userId)', ['userId' => $userId]),
            'story_fields' => $this->accountRows($db, $table, 'id_story IN (SELECT id FROM stories WHERE id_user = :userId)', ['userId' => $userId]),
            'story_field_values' => $this->accountRows($db, $table, 'id_story IN (SELECT id FROM stories WHERE id_user = :userId)', ['userId' => $userId]),
            'story_characters' => $this->accountRows($db, $table, 'id_story IN (SELECT id FROM stories WHERE id_user = :userId)', ['userId' => $userId]),
            'story_character_pseudonym_mapping' => $this->accountRows($db, $table, 'id_story_character IN (SELECT sc.id FROM story_characters sc JOIN stories s ON s.id = sc.id_story WHERE s.id_user = :userId)', ['userId' => $userId]),
            default => in_array($table, self::USER_OWNED_TABLES, true)
                ? $this->accountRows($db, $table, 'id_user = :userId', ['userId' => $userId])
                : [],
        };
    }

    private function importAccountData(PDO $db, int $userId, array $data, ZipArchive $zip, string $mode): void
    {
        $tables = is_array($data['tables'] ?? null) ? $data['tables'] : [];
        $maps = [];
        $filenameMap = [];

        $db->beginTransaction();
        try {
            if ($mode === 'replace') {
                $this->deleteAccountData($db, $userId);
            }

            $maps['filters'] = $this->importFilters($db, $tables['filters'] ?? [], $userId);
            $maps['image_assets'] = $this->importImages($db, $userId, $tables['image_assets'] ?? [], $zip, $filenameMap, $mode);

            $this->importMappedRows($db, $userId, $tables, $maps, $filenameMap, $mode);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private function importMappedRows(PDO $db, int $userId, array $tables, array &$maps, array $filenameMap, string $mode): void
    {
        $order = [
            'templates', 'template_fields',
            'worlds',
            'characters', 'character_variants', 'character_field_values', 'character_variant_field_values',
            'character_filters', 'world_filters', 'user_blocked_filters', 'image_asset_filters', 'content_filters',
            'relation_boards', 'relation_board_worlds', 'relation_board_characters', 'character_relations',
            'relation_tree_nodes', 'relation_tree_rules', 'relation_tree_character_exceptions',
            'story_folders', 'stories', 'story_fields', 'story_field_values', 'story_characters', 'story_character_pseudonym_mapping',
        ];

        foreach ($order as $table) {
            foreach (($tables[$table] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $oldId = (int)($row['id'] ?? 0);
                $row = $this->mapImportRow($table, $row, $maps, $filenameMap, $userId);
                if ($row === null) {
                    continue;
                }

                $existingId = $this->existingPublicIdRow($db, $table, $row, $userId);
                if ($existingId && $mode === 'missing') {
                    $maps[$table][$oldId] = $existingId;
                    continue;
                }
                if ($existingId && $mode === 'merge') {
                    $this->updateById($db, $table, $existingId, $row);
                    $maps[$table][$oldId] = $existingId;
                    continue;
                }

                $newId = $this->insertImportRow($db, $table, $row);
                if ($oldId > 0 && $newId > 0) {
                    $maps[$table][$oldId] = $newId;
                }
            }
        }
    }

    private function mapImportRow(string $table, array $row, array $maps, array $filenameMap, int $userId): ?array
    {
        unset($row['id']);
        if (in_array($table, self::USER_OWNED_TABLES, true) || in_array($table, ['templates', 'worlds', 'characters', 'story_folders', 'stories'], true)) {
            $row['id_user'] = $userId;
        }

        $fkMap = [
            'template_fields' => ['id_template' => 'templates'],
            'worlds' => ['parent_id' => 'worlds'],
            'characters' => ['id_template' => 'templates', 'id_world' => 'worlds'],
            'character_variants' => ['id_character' => 'characters'],
            'character_field_values' => ['id_character' => 'characters', 'id_template_field' => 'template_fields'],
            'character_variant_field_values' => ['id_variant' => 'character_variants', 'id_template_field' => 'template_fields'],
            'character_filters' => ['id_character' => 'characters', 'id_filter' => 'filters'],
            'world_filters' => ['id_world' => 'worlds', 'id_filter' => 'filters'],
            'user_blocked_filters' => ['id_filter' => 'filters'],
            'image_asset_filters' => ['id_image' => 'image_assets', 'id_filter' => 'filters'],
            'relation_board_worlds' => ['id_board' => 'relation_boards', 'id_world' => 'worlds'],
            'relation_board_characters' => ['id_board' => 'relation_boards', 'id_character' => 'characters'],
            'character_relations' => ['character_a_id' => 'characters', 'character_b_id' => 'characters'],
            'relation_tree_nodes' => ['id_board' => 'relation_boards', 'id_world' => 'worlds', 'id_character' => 'characters'],
            'relation_tree_rules' => ['id_world' => 'worlds', 'excluded_world_id' => 'worlds'],
            'relation_tree_character_exceptions' => ['id_world' => 'worlds', 'id_character' => 'characters'],
            'story_folders' => ['id_world' => 'worlds', 'parent_id' => 'story_folders'],
            'stories' => ['id_world' => 'worlds', 'id_folder' => 'story_folders'],
            'story_fields' => ['id_story' => 'stories'],
            'story_field_values' => ['id_story' => 'stories', 'id_story_field' => 'story_fields'],
            'story_characters' => ['id_story' => 'stories', 'id_character' => 'characters', 'pseudonym_field_id' => 'template_fields'],
            'story_character_pseudonym_mapping' => ['id_story_character' => 'story_characters'],
            'content_filters' => ['id_filter' => 'filters'],
        ];

        foreach (($fkMap[$table] ?? []) as $column => $mapTable) {
            if (!array_key_exists($column, $row) || $row[$column] === null || $row[$column] === '') {
                continue;
            }
            $mapped = $maps[$mapTable][(int)$row[$column]] ?? null;
            if ($mapped === null && $column !== 'relation_type_id') {
                return null;
            }
            $row[$column] = $mapped;
        }

        if ($table === 'content_filters') {
            $objectType = (string)($row['object_type'] ?? '');
            $objectMap = ['character' => 'characters', 'world' => 'worlds', 'story' => 'stories', 'template' => 'templates'][$objectType] ?? null;
            if (!$objectMap || empty($maps[$objectMap][(int)($row['object_id'] ?? 0)])) {
                return null;
            }
            $row['object_id'] = $maps[$objectMap][(int)$row['object_id']];
        }

        foreach ($row as $column => $value) {
            if (is_string($value)) {
                $row[$column] = $this->replaceImportedFilenames($value, $filenameMap);
            }
        }

        return $row;
    }

    private function importFilters(PDO $db, array $rows, int $userId): array
    {
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $oldId = (int)($row['id'] ?? 0);
            $slug = trim((string)($row['slug'] ?? ''));
            $name = trim((string)($row['name'] ?? $slug));
            if ($slug === '' && $name === '') {
                continue;
            }

            $stmt = $db->prepare('SELECT id FROM filters WHERE slug = :slug OR name = :name LIMIT 1');
            $stmt->execute([':slug' => $slug, ':name' => $name]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                $map[$oldId] = (int)$existing;
                continue;
            }

            $insert = $db->prepare(
                'INSERT INTO filters (slug, name, label, is_active, is_public, id_user)
                 VALUES (:slug, :name, :label, :active, :public, :userId)
                 ON CONFLICT (slug) DO UPDATE SET label = EXCLUDED.label
                 RETURNING id'
            );
            $insert->execute([
                ':slug' => $slug !== '' ? $slug : $name,
                ':name' => $name !== '' ? $name : $slug,
                ':label' => $row['label'] ?? ($name ?: $slug),
                ':active' => $this->pgBool($row['is_active'] ?? true),
                ':public' => $this->pgBool($row['is_public'] ?? true),
                ':userId' => $userId,
            ]);
            $map[$oldId] = (int)$insert->fetchColumn();
        }
        return $map;
    }

    private function importImages(PDO $db, int $userId, array $rows, ZipArchive $zip, array &$filenameMap, string $mode): array
    {
        $map = [];
        $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $oldId = (int)($row['id'] ?? 0);
            $oldFilename = basename((string)($row['filename'] ?? ''));
            if ($oldFilename === '' || in_array($oldFilename, self::DEFAULT_UPLOADS, true)) {
                $filenameMap[$oldFilename] = $oldFilename;
                continue;
            }

            $sha = (string)($row['sha256'] ?? '');
            $existing = $sha !== '' ? $this->findImageByHash($db, $userId, $sha) : null;
            if ($existing) {
                $map[$oldId] = (int)$existing['id'];
                $filenameMap[$oldFilename] = (string)$existing['filename'];
                continue;
            }

            $zipPath = 'uploads/' . $oldFilename;
            $contents = $zip->getFromName($zipPath);
            if ($contents === false) {
                continue;
            }

            $newFilename = $this->uniqueUploadFilename($uploadDir, $oldFilename);
            file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $newFilename, $contents);
            $filenameMap[$oldFilename] = $newFilename;

            $imageRow = $row;
            unset($imageRow['id']);
            $imageRow['id_user'] = $userId;
            $imageRow['filename'] = $newFilename;
            $imageRow['original_name'] = $imageRow['original_name'] ?? $oldFilename;
            $imageRow['size_bytes'] = filesize($uploadDir . DIRECTORY_SEPARATOR . $newFilename) ?: strlen($contents);
            $imageRow['sha256'] = hash_file('sha256', $uploadDir . DIRECTORY_SEPARATOR . $newFilename);

            $newId = $this->insertImportRow($db, 'image_assets', $imageRow);
            if ($oldId > 0 && $newId > 0) {
                $map[$oldId] = $newId;
            }
        }

        return $map;
    }

    private function deleteAccountData(PDO $db, int $userId): void
    {
        $deleteSql = [
            'DELETE FROM content_filters WHERE (object_type = \'character\' AND object_id IN (SELECT id FROM characters WHERE id_user = :userId)) OR (object_type = \'world\' AND object_id IN (SELECT id FROM worlds WHERE id_user = :userId)) OR (object_type = \'story\' AND object_id IN (SELECT id FROM stories WHERE id_user = :userId)) OR (object_type = \'template\' AND object_id IN (SELECT id FROM templates WHERE id_user = :userId))',
            'DELETE FROM story_character_pseudonym_mapping WHERE id_story_character IN (SELECT sc.id FROM story_characters sc JOIN stories s ON s.id = sc.id_story WHERE s.id_user = :userId)',
            'DELETE FROM story_characters WHERE id_story IN (SELECT id FROM stories WHERE id_user = :userId)',
            'DELETE FROM story_field_values WHERE id_story IN (SELECT id FROM stories WHERE id_user = :userId)',
            'DELETE FROM story_fields WHERE id_story IN (SELECT id FROM stories WHERE id_user = :userId)',
            'DELETE FROM stories WHERE id_user = :userId',
            'DELETE FROM story_folders WHERE id_user = :userId',
            'DELETE FROM relation_tree_character_exceptions WHERE id_user = :userId',
            'DELETE FROM relation_tree_rules WHERE id_user = :userId',
            'DELETE FROM relation_tree_nodes WHERE id_user = :userId',
            'DELETE FROM relation_board_characters WHERE id_board IN (SELECT id FROM relation_boards WHERE id_user = :userId)',
            'DELETE FROM relation_board_worlds WHERE id_board IN (SELECT id FROM relation_boards WHERE id_user = :userId)',
            'DELETE FROM relation_boards WHERE id_user = :userId',
            'DELETE FROM character_relations WHERE id_user = :userId',
            'DELETE FROM image_asset_filters WHERE id_image IN (SELECT id FROM image_assets WHERE id_user = :userId)',
            'DELETE FROM user_blocked_filters WHERE id_user = :userId',
            'DELETE FROM character_variant_field_values WHERE id_variant IN (SELECT cv.id FROM character_variants cv JOIN characters c ON c.id = cv.id_character WHERE c.id_user = :userId)',
            'DELETE FROM character_field_values WHERE id_character IN (SELECT id FROM characters WHERE id_user = :userId)',
            'DELETE FROM character_variants WHERE id_character IN (SELECT id FROM characters WHERE id_user = :userId)',
            'DELETE FROM character_filters WHERE id_character IN (SELECT id FROM characters WHERE id_user = :userId)',
            'DELETE FROM characters WHERE id_user = :userId',
            'DELETE FROM world_filters WHERE id_world IN (SELECT id FROM worlds WHERE id_user = :userId)',
            'DELETE FROM worlds WHERE id_user = :userId',
            'DELETE FROM template_fields WHERE id_template IN (SELECT id FROM templates WHERE id_user = :userId)',
            'DELETE FROM templates WHERE id_user = :userId',
            'DELETE FROM image_assets WHERE id_user = :userId',
        ];

        foreach ($deleteSql as $sql) {
            if (!$this->tablesInSqlExist($db, $sql)) {
                continue;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute([':userId' => $userId]);
        }
    }

    private function insertImportRow(PDO $db, string $table, array $row): int
    {
        if (!empty($row['public_id']) && in_array('public_id', $this->tableColumns($db, $table), true)) {
            $stmt = $db->prepare('SELECT 1 FROM ' . $this->quoteIdentifier($table) . ' WHERE public_id::text = :publicId LIMIT 1');
            $stmt->execute([':publicId' => (string)$row['public_id']]);
            if ($stmt->fetchColumn()) {
                unset($row['public_id']);
            }
        }

        $columns = array_values(array_intersect(array_keys($row), $this->tableColumns($db, $table)));
        $columns = array_values(array_filter($columns, fn($column) => $column !== 'id'));
        if (empty($columns)) {
            return 0;
        }

        $columnSql = implode(', ', array_map(fn($column) => $this->quoteIdentifier($column), $columns));
        $placeholders = implode(', ', array_map(fn($column) => ':' . $column, $columns));
        $stmt = $db->prepare('INSERT INTO ' . $this->quoteIdentifier($table) . " ({$columnSql}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING RETURNING id");
        foreach ($columns as $column) {
            $stmt->bindValue(':' . $column, $this->dbValue($row[$column]));
        }
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function updateById(PDO $db, string $table, int $id, array $row): void
    {
        $columns = array_values(array_intersect(array_keys($row), $this->tableColumns($db, $table)));
        $columns = array_values(array_filter($columns, fn($column) => $column !== 'id' && $column !== 'created_at'));
        if (empty($columns)) {
            return;
        }
        $assignments = implode(', ', array_map(fn($column) => $this->quoteIdentifier($column) . ' = :' . $column, $columns));
        $stmt = $db->prepare('UPDATE ' . $this->quoteIdentifier($table) . " SET {$assignments} WHERE id = :id");
        foreach ($columns as $column) {
            $stmt->bindValue(':' . $column, $this->dbValue($row[$column]));
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function existingPublicIdRow(PDO $db, string $table, array $row, int $userId): ?int
    {
        if (empty($row['public_id']) || !in_array('public_id', $this->tableColumns($db, $table), true) || !in_array('id_user', $this->tableColumns($db, $table), true)) {
            return null;
        }
        $stmt = $db->prepare('SELECT id FROM ' . $this->quoteIdentifier($table) . ' WHERE public_id::text = :publicId AND id_user = :userId LIMIT 1');
        $stmt->execute([':publicId' => (string)$row['public_id'], ':userId' => $userId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function findImageByHash(PDO $db, int $userId, string $sha): ?array
    {
        $stmt = $db->prepare('SELECT id, filename FROM image_assets WHERE id_user = :userId AND sha256 = :sha LIMIT 1');
        $stmt->execute([':userId' => $userId, ':sha' => $sha]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function uniqueUploadFilename(string $uploadDir, string $filename): string
    {
        $info = pathinfo($filename);
        $base = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $info['filename'] ?? 'image');
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . preg_replace('/[^a-zA-Z0-9]+/', '', $info['extension']) : '';
        $candidate = $base . $ext;
        $i = 1;
        while (is_file($uploadDir . DIRECTORY_SEPARATOR . $candidate)) {
            $candidate = $base . '_import_' . $i . $ext;
            $i++;
        }
        return $candidate;
    }

    private function replaceImportedFilenames(string $value, array $filenameMap): string
    {
        foreach ($filenameMap as $old => $new) {
            if ($old !== '' && $old !== $new) {
                $value = str_replace((string)$old, (string)$new, $value);
            }
        }
        return $value;
    }

    private function tableColumns(PDO $db, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table");
        $stmt->execute([':table' => $table]);
        return $cache[$table] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function tableColumnTypes(PDO $db, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $stmt = $db->prepare("
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = :table
        ");
        $stmt->execute([':table' => $table]);

        $types = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $types[(string)$row['column_name']] = (string)$row['data_type'];
        }

        return $cache[$table] = $types;
    }

    private function sqlLiteral(PDO $db, mixed $value, ?string $dataType): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($dataType === 'boolean') {
            return $this->toBooleanLiteral($value);
        }

        return $db->quote((string)$value);
    }

    private function toBooleanLiteral(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true') {
            return 'true';
        }

        return 'false';
    }

    private function dbValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return $value;
    }

    private function pgBool(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0';
    }

    private function tablesInSqlExist(PDO $db, string $sql): bool
    {
        if (!preg_match_all('/(?:FROM|JOIN|DELETE FROM)\s+([a-z_]+)/i', $sql, $matches)) {
            return true;
        }
        foreach (array_unique($matches[1]) as $table) {
            if (!$this->tableExists($db, $table)) {
                return false;
            }
        }
        return true;
    }

    private function accountRows(PDO $db, string $table, string $where, array $params): array
    {
        if (!$this->tableExists($db, $table)) {
            return [];
        }
        $stmt = $db->prepare('SELECT * FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . $where . ' ORDER BY 1 ASC');
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . ltrim((string)$key, ':'), $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare("SELECT to_regclass(:table) IS NOT NULL");
        $stmt->execute(['table' => 'public.' . $table]);
        return (bool)$stmt->fetchColumn();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
