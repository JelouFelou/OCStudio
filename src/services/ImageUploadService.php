<?php

class ImageUploadService
{
    private const MAX_FILE_SIZE = 8 * 1024 * 1024;
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/gif' => 'gif',
        'image/x-gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function upload(array $file, ?int $userId = null, array $filterIds = [], string $visibility = 'normal'): array
    {
        $visibility = $this->normalizeVisibility($visibility);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Brak pliku lub blad przesylania.', 400);
        }

        if (($file['size'] ?? 0) > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('Plik jest zbyt duzy (max 8 MB).', 413);
        }

        $tmpName = $file['tmp_name'] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Nieprawidlowy plik uploadu.', 400);
        }

        $mimeType = mime_content_type($tmpName);
        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new InvalidArgumentException('Niedozwolony typ pliku. Dozwolone: jpg, png, gif, webp, avif.', 415);
        }

        $sha256 = hash_file('sha256', $tmpName);
        if ($userId !== null) {
            require_once __DIR__ . '/../repositories/ImageRepository.php';
            $imageRepository = new ImageRepository();
            $existing = $imageRepository->findByHash($userId, $sha256);
            if ($existing) {
                if ($this->uploadFileExists($existing['filename'])) {
                    $existing = $imageRepository->elevateVisibility($userId, (int)$existing['id'], $visibility);
                    return [
                        'url' => $existing['url'],
                        'filename' => $existing['filename'],
                        'imageId' => $existing['id'],
                        'imageAsset' => $existing,
                        'deduplicated' => true,
                    ];
                }
                $imageRepository->deleteAsset($userId, (int)$existing['id'], true);
            }
        }

        if ($userId !== null) {
            $this->assertUserStorageLimit($userId, (int)($file['size'] ?? 0));
        }

        $uploadDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new RuntimeException('Nie udalo sie utworzyc katalogu uploads.', 500);
        }

        $extension = self::ALLOWED_MIME_TYPES[$mimeType];
        [$filename, $destination] = $this->reserveUniqueDestination($uploadDir, $extension);

        if (!move_uploaded_file($tmpName, $destination)) {
            @unlink($destination);
            throw new RuntimeException('Nie udalo sie zapisac pliku na serwerze.', 500);
        }

        $asset = null;
        if ($userId !== null) {
            require_once __DIR__ . '/../repositories/ImageRepository.php';
            $asset = (new ImageRepository())->createAsset(
                $userId,
                $filename,
                $mimeType,
                (int)($file['size'] ?? filesize($destination) ?: 0),
                $sha256,
                $filterIds,
                $visibility
            );
        }

        return [
            'url' => '/media/' . rawurlencode($filename),
            'filename' => $filename,
            'imageId' => $asset['id'] ?? null,
            'imageAsset' => $asset,
            'deduplicated' => false,
        ];
    }

    private function normalizeVisibility(string $visibility): string
    {
        return in_array($visibility, ['normal', 'hidden', 'adult'], true) ? $visibility : 'normal';
    }

    private function reserveUniqueDestination(string $uploadDir, string $extension): array
    {
        do {
            $filename = 'img_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
            $destination = $uploadDir . $filename;
            $handle = @fopen($destination, 'x');
        } while ($handle === false);

        fclose($handle);

        return [$filename, $destination];
    }

    private function assertUserStorageLimit(int $userId, int $incomingBytes): void
    {
        require_once __DIR__ . '/../repositories/ImageRepository.php';
        require_once __DIR__ . '/../repositories/SocialFeatureSettingsRepository.php';
        require_once __DIR__ . '/../repositories/UserRepository.php';

        $user = (new UsersRepository())->getUserById($userId);
        $accountType = $user ? $user->getAccountType() : 0;
        $quotaMb = (new SocialFeatureSettingsRepository())->storageQuotaMbForAccountType($accountType);
        $quotaBytes = $quotaMb * 1024 * 1024;
        $usedBytes = (new ImageRepository())->getStorageBytes($userId);

        if ($quotaBytes > 0 && $usedBytes + $incomingBytes > $quotaBytes) {
            throw new InvalidArgumentException(
                'Limit miejsca na zdjecia zostal przekroczony. Uzyte: '
                . $this->formatBytes($usedBytes)
                . ', limit: '
                . $quotaMb
                . ' MB.',
                413
            );
        }
    }

    private function formatBytes(int $bytes): string
    {
        $megabytes = $bytes / 1024 / 1024;

        if ($megabytes >= 10) {
            return number_format($megabytes, 0, '.', '') . ' MB';
        }

        return number_format($megabytes, 1, '.', '') . ' MB';
    }

    private function uploadFileExists(string $filename): bool
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . basename($filename);
        return is_file($path);
    }
}
