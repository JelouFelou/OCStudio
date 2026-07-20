<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/UserRepository.php';
require_once __DIR__ . '/../repositories/CharacterRepository.php';
require_once __DIR__ . '/../repositories/SiteEffectRepository.php';
require_once __DIR__ . '/../repositories/AdminActivityLogRepository.php';
require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
require_once __DIR__ . '/../repositories/PublicationRepository.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';
require_once __DIR__ . '/../repositories/AccountTypeRepository.php';

class AdminController extends AppController
{
    private UsersRepository $userRepository;
    private CharacterRepository $characterRepository;
    private SiteEffectRepository $effectRepository;
    private AdminActivityLogRepository $activityLogRepository;
    private SocialFeatureSettingsRepository $socialFeatureSettingsRepository;
    private PublicationRepository $publicationRepository;
    private NotificationRepository $notificationRepository;
    private FilterRepository $filterRepository;
    private AccountTypeRepository $accountTypeRepository;

    public function __construct()
    {
        $this->userRepository = new UsersRepository();
        $this->characterRepository = new CharacterRepository();
        $this->effectRepository = new SiteEffectRepository();
        $this->activityLogRepository = new AdminActivityLogRepository();
        $this->socialFeatureSettingsRepository = new SocialFeatureSettingsRepository();
        $this->publicationRepository = new PublicationRepository();
        $this->notificationRepository = new NotificationRepository();
        $this->filterRepository = new FilterRepository();
        $this->accountTypeRepository = new AccountTypeRepository();
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
            'socialFeatureSettings' => $this->socialFeatureSettingsRepository->all(),
            'accountTypes' => $this->accountTypeRepository->all(),
            'accountTypeOptions' => $this->accountTypeRepository->activeOptions(),
            'accountTypeFeatureDefinitions' => $this->accountTypeRepository->featureDefinitions(),
            'offlineUserId' => $this->socialFeatureSettingsRepository->integerValue('auth.offline_user_id', 0),
            'adminUserOptions' => $this->userRepository->activeUserOptions(),
            'adminPublicationReports' => $this->publicationRepository->adminReportQueue(),
            'adminFilters' => $this->filterRepository->adminFilterRows(LocaleService::SUPPORTED_LOCALES),
            'filterLocales' => LocaleService::SUPPORTED_LOCALES,
            'adminActivityLogs' => $this->activityLogRepository->latest(),
            'backupReminder' => $this->backupReminderStatus(),
            'csrfToken' => $this->csrfToken(),
        ]);
    }

    public function addFilterAlias(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        try {
            $this->filterRepository->addAlias(
                (int)($_POST['filter_id'] ?? 0),
                (string)($_POST['alias'] ?? ''),
                (string)($_POST['language'] ?? 'pl')
            );
            $this->logAdminAction('filter.alias.add', 'filter', (int)($_POST['filter_id'] ?? 0), (string)($_POST['alias'] ?? ''));
            header('Location: /admin?filters=alias');
        } catch (Throwable $e) {
            header('Location: /admin?filters=error');
        }
        exit();
    }

    public function saveFilterCell(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $filterId = (int)($_POST['filter_id'] ?? 0);
        $column = (string)($_POST['column'] ?? '');
        $value = (string)($_POST['value'] ?? '');
        try {
            $this->filterRepository->setAdminCell($filterId, $column, $value, LocaleService::SUPPORTED_LOCALES);
            $this->logAdminAction('filter.cell.save', 'filter', $filterId, $column . '=' . $value);
            $this->jsonResponse([
                'success' => true,
                'filters' => $this->filterRepository->adminFilterRows(LocaleService::SUPPORTED_LOCALES),
                'locales' => LocaleService::SUPPORTED_LOCALES,
            ]);
        } catch (Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    public function mergeFilters(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $sourceId = (int)($_POST['source_filter_id'] ?? 0);
        $targetId = (int)($_POST['target_filter_id'] ?? 0);
        try {
            $this->filterRepository->mergeFilters($sourceId, $targetId);
            $this->logAdminAction('filter.merge', 'filter', $targetId, 'source=' . $sourceId);
            header('Location: /admin?filters=merge');
        } catch (Throwable $e) {
            header('Location: /admin?filters=error');
        }
        exit();
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

    public function saveSocialFeatures(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $currentLoginEnabled = $this->socialFeatureSettingsRepository->isEnabled('auth.login.enabled');
        $currentOfflineUserId = $this->socialFeatureSettingsRepository->integerValue('auth.offline_user_id', 0);
        $postedEnabledKeys = array_flip(array_map('strval', is_array($_POST['enabled_keys'] ?? null) ? $_POST['enabled_keys'] : []));
        $nextLoginEnabled = isset($postedEnabledKeys['auth.login.enabled']);
        $nextOfflineUserId = max(0, (int)($_POST['offline_user_id'] ?? $currentOfflineUserId));

        if ((!$nextLoginEnabled || $nextOfflineUserId !== $currentOfflineUserId || $nextLoginEnabled !== $currentLoginEnabled)
            && !$this->validAdminPassword((string)($_POST['admin_password_confirm'] ?? ''))
        ) {
            header('Location: /admin?social=password');
            exit();
        }

        if (!$nextLoginEnabled) {
            $offlineUser = $nextOfflineUserId > 0 ? $this->userRepository->getUserById($nextOfflineUserId) : null;
            if (!$offlineUser || !$offlineUser->isAdmin()) {
                header('Location: /admin?social=offline_user');
                exit();
            }
        }

        $_POST['value']['auth__offline_user_id'] = (string)$nextOfflineUserId;
        $saved = $this->socialFeatureSettingsRepository->save($_POST, (int)$_SESSION['user_id']);
        $summary = [];
        foreach ($saved as $key => $state) {
            $summary[] = $key . '=' . (!empty($state['enabled']) ? 'on' : 'off') . (($state['value'] ?? '') !== '' ? ':' . $state['value'] : '');
        }
        $this->logAdminAction('social_features.update', 'social_feature_settings', null, implode(', ', $summary));

        header('Location: /admin?social=1');
        exit();
    }

    public function saveStorageQuotas(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $this->saveAccountTypeQuotasFromPost();

        header('Location: /admin?accountTypes=1');
        exit();
    }

    public function createAccountType(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        try {
            $id = $this->accountTypeRepository->create(
                (string)($_POST['name'] ?? ''),
                (int)($_POST['storage_quota_mb'] ?? 500),
                !empty($_POST['is_admin']),
                $this->permissionsFromPost($_POST['features'] ?? [])
            );
            $this->logAdminAction('account_type.create', 'account_type', $id, (string)($_POST['name'] ?? ''));
            header('Location: /admin?accountTypes=created');
        } catch (Throwable $e) {
            error_log('Account type create failed: ' . $e->getMessage());
            header('Location: /admin?accountTypes=error');
        }

        exit();
    }

    public function updateAccountType(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $id = (int)($_POST['account_type_id'] ?? -1);
        try {
            $this->accountTypeRepository->update(
                $id,
                (string)($_POST['name'] ?? ''),
                (int)($_POST['storage_quota_mb'] ?? 500),
                !empty($_POST['is_admin']),
                !empty($_POST['is_active']),
                $this->permissionsFromPost($_POST['features'] ?? [])
            );
            $this->logAdminAction('account_type.update', 'account_type', $id, (string)($_POST['name'] ?? ''));
            header('Location: /admin?accountTypes=updated');
        } catch (Throwable $e) {
            error_log('Account type update failed: ' . $e->getMessage());
            header('Location: /admin?accountTypes=error');
        }

        exit();
    }

    public function assignAccountType(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $userId = (int)($_POST['user_id'] ?? 0);
        $accountType = (int)($_POST['account_type'] ?? 0);
        try {
            $this->userRepository->setAccountType($userId, $accountType, (int)$_SESSION['user_id']);
            $this->logAdminAction('user.account_type.update', 'user', $userId, 'account_type=' . $accountType);
            header('Location: /admin?users=account_type');
        } catch (Throwable $e) {
            error_log('Account type assignment failed: ' . $e->getMessage());
            header('Location: /admin?users=account_type_error');
        }

        exit();
    }

    private function saveAccountTypeQuotasFromPost(): void
    {
        $quotas = is_array($_POST['quota_mb'] ?? null) ? $_POST['quota_mb'] : [];
        foreach ($this->accountTypeRepository->all() as $accountType) {
            $id = (int)$accountType['id'];
            if (!array_key_exists((string)$id, $quotas) && !array_key_exists($id, $quotas)) {
                continue;
            }

            $this->accountTypeRepository->update(
                $id,
                (string)$accountType['name'],
                (int)($quotas[(string)$id] ?? $quotas[$id] ?? $accountType['storageQuotaMb']),
                !empty($accountType['isAdmin']),
                !empty($accountType['isActive']),
                $accountType['permissions'] ?? []
            );
        }
    }

    private function permissionsFromPost(mixed $input): array
    {
        $posted = array_flip(array_map('strval', is_array($input) ? $input : []));
        $permissions = [];
        foreach (array_keys($this->accountTypeRepository->featureDefinitions()) as $featureKey) {
            $permissions[$featureKey] = isset($posted[$featureKey]);
        }

        return $permissions;
    }

    private function validAdminPassword(string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $user = $this->userRepository->getUserById((int)$_SESSION['user_id']);
        return $user !== null && password_verify($password, $user->getPassword());
    }

    public function moderatePublication(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $publicationId = (int)($_POST['publication_id'] ?? 0);
        $action = (string)($_POST['moderation_action'] ?? '');
        if ($publicationId > 0) {
            try {
                $publication = $this->publicationRepository->moderatePublication(
                    $publicationId,
                    (int)$_SESSION['user_id'],
                    $action
                );
                if ($publication) {
                    $this->logAdminAction('publication.moderate.' . $action, 'publication', $publicationId);
                    $this->notificationRepository->create(
                        (int)$publication['ownerUserId'],
                        (int)$_SESSION['user_id'],
                        'publication.moderation',
                        'Decyzja administracji',
                        $this->moderationNotificationBody($action),
                        'publication',
                        $publicationId,
                        '/p/' . (string)$publication['publicId'],
                        ['action' => $action]
                    );
                }
            } catch (Throwable $e) {
                error_log('Publication moderation failed: ' . $e->getMessage());
                header('Location: /admin?moderation=error');
                exit();
            }
        }

        header('Location: /admin?moderation=1');
        exit();
    }

    private function moderationNotificationBody(string $action): string
    {
        return match ($action) {
            'mark_adult' => 'Twoja publikacja zostala oznaczona jako +18.',
            'mark_general' => 'Oznaczenie +18 zostalo zdjete z Twojej publikacji.',
            'hide' => 'Twoja publikacja zostala ukryta przez administracje.',
            'show' => 'Twoja publikacja zostala ponownie pokazana.',
            default => 'Zgloszenia dotyczace Twojej publikacji zostaly rozpatrzone.',
        };
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

    public function saveBackupReminder(): void
    {
        $this->requireAdmin();
        $this->validateCsrf();

        $enabled = !empty($_POST['backup_reminder_enabled']);
        $days = max(1, min(365, (int)($_POST['backup_reminder_interval_days'] ?? 7)));
        $this->socialFeatureSettingsRepository->setBoolean('backup.reminder.enabled', $enabled, (int)$_SESSION['user_id']);
        $this->socialFeatureSettingsRepository->setInteger('backup.reminder_interval_days', $days, (int)$_SESSION['user_id']);
        $this->logAdminAction('backup.reminder.update', 'backup', null, ($enabled ? 'enabled' : 'disabled') . ', days=' . $days);

        header('Location: /admin?backupReminder=1');
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

    private function backupReminderStatus(): array
    {
        $enabled = $this->socialFeatureSettingsRepository->isEnabled('backup.reminder.enabled');
        $days = max(1, $this->socialFeatureSettingsRepository->integerValue('backup.reminder_interval_days', 7));
        $latestBackup = $this->activityLogRepository->latestAction('database.backup');
        $lastAtRaw = (string)($latestBackup['created_at'] ?? '');
        $lastAt = $lastAtRaw !== '' ? new DateTimeImmutable($lastAtRaw) : null;
        $now = new DateTimeImmutable('now');
        $nextDue = $lastAt ? $lastAt->modify('+' . $days . ' days') : null;
        $isDue = $enabled && ($nextDue === null || $nextDue <= $now);

        return [
            'enabled' => $enabled,
            'intervalDays' => $days,
            'lastAt' => $lastAt ? $lastAt->format('Y-m-d H:i') : '',
            'nextDueAt' => $nextDue ? $nextDue->format('Y-m-d H:i') : '',
            'isDue' => $isDue,
            'lastFilename' => (string)($latestBackup['details'] ?? ''),
        ];
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
