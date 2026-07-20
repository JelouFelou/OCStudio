<?php

require_once __DIR__ . '/../Database.php';

const BASELINE_THROUGH = '018_relation_variants.sql';

$root = dirname(__DIR__);
$migrationsDir = $root . '/docker/db/migrations';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$pdo = (new Database())->connect();
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
    )"
);

$applied = fetchAppliedMigrations($pdo);

if (empty($applied) && hasBaselineApplicationSchema($pdo)) {
    baselineExistingSchema($pdo, $files);
    $applied = fetchAppliedMigrations($pdo);
}

$pending = array_values(array_filter($files, static function (string $file) use ($applied): bool {
    return !isset($applied[basename($file)]);
}));

if (empty($pending)) {
    echo "No pending migrations.\n";
    exit(0);
}

foreach ($pending as $file) {
    $version = basename($file);
    $sql = file_get_contents($file);

    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "Migration is empty or unreadable: {$version}\n");
        exit(1);
    }

    echo "Applying {$version}...\n";

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $stmt->execute([':version' => $version]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        fwrite(STDERR, "Failed to apply {$version}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "Applied " . count($pending) . " migration(s).\n";

function fetchAppliedMigrations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT version FROM schema_migrations');
    $versions = [];

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $version) {
        $versions[(string)$version] = true;
    }

    return $versions;
}

function hasBaselineApplicationSchema(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT to_regclass('public.users') IS NOT NULL
            AND to_regclass('public.characters') IS NOT NULL
            AND to_regclass('public.stories') IS NOT NULL
            AND EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = 'story_characters'
                  AND column_name = 'id_variant'
            )
            AND EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = 'relation_board_characters'
                  AND column_name = 'id_variant'
            )
            AND EXISTS (
                SELECT 1 FROM information_schema.columns
                WHERE table_schema = 'public'
                  AND table_name = 'character_relations'
                  AND column_name = 'character_a_variant_id'
            )"
    );

    return (bool)$stmt->fetchColumn();
}

function baselineExistingSchema(PDO $pdo, array $files): void
{
    $baselineFiles = array_values(array_filter($files, static function (string $file): bool {
        return basename($file) <= BASELINE_THROUGH;
    }));

    if (empty($baselineFiles)) {
        return;
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO schema_migrations (version) VALUES (:version)
             ON CONFLICT (version) DO NOTHING'
        );

        foreach ($baselineFiles as $file) {
            $stmt->execute([':version' => basename($file)]);
        }

        $pdo->commit();
        echo "Baselined " . count($baselineFiles) . " existing migration(s).\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
