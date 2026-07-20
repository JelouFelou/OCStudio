<?php

require_once __DIR__ . '/Repository.php';

class SocialFeatureSettingsRepository extends Repository
{
    private const OFFLINE_DISABLED_KEYS = [
        'community.enabled',
        'publications.enabled',
        'comments.enabled',
        'reactions.enabled',
        'follows.enabled',
        'messages.enabled',
        'reports.enabled',
        'copying.enabled',
        'public_search.enabled',
    ];

    public const DEFINITIONS = [
        'community.enabled' => [
            'label' => 'Spolecznosc',
            'description' => 'Glowny przelacznik funkcji spolecznosciowych.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'characters.enabled' => [
            'label' => 'Postacie i szablony',
            'description' => 'Tworzenie oraz edycja postaci, folderow i szablonow postaci.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'relations.enabled' => [
            'label' => 'Relacje',
            'description' => 'Tworzenie oraz edycja tablic relacji.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'stories.enabled' => [
            'label' => 'Historie',
            'description' => 'Tworzenie oraz edycja historii.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'gallery.enabled' => [
            'label' => 'Galeria i uploady',
            'description' => 'Galeria oraz przesylanie zdjec z komputera lub biblioteki.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'auth.login.enabled' => [
            'label' => 'Logowanie',
            'description' => 'Klasyczne logowanie uzytkownikow. Po wylaczeniu strona uzywa konta trybu offline.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'auth.offline_user_id' => [
            'label' => 'Konto trybu offline',
            'description' => 'ID konta uzywanego automatycznie, gdy logowanie jest wylaczone.',
            'type' => 'integer',
            'defaultEnabled' => true,
            'defaultValue' => '0',
            'maxValue' => 2147483647,
        ],
        'publications.enabled' => [
            'label' => 'Publikacje',
            'description' => 'Publikowanie i aktualizowanie publicznych tresci.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'comments.enabled' => [
            'label' => 'Komentarze',
            'description' => 'Komentarze pod publikacjami.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'reactions.enabled' => [
            'label' => 'Reakcje',
            'description' => 'Reakcje emoji pod publikacjami.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'follows.enabled' => [
            'label' => 'Follow',
            'description' => 'Obserwowanie uzytkownikow.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'messages.enabled' => [
            'label' => 'Wiadomosci',
            'description' => 'Wiadomosci prywatne.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'reports.enabled' => [
            'label' => 'Zgloszenia',
            'description' => 'Zgloszenia tresci i uzytkownikow.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'copying.enabled' => [
            'label' => 'Kopiowanie',
            'description' => 'Kopiowanie publicznych snapshotow.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'public_search.enabled' => [
            'label' => 'Publiczne wyszukiwanie',
            'description' => 'Wyszukiwanie publicznych tresci innych uzytkownikow.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'new_publications.require_review' => [
            'label' => 'Akceptacja nowych publikacji',
            'description' => 'Nowe publikacje wymagaja recznej akceptacji administracji.',
            'type' => 'boolean',
            'defaultEnabled' => false,
            'defaultValue' => '',
        ],
        'new_users.social_cooldown_hours' => [
            'label' => 'Cooldown nowych kont',
            'description' => 'Liczba godzin ograniczen spolecznosciowych dla nowych kont.',
            'type' => 'integer',
            'defaultEnabled' => true,
            'defaultValue' => '0',
        ],
        'reports.auto_adult_threshold' => [
            'label' => 'Prog automatycznego +18',
            'description' => 'Liczba otwartych zgloszen publikacji wymagana do automatycznego oznaczenia +18.',
            'type' => 'integer',
            'defaultEnabled' => true,
            'defaultValue' => '15',
        ],
        'storage.user_quota_mb' => [
            'label' => 'Limit miejsca uzytkownika',
            'description' => 'Maksymalna suma prywatnych zdjec zwyklego uzytkownika w megabajtach.',
            'type' => 'integer',
            'defaultEnabled' => true,
            'defaultValue' => '500',
            'maxValue' => 1048576,
        ],
        'storage.admin_quota_mb' => [
            'label' => 'Limit miejsca admina',
            'description' => 'Maksymalna suma prywatnych zdjec administratora w megabajtach.',
            'type' => 'integer',
            'defaultEnabled' => true,
            'defaultValue' => '500',
            'maxValue' => 1048576,
        ],
        'backup.reminder.enabled' => [
            'label' => 'Przypomnienia backupu',
            'description' => 'Pokazuje adminowi przypomnienie, gdy od ostatniego backupu minie ustawiony czas.',
            'type' => 'boolean',
            'defaultEnabled' => true,
            'defaultValue' => '',
        ],
        'backup.reminder_interval_days' => [
            'label' => 'Interwal przypomnien backupu',
            'description' => 'Liczba dni po ostatnim backupie, po ktorej panel admina przypomina o kopii.',
            'type' => 'integer',
            'defaultEnabled' => true,
            'defaultValue' => '7',
            'maxValue' => 365,
        ],
    ];

    public function all(): array
    {
        $this->ensureDefaults();

        $stmt = $this->database->connect()->query(
            'SELECT key, enabled, value, description, updated_at
             FROM social_feature_settings
             ORDER BY key ASC'
        );

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['key'];
            $definition = self::DEFINITIONS[$key] ?? [
                'label' => $key,
                'description' => (string)($row['description'] ?? ''),
                'type' => 'boolean',
                'defaultEnabled' => true,
                'defaultValue' => '',
            ];

            $rows[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'] ?: (string)($row['description'] ?? ''),
                'type' => $definition['type'],
                'enabled' => $this->toBool($row['enabled']),
                'value' => (string)($row['value'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        return $rows;
    }

    public function save(array $input, int $adminUserId): array
    {
        $this->ensureDefaults();

        $saved = [];
        $enabledKeys = array_flip(array_map('strval', is_array($input['enabled_keys'] ?? null) ? $input['enabled_keys'] : []));
        $values = is_array($input['value'] ?? null) ? $input['value'] : [];
        $stmt = $this->database->connect()->prepare(
            'UPDATE social_feature_settings
             SET enabled = :enabled,
                 value = :value,
                 updated_by = :updatedBy,
                 updated_at = CURRENT_TIMESTAMP
             WHERE key = :key'
        );

        foreach (self::DEFINITIONS as $key => $definition) {
            $valueKey = str_replace('.', '__', $key);
            $enabled = isset($enabledKeys[$key]);
            $value = (string)($values[$valueKey] ?? $values[$key] ?? $definition['defaultValue']);

            if ($definition['type'] === 'integer') {
                $maxValue = (int)($definition['maxValue'] ?? 8760);
                $value = (string)max(0, min($maxValue, (int)$value));
            } else {
                $value = '';
            }

            $stmt->bindValue(':key', $key);
            $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
            $stmt->bindValue(':value', $value);
            $stmt->bindValue(':updatedBy', $adminUserId, PDO::PARAM_INT);
            $stmt->execute();

            $saved[$key] = ['enabled' => $enabled, 'value' => $value];
        }

        return $saved;
    }

    public function isEnabled(string $key): bool
    {
        $this->ensureDefaults();

        if ($key !== 'auth.login.enabled'
            && in_array($key, self::OFFLINE_DISABLED_KEYS, true)
            && !$this->storedEnabled('auth.login.enabled', true)
        ) {
            return false;
        }

        return $this->storedEnabled($key, (bool)(self::DEFINITIONS[$key]['defaultEnabled'] ?? false));
    }

    public function isEnabledForAccountType(string $key, int $accountType): bool
    {
        if (!$this->isEnabled($key)) {
            return false;
        }

        if (in_array($key, ['auth.login.enabled', 'auth.offline_user_id'], true)) {
            return true;
        }

        try {
            $stmt = $this->database->connect()->prepare(
                'SELECT enabled
                 FROM account_type_feature_permissions
                 WHERE account_type_id = :accountType AND feature_key = :key
                 LIMIT 1'
            );
            $stmt->execute([
                ':accountType' => $accountType,
                ':key' => $key,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ? $this->toBool($row['enabled'] ?? true) : true;
        } catch (Throwable $e) {
            return true;
        }
    }

    public function integerValue(string $key, int $fallback = 0): int
    {
        $this->ensureDefaults();

        $stmt = $this->database->connect()->prepare(
            'SELECT value FROM social_feature_settings WHERE key = :key'
        );
        $stmt->execute([':key' => $key]);
        $value = $stmt->fetchColumn();

        if ($value === false || trim((string)$value) === '') {
            return $fallback;
        }

        return max(0, (int)$value);
    }

    public function storageQuotaMbForAccountType(int $accountType): int
    {
        try {
            $stmt = $this->database->connect()->prepare(
                'SELECT storage_quota_mb FROM account_types WHERE id = :id AND is_active = TRUE LIMIT 1'
            );
            $stmt->execute([':id' => $accountType]);
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return max(1, (int)$value);
            }
        } catch (Throwable $e) {
            // Older databases fall back to the legacy global quota settings below.
        }

        $key = $accountType === 1 ? 'storage.admin_quota_mb' : 'storage.user_quota_mb';
        $default = (int)(self::DEFINITIONS[$key]['defaultValue'] ?? '500');

        return max(1, $this->integerValue($key, $default));
    }

    public function setBoolean(string $key, bool $enabled, ?int $adminUserId = null): void
    {
        if (!isset(self::DEFINITIONS[$key])) {
            return;
        }

        $this->ensureDefaults();
        $stmt = $this->database->connect()->prepare(
            'UPDATE social_feature_settings
             SET enabled = :enabled,
                 updated_by = :updatedBy,
                 updated_at = CURRENT_TIMESTAMP
             WHERE key = :key'
        );
        $stmt->bindValue(':key', $key);
        $stmt->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $stmt->bindValue(':updatedBy', $adminUserId, $adminUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    public function setInteger(string $key, int $value, ?int $adminUserId = null): void
    {
        if (!isset(self::DEFINITIONS[$key])) {
            return;
        }

        $this->ensureDefaults();
        $maxValue = (int)(self::DEFINITIONS[$key]['maxValue'] ?? 8760);
        $value = max(0, min($maxValue, $value));
        $stmt = $this->database->connect()->prepare(
            'UPDATE social_feature_settings
             SET value = :value,
                 updated_by = :updatedBy,
                 updated_at = CURRENT_TIMESTAMP
             WHERE key = :key'
        );
        $stmt->bindValue(':key', $key);
        $stmt->bindValue(':value', (string)$value);
        $stmt->bindValue(':updatedBy', $adminUserId, $adminUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    private function ensureDefaults(): void
    {
        $stmt = $this->database->connect()->prepare(
            'INSERT INTO social_feature_settings (key, enabled, value, description)
             VALUES (:key, :enabled, :value, :description)
             ON CONFLICT (key) DO NOTHING'
        );

        foreach (self::DEFINITIONS as $key => $definition) {
            $stmt->bindValue(':key', $key);
            $stmt->bindValue(':enabled', (bool)$definition['defaultEnabled'], PDO::PARAM_BOOL);
            $stmt->bindValue(':value', (string)$definition['defaultValue']);
            $stmt->bindValue(':description', (string)$definition['description']);
            $stmt->execute();
        }
    }

    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private function storedEnabled(string $key, bool $fallback): bool
    {
        $stmt = $this->database->connect()->prepare(
            'SELECT enabled FROM social_feature_settings WHERE key = :key'
        );
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $fallback;
        }

        return $this->toBool($row['enabled'] ?? null);
    }
}
