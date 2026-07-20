<?php

require_once __DIR__ . '/../Database.php';

$output = $argv[1] ?? (__DIR__ . '/../docs/erd.png');
$output = str_replace('\\', '/', $output);

$db = (new Database())->connect();

$columnsStmt = $db->query(<<<SQL
    SELECT
        c.table_name,
        c.column_name,
        c.data_type,
        c.character_maximum_length,
        c.numeric_precision,
        c.numeric_scale,
        c.is_nullable,
        c.ordinal_position
    FROM information_schema.columns c
    JOIN information_schema.tables t
      ON t.table_schema = c.table_schema
     AND t.table_name = c.table_name
    WHERE c.table_schema = 'public'
      AND t.table_type = 'BASE TABLE'
    ORDER BY c.table_name, c.ordinal_position
SQL);

$tables = [];
foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $name = $row['table_name'];
    $tables[$name] ??= ['columns' => [], 'pk' => [], 'fk' => []];
    $tables[$name]['columns'][] = [
        'name' => $row['column_name'],
        'type' => columnType($row),
        'nullable' => $row['is_nullable'] === 'YES',
    ];
}

$pkStmt = $db->query(<<<SQL
    SELECT tc.table_name, kcu.column_name
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu
      ON kcu.constraint_name = tc.constraint_name
     AND kcu.table_schema = tc.table_schema
    WHERE tc.table_schema = 'public'
      AND tc.constraint_type = 'PRIMARY KEY'
SQL);

foreach ($pkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($tables[$row['table_name']])) {
        $tables[$row['table_name']]['pk'][$row['column_name']] = true;
    }
}

$fkStmt = $db->query(<<<SQL
    SELECT
        con.conname,
        source.relname AS source_table,
        source_att.attname AS source_column,
        target.relname AS target_table,
        target_att.attname AS target_column
    FROM pg_constraint con
    JOIN pg_class source ON source.oid = con.conrelid
    JOIN pg_namespace source_ns ON source_ns.oid = source.relnamespace
    JOIN pg_class target ON target.oid = con.confrelid
    JOIN unnest(con.conkey) WITH ORDINALITY source_cols(attnum, ord) ON true
    JOIN unnest(con.confkey) WITH ORDINALITY target_cols(attnum, ord) ON target_cols.ord = source_cols.ord
    JOIN pg_attribute source_att ON source_att.attrelid = con.conrelid AND source_att.attnum = source_cols.attnum
    JOIN pg_attribute target_att ON target_att.attrelid = con.confrelid AND target_att.attnum = target_cols.attnum
    WHERE con.contype = 'f'
      AND source_ns.nspname = 'public'
    ORDER BY source.relname, con.conname, source_cols.ord
SQL);

$relations = [];
foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $relations[] = $row;
    if (isset($tables[$row['source_table']])) {
        $tables[$row['source_table']]['fk'][$row['source_column']] = true;
    }
}

ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);

$boxWidth = 340;
$headerHeight = 30;
$rowHeight = 18;
$gapX = 58;
$gapY = 34;
$margin = 44;
$titleHeight = 82;
$columns = 5;

$positions = [];
$columnHeights = array_fill(0, $columns, $titleHeight + $margin);

foreach ($tables as $table => $meta) {
    $height = boxHeight($meta, $headerHeight, $rowHeight);
    $column = array_search(min($columnHeights), $columnHeights, true);
    $x = $margin + $column * ($boxWidth + $gapX);
    $y = $columnHeights[$column];
    $positions[$table] = ['x' => $x, 'y' => $y, 'w' => $boxWidth, 'h' => $height];
    $columnHeights[$column] += $height + $gapY;
}

$width = $margin * 2 + $columns * $boxWidth + ($columns - 1) * $gapX;
$height = max($columnHeights) + $margin;

$img = imagecreatetruecolor($width, $height);
imageantialias($img, true);

$bg = color($img, '#101418');
$panel = color($img, '#1B2027');
$panelAlt = color($img, '#151A20');
$border = color($img, '#323B48');
$text = color($img, '#F4F7FB');
$muted = color($img, '#AFC0D3');
$primary = color($img, '#7B5CFF');
$pk = color($img, '#22C55E');
$fk = color($img, '#60A5FA');
$line = colorAlpha($img, '#7B8796', 78);

imagefill($img, 0, 0, $bg);
imagestring($img, 5, $margin, 24, 'OCStudio ERD', $text);
imagestring($img, 3, $margin, 50, 'Generated from PostgreSQL schema. Tables: ' . count($tables) . ', foreign keys: ' . count($relations), $muted);
imagestring($img, 3, $width - 360, 30, 'PK = primary key   FK = foreign key', $muted);

foreach ($relations as $relation) {
    if (!isset($positions[$relation['source_table']], $positions[$relation['target_table']])) {
        continue;
    }
    drawRelation($img, $positions[$relation['source_table']], $positions[$relation['target_table']], $line);
}

foreach ($tables as $table => $meta) {
    drawTable($img, $positions[$table], $table, $meta, [
        'panel' => $panel,
        'panelAlt' => $panelAlt,
        'border' => $border,
        'text' => $text,
        'muted' => $muted,
        'primary' => $primary,
        'pk' => $pk,
        'fk' => $fk,
    ], $headerHeight, $rowHeight);
}

$dir = dirname($output);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

imagepng($img, $output, 6);
imagedestroy($img);

echo "ERD generated: {$output}\n";

function columnType(array $row): string
{
    $type = (string)$row['data_type'];
    if ($row['character_maximum_length'] !== null) {
        return $type . '(' . $row['character_maximum_length'] . ')';
    }
    if ($type === 'numeric' && $row['numeric_precision'] !== null) {
        return $type . '(' . $row['numeric_precision'] . ',' . ($row['numeric_scale'] ?? 0) . ')';
    }
    return $type;
}

function boxHeight(array $meta, int $headerHeight, int $rowHeight): int
{
    return $headerHeight + 10 + max(1, count($meta['columns'])) * $rowHeight + 10;
}

function color($img, string $hex): int
{
    [$r, $g, $b] = hexRgb($hex);
    return imagecolorallocate($img, $r, $g, $b);
}

function colorAlpha($img, string $hex, int $alpha): int
{
    [$r, $g, $b] = hexRgb($hex);
    return imagecolorallocatealpha($img, $r, $g, $b, $alpha);
}

function hexRgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function drawRelation($img, array $from, array $to, int $color): void
{
    $fromX = $from['x'] + $from['w'] / 2;
    $fromY = $from['y'] + $from['h'] / 2;
    $toX = $to['x'] + $to['w'] / 2;
    $toY = $to['y'] + $to['h'] / 2;
    imagesetthickness($img, 1);
    imageline($img, (int)$fromX, (int)$fromY, (int)$toX, (int)$toY, $color);
}

function drawTable($img, array $box, string $table, array $meta, array $colors, int $headerHeight, int $rowHeight): void
{
    imagefilledrectangle($img, $box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h'], $colors['panel']);
    imagerectangle($img, $box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $box['h'], $colors['border']);
    imagefilledrectangle($img, $box['x'], $box['y'], $box['x'] + $box['w'], $box['y'] + $headerHeight, $colors['panelAlt']);
    imageline($img, $box['x'], $box['y'] + $headerHeight, $box['x'] + $box['w'], $box['y'] + $headerHeight, $colors['primary']);
    imagestring($img, 3, $box['x'] + 10, $box['y'] + 8, trimText($table, 38), $colors['text']);

    $y = $box['y'] + $headerHeight + 8;
    foreach ($meta['columns'] as $column) {
        $name = $column['name'];
        $markers = [];
        if (isset($meta['pk'][$name])) {
            $markers[] = 'PK';
        }
        if (isset($meta['fk'][$name])) {
            $markers[] = 'FK';
        }

        $markerText = implode(' ', $markers);
        $markerColor = isset($meta['pk'][$name]) ? $colors['pk'] : (isset($meta['fk'][$name]) ? $colors['fk'] : $colors['muted']);
        if ($markerText !== '') {
            imagestring($img, 2, $box['x'] + 10, $y, $markerText, $markerColor);
        }

        $nullable = $column['nullable'] ? '?' : '';
        $label = trimText($name . $nullable . ': ' . $column['type'], 46);
        imagestring($img, 2, $box['x'] + 54, $y, $label, $colors['muted']);
        $y += $rowHeight;
    }
}

function trimText(string $value, int $max): string
{
    if (strlen($value) <= $max) {
        return $value;
    }
    return substr($value, 0, max(0, $max - 3)) . '...';
}
