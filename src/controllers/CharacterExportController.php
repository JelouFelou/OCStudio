<?php

require_once 'AppController.php';
require_once __DIR__ . '/../services/CharacterExportService.php';

class CharacterExportController extends AppController
{
    private CharacterExportService $exportService;

    public function __construct()
    {
        $this->exportService = new CharacterExportService();
    }

    public function export(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Postacie sa obecnie wylaczone.');

        try {
            $format = strtolower((string)($_GET['format'] ?? 'txt'));
            if (!in_array($format, ['txt', 'pdf'], true)) {
                throw new InvalidArgumentException('Nieznany format eksportu.', 400);
            }

            $scope = (string)($_GET['scope'] ?? 'current') === 'all' ? 'all' : 'current';
            $variantId = isset($_GET['variant']) && ctype_digit((string)$_GET['variant']) ? (int)$_GET['variant'] : null;
            $export = $this->exportService->buildExport(
                (int)$_SESSION['user_id'],
                (string)($_GET['id'] ?? ''),
                $scope,
                $variantId
            );

            $body = $format === 'pdf'
                ? $this->exportService->renderPdf($export, $this->getUserInterfaceSettings())
                : $this->exportService->renderTxt($export);
            $filename = $this->exportService->filename($export, $format, $scope);

            header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'text/plain; charset=UTF-8'));
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($body));
            echo $body;
            exit();
        } catch (Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;
            http_response_code($status);
            echo htmlspecialchars($status === 500 ? 'Nie udalo sie wyeksportowac postaci.' : $e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit();
        }
    }

    public function bulkExport(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('characters.enabled', 'Postacie sa obecnie wylaczone.');

        if (!$this->isPost()) {
            http_response_code(405);
            echo 'Metoda niedozwolona.';
            exit();
        }

        $this->validateCsrf();

        try {
            $format = strtolower((string)($_POST['format'] ?? 'pdf'));
            if (!in_array($format, ['txt', 'pdf'], true)) {
                throw new InvalidArgumentException('Nieznany format eksportu.', 400);
            }

            $delivery = (string)($_POST['delivery'] ?? 'single') === 'zip' ? 'zip' : 'single';
            $ids = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($_POST['character_ids'] ?? null) ? $_POST['character_ids'] : []
            ), fn(int $id): bool => $id > 0)));

            if (empty($ids)) {
                throw new InvalidArgumentException('Wybierz przynajmniej jedna postac do eksportu.', 400);
            }
            if (count($ids) > 100) {
                throw new InvalidArgumentException('Jednorazowo mozna wyeksportowac maksymalnie 100 postaci.', 400);
            }

            $exports = [];
            foreach ($ids as $id) {
                $exports[] = $this->exportService->buildExport((int)$_SESSION['user_id'], (string)$id, 'all');
            }

            if ($delivery === 'zip') {
                $this->sendZipExport($exports, $format);
            }

            $body = $format === 'pdf'
                ? $this->exportService->renderPdf($this->exportService->mergeExports($exports, 'Masowy eksport postaci'), $this->getUserInterfaceSettings())
                : $this->exportService->renderBulkTxt($exports);

            $filename = $this->exportService->bulkFilename($format, false);
            header('Content-Type: ' . ($format === 'pdf' ? 'application/pdf' : 'text/plain; charset=UTF-8'));
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($body));
            echo $body;
            exit();
        } catch (Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;
            http_response_code($status);
            echo htmlspecialchars($status === 500 ? 'Nie udalo sie wyeksportowac postaci.' : $e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit();
        }
    }

    private function sendZipExport(array $exports, string $format): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Eksport ZIP nie jest dostepny w tej instalacji.', 500);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'oc_bulk_export_');
        if ($zipPath === false) {
            throw new RuntimeException('Nie udalo sie przygotowac pliku ZIP.', 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Nie udalo sie utworzyc pliku ZIP.', 500);
        }

        $usedNames = [];
        foreach ($exports as $export) {
            $filename = $this->exportService->uniqueArchiveFilename(
                $this->exportService->filename($export, $format, 'all'),
                $usedNames
            );
            $body = $format === 'pdf'
                ? $this->exportService->renderPdf($export, $this->getUserInterfaceSettings())
                : $this->exportService->renderTxt($export);
            $zip->addFromString($filename, $body);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $this->exportService->bulkFilename($format, true) . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit();
    }
}
