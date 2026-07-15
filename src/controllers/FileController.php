<?php

require_once 'AppController.php';
require_once __DIR__ . '/../services/ImageUploadService.php';
require_once __DIR__ . '/../repositories/ImageRepository.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';

class FileController extends AppController
{
    private ImageRepository $imageRepository;
    private FilterRepository $filterRepository;

    public function __construct()
    {
        $this->imageRepository = new ImageRepository();
        $this->filterRepository = new FilterRepository();
    }

    public function gallery(): void
    {
        $this->requireLogin();
        $this->render('gallery', [
            'title' => 'Galeria zdjec - OCStudio',
            'images' => $this->imageRepository->listAssets(
                (int)$_SESSION['user_id'],
                null,
                !empty($this->getUserInterfaceSettings()['revealHidden'])
            ),
        ]);
    }

    public function uploadFile(): void
    {
        $this->requireLogin();

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

        if (!$this->isPost()) {
            $this->jsonError('Method not allowed', 405);
        }

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

        if (!$this->isPost()) {
            $this->jsonError('Method not allowed', 405);
        }

        try {
            $input = $this->requireJsonPost();
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
}
