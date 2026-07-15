<?php

class ImageThumbnailService
{
    private const SIZE = 420;
    private const QUALITY = 72;
    private const SUPPORTED_MIME_TYPES = [
        'image/jpeg' => 'jpeg',
        'image/pjpeg' => 'jpeg',
        'image/png' => 'png',
        'image/x-png' => 'png',
        'image/gif' => 'gif',
        'image/x-gif' => 'gif',
    ];

    public function galleryThumbnailUrl(string $filename, string $mimeType = ''): string
    {
        $filename = basename($filename);
        if ($filename === '' || $this->isDefaultImage($filename)) {
            return '/public/uploads/' . $filename;
        }

        $source = $this->uploadPath($filename);
        if (!is_file($source)) {
            return '/public/uploads/' . $filename;
        }

        $mimeType = $mimeType !== '' ? $mimeType : (mime_content_type($source) ?: '');
        if (!isset(self::SUPPORTED_MIME_TYPES[$mimeType])) {
            $mimeType = mime_content_type($source) ?: $mimeType;
        }
        if (!isset(self::SUPPORTED_MIME_TYPES[$mimeType])) {
            return '/public/uploads/' . $filename;
        }

        $thumbPath = $this->thumbnailPath($filename);
        if (!is_file($thumbPath) || filemtime($thumbPath) < filemtime($source)) {
            $this->createThumbnail($source, $thumbPath, self::SUPPORTED_MIME_TYPES[$mimeType]);
        }

        return is_file($thumbPath)
            ? '/public/uploads/thumbs/gallery/' . basename($thumbPath)
            : '/public/uploads/' . $filename;
    }

    public function deleteGalleryThumbnail(string $filename): void
    {
        $path = $this->thumbnailPath(basename($filename));
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function createThumbnail(string $source, string $destination, string $type): void
    {
        $image = match ($type) {
            'jpeg' => @imagecreatefromjpeg($source),
            'png' => @imagecreatefrompng($source),
            'gif' => @imagecreatefromgif($source),
            default => false,
        };

        if (!$image) {
            return;
        }

        $srcWidth = imagesx($image);
        $srcHeight = imagesy($image);
        if ($srcWidth <= 0 || $srcHeight <= 0) {
            imagedestroy($image);
            return;
        }

        $side = min($srcWidth, $srcHeight);
        $srcX = (int)(($srcWidth - $side) / 2);
        $srcY = (int)(($srcHeight - $side) / 2);

        $thumb = imagecreatetruecolor(self::SIZE, self::SIZE);
        $background = imagecolorallocate($thumb, 245, 245, 245);
        imagefill($thumb, 0, 0, $background);
        imagecopyresampled($thumb, $image, 0, 0, $srcX, $srcY, self::SIZE, self::SIZE, $side, $side);

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @imagejpeg($thumb, $destination, self::QUALITY);
        imagedestroy($thumb);
        imagedestroy($image);
    }

    private function thumbnailPath(string $filename): string
    {
        $info = pathinfo($filename);
        $name = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $info['filename'] ?? 'image');
        $hash = substr(sha1($filename), 0, 12);
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR . 'gallery'
            . DIRECTORY_SEPARATOR . $name . '_' . $hash . '.jpg';
    }

    private function uploadPath(string $filename): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . basename($filename);
    }

    private function isDefaultImage(string $filename): bool
    {
        return in_array(basename($filename), ['default.png', 'default.jpg', 'default_dark.png'], true);
    }
}
