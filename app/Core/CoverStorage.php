<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Stores a cover photograph.
 *
 * Every uploaded image is decoded and re-encoded rather than moved into place.
 * That is not about file size: a photograph taken on a phone carries EXIF
 * metadata, routinely including the GPS coordinates where it was taken. For
 * covers photographed at home that is the home address, and it would be served
 * to anyone who opens the image. Re-encoding drops all of it.
 *
 * The file name is chosen by the server, never taken from the upload, and the
 * type is decided by inspecting the image rather than by trusting the browser.
 */
final class CoverStorage
{
    private const MAX_BYTES = 12 * 1024 * 1024;
    private const GRID_WIDTH = 400;
    private const DETAIL_WIDTH = 900;
    private const QUALITY = 82;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param  array<string,mixed> $upload one entry of $_FILES
     * @return array{path: string, width: int, height: int}
     */
    public function storeUpload(array $upload, string $key): array
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with code ' . $error);
        }
        $temporary = (string) ($upload['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw new RuntimeException('Not an uploaded file.');
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Image too large.');
        }

        return $this->storeFile($temporary, $key);
    }

    /** Also used for images captured in the browser and posted as raw bytes. */
    public function storeBinary(string $bytes, string $key): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'cover');
        if ($temporary === false) {
            throw new RuntimeException('No temporary file available.');
        }
        file_put_contents($temporary, $bytes);
        try {
            return $this->storeFile($temporary, $key);
        } finally {
            @unlink($temporary);
        }
    }

    /** @return array{path: string, width: int, height: int} */
    private function storeFile(string $sourcePath, string $key): array
    {
        // The type comes from the image itself, not from the browser's claim.
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new RuntimeException('Not a readable image.');
        }
        [$width, $height, $type] = $info;

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            default        => false,
        };
        if ($image === false) {
            throw new RuntimeException('Unsupported image type.');
        }

        // Phones record orientation in EXIF instead of rotating the pixels;
        // once EXIF is dropped, an unrotated image would be shown sideways.
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $image = $this->applyExifOrientation($image, $sourcePath);
            $width = imagesx($image);
            $height = imagesy($image);
        }

        $base = $this->safeKey($key);
        $this->ensureDirectory();

        $detail = $this->resample($image, min(self::DETAIL_WIDTH, $width), $width, $height);
        $this->write($detail, $base . '.webp');
        $storedWidth = imagesx($detail);
        $storedHeight = imagesy($detail);

        // A second, smaller copy for the grid: three thousand full-size covers
        // on a phone would be a lot of mobile data for thumbnails.
        $grid = $this->resample($image, min(self::GRID_WIDTH, $width), $width, $height);
        $this->write($grid, $base . '-klein.webp');

        return ['path' => $base . '.webp', 'width' => $storedWidth, 'height' => $storedHeight];
    }

    private function resample(\GdImage $source, int $targetWidth, int $width, int $height): \GdImage
    {
        $targetWidth = max(1, $targetWidth);
        $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    private function write(\GdImage $image, string $filename): void
    {
        $path = $this->directory . '/' . $filename;
        if (!imagewebp($image, $path, self::QUALITY)) {
            throw new RuntimeException('Could not write ' . $filename);
        }
        @chmod($path, 0o644);
    }

    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3       => imagerotate($image, 180, 0),
            6       => imagerotate($image, -90, 0),
            8       => imagerotate($image, 90, 0),
            default => null,
        };
        return ($rotated === null || $rotated === false) ? $image : $rotated;
    }

    /** The stored name is derived from the ISBN, never from the upload. */
    private function safeKey(string $key): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $key) ?? '';

        return $clean !== '' ? $clean : bin2hex(random_bytes(8));
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cover directory is not writable.');
        }
    }

    public function delete(string $path): void
    {
        $base = basename($path, '.webp');
        foreach ([$base . '.webp', $base . '-klein.webp'] as $file) {
            $full = $this->directory . '/' . $file;
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }
}
