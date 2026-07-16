<?php

require_once 'Repository.php';

class SiteEffectRepository extends Repository
{
    public const EFFECTS = ['auto', 'off', 'snow', 'confetti', 'hearts', 'stars', 'sunrays', 'sakura', 'custom'];
    public const INTENSITIES = ['low', 'medium', 'high'];
    public const SIZES = ['small', 'medium', 'large'];
    public const LAYERS = ['under', 'over'];

    public function getSettings(): array
    {
        $this->ensureTable();
        $stmt = $this->database->connect()->query('SELECT key, value FROM site_effect_settings');
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $mode = $this->normalizeEffect($rows['effect_mode'] ?? 'auto', 'auto');
        $intensity = in_array(($rows['effect_intensity'] ?? 'medium'), self::INTENSITIES, true)
            ? $rows['effect_intensity']
            : 'medium';
        $size = in_array(($rows['effect_size'] ?? 'medium'), self::SIZES, true)
            ? $rows['effect_size']
            : 'medium';
        $layer = in_array(($rows['effect_layer'] ?? 'under'), self::LAYERS, true)
            ? $rows['effect_layer']
            : 'under';

        return [
            'mode' => $mode,
            'symbols' => trim((string)($rows['effect_symbols'] ?? '')),
            'intensity' => $intensity,
            'size' => $size,
            'layer' => $layer,
            'dateRules' => $this->getDateRules(),
        ];
    }

    public function saveSettings(string $mode, string $symbols, string $intensity, string $size = 'medium', string $layer = 'under'): void
    {
        $this->ensureTable();
        $mode = $this->normalizeEffect($mode, 'auto');
        $symbols = mb_substr(trim($symbols), 0, 120);
        $intensity = in_array($intensity, self::INTENSITIES, true) ? $intensity : 'medium';
        $size = in_array($size, self::SIZES, true) ? $size : 'medium';
        $layer = in_array($layer, self::LAYERS, true) ? $layer : 'under';

        $stmt = $this->database->connect()->prepare(
            "INSERT INTO site_effect_settings (key, value, updated_at)
             VALUES (:key, :value, CURRENT_TIMESTAMP)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP"
        );

        foreach ([
            'effect_mode' => $mode,
            'effect_symbols' => $symbols,
            'effect_intensity' => $intensity,
            'effect_size' => $size,
            'effect_layer' => $layer,
        ] as $key => $value) {
            $stmt->execute([':key' => $key, ':value' => $value]);
        }
    }

    public function getDateRules(): array
    {
        $this->ensureTable();
        $stmt = $this->database->connect()->query(
            "SELECT id,
                    COALESCE(date_start, date_value) AS date_start,
                    COALESCE(date_end, date_value, date_start) AS date_end,
                    date_value,
                    effect,
                    symbols
             FROM site_effect_dates
             ORDER BY COALESCE(date_start, date_value) ASC, id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveDateRules(array $starts, array $ends, array $effects, array $symbols): void
    {
        $this->ensureTable();
        $rules = [];

        foreach ($starts as $index => $start) {
            $start = $this->normalizeDateValue((string)$start);
            $end = $this->normalizeDateValue((string)($ends[$index] ?? ''));
            $effect = $this->normalizeEffect((string)($effects[$index] ?? 'none'), 'none');
            if ($start === null || in_array($effect, ['none', 'auto', 'off'], true)) {
                continue;
            }
            $end = $end ?? $start;

            $rules[] = [
                'start' => $start,
                'end' => $end,
                'effect' => $effect,
                'symbols' => mb_substr(trim((string)($symbols[$index] ?? '')), 0, 120),
            ];
        }

        $db = $this->database->connect();
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM site_effect_dates');
            $stmt = $db->prepare(
                'INSERT INTO site_effect_dates (date_value, date_start, date_end, effect, symbols)
                 VALUES (:date, :start, :end, :effect, :symbols)'
            );
            foreach ($rules as $rule) {
                $stmt->execute([
                    ':date' => $rule['start'],
                    ':start' => $rule['start'],
                    ':end' => $rule['end'],
                    ':effect' => $rule['effect'],
                    ':symbols' => $rule['symbols'],
                ]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function activeEffect(?array $settings = null, ?DateTimeInterface $date = null): array
    {
        $settings = $settings ?? $this->getSettings();
        $mode = $settings['mode'] ?? 'auto';
        $dateRule = $mode === 'auto' ? $this->dateRuleForDate($date) : null;
        $effect = $mode === 'auto' ? ($dateRule['effect'] ?? 'none') : $mode;
        if ($effect === 'off' || $effect === 'auto') {
            $effect = 'none';
        }

        return [
            'name' => $effect,
            'symbols' => $dateRule['symbols'] ?? ($settings['symbols'] ?? ''),
            'intensity' => $settings['intensity'] ?? 'medium',
            'size' => $settings['size'] ?? 'medium',
            'layer' => $settings['layer'] ?? 'under',
        ];
    }

    public function dateRuleForDate(?DateTimeInterface $date = null): ?array
    {
        $date = $date ?? new DateTimeImmutable('now');
        $full = $date->format('Y-m-d');
        $monthDay = $date->format('m-d');

        foreach ($this->getDateRules() as $rule) {
            $start = (string)($rule['date_start'] ?? $rule['date_value'] ?? '');
            $end = (string)($rule['date_end'] ?? $rule['date_value'] ?? $start);
            if (!$this->dateMatchesRange($full, $monthDay, $start, $end)) {
                continue;
            }

            return [
                'effect' => $this->normalizeEffect((string)$rule['effect'], 'none'),
                'symbols' => (string)($rule['symbols'] ?? ''),
            ];
        }

        return null;
    }

    private function dateMatchesRange(string $full, string $monthDay, string $start, string $end): bool
    {
        $start = $this->normalizeDateValue($start);
        $end = $this->normalizeDateValue($end) ?? $start;
        if ($start === null || $end === null) {
            return false;
        }

        if (strlen($start) === 10 || strlen($end) === 10) {
            if (strlen($start) !== 10 || strlen($end) !== 10) {
                return false;
            }

            return $full >= $start && $full <= $end;
        }

        if ($start <= $end) {
            return $monthDay >= $start && $monthDay <= $end;
        }

        return $monthDay >= $start || $monthDay <= $end;
    }

    public function seasonalEffect(?DateTimeInterface $date = null): string
    {
        return $this->dateRuleForDate($date)['effect'] ?? 'none';
    }

    public function normalizeEffect(string $effect, string $fallback = 'none'): string
    {
        $effect = strtolower(trim($effect));
        return in_array($effect, self::EFFECTS, true) || $effect === 'none' ? $effect : $fallback;
    }

    private function normalizeDateValue(string $date): ?string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            [$year, $month, $day] = array_map('intval', explode('-', $date));
            return checkdate($month, $day, $year) ? $date : null;
        }

        if (preg_match('/^\d{2}-\d{2}$/', $date)) {
            [$month, $day] = array_map('intval', explode('-', $date));
            return checkdate($month, $day, 2024) ? $date : null;
        }

        return null;
    }

    private function ensureTable(): void
    {
        $db = $this->database->connect();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS site_effect_settings (
                key VARCHAR(64) PRIMARY KEY,
                value TEXT NOT NULL DEFAULT '',
                updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS site_effect_dates (
                id SERIAL PRIMARY KEY,
                date_value VARCHAR(10) NOT NULL,
                date_start VARCHAR(10),
                date_end VARCHAR(10),
                effect VARCHAR(32) NOT NULL,
                symbols TEXT DEFAULT '',
                created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $db->exec("ALTER TABLE site_effect_dates ADD COLUMN IF NOT EXISTS date_start VARCHAR(10)");
        $db->exec("ALTER TABLE site_effect_dates ADD COLUMN IF NOT EXISTS date_end VARCHAR(10)");
        $db->exec("UPDATE site_effect_dates SET date_start = date_value WHERE date_start IS NULL");
        $db->exec("UPDATE site_effect_dates SET date_end = date_value WHERE date_end IS NULL");
        $db->exec(
            "INSERT INTO site_effect_settings (key, value) VALUES
                ('effect_mode', 'auto'),
                ('effect_symbols', ''),
                ('effect_intensity', 'medium'),
                ('effect_size', 'medium'),
                ('effect_layer', 'under'),
                ('effect_dates_seeded', '0')
             ON CONFLICT (key) DO NOTHING"
        );
        $this->seedDefaultDateRules($db);
    }

    private function seedDefaultDateRules(PDO $db): void
    {
        $seeded = $db->query("SELECT value FROM site_effect_settings WHERE key = 'effect_dates_seeded'")
            ->fetchColumn();
        if ($seeded === '1') {
            return;
        }

        $hasRules = (int)$db->query('SELECT COUNT(*) FROM site_effect_dates')->fetchColumn() > 0;
        if (!$hasRules) {
            $stmt = $db->prepare(
                'INSERT INTO site_effect_dates (date_value, date_start, date_end, effect, symbols)
                 VALUES (:date, :start, :end, :effect, :symbols)'
            );

            foreach ([
                ['12-01', '12-30', 'snow', ''],
                ['01-01', '01-01', 'confetti', ''],
                ['12-31', '12-31', 'confetti', ''],
                ['02-14', '02-14', 'hearts', ''],
            ] as [$start, $end, $effect, $symbols]) {
                $stmt->execute([
                    ':date' => $start,
                    ':start' => $start,
                    ':end' => $end,
                    ':effect' => $effect,
                    ':symbols' => $symbols,
                ]);
            }
        }

        $stmt = $db->prepare(
            "INSERT INTO site_effect_settings (key, value, updated_at)
             VALUES ('effect_dates_seeded', '1', CURRENT_TIMESTAMP)
             ON CONFLICT (key) DO UPDATE SET value = '1', updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute();
    }
}
