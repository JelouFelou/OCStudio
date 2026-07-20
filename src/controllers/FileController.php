<?php

require_once 'AppController.php';
require_once __DIR__ . '/../services/ImageUploadService.php';
require_once __DIR__ . '/../repositories/ImageRepository.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';
require_once __DIR__ . '/../repositories/PublicationRepository.php';

class FileController extends AppController
{
    private const PUBLIC_DEFAULT_MEDIA = [
        'default.png',
        'default.jpg',
        'default_dark.png',
        'default_story.png',
        'default_story.jpg',
        'default_story_dark.png',
    ];

    private ImageRepository $imageRepository;
    private FilterRepository $filterRepository;
    private PublicationRepository $publicationRepository;

    public function __construct()
    {
        $this->imageRepository = new ImageRepository();
        $this->filterRepository = new FilterRepository();
        $this->publicationRepository = new PublicationRepository();
    }

    public function gallery(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Galeria jest obecnie wylaczona.');
        $this->render('gallery', [
            'title' => 'Galeria zdjec - OCStudio',
            'images' => $this->imageRepository->listAssets(
                (int)$_SESSION['user_id'],
                null,
                !empty($this->getUserInterfaceSettings()['revealHidden'])
            ),
        ]);
    }

    public function serveMedia(): void
    {
        $filename = $this->cleanMediaFilename($_GET['filename'] ?? '');
        if ($filename === '') {
            $this->mediaNotFound();
        }

        $path = $this->uploadPath($filename);
        if (!is_file($path)) {
            $this->mediaNotFound();
        }

        $isDefault = in_array($filename, self::PUBLIC_DEFAULT_MEDIA, true);
        $asset = null;
        if (!$isDefault) {
            if (isset($_SESSION['user_id'])) {
                $asset = $this->imageRepository->getAssetByFilename((int)$_SESSION['user_id'], $filename);
            }
            if (!$asset && !$this->publicationRepository->isFilenameInVisiblePublication($filename)) {
                $this->mediaNotFound();
            }
        }

        $mimeType = (string)($asset['mimeType'] ?? mime_content_type($path) ?: 'application/octet-stream');
        if (!str_starts_with($mimeType, 'image/')) {
            $this->mediaNotFound();
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($isDefault ? 'public, max-age=86400' : 'private, max-age=300'));
        readfile($path);
        exit();
    }

    public function uploadFile(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Przesylanie zdjec jest obecnie wylaczone.', true);
        $this->validateCsrfRequest(true);

        try {
            $filterIds = [];
            if (!empty($_POST['tags'])) {
                $tags = $this->filterRepository->validateMinimumTags((string)$_POST['tags']);
                $filterIds = array_map(fn($tag) => (int)$tag['id'], $tags);
            }
            $uploaded = (new ImageUploadService())->upload(
                $_FILES['file'] ?? [],
                (int)$_SESSION['user_id'],
                $filterIds,
                (string)($_POST['visibility'] ?? 'normal')
            );
            $this->jsonResponse($uploaded);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie przeslac pliku.');
        }
    }

    public function updateImageVisibility(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Galeria jest obecnie wylaczona.', true);

        try {
            $input = $this->requireJsonPost();
            $asset = $this->imageRepository->updateVisibility(
                (int)$_SESSION['user_id'],
                (int)($input['imageId'] ?? 0),
                (string)($input['visibility'] ?? 'normal')
            );
            $this->jsonResponse(['imageAsset' => $asset]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie zmienic widocznosci zdjecia.');
        }
    }

    public function listImages(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Galeria jest obecnie wylaczona.', true);
        $query = trim($_GET['q'] ?? '');
        $this->jsonResponse([
            'images' => $this->imageRepository->listAssets(
                (int)$_SESSION['user_id'],
                $query,
                !empty($this->getUserInterfaceSettings()['revealHidden'])
            ),
        ]);
    }

    public function uploadImage(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Przesylanie zdjec jest obecnie wylaczone.', true);

        if (!$this->isPost()) {
            $this->jsonError('Method not allowed', 405);
        }

        $this->validateCsrfRequest(true);

        try {
            $tags = $this->filterRepository->validateMinimumTags($_POST['tags'] ?? '');
            $filterIds = array_map(fn($tag) => (int)$tag['id'], $tags);
            $uploaded = (new ImageUploadService())->upload(
                $_FILES['file'] ?? [],
                (int)$_SESSION['user_id'],
                $filterIds,
                (string)($_POST['visibility'] ?? 'normal')
            );
            $this->jsonResponse(['imageAsset' => $uploaded['imageAsset'], 'uploaded' => $uploaded]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie przeslac zdjecia.');
        }
    }

    public function updateImageTags(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Galeria jest obecnie wylaczona.', true);

        try {
            $input = $this->requireJsonPost();
            $imageId = (int)($input['imageId'] ?? 0);
            $tags = $this->filterRepository->validateMinimumTags($input['tags'] ?? []);
            $asset = $this->imageRepository->updateTagsAndVisibility(
                (int)$_SESSION['user_id'],
                $imageId,
                array_map(fn($tag) => (int)$tag['id'], $tags),
                array_key_exists('visibility', $input) ? (string)$input['visibility'] : null
            );
            $this->jsonResponse(['imageAsset' => $asset]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie zapisac tagow zdjecia.');
        }
    }

    public function deleteImage(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Galeria jest obecnie wylaczona.', true);

        if (!$this->isPost()) {
            $this->jsonError('Method not allowed', 405);
        }

        try {
            $input = $this->requireJsonPost();
            if ((string)($input['confirmation'] ?? '') !== '123456') {
                $this->jsonError('Kod potwierdzenia nie zgadza sie.', 400);
            }

            $this->imageRepository->deleteAsset(
                (int)$_SESSION['user_id'],
                (int)($input['imageId'] ?? 0),
                !empty($input['forceMissing'])
            );
            $this->jsonResponse([
                'success' => true,
                'storage' => $this->getUserStorageStats((int)$_SESSION['user_id']),
            ]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie usunac zdjecia.');
        }
    }

    public function imageUsage(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Galeria jest obecnie wylaczona.', true);

        try {
            $imageId = (int)($_GET['imageId'] ?? 0);
            $asset = $this->imageRepository->getAsset((int)$_SESSION['user_id'], $imageId);
            if (!$asset) {
                throw new InvalidArgumentException('Zdjecie nie istnieje.', 404);
            }
            $this->jsonResponse([
                'usage' => $this->imageRepository->usageDetails((int)$_SESSION['user_id'], (string)$asset['filename']),
            ]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie pobrac uzyc zdjecia.');
        }
    }

    public function mergeImages(): void
    {
        $this->requireLogin();
        $this->requireFeatureEnabled('gallery.enabled', 'Galeria jest obecnie wylaczona.', true);

        try {
            $input = $this->requireJsonPost();
            $asset = $this->imageRepository->mergeImages(
                (int)$_SESSION['user_id'],
                (int)($input['sourceImageId'] ?? 0),
                (int)($input['targetImageId'] ?? 0)
            );
            $this->jsonResponse(['success' => true, 'imageAsset' => $asset]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie scalic zdjec.');
        }
    }

    public function resolveFilters(): void
    {
        $this->requireLogin();

        try {
            $input = $this->requireJsonPost();
            $this->jsonResponse(['filters' => $this->filterRepository->resolveTags($input['tags'] ?? [])]);
        } catch (Throwable $e) {
            $this->jsonException($e, 'Nie udalo sie rozpoznac filtrow.');
        }
    }

    private function cleanMediaFilename(mixed $filename): string
    {
        $filename = rawurldecode(trim((string)$filename));
        $basename = basename($filename);

        if ($basename !== $filename || $basename === '' || str_contains($basename, "\0")) {
            return '';
        }

        return preg_match('/^[a-zA-Z0-9_.-]+$/', $basename) ? $basename : '';
    }

    private function uploadPath(string $filename): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . $filename;
    }

    private function mediaNotFound(): void
    {
        http_response_code(404);
        exit();
    }
}
