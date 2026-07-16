<?php

require_once __DIR__ . '/../repositories/ImageRepository.php';
require_once __DIR__ . '/../repositories/FilterRepository.php';
require_once __DIR__ . '/ImageUploadService.php';

class CharacterFieldUploadService
{
    private ImageRepository $imageRepository;
    private FilterRepository $filterRepository;
    private ImageUploadService $imageUploadService;

    public function __construct(
        ?ImageRepository $imageRepository = null,
        ?FilterRepository $filterRepository = null,
        ?ImageUploadService $imageUploadService = null
    ) {
        $this->imageRepository = $imageRepository ?? new ImageRepository();
        $this->filterRepository = $filterRepository ?? new FilterRepository();
        $this->imageUploadService = $imageUploadService ?? new ImageUploadService();
    }

    public function uploadCharacterImage(int $userId, string $fallback, array $post, array $files): string
    {
        $fallback = $fallback !== '' ? $fallback : 'default.png';

        $selectedImageId = (int)($post['character_image_id'] ?? 0);
        if ($selectedImageId > 0) {
            $asset = $this->imageRepository->getAsset($userId, $selectedImageId);
            if ($asset) {
                return $asset['filename'];
            }
        }

        $file = $files['character_image'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $fallback;
        }

        $tags = $this->filterRepository->validateMinimumTags($post['character_image_tags'] ?? '');
        $uploaded = $this->imageUploadService->upload(
            $file,
            $userId,
            array_map(fn($tag) => (int)$tag['id'], $tags)
        );

        return $uploaded['filename'] ?: $fallback;
    }

    public function processCharacterFieldUploads(int $userId, array $fieldValues, array $post, array $files): array
    {
        foreach ((array)($files['field_image_files']['name'] ?? []) as $fieldId => $_unused) {
            $file = $this->nestedFile($files, 'field_image_files', [$fieldId]);
            if (!$this->hasUploadedFile($file)) {
                continue;
            }
            $tags = (string)($post['field_image_tags'][$fieldId] ?? '');
            $fieldValues[$fieldId] = json_encode($this->uploadPostedImage($userId, $file, $tags), JSON_UNESCAPED_SLASHES);
        }

        foreach ((array)($files['field_table_image_files']['name'] ?? []) as $fieldId => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $tableValue = json_decode((string)($fieldValues[$fieldId] ?? '{}'), true);
            if (!is_array($tableValue)) {
                $tableValue = [];
            }

            foreach ($rows as $encodedRowKey => $_unused) {
                $file = $this->nestedFile($files, 'field_table_image_files', [$fieldId, $encodedRowKey]);
                if (!$this->hasUploadedFile($file)) {
                    continue;
                }
                $tags = (string)($post['field_table_image_tags'][$fieldId][$encodedRowKey] ?? '');
                $rowKey = urldecode((string)$encodedRowKey);
                $tableValue[$rowKey] = [
                    'type' => 'image',
                    'value' => $this->uploadPostedImage($userId, $file, $tags),
                ];
            }

            $fieldValues[$fieldId] = json_encode($tableValue, JSON_UNESCAPED_SLASHES);
        }

        foreach ((array)($files['field_gallery_files']['name'] ?? []) as $fieldId => $galleryFiles) {
            if (!is_array($galleryFiles)) {
                continue;
            }
            $galleryValue = json_decode((string)($fieldValues[$fieldId] ?? '[]'), true);
            if (!is_array($galleryValue)) {
                $galleryValue = [];
            }
            $galleryValue = array_values(array_filter($galleryValue, fn($image) => empty($image['pendingUpload'])));
            $tags = (string)($post['field_gallery_tags'][$fieldId] ?? '');

            foreach ($galleryFiles as $index => $_unused) {
                $file = $this->nestedFile($files, 'field_gallery_files', [$fieldId, $index]);
                if (!$this->hasUploadedFile($file)) {
                    continue;
                }
                $galleryValue[] = $this->uploadPostedImage($userId, $file, $tags);
            }

            $fieldValues[$fieldId] = json_encode($galleryValue, JSON_UNESCAPED_SLASHES);
        }

        return $fieldValues;
    }

    public function prepareVariantsFromPost(int $userId, array $post, array $files): array
    {
        $postedVariants = $post['variants'] ?? [];
        if (!is_array($postedVariants)) {
            return [];
        }

        $variants = [];
        foreach ($postedVariants as $key => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $name = trim($variant['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $image = $variant['existing_image'] ?? null;
            $selectedImageId = (int)($variant['existing_image_id'] ?? 0);
            if ($selectedImageId > 0) {
                $asset = $this->imageRepository->getAsset($userId, $selectedImageId);
                if ($asset) {
                    $image = $asset['filename'];
                }
            }

            $file = $this->variantUploadFile($files, (string)$key);
            if ($this->hasUploadedFile($file)) {
                $uploaded = $this->imageUploadService->upload($file, $userId, []);
                $image = $uploaded['filename'];
            }

            $values = is_array($variant['values'] ?? null) ? $variant['values'] : [];
            $this->processVariantFieldImages($userId, $key, $values, $post, $files);
            $this->processVariantFieldGalleries($userId, $key, $values, $post, $files);

            $variants[] = [
                'key' => (string)$key,
                'name' => $name,
                'description' => (string)($variant['description'] ?? ''),
                'image' => $image ?: null,
                'is_adult' => !empty($variant['is_adult']),
                'is_hidden' => !empty($variant['is_hidden']),
                'content_tags' => (string)($variant['content_tags'] ?? ''),
                'image_fit' => $variant['image_fit'] ?? 'cover',
                'image_focus_x' => $variant['image_focus_x'] ?? 50,
                'image_focus_y' => $variant['image_focus_y'] ?? 50,
                'image_zoom' => $variant['image_zoom'] ?? 1,
                'values' => $values,
            ];
        }

        return $variants;
    }

    private function processVariantFieldImages(int $userId, string|int $key, array &$values, array $post, array $files): void
    {
        foreach ((array)($files['variant_field_image_files']['name'][$key] ?? []) as $fieldId => $_unused) {
            $file = $this->nestedFile($files, 'variant_field_image_files', [$key, $fieldId]);
            if (!$this->hasUploadedFile($file)) {
                continue;
            }
            $tags = (string)($post['variant_field_image_tags'][$key][$fieldId] ?? '');
            $values[$fieldId] = json_encode($this->uploadPostedImage($userId, $file, $tags), JSON_UNESCAPED_SLASHES);
        }
    }

    private function processVariantFieldGalleries(int $userId, string|int $key, array &$values, array $post, array $files): void
    {
        foreach ((array)($files['variant_field_gallery_files']['name'][$key] ?? []) as $fieldId => $galleryFiles) {
            if (!is_array($galleryFiles)) {
                continue;
            }

            $galleryValue = json_decode((string)($values[$fieldId] ?? '[]'), true);
            if (!is_array($galleryValue)) {
                $galleryValue = [];
            }
            $galleryValue = array_values(array_filter($galleryValue, fn($image) => empty($image['pendingUpload'])));
            $tags = (string)($post['variant_field_gallery_tags'][$key][$fieldId] ?? '');

            foreach ($galleryFiles as $index => $_unused) {
                $file = $this->nestedFile($files, 'variant_field_gallery_files', [$key, $fieldId, $index]);
                if (!$this->hasUploadedFile($file)) {
                    continue;
                }
                $galleryValue[] = $this->uploadPostedImage($userId, $file, $tags);
            }

            $values[$fieldId] = json_encode($galleryValue, JSON_UNESCAPED_SLASHES);
        }
    }

    private function uploadPostedImage(int $userId, array $file, string $tags): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Brak pliku lub blad przesylania.', 400);
        }

        $resolvedTags = $this->filterRepository->validateMinimumTags($tags);
        $uploaded = $this->imageUploadService->upload(
            $file,
            $userId,
            array_map(fn($tag) => (int)$tag['id'], $resolvedTags)
        );

        return [
            'imageId' => $uploaded['imageId'] ?? null,
            'url' => $uploaded['url'] ?? '',
            'filename' => $uploaded['filename'] ?? '',
        ];
    }

    private function nestedFile(array $files, string $name, array $keys): ?array
    {
        if (empty($files[$name]) || !is_array($files[$name])) {
            return null;
        }

        $file = $files[$name];
        $parts = [];
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $part) {
            $value = $file[$part] ?? null;
            foreach ($keys as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    return null;
                }
                $value = $value[$key];
            }
            $parts[$part] = $value;
        }

        return $parts;
    }

    private function variantUploadFile(array $files, string $key): ?array
    {
        if (!isset($files['variant_images']['name'][$key])) {
            return null;
        }

        return [
            'name' => $files['variant_images']['name'][$key],
            'type' => $files['variant_images']['type'][$key],
            'tmp_name' => $files['variant_images']['tmp_name'][$key],
            'error' => $files['variant_images']['error'][$key],
            'size' => $files['variant_images']['size'][$key],
        ];
    }

    private function hasUploadedFile(?array $file): bool
    {
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }
}
