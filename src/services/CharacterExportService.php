<?php

require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../repositories/CharacterRepository.php';
require_once __DIR__ . '/../repositories/TemplateRepository.php';

class CharacterExportService
{
    private CharacterRepository $characterRepository;
    private TemplateRepository $templateRepository;

    public function __construct()
    {
        $this->characterRepository = new CharacterRepository();
        $this->templateRepository = new TemplateRepository();
    }

    public function buildExport(int $userId, string $rawCharacterId, string $scope = 'current', ?int $variantId = null): array
    {
        $character = $this->findOwnedCharacter($userId, $rawCharacterId);
        if (!$character) {
            throw new InvalidArgumentException('Postac nie zostala znaleziona.', 404);
        }

        $baseValues = $this->characterRepository->getCharacterFieldValues((int)$character->getId());
        $fields = $character->getIdTemplate()
            ? $this->templateRepository->getTemplateFields((int)$character->getIdTemplate())
            : [];
        $template = $character->getIdTemplate()
            ? $this->templateRepository->getTemplateByIdAndUserId((int)$character->getIdTemplate(), $userId)
            : null;

        $entries = [];
        if ($scope === 'all') {
            $entries[] = $this->entryFromCharacter($character, 'Forma podstawowa', $baseValues, $fields);
            foreach ($this->characterRepository->getCharacterVariants((int)$character->getId()) as $variant) {
                $entries[] = $this->entryFromVariant($character, $variant, $baseValues, $fields);
            }
        } elseif ($variantId !== null && $variantId > 0) {
            $variant = $this->characterRepository->getCharacterVariant($variantId, (int)$character->getId());
            if (!$variant) {
                throw new InvalidArgumentException('Wariant nie zostal znaleziony.', 404);
            }
            $entries[] = $this->entryFromVariant($character, $variant, $baseValues, $fields);
        } else {
            $entries[] = $this->entryFromCharacter($character, 'Forma podstawowa', $baseValues, $fields);
        }

        return [
            'character' => [
                'name' => $character->getName(),
                'template' => $template ? $template->getName() : '',
                'publicId' => $character->getPublicId(),
                'txtExportEnabled' => $template ? $template->isTxtExportEnabled() : false,
                'txtExportTemplate' => $template ? $template->getTxtExportTemplate() : '',
            ],
            'entries' => $entries,
        ];
    }

    public function renderTxt(array $export): string
    {
        if (!empty($export['character']['txtExportEnabled']) && trim((string)($export['character']['txtExportTemplate'] ?? '')) !== '') {
            $blocks = [];
            foreach ($export['entries'] ?? [] as $entry) {
                $blocks[] = $this->renderCustomTxtTemplate(
                    (string)$export['character']['txtExportTemplate'],
                    $export['character'],
                    $entry
                );
            }

            return implode("\r\n\r\n", array_map('trim', $blocks)) . "\r\n";
        }

        $lines = [
            (string)($export['character']['name'] ?? 'Postac'),
            str_repeat('=', max(8, mb_strlen((string)($export['character']['name'] ?? 'Postac')))),
        ];

        if (!empty($export['character']['template'])) {
            $lines[] = 'Szablon: ' . $export['character']['template'];
        }

        foreach ($export['entries'] ?? [] as $index => $entry) {
            if ($index > 0) {
                $lines[] = '';
            }
            foreach ($this->entryLines($entry) as $line) {
                $lines[] = $line;
            }
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    public function renderPdf(array $export, array $settings = []): string
    {
        return $this->buildVisualPdf($export, $settings);
    }

    public function renderBulkTxt(array $exports): string
    {
        $blocks = [];
        foreach ($exports as $export) {
            $blocks[] = trim($this->renderTxt($export));
        }

        return implode("\r\n\r\n" . str_repeat('=', 72) . "\r\n\r\n", array_filter($blocks, fn(string $block): bool => $block !== '')) . "\r\n";
    }

    public function mergeExports(array $exports, string $title): array
    {
        $entries = [];
        foreach ($exports as $export) {
            $characterName = (string)($export['character']['name'] ?? 'Postac');
            foreach ($export['entries'] ?? [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entry['variantLabel'] = trim($characterName . ' / ' . (string)($entry['variantLabel'] ?? ''), ' /');
                $entries[] = $entry;
            }
        }

        return [
            'character' => [
                'name' => $title,
                'template' => '',
                'publicId' => '',
                'txtExportEnabled' => false,
                'txtExportTemplate' => '',
            ],
            'entries' => $entries,
        ];
    }

    public function filename(array $export, string $extension, string $scope = 'current'): string
    {
        $name = (string)($export['character']['name'] ?? 'postac');
        $safe = preg_replace('/[^a-z0-9_-]+/i', '-', $this->ascii($name));
        $safe = trim((string)$safe, '-_') ?: 'postac';
        $suffix = $scope === 'all' ? 'calosc' : 'wariant';

        return strtolower($safe . '-' . $suffix . '.' . $extension);
    }

    public function bulkFilename(string $format, bool $zip): string
    {
        $date = date('Y-m-d');
        return $zip
            ? 'postacie-osobne-' . $format . '-' . $date . '.zip'
            : 'postacie-masowo-' . $date . '.' . $format;
    }

    public function uniqueArchiveFilename(string $filename, array &$usedNames): string
    {
        $filename = basename($filename);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME) ?: 'postac';
        $candidate = $filename;
        $i = 2;
        while (isset($usedNames[mb_strtolower($candidate)])) {
            $candidate = $base . '-' . $i . ($extension !== '' ? '.' . $extension : '');
            $i++;
        }
        $usedNames[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    private function findOwnedCharacter(int $userId, string $raw): ?Character
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        return ctype_digit($raw)
            ? $this->characterRepository->getCharacterByIdAndUserId((int)$raw, $userId)
            : $this->characterRepository->getCharacterByPublicIdAndUserId($raw, $userId);
    }

    private function entryFromCharacter(Character $character, string $variantLabel, array $values, array $fields): array
    {
        return [
            'title' => $character->getName(),
            'variantLabel' => $variantLabel,
            'intro' => $character->getIntro(),
            'description' => $character->getDescription(),
            'image' => $character->getImage(),
            'fields' => $this->fieldLines($fields, $values),
            'fieldMap' => $this->fieldMap($fields, $values),
        ];
    }

    private function entryFromVariant(Character $character, array $variant, array $baseValues, array $fields): array
    {
        $values = array_replace($baseValues, is_array($variant['values'] ?? null) ? $variant['values'] : []);
        $name = trim((string)($variant['name'] ?? '')) ?: $character->getName();
        $description = trim((string)($variant['description'] ?? ''));

        return [
            'title' => $name,
            'variantLabel' => 'Wariant',
            'intro' => $character->getIntro(),
            'description' => $description !== '' ? $description : $character->getDescription(),
            'image' => trim((string)($variant['image'] ?? '')) ?: $character->getImage(),
            'fields' => $this->fieldLines($fields, $values),
            'fieldMap' => $this->fieldMap($fields, $values),
        ];
    }

    private function renderCustomTxtTemplate(string $template, array $character, array $entry): string
    {
        $fieldMap = is_array($entry['fieldMap'] ?? null) ? $entry['fieldMap'] : [];
        $replacements = [
            '{{character.name}}' => (string)($character['name'] ?? ''),
            '{{variant.name}}' => (string)($entry['title'] ?? ''),
            '{{template.name}}' => (string)($character['template'] ?? ''),
            '{{variant.label}}' => (string)($entry['variantLabel'] ?? ''),
            '{{intro}}' => (string)($entry['intro'] ?? ''),
            '{{description}}' => (string)($entry['description'] ?? ''),
            '{{all_fields}}' => $this->allFieldsText($entry),
        ];

        $output = strtr($template, $replacements);
        $output = preg_replace_callback('/\{\{field_id:(\d+)\}\}/', function (array $matches) use ($fieldMap): string {
            return (string)($fieldMap['id:' . $matches[1]] ?? '');
        }, $output) ?? $output;
        $output = preg_replace_callback('/\{\{field:([^}]+)\}\}/', function (array $matches) use ($fieldMap): string {
            $key = $this->fieldLabelKey($matches[1]);
            return (string)($fieldMap['label:' . $key] ?? '');
        }, $output) ?? $output;

        return $output;
    }

    private function fieldMap(array $fields, array $values): array
    {
        $map = [];
        foreach ($fields as $field) {
            $fieldId = (int)($field['id'] ?? 0);
            $label = trim((string)($field['label'] ?? ''));
            if ($fieldId <= 0 || $label === '') {
                continue;
            }
            $value = $this->fieldValueToText($field, $values[$fieldId] ?? null);
            $map['id:' . $fieldId] = $value;
            $map['label:' . $this->fieldLabelKey($label)] = $value;
        }

        return $map;
    }

    private function allFieldsText(array $entry): string
    {
        $lines = [];
        foreach (($entry['fields'] ?? []) as $field) {
            if (!is_array($field) || count($field) < 2) {
                continue;
            }
            $lines[] = $field[0] . ': ' . str_replace("\n", '; ', (string)$field[1]);
        }

        return implode("\n", $lines);
    }

    private function fieldLabelKey(string $label): string
    {
        return mb_strtolower(trim($label));
    }

    private function fieldLines(array $fields, array $values): array
    {
        $lines = [];
        foreach ($fields as $field) {
            $fieldId = (int)($field['id'] ?? 0);
            $label = trim((string)($field['label'] ?? ''));
            if ($fieldId <= 0 || $label === '') {
                continue;
            }

            $rawValue = $values[$fieldId] ?? null;
            $value = $this->fieldValueToText($field, $rawValue);
            $type = (string)($field['field_type'] ?? 'text');
            $images = $this->fieldImages($field, $rawValue);
            if ($value === '' && empty($images)) {
                continue;
            }
            $lines[] = [
                $label,
                $value,
                'id' => $fieldId,
                'type' => $type,
                'typeLabel' => $this->fieldTypeLabel($type),
                'location' => (string)($field['location'] ?? 'left'),
                'imageSize' => $this->fieldImageSize($field),
                'images' => $images,
            ];
        }

        return $lines;
    }

    private function entryLines(array $entry): array
    {
        $lines = [
            (string)($entry['title'] ?? 'Postac'),
            str_repeat('-', max(8, mb_strlen((string)($entry['title'] ?? 'Postac')))),
        ];

        if (!empty($entry['variantLabel'])) {
            $lines[] = 'Zakres: ' . $entry['variantLabel'];
        }
        if (trim((string)($entry['intro'] ?? '')) !== '') {
            $lines[] = '';
            $lines[] = 'Wstep';
            $lines = array_merge($lines, $this->paragraphLines((string)$entry['intro']));
        }
        if (trim((string)($entry['description'] ?? '')) !== '') {
            $lines[] = '';
            $lines[] = 'Opis';
            $lines = array_merge($lines, $this->paragraphLines((string)$entry['description']));
        }
        if (!empty($entry['fields'])) {
            $lines[] = '';
            $lines[] = 'Pola';
            foreach ($entry['fields'] as $field) {
                $label = (string)($field[0] ?? '');
                $value = (string)($field[1] ?? '');
                $lines[] = $label . ':';
                foreach ($this->paragraphLines($value) as $line) {
                    $lines[] = '  ' . $line;
                }
            }
        }

        return $lines;
    }

    private function fieldValueToText(array $field, mixed $rawValue): string
    {
        if ($rawValue === null || $rawValue === '') {
            return '';
        }

        $type = (string)($field['field_type'] ?? 'text');
        $value = $this->decodeJsonDeep($rawValue);

        if ($type === 'date') {
            return $this->formatDate($value);
        }

        if ($type === 'table') {
            return $this->tableToText($field, $value);
        }

        if ($type === 'list') {
            $items = $this->listItems($value);
            return implode("\n", array_map(fn($item) => '- ' . $item, $items));
        }

        if ($type === 'image' || $type === 'image-gallery') {
            return $this->readableValue($value);
        }

        return $this->readableValue($value);
    }

    private function fieldTypeLabel(string $type): string
    {
        return match ($type) {
            'textarea' => 'Dlugi tekst',
            'list' => 'Lista',
            'image' => 'Zdjecie',
            'image-gallery' => 'Galeria',
            'table' => 'Tabela',
            'stats' => 'Statystyki',
            'date' => 'Data',
            'select' => 'Wybor',
            default => 'Tekst',
        };
    }

    private function tableToText(array $field, mixed $value): string
    {
        if (!is_array($value)) {
            return $this->readableValue($value);
        }

        $rowDefs = $this->tableRowsConfig($field);
        $lines = [];
        if (empty($rowDefs)) {
            foreach ($value as $key => $cell) {
                if ($this->imageFilenameFromValue($cell) !== '') {
                    continue;
                }
                $text = $this->readableValue($cell);
                if ($text !== '') {
                    $lines[] = (is_string($key) ? $key : 'Wartosc') . ': ' . $text;
                }
            }
            return implode("\n", $lines);
        }

        foreach ($rowDefs as $row) {
            $cell = $value[$row['key']] ?? $value[$row['label']] ?? null;
            if ($this->tableCellType($row, $cell) === 'image') {
                continue;
            }
            $text = $this->readableValue($cell);
            if ($text !== '') {
                $lines[] = $row['label'] . ': ' . $text;
            }
        }

        return implode("\n", $lines);
    }

    private function tableRowsConfig(array $field): array
    {
        $cfg = $this->decodeJsonDeep($field['placeholder'] ?? '');
        $rows = is_array($cfg['rows'] ?? null) ? $cfg['rows'] : (is_array($cfg) ? $cfg : []);
        $normalized = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $normalized[] = ['key' => $row, 'label' => $row, 'type' => 'text'];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string)($row['label'] ?? $row['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = in_array(($row['type'] ?? 'text'), ['text', 'date', 'image', 'list', 'age', 'select'], true)
                ? (string)$row['type']
                : 'text';
            $normalized[] = ['key' => (string)($row['key'] ?? $label), 'label' => $label, 'type' => $type];
        }

        return $normalized;
    }

    private function tableCellType(array $row, mixed $cell): string
    {
        if (is_array($cell)) {
            $declared = (string)($cell['type'] ?? '');
            if (in_array($declared, ['text', 'date', 'image', 'list', 'age', 'select'], true)) {
                return $declared;
            }
        }

        return (string)($row['type'] ?? 'text');
    }

    private function fieldImages(array $field, mixed $rawValue): array
    {
        $type = (string)($field['field_type'] ?? 'text');
        $value = $this->decodeJsonDeep($rawValue);
        $label = trim((string)($field['label'] ?? 'Zdjecie'));

        if ($type === 'image') {
            $filename = $this->imageFilenameFromValue($value);
            return $filename !== '' ? [[
                'filename' => $filename,
                'caption' => $label,
                'layout' => $this->fieldImageSize($field),
            ]] : [];
        }

        if ($type === 'image-gallery') {
            $images = [];
            foreach ($this->imageListFromValue($value) as $index => $imageValue) {
                $filename = $this->imageFilenameFromValue($imageValue);
                if ($filename !== '') {
                    $images[] = ['filename' => $filename, 'caption' => $label . ' ' . ($index + 1), 'layout' => 'gallery'];
                }
            }
            return $images;
        }

        if ($type === 'table') {
            return $this->tableImages($field, $value);
        }

        return [];
    }

    private function tableImages(array $field, mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rowDefs = $this->tableRowsConfig($field);
        if (empty($rowDefs)) {
            $rowDefs = array_map(fn($key) => ['key' => (string)$key, 'label' => (string)$key, 'type' => 'text'], array_keys($value));
        }

        $images = [];
        foreach ($rowDefs as $row) {
            $cell = $value[$row['key']] ?? $value[$row['label']] ?? null;
            if ($this->tableCellType($row, $cell) !== 'image') {
                continue;
            }
            $filename = $this->imageFilenameFromValue($this->cellValue($cell));
            if ($filename !== '') {
                $images[] = ['filename' => $filename, 'caption' => (string)$row['label'], 'layout' => 'table'];
            }
        }

        return $images;
    }

    private function fieldImageSize(array $field): string
    {
        if (($field['field_type'] ?? '') !== 'image') {
            return 'medium';
        }

        $cfg = $this->decodeJsonDeep($field['placeholder'] ?? '');
        $size = is_array($cfg) ? (string)($cfg['size'] ?? 'medium') : 'medium';

        return in_array($size, ['small', 'medium', 'large', 'full'], true) ? $size : 'medium';
    }

    private function imageListFromValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (!$this->isAssoc($value) || empty($value['url']) && empty($value['filename'])) {
            return array_values($value);
        }

        return [$value];
    }

    private function imageFilenameFromValue(mixed $value): string
    {
        $value = $this->decodeJsonDeep($this->cellValue($value));
        if (is_string($value)) {
            $candidate = basename(parse_url($value, PHP_URL_PATH) ?: $value);
            return is_file($this->uploadPath($candidate)) ? $candidate : '';
        }
        if (!is_array($value)) {
            return '';
        }

        $candidate = '';
        if (!empty($value['filename'])) {
            $candidate = basename((string)$value['filename']);
        } elseif (!empty($value['url'])) {
            $candidate = basename(parse_url((string)$value['url'], PHP_URL_PATH) ?: (string)$value['url']);
        }

        return $candidate !== '' && is_file($this->uploadPath($candidate)) ? $candidate : '';
    }

    private function readableValue(mixed $value): string
    {
        $value = $this->cellValue($value);
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return trim((string)$value);
        }
        if (is_array($value)) {
            if (!empty($value['filename'])) {
                return (string)$value['filename'];
            }
            if (!empty($value['url'])) {
                return basename((string)$value['url']);
            }
            if (array_key_exists('day', $value) || array_key_exists('monthName', $value) || array_key_exists('year', $value)) {
                return $this->formatDate($value);
            }
            if ($this->isAssoc($value)) {
                $parts = [];
                foreach ($value as $key => $item) {
                    $text = $this->readableValue($item);
                    if ($text !== '') {
                        $parts[] = (is_string($key) ? $key . ': ' : '') . $text;
                    }
                }
                return implode("\n", $parts);
            }
            return implode("\n", array_filter(array_map(fn($item) => $this->readableValue($item), $value)));
        }

        return '';
    }

    private function listItems(mixed $value): array
    {
        $value = $this->cellValue($value);
        if (is_array($value) && array_key_exists('value', $value)) {
            $value = $value['value'];
        }
        if (is_array($value)) {
            $items = $this->isAssoc($value) ? array_values($value) : $value;
            return array_values(array_filter(array_map(fn($item) => $this->readableValue($item), $items)));
        }

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$value) ?: [])));
    }

    private function formatDate(mixed $date): string
    {
        if (!is_array($date)) {
            return trim((string)$date);
        }

        return implode(' ', array_filter([
            $date['day'] ?? null,
            $date['monthName'] ?? null,
            $date['year'] ?? null,
            $date['era'] ?? null,
        ], fn($part) => $part !== null && $part !== ''));
    }

    private function decodeJsonDeep(mixed $value): mixed
    {
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $candidate = trim($value);
            if ($candidate === '') {
                return '';
            }
            $decoded = json_decode($candidate, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                break;
            }
            $value = $decoded;
        }

        return $value;
    }

    private function cellValue(mixed $cell): mixed
    {
        return is_array($cell) && array_key_exists('value', $cell) ? $cell['value'] : $cell;
    }

    private function isAssoc(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }

    private function paragraphLines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function paginateLines(array $lines): array
    {
        $wrapped = [];
        foreach ($lines as $line) {
            $parts = $this->wrapLine($line, 92);
            foreach ($parts as $part) {
                $wrapped[] = $part;
            }
        }

        $pages = [];
        $page = [];
        foreach ($wrapped as $line) {
            if (count($page) >= 52) {
                $pages[] = $page;
                $page = [];
            }
            $page[] = $line;
        }
        if (!empty($page)) {
            $pages[] = $page;
        }

        return $pages;
    }

    private function wrapLine(string $line, int $max): array
    {
        if ($line === '') {
            return [''];
        }

        $indent = str_repeat(' ', strspn($line, ' '));
        $words = preg_split('/\s+/', trim($line)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($candidate) <= $max) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = ($lines ? $indent : '') . $current;
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = (empty($lines) ? $indent : $indent) . $current;
        }

        return $lines ?: [''];
    }

    private function buildVisualPdf(array $export, array $settings): string
    {
        $palette = $this->pdfPalette($settings);
        $pages = $this->visualPdfPages($export);
        if (empty($pages)) {
            $pages[] = [
                'entry' => ['title' => (string)($export['character']['name'] ?? 'Postac'), 'variantLabel' => '', 'image' => ''],
                'items' => [['type' => 'paragraph', 'lines' => ['Brak danych postaci.']]],
                'first' => true,
            ];
        }

        $objects = [
            1 => '',
            2 => '',
            3 => $this->pdfFontObject('Helvetica'),
            4 => $this->pdfFontObject('Helvetica-Bold'),
        ];
        $kids = [];
        $nextObject = 5;
        foreach ($pages as $pageIndex => $page) {
            $pageForContent = $page;
            $imageObjects = [];
            $imageIndex = 1;
            $portraitName = null;

            if (!empty($pageForContent['first'])) {
                $image = $this->pdfImageForEntry($pageForContent['entry'], $settings, $palette);
                if ($image) {
                    $portraitName = 'Im' . $imageIndex++;
                    $objectNumber = $nextObject++;
                    $objects[$objectNumber] = $this->pdfImageObject($image);
                    $imageObjects[$portraitName] = $objectNumber;
                }
            }

            if (!isset($pageForContent['entry']['fields']) || !is_array($pageForContent['entry']['fields'])) {
                $pageForContent['entry']['fields'] = [];
            }
            $this->attachPdfImagesToFields($pageForContent['entry']['fields'], $palette, $objects, $nextObject, $imageObjects, $imageIndex);
            $this->attachPdfImagesToItems($pageForContent['items'], $palette, $objects, $nextObject, $imageObjects, $imageIndex);

            $content = $this->visualPdfContent($pageForContent, $palette, $portraitName, $pageIndex + 1, count($pages));
            $contentObject = $nextObject++;
            $objects[$contentObject] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

            $pageObject = $nextObject++;
            $xObjects = '';
            if (!empty($imageObjects)) {
                $xObjectRefs = [];
                foreach ($imageObjects as $name => $objectNumber) {
                    $xObjectRefs[] = '/' . $name . ' ' . $objectNumber . ' 0 R';
                }
                $xObjects = ' /XObject << ' . implode(' ', $xObjectRefs) . ' >>';
            }
            $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>{$xObjects} >> /Contents {$contentObject} 0 R >>";
            $kids[] = "{$pageObject} 0 R";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        return $this->pdfFromObjects($objects);
    }

    private function attachPdfImagesToFields(array &$fields, array $palette, array &$objects, int &$nextObject, array &$imageObjects, int &$imageIndex): void
    {
        foreach ($fields as &$field) {
            if (!is_array($field['images'] ?? null)) {
                continue;
            }
            $this->attachPdfImages($field['images'], $palette, $objects, $nextObject, $imageObjects, $imageIndex);
        }
    }

    private function attachPdfImagesToItems(array &$items, array $palette, array &$objects, int &$nextObject, array &$imageObjects, int &$imageIndex): void
    {
        foreach ($items as &$item) {
            if (!is_array($item['images'] ?? null)) {
                continue;
            }
            $this->attachPdfImages($item['images'], $palette, $objects, $nextObject, $imageObjects, $imageIndex);
        }
    }

    private function attachPdfImages(array &$images, array $palette, array &$objects, int &$nextObject, array &$imageObjects, int &$imageIndex): void
    {
        foreach ($images as &$imageRef) {
            $filename = (string)($imageRef['filename'] ?? '');
            if ($filename === '') {
                continue;
            }
            $size = $this->pdfImageRectForLayout((string)($imageRef['layout'] ?? 'table'));
            $image = $this->pdfImageFromFilename(
                $filename,
                $this->pdfRasterWidth($size['width']),
                $this->pdfRasterHeight($size['height']),
                $palette,
                'contain'
            );
            if (!$image) {
                continue;
            }
            $name = 'Im' . $imageIndex++;
            $objectNumber = $nextObject++;
            $objects[$objectNumber] = $this->pdfImageObject($image);
            $imageObjects[$name] = $objectNumber;
            $imageRef['pdfName'] = $name;
            $imageRef['pdfWidth'] = $size['width'];
            $imageRef['pdfHeight'] = $size['height'];
        }
    }

    private function visualPdfPages(array $export): array
    {
        $pages = [];
        foreach ($export['entries'] ?? [] as $entry) {
            $items = $this->visualItems($entry);
            $chunks = $this->chunkVisualItems($items, 430, 700);
            foreach ($chunks as $index => $chunk) {
                $pages[] = [
                    'entry' => $entry,
                    'items' => $chunk,
                    'first' => $index === 0,
                ];
            }
        }

        return $pages;
    }

    private function visualItems(array $entry): array
    {
        $items = [];
        $rightFields = $this->rightFields($entry);
        if (!empty($rightFields)) {
            foreach ($rightFields as $field) {
                $items[] = $this->fieldToVisualItem($field);
            }
        }
        if (trim((string)($entry['intro'] ?? '')) !== '') {
            $items[] = ['type' => 'section', 'text' => 'Wstep'];
            $items[] = ['type' => 'paragraph', 'lines' => $this->wrapPdfText((string)$entry['intro'], 78)];
        }
        if (trim((string)($entry['description'] ?? '')) !== '') {
            $items[] = ['type' => 'section', 'text' => 'Opis'];
            $items[] = ['type' => 'paragraph', 'lines' => $this->wrapPdfText((string)$entry['description'], 78)];
        }
        $mainFields = $this->mainFields($entry);
        if (!empty($mainFields)) {
            $items[] = ['type' => 'section', 'text' => 'Informacje'];
            foreach ($mainFields as $field) {
                $items[] = $this->fieldToVisualItem($field);
            }
        }

        return $items;
    }

    private function rightFields(array $entry): array
    {
        return array_values(array_filter(($entry['fields'] ?? []), function ($field): bool {
            return is_array($field) && (string)($field['location'] ?? 'left') === 'right';
        }));
    }

    private function mainFields(array $entry): array
    {
        return array_values(array_filter(($entry['fields'] ?? []), function ($field): bool {
            return is_array($field) && (string)($field['location'] ?? 'left') !== 'right';
        }));
    }

    private function fieldToVisualItem(array $field): array
    {
        $fieldType = (string)($field['type'] ?? 'text');
        $lines = $fieldType === 'image' || $fieldType === 'image-gallery'
            ? []
            : $this->wrapPdfText((string)($field[1] ?? ''), 70);

        return [
            'type' => 'field',
            'label' => (string)($field[0] ?? ''),
            'fieldType' => $fieldType,
            'imageSize' => (string)($field['imageSize'] ?? 'medium'),
            'lines' => $lines,
            'images' => is_array($field['images'] ?? null) ? $field['images'] : [],
        ];
    }

    private function chunkVisualItems(array $items, int $firstHeight, int $nextHeight): array
    {
        $chunks = [];
        $chunk = [];
        $remaining = $firstHeight;
        $isFirst = true;
        foreach ($items as $item) {
            $height = $this->visualItemHeight($item);
            if (!empty($chunk) && $height > $remaining) {
                $chunks[] = $chunk;
                $chunk = [];
                $remaining = $nextHeight;
                $isFirst = false;
            }
            $chunk[] = $item;
            $remaining -= $height;
        }
        if (!empty($chunk)) {
            $chunks[] = $chunk;
        }

        return $chunks ?: [[]];
    }

    private function visualItemHeight(array $item): int
    {
        return match ($item['type'] ?? '') {
            'section' => 28,
            'field' => 30 + (count($item['lines'] ?? []) * 13) + $this->visualImagesHeight($item),
            default => 12 + (count($item['lines'] ?? []) * 14),
        };
    }

    private function visualImagesHeight(array $item): int
    {
        $images = is_array($item['images'] ?? null) ? $item['images'] : [];
        if (empty($images)) {
            return 0;
        }

        $fieldType = (string)($item['fieldType'] ?? 'text');
        if ($fieldType === 'image') {
            $size = $this->pdfImageDisplaySize((string)($item['imageSize'] ?? 'medium'));
            return 18 + $size['height'];
        }
        if ($fieldType === 'image-gallery') {
            return 20 + ((int)ceil(min(8, count($images)) / 3) * 108);
        }

        return 20 + ((int)ceil(min(6, count($images)) / 4) * 78);
    }

    private function pdfImageDisplaySize(string $size): array
    {
        return match ($size) {
            'small' => ['width' => 118, 'height' => 86],
            'large' => ['width' => 386, 'height' => 250],
            'full' => ['width' => 475, 'height' => 305],
            default => ['width' => 250, 'height' => 165],
        };
    }

    private function pdfImageRectForLayout(string $layout): array
    {
        if (in_array($layout, ['small', 'medium', 'large', 'full'], true)) {
            return $this->pdfImageDisplaySize($layout);
        }

        return match ($layout) {
            'gallery' => ['width' => 142, 'height' => 92],
            default => ['width' => 92, 'height' => 58],
        };
    }

    private function pdfRasterWidth(int $displayWidth): int
    {
        return min(1600, max(1, $displayWidth * 2));
    }

    private function pdfRasterHeight(int $displayHeight): int
    {
        return min(1600, max(1, $displayHeight * 2));
    }

    private function visualPdfContent(array $page, array $palette, ?string $portraitImageName, int $pageNumber, int $pageCount): string
    {
        $entry = $page['entry'];
        $first = !empty($page['first']);
        $out = '';
        $out .= $this->pdfFill($palette['bg']) . "0 0 595 842 re f\n";
        $out .= $this->pdfFill($palette['accent']) . "0 818 595 24 re f\n";

        if ($first) {
            $out .= $this->pdfFill($palette['surface']) . "34 536 527 248 re f\n";
            $out .= $this->pdfFill($palette['accent']) . "34 536 6 248 re f\n";
            $out .= $this->pdfFill($palette['imageBg']) . "56 572 142 180 re f\n";
            if ($portraitImageName !== null) {
                $out .= "q 142 0 0 180 56 572 cm /{$portraitImageName} Do Q\n";
            } else {
                $out .= $this->pdfText('Brak zdjecia', 92, 674, 10, $palette['muted'], true);
            }
            $out .= $this->pdfText((string)($entry['title'] ?? 'Postac'), 222, 732, 24, $palette['text'], true, 28);
            $out .= $this->pdfText((string)($entry['variantLabel'] ?? ''), 222, 704, 11, $palette['accent'], true);
            $out .= $this->pdfText('Eksport OCStudio', 222, 681, 10, $palette['muted']);
        } else {
            $out .= $this->pdfText((string)($entry['title'] ?? 'Postac'), 42, 782, 17, $palette['text'], true);
            $out .= $this->pdfFill($palette['border']) . "42 764 511 1 re f\n";
        }

        $y = $first ? 496 : 738;
        foreach ($page['items'] as $item) {
            $type = (string)($item['type'] ?? 'paragraph');
            if ($type === 'section') {
                $out .= $this->pdfText((string)($item['text'] ?? ''), 42, $y, 13, $palette['accent'], true);
                $y -= 24;
                continue;
            }
            if ($type === 'field') {
                $height = max(42, $this->visualItemHeight($item) - 6);
                $out .= $this->pdfFill($palette['surface']) . "42 " . ($y - $height + 12) . " 511 {$height} re f\n";
                $out .= $this->pdfFill($palette['accentSoft']) . "42 " . ($y - $height + 12) . " 5 {$height} re f\n";
                $out .= $this->pdfText((string)($item['label'] ?? ''), 58, $y - 5, 10, $palette['text'], true, 48);
                $lineY = $y - 22;
                foreach (($item['lines'] ?? []) as $line) {
                    $out .= $this->pdfText((string)$line, 58, $lineY, 9, $palette['muted']);
                    $lineY -= 12;
                }
                $out .= $this->pdfFieldImages($item, $palette, $lineY);
                $y -= $height + 10;
                continue;
            }
            foreach (($item['lines'] ?? []) as $line) {
                $out .= $this->pdfText((string)$line, 42, $y, 10, $palette['text']);
                $y -= 14;
            }
            $y -= 10;
        }

        $out .= $this->pdfText("Strona {$pageNumber}/{$pageCount}", 492, 28, 9, $palette['muted']);

        return $out;
    }

    private function pdfFieldImages(array $item, array $palette, int $lineY): string
    {
        $images = array_values(array_filter(
            is_array($item['images'] ?? null) ? $item['images'] : [],
            static fn($image): bool => is_array($image) && !empty($image['pdfName'])
        ));
        if (empty($images)) {
            return '';
        }

        $fieldType = (string)($item['fieldType'] ?? 'text');
        $out = '';
        if ($fieldType === 'image') {
            $imageRef = $images[0];
            $size = $this->pdfImageDisplaySize((string)($item['imageSize'] ?? 'medium'));
            $x = $size['width'] >= 475 ? 58 : 58;
            $y = $lineY - $size['height'] - 8;
            $out .= $this->pdfFill($palette['imageBg']) . "{$x} {$y} {$size['width']} {$size['height']} re f\n";
            $out .= "q {$size['width']} 0 0 {$size['height']} {$x} {$y} cm /{$imageRef['pdfName']} Do Q\n";

            return $out;
        }

        if ($fieldType === 'image-gallery') {
            $x = 58;
            $y = $lineY - 100;
            foreach (array_slice($images, 0, 8) as $index => $imageRef) {
                if ($index > 0 && $index % 3 === 0) {
                    $x = 58;
                    $y -= 108;
                }
                $out .= $this->pdfFill($palette['imageBg']) . "{$x} {$y} 142 92 re f\n";
                $out .= "q 142 0 0 92 {$x} {$y} cm /{$imageRef['pdfName']} Do Q\n";
                $x += 158;
            }

            return $out;
        }

        $imageY = $lineY - 64;
        $imageX = 58;
        foreach (array_slice($images, 0, 6) as $imageRef) {
            if ($imageX > 405) {
                $imageX = 58;
                $imageY -= 78;
            }
            $out .= $this->pdfFill($palette['imageBg']) . "{$imageX} {$imageY} 92 58 re f\n";
            $out .= "q 92 0 0 58 {$imageX} {$imageY} cm /{$imageRef['pdfName']} Do Q\n";
            if (!empty($imageRef['caption'])) {
                $out .= $this->pdfText((string)$imageRef['caption'], $imageX, $imageY - 10, 7, $palette['muted'], false, 18);
            }
            $imageX += 112;
        }

        return $out;
    }

    private function wrapPdfText(string $text, int $max): array
    {
        $lines = [];
        foreach ($this->paragraphLines($text) as $paragraph) {
            foreach ($this->wrapLine($paragraph, $max) as $line) {
                $lines[] = $line;
            }
        }

        return $lines ?: [''];
    }

    private function pdfPalette(array $settings): array
    {
        $accentName = (string)($settings['accent'] ?? 'orange');
        $accents = [
            'orange' => [245, 158, 11],
            'green' => [34, 197, 94],
            'blue' => [59, 130, 246],
            'purple' => [124, 92, 255],
            'rose' => [236, 72, 153],
        ];
        $accent = $accents[$accentName] ?? $accents['orange'];
        $dark = (string)($settings['theme'] ?? 'light') === 'dark';

        return [
            'bg' => $dark ? [13, 17, 23] : [245, 247, 251],
            'surface' => $dark ? [28, 33, 41] : [255, 255, 255],
            'imageBg' => $dark ? [12, 16, 22] : [235, 239, 245],
            'border' => $dark ? [52, 60, 72] : [210, 217, 229],
            'text' => $dark ? [245, 247, 250] : [24, 31, 42],
            'muted' => $dark ? [172, 185, 202] : [88, 101, 119],
            'accent' => $accent,
            'accentSoft' => [
                (int)round(($accent[0] * .62) + (($dark ? 28 : 255) * .38)),
                (int)round(($accent[1] * .62) + (($dark ? 33 : 255) * .38)),
                (int)round(($accent[2] * .62) + (($dark ? 41 : 255) * .38)),
            ],
        ];
    }

    private function pdfImageForEntry(array $entry, array $settings, array $palette): ?array
    {
        $filename = $this->resolveCharacterImage((string)($entry['image'] ?? ''), $settings);
        return $this->pdfImageFromFilename($filename, 568, 720, $palette, 'cover');
    }

    private function pdfImageFromFilename(string $filename, int $targetWidth, int $targetHeight, array $palette, string $fit = 'cover'): ?array
    {
        $path = $this->uploadPath($filename);
        if (!is_file($path)) {
            return null;
        }

        $source = $this->loadPdfSourceImage($path);
        if (!$source) {
            return null;
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, $palette['imageBg'][0], $palette['imageBg'][1], $palette['imageBg'][2]));
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = $fit === 'contain'
            ? min($targetWidth / max(1, $sourceWidth), $targetHeight / max(1, $sourceHeight))
            : max($targetWidth / max(1, $sourceWidth), $targetHeight / max(1, $sourceHeight));
        $copyWidth = (int)ceil($targetWidth / $scale);
        $copyHeight = (int)ceil($targetHeight / $scale);
        $srcX = max(0, (int)(($sourceWidth - $copyWidth) / 2));
        $srcY = max(0, (int)(($sourceHeight - $copyHeight) / 2));
        if ($fit === 'contain') {
            $drawWidth = (int)round($sourceWidth * $scale);
            $drawHeight = (int)round($sourceHeight * $scale);
            $dstX = (int)(($targetWidth - $drawWidth) / 2);
            $dstY = (int)(($targetHeight - $drawHeight) / 2);
            imagecopyresampled($target, $source, $dstX, $dstY, 0, 0, $drawWidth, $drawHeight, $sourceWidth, $sourceHeight);
        } else {
            imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $targetWidth, $targetHeight, $copyWidth, $copyHeight);
        }

        ob_start();
        imagejpeg($target, null, 92);
        $jpeg = (string)ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        return ['data' => $jpeg, 'width' => $targetWidth, 'height' => $targetHeight];
    }

    private function loadPdfSourceImage(string $path): mixed
    {
        $source = @imagecreatefromstring((string)file_get_contents($path));
        if ($source) {
            return $source;
        }

        $mime = (string)(mime_content_type($path) ?: '');
        return match ($mime) {
            'image/png' => @imagecreatefrompng($path),
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function pdfImageObject(array $image): string
    {
        return "<< /Type /XObject /Subtype /Image /Width {$image['width']} /Height {$image['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
    }

    private function resolveCharacterImage(string $image, array $settings): string
    {
        $image = basename(trim($image));
        $defaults = ['default.png', 'default.jpg', 'default_dark.png', ''];
        if (in_array($image, $defaults, true)) {
            return (string)($settings['defaultCharacterImage'] ?? (((string)($settings['theme'] ?? 'light') === 'dark') ? 'default_dark.png' : 'default.png'));
        }

        return $image;
    }

    private function uploadPath(string $filename): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . basename($filename);
    }

    private function pdfFill(array $rgb): string
    {
        return sprintf("%.4F %.4F %.4F rg\n", $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    private function pdfFontObject(string $baseFont): string
    {
        return '<< /Type /Font /Subtype /Type1 /BaseFont /' . $baseFont
            . ' /Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ['
            . '128 /Aogonek /aogonek /Cacute /cacute /Eogonek /eogonek /Lslash /lslash '
            . '/Nacute /nacute /Oacute /oacute /Sacute /sacute /Zacute /zacute /Zdotaccent /zdotaccent'
            . '] >> >>';
    }

    private function pdfText(string $text, int $x, int $y, int $size, array $rgb, bool $bold = false, int $maxChars = 0): string
    {
        if ($maxChars > 0 && mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, max(1, $maxChars - 3)) . '...';
        }
        $encoded = $this->pdfSingleByteText($text);

        return $this->pdfFill($rgb)
            . "BT\n/" . ($bold ? 'F2' : 'F1') . " {$size} Tf\n{$x} {$y} Td\n("
            . $this->pdfEscape($encoded)
            . ") Tj\nET\n";
    }

    private function pdfFromObjects(array $objects): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $objectNumber => $object) {
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= $objectNumber . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObject = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d 00000 n \n", $offsets[$i])
                : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function buildSimplePdf(array $pages): string
    {
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $contentObjects = [];

        foreach ($pages as $index => $lines) {
            $pageObjectId = 3 + ($index * 2);
            $contentObjectId = $pageObjectId + 1;
            $kids[] = $pageObjectId . ' 0 R';
            $content = $this->pdfContentStream($lines);
            $objects[$pageObjectId - 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . (3 + (count($pages) * 2)) . ' 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
            $contentObjects[$contentObjectId - 1] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }

        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        foreach ($contentObjects as $objectIndex => $contentObject) {
            $objects[$objectIndex] = $contentObject;
        }
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $objectNumber = $index + 1;
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= $objectNumber . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function pdfContentStream(array $lines): string
    {
        $out = "BT\n/F1 11 Tf\n50 790 Td\n14 TL\n";
        foreach ($lines as $line) {
            $out .= '(' . $this->pdfEscape($this->pdfSingleByteText($line)) . ") Tj\nT*\n";
        }
        $out .= "ET";

        return $out;
    }

    private function pdfEscape(string $value): string
    {
        $out = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if ($value[$i] === '\\' || $value[$i] === '(' || $value[$i] === ')') {
                $out .= '\\' . $value[$i];
            } elseif ($byte < 32 || $byte > 126) {
                $out .= sprintf('\\%03o', $byte);
            } else {
                $out .= $value[$i];
            }
        }

        return $out;
    }

    private function pdfSingleByteText(string $value): string
    {
        $map = [
            'Ą' => 128, 'ą' => 129,
            'Ć' => 130, 'ć' => 131,
            'Ę' => 132, 'ę' => 133,
            'Ł' => 134, 'ł' => 135,
            'Ń' => 136, 'ń' => 137,
            'Ó' => 138, 'ó' => 139,
            'Ś' => 140, 'ś' => 141,
            'Ź' => 142, 'ź' => 143,
            'Ż' => 144, 'ż' => 145,
        ];

        $out = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (isset($map[$char])) {
                $out .= chr($map[$char]);
                continue;
            }
            if (strlen($char) === 1 && ord($char) <= 126) {
                $out .= $char;
                continue;
            }
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $char);
                if (is_string($converted) && strlen($converted) === 1 && ord($converted) >= 32) {
                    $out .= $converted;
                    continue;
                }
            }
            $out .= '?';
        }

        return $out;
    }

    private function ascii(string $value): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }

        return preg_replace('/[^\x20-\x7E\r\n\t]/', '?', $value) ?? $value;
    }
}
