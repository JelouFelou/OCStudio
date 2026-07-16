<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../repositories/CharacterRepository.php';
require_once __DIR__ . '/../repositories/SiteEffectRepository.php';
require_once __DIR__ . '/../repositories/AdminActivityLogRepository.php';

class AdminController extends AppController
{
    private UsersRepository $userRepository;
    private CharacterRepository $characterRepository;
    private SiteEffectRepository $effectRepository;
    private AdminActivityLogRepository $activityLogRepository;

    public function __construct()
    {
        $this->userRepository = new UsersRepository();
        $this->characterRepository = new CharacterRepository();
        $this->effectRepository = new SiteEffectRepository();
        $this->activityLogRepository = new AdminActivityLogRepository();
    }

    public function index(): void
    {
        $this->requireAdmin();
        $this->purgeExpiredDeletionRequests();

        $search = trim($_GET['q'] ?? '');
        $users = $this->userRepository->getAdminUserRows($search);
        foreach ($users as &$user) {
            $storage = $this->getUserStorageStats((int)$user['id']);
            $user['storage_used'] = $storage['usedMb'];
            $user['storage_percent'] = $storage['percent'];
            $user['is_current_admin'] = (int)$user['id'] === (int)$_SESSION['user_id'];
            $user['characters'] = $this->characterRepository->getCharactersByUserId((int)$user['id']);
        }

        $this->render('admin', [
            'title' => 'Admin - OCStudio',
            'adminUsers' => $users,
            'adminSearch' => $search,
            'effectSettings' => $this->effectRepository->getSettings(),
            'activePageEffect' => $this->effectRepository->activeEffect(),
            'adminActivityLogs' => $this->activityLogRepository->latest(),
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    public function saveEffects(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $this->effectRepository->saveSettings(
            (string)($_POST['effect_mode'] ?? 'auto'),
            (string)($_POST['effect_symbols'] ?? ''),
            (string)($_POST['effect_intensity'] ?? 'medium'),
            (string)($_POST['effect_size'] ?? 'medium'),
            (string)($_POST['effect_layer'] ?? 'under')
        );
        $this->effectRepository->saveDateRules(
            is_array($_POST['effect_date_start'] ?? null) ? $_POST['effect_date_start'] : (is_array($_POST['effect_date'] ?? null) ? $_POST['effect_date'] : []),
            is_array($_POST['effect_date_end'] ?? null) ? $_POST['effect_date_end'] : (is_array($_POST['effect_date'] ?? null) ? $_POST['effect_date'] : []),
            is_array($_POST['effect_date_effect'] ?? null) ? $_POST['effect_date_effect'] : [],
            is_array($_POST['effect_date_symbols'] ?? null) ? $_POST['effect_date_symbols'] : []
        );
        $this->logAdminAction('site_effects.update', 'site_effects', null, (string)($_POST['effect_mode'] ?? 'auto'));

        header('Location: /admin?effects=1');
        exit();
    }

    public function backupDatabase(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        try {
            $filename = $this->createDatabaseBackup();
            $this->logAdminAction('database.backup', 'database', null, $filename);
            header('Location: /admin?backup=ok&file=' . rawurlencode($filename));
        } catch (Throwable $e) {
            error_log('Database backup failed: ' . $e->getMessage());
            header('Location: /admin?backup=error');
        }

        exit();
    }

    public function importDatabase(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $file = $_FILES['database_import'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            header('Location: /admin?import=missing_file');
            exit();
        }

        try {
            [$sql, $zipPath] = $this->readDatabaseImportFile($file);
            $this->restoreDatabaseFromSql($sql);
            if ($zipPath !== null) {
                $this->restoreUploadsFromZip($zipPath);
            }
            $this->logAdminAction('database.import', 'database', null, basename((string)($file['name'] ?? 'import')));
            header('Location: /admin?import=ok');
        } catch (Throwable $e) {
            error_log('Database import failed: ' . $e->getMessage());
            header('Location: /admin?import=error');
        }

        exit();
    }

    public function banUser(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        $days = max(1, min(3650, (int)($_POST['days'] ?? 1)));
        $reason = trim($_POST['reason'] ?? '');

        if ($userId > 0 && $userId !== (int)$_SESSION['user_id']) {
            $until = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:sP');
            $this->userRepository->setBan($userId, $until, $reason ?: 'Brak podanego powodu.');
            $this->logAdminAction('user.ban', 'user', $userId, 'Do: ' . $until . '. Powod: ' . ($reason ?: 'Brak podanego powodu.'));
        }

        header('Location: /admin');
        exit();
    }

    public function unbanUser(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $this->userRepository->clearBan($userId);
            $this->logAdminAction('user.unban', 'user', $userId);
        }

        header('Location: /admin');
        exit();
    }

    public function scheduleDeleteUser(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0 && $userId !== (int)$_SESSION['user_id']) {
            $this->userRepository->scheduleDeletion($userId);
            $this->logAdminAction('user.delete_schedule', 'user', $userId);
        }

        header('Location: /admin');
        exit();
    }

    public function cancelDeleteUser(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $this->userRepository->cancelDeletion($userId);
            $this->logAdminAction('user.delete_cancel', 'user', $userId);
        }

        header('Location: /admin');
        exit();
    }

    private function purgeExpiredDeletionRequests(): void
    {
        foreach ($this->userRepository->getExpiredDeletionUserIds() as $userId) {
            foreach ($this->getUserImageFilenames($userId) as $filename) {
                $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $filename;
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $this->userRepository->deleteUserById($userId);
        }
    }

    private function createDatabaseBackup(): string
    {
        require_once dirname(__DIR__, 2) . '/config.php';

        $backupDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Nie mozna utworzyc katalogu backupow.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw'));
        $filename = 'ocstudio_db_' . $now->format('Ymd_His') . '.sql';
        $path = $backupDir . DIRECTORY_SEPARATOR . $filename;
        $db = (new Database())->connect();

        $db->beginTransaction();
        try {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $sql = $this->buildDatabaseDump($db);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if (file_put_contents($path, $sql, LOCK_EX) === false) {
            throw new RuntimeException('Nie mozna zapisac pliku backupu.');
        }

        return $filename;
    }

    private function readDatabaseImportFile(array $file): array
    {
        $tmp = (string)($file['tmp_name'] ?? '');
        $name = strtolower((string)($file['name'] ?? ''));
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Brak pliku importu.');
        }

        if (str_ends_with($name, '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('Nie mozna otworzyc ZIP.');
            }
            $sql = $zip->getFromName('data.sql');
            $zip->close();
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('ZIP nie zawiera data.sql.');
            }
            $this->assertDatabaseBackupSql($sql);
            return [$sql, $tmp];
        }

        $sql = file_get_contents($tmp);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Pusty plik SQL.');
        }
        $this->assertDatabaseBackupSql($sql);
        return [$sql, null];
    }

    private function assertDatabaseBackupSql(string $sql): void
    {
        if (!str_contains($sql, '-- OCStudio database backup') || !str_contains($sql, 'DROP TABLE IF EXISTS')) {
            throw new RuntimeException('To nie wyglada na globalny backup bazy OCStudio.');
        }
    }

    private function restoreDatabaseFromSql(string $sql): void
    {
        $db = (new Database())->connect();
        $db->exec($sql);
    }

    private function restoreUploadsFromZip(string $zipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return;
        }

        $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/')) {
                continue;
            }
            $filename = basename($name);
            if ($filename === '' || $filename !== basename($filename)) {
                continue;
            }
            $contents = $zip->getFromIndex($i);
            if ($contents !== false) {
                file_put_contents($uploadDir . DIRECTORY_SEPARATOR . $filename, $contents);
            }
        }

        $zip->close();
    }

    private function logAdminAction(string $action, ?string $targetType = null, ?int $targetId = null, ?string $details = null): void
    {
        try {
            $this->activityLogRepository->log(
                (int)($_SESSION['user_id'] ?? 0),
                $action,
                $targetType,
                $targetId,
                $details,
                $_SERVER['REMOTE_ADDR'] ?? null
            );
        } catch (Throwable $e) {
            error_log('Admin activity log failed: ' . $e->getMessage());
        }
    }

    private function buildDatabaseDump(PDO $db): string
    {
        $tables = $db->query("
            SELECT tablename
            FROM pg_tables
            WHERE schemaname = 'public'
            ORDER BY tablename
        ")->fetchAll(PDO::FETCH_COLUMN);

        $lines = [
            '-- OCStudio database backup',
            '-- Created at: ' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format(DateTimeInterface::ATOM),
            '-- This file contains user data and should be stored privately.',
            '',
            'BEGIN;',
            'SET CONSTRAINTS ALL DEFERRED;',
            '',
        ];

        foreach ($tables as $table) {
            $lines[] = 'DROP TABLE IF EXISTS ' . $this->quoteQualifiedIdentifier('public', $table) . ' CASCADE;';
        }
        $lines[] = '';
        $lines[] = $this->dumpCreateSequences($db);

        foreach ($tables as $table) {
            $lines[] = $this->dumpCreateTable($db, $table);
        }

        foreach ($tables as $table) {
            $lines[] = $this->dumpTableData($db, $table);
        }

        foreach ($tables as $table) {
            $lines[] = $this->dumpTableSequences($db, $table);
        }

        foreach ($tables as $table) {
            $lines[] = $this->dumpForeignKeys($db, $table);
        }

        $lines[] = 'COMMIT;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function dumpCreateSequences(PDO $db): string
    {
        $sequences = $db->query("
            SELECT sequence_name
            FROM information_schema.sequences
            WHERE sequence_schema = 'public'
            ORDER BY sequence_name
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (!$sequences) {
            return '';
        }

        $lines = ['-- Sequences'];
        foreach ($sequences as $sequence) {
            $lines[] = 'DROP SEQUENCE IF EXISTS ' . $this->quoteQualifiedIdentifier('public', $sequence) . ' CASCADE;';
        }
        foreach ($sequences as $sequence) {
            $lines[] = 'CREATE SEQUENCE ' . $this->quoteQualifiedIdentifier('public', $sequence) . ';';
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function dumpCreateTable(PDO $db, string $table): string
    {
        $columnsStmt = $db->prepare("
            SELECT
                a.attname,
                pg_catalog.format_type(a.atttypid, a.atttypmod) AS type_name,
                a.attnotnull,
                pg_get_expr(ad.adbin, ad.adrelid) AS default_expr
            FROM pg_attribute a
            LEFT JOIN pg_attrdef ad
                ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
            WHERE a.attrelid = to_regclass(:table)
              AND a.attnum > 0
              AND NOT a.attisdropped
            ORDER BY a.attnum
        ");
        $columnsStmt->execute(['table' => 'public.' . $table]);
        $columns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);

        $parts = [];
        foreach ($columns as $column) {
            $definition = '    ' . $this->quoteIdentifier($column['attname']) . ' ' . $column['type_name'];
            if ($column['default_expr'] !== null && $column['default_expr'] !== '') {
                $definition .= ' DEFAULT ' . $column['default_expr'];
            }
            if (!empty($column['attnotnull'])) {
                $definition .= ' NOT NULL';
            }
            $parts[] = $definition;
        }

        $sql = [
            '--',
            '-- Table: public.' . $table,
            '--',
            'CREATE TABLE ' . $this->quoteQualifiedIdentifier('public', $table) . " (\n" . implode(",\n", $parts) . "\n);",
        ];

        $constraintsStmt = $db->prepare("
            SELECT conname, pg_get_constraintdef(oid, true) AS definition
            FROM pg_constraint
            WHERE conrelid = to_regclass(:table)
              AND contype IN ('p', 'u', 'c', 'x')
            ORDER BY CASE contype WHEN 'p' THEN 1 WHEN 'u' THEN 2 WHEN 'c' THEN 3 ELSE 4 END, conname
        ");
        $constraintsStmt->execute(['table' => 'public.' . $table]);
        foreach ($constraintsStmt->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
            $sql[] = 'ALTER TABLE ONLY ' . $this->quoteQualifiedIdentifier('public', $table)
                . ' ADD CONSTRAINT ' . $this->quoteIdentifier($constraint['conname'])
                . ' ' . $constraint['definition'] . ';';
        }

        $sql[] = '';
        return implode("\n", $sql);
    }

    private function dumpTableData(PDO $db, string $table): string
    {
        $qualified = $this->quoteQualifiedIdentifier('public', $table);
        $rows = $db->query('SELECT * FROM ' . $qualified)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return '-- Data: public.' . $table . "\n";
        }

        $columns = array_keys($rows[0]);
        $columnTypes = $this->tableColumnTypes($db, $table);
        $columnSql = implode(', ', array_map(fn($column) => $this->quoteIdentifier($column), $columns));
        $lines = ['-- Data: public.' . $table];

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->sqlLiteral($db, $row[$column], $columnTypes[$column] ?? null);
            }
            $lines[] = 'INSERT INTO ' . $qualified . ' (' . $columnSql . ') VALUES (' . implode(', ', $values) . ');';
        }

        $lines[] = '';
        return implode("\n", $lines);
    }

    private function dumpTableSequences(PDO $db, string $table): string
    {
        $columnsStmt = $db->prepare("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = :table
            ORDER BY ordinal_position
        ");
        $columnsStmt->execute(['table' => $table]);

        $lines = [];
        foreach ($columnsStmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $sequenceStmt = $db->prepare("SELECT pg_get_serial_sequence(:table, :column)");
            $sequenceStmt->execute(['table' => 'public.' . $table, 'column' => $column]);
            $sequence = $sequenceStmt->fetchColumn();
            if (!$sequence) {
                continue;
            }

            $qualifiedTable = $this->quoteQualifiedIdentifier('public', $table);
            $quotedColumn = $this->quoteIdentifier($column);
            $lines[] = "SELECT setval(" . $db->quote($sequence) . ", COALESCE((SELECT MAX($quotedColumn) FROM $qualifiedTable), 1), (SELECT MAX($quotedColumn) IS NOT NULL FROM $qualifiedTable));";
        }

        return $lines ? implode("\n", $lines) . "\n" : '';
    }

    private function dumpForeignKeys(PDO $db, string $table): string
    {
        $constraintsStmt = $db->prepare("
            SELECT conname, pg_get_constraintdef(oid, true) AS definition
            FROM pg_constraint
            WHERE conrelid = to_regclass(:table)
              AND contype = 'f'
            ORDER BY conname
        ");
        $constraintsStmt->execute(['table' => 'public.' . $table]);

        $lines = [];
        foreach ($constraintsStmt->fetchAll(PDO::FETCH_ASSOC) as $constraint) {
            $lines[] = 'ALTER TABLE ONLY ' . $this->quoteQualifiedIdentifier('public', $table)
                . ' ADD CONSTRAINT ' . $this->quoteIdentifier($constraint['conname'])
                . ' ' . $constraint['definition'] . ';';
        }

        return $lines ? implode("\n", $lines) . "\n" : '';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function quoteQualifiedIdentifier(string $schema, string $table): string
    {
        return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($table);
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
        $stmt->execute(['table' => $table]);

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

    private function getUserImageFilenames(int $userId): array
    {
        $filenames = [];
        foreach ($this->characterRepository->getCharactersByUserId($userId) as $character) {
            $this->addImageFilename($filenames, $character->getImage());

            foreach ($this->characterRepository->getCharacterVariants($character->getId()) as $variant) {
                $this->addImageFilename($filenames, $variant['image'] ?? null);
            }
        }

        return array_keys($filenames);
    }

    private function addImageFilename(array &$filenames, ?string $image): void
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
}
