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
            /* A photograph off a phone is several megabytes, and PHP's own
               limit is often two. It rejects the file before any of this code
               runs, so the distinct codes are worth naming - "it did not
               work" sends someone trying the same picture again. */
            throw new RuntimeException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'too_large',
                UPLOAD_ERR_PARTIAL                        => 'incomplete',
                UPLOAD_ERR_NO_FILE                        => 'no_file',
                default                                   => 'upload_failed_' . $error,
            });
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

    /**
     * Hosts we will fetch a cover from.
     *
     * An allowlist rather than a check for "is it https": the URL that reaches
     * this method comes from a third-party API response, and an open fetcher
     * would happily be pointed at the server's own network. Only these hosts
     * are ever contacted.
     */
    private const ALLOWED_HOSTS = [
        'covers.openlibrary.org',
        // Open Library redirects its cover requests onto the Internet
        // Archive's own storage, so the redirect target has to be allowed
        // too or every one of its covers fails.
        'archive.org',
        'ia600000.us.archive.org',
        'books.google.com',
        'books.googleusercontent.com',
        'lh3.googleusercontent.com',
    ];

    /** Internet Archive storage nodes are numbered: ia601504.us.archive.org. */
    private const ALLOWED_HOST_PATTERNS = [
        '/^ia\d+\.us\.archive\.org$/',
    ];

    /**
     * Fetch a cover once and keep it.
     *
     * This is what lets every visitor see covers without a consent banner:
     * because the image is served from this server, no visitor ever contacts
     * Google or the Internet Archive, and no visitor IP leaves the site. The
     * trade is legal rather than technical - the image is copied rather than
     * embedded - and the source is recorded alongside it so attribution can
     * be shown and a cover can be withdrawn on request.
     *
     * @return array{path: string, width: int, height: int}
     */
    public function storeRemote(string $url, string $key): array
    {
        // Open Library answers cover requests with a redirect, so redirects
        // have to be followed - but curl's own following would happily leave
        // the allowlist. Each hop is therefore checked by hand.
        $body = $this->fetch($url, 3);

        return $this->storeBinary($body, $key);
    }

    private function fetch(string $url, int $hopsLeft): string
    {
        if ($hopsLeft <= 0) {
            throw new RuntimeException('Too many redirects.');
        }
        $this->assertAllowed($url);

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('curl_init failed.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Buecherregal/1.0 (private library catalogue)',
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, $expected, $received): int {
                // Stop a hostile or broken response before it fills memory.
                return $received > self::MAX_BYTES ? 1 : 0;
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL);

        if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
            return $this->fetch($location, $hopsLeft - 1);
        }
        if (!is_string($body) || $body === '' || $status !== 200) {
            throw new RuntimeException('Cover fetch failed with status ' . $status);
        }

        return $body;
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new RuntimeException('Only https cover URLs are fetched.');
        }
        $host = strtolower($parts['host'] ?? '');
        if (in_array($host, self::ALLOWED_HOSTS, true)) {
            return;
        }
        foreach (self::ALLOWED_HOST_PATTERNS as $pattern) {
            if (preg_match($pattern, $host) === 1) {
                return;
            }
        }

        throw new RuntimeException('Cover host not allowed: ' . $host);
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

        if (self::looksLikePlaceholder($image, $width, $height)) {
            throw new RuntimeException('placeholder');
        }

        $base = $this->safeKey($key);
        $shard = self::shardFor($base);
        $this->ensureDirectory($shard);

        $detail = $this->resample($image, min(self::DETAIL_WIDTH, $width), $width, $height);
        $this->write($detail, $shard . '/' . $base . '.webp');
        $storedWidth = imagesx($detail);
        $storedHeight = imagesy($detail);

        // A second, smaller copy for the grid: three thousand full-size covers
        // on a phone would be a lot of mobile data for thumbnails.
        $grid = $this->resample($image, min(self::GRID_WIDTH, $width), $width, $height);
        $this->write($grid, $shard . '/' . $base . '-klein.webp');

        return [
            'path'   => $shard . '/' . $base . '.webp',
            'width'  => $storedWidth,
            'height' => $storedHeight,
        ];
    }

    /**
     * Is this a cover at all?
     *
     * For books with no preview Google serves a flat filler image rather than
     * an error - in testing, 575x750 pixels in seven colours, blue throughout.
     * Stored, that would sit in the shelf looking like a cover, and it is
     * worse than the generated placeholder, which at least shows the title.
     *
     * A few hundred sampled pixels are enough to tell them apart: a printed
     * cover has dozens of colour values, a filler image has a handful.
     */
    private static function looksLikePlaceholder(\GdImage $image, int $width, int $height): bool
    {
        $stepX = max(1, (int) ($width / 30));
        $stepY = max(1, (int) ($height / 30));

        $colours = [];
        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $colours[imagecolorat($image, $x, $y)] = true;
                if (count($colours) > 24) {
                    return false;
                }
            }
        }

        return true;
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

    /**
     * Where a cover lives, relative to the cover root: "a3/9783473408061.webp".
     *
     * Three thousand books mean over six thousand files once the small copies
     * are counted, and a single directory holding those is slow to list, slow
     * to stat, and painful over FTP - which is the only way onto this server.
     * Splitting on two hex characters of a hash of the key gives 256 buckets,
     * so the shelf as it stands averages roughly two dozen files per
     * directory, and it keeps scaling long past that.
     *
     * The hash is taken rather than, say, the ISBN's publisher prefix because
     * publishers are wildly uneven - one of them accounts for dozens of these
     * books while most account for one.
     */
    public static function shardFor(string $key): string
    {
        return substr(sha1($key !== '' ? $key : 'leer'), 0, 2);
    }

    /**
     * The stored name, derived from the caller's key and never from the
     * upload's own filename.
     *
     * The key has to distinguish the source as well as the book. Naming files
     * after the ISBN alone meant a photograph and a downloaded cover for the
     * same book landed on the same path: the second silently overwrote the
     * first, and both database rows then pointed at one file - so deleting
     * either took the other's picture with it.
     */
    private function safeKey(string $key): string
    {
        $clean = preg_replace('/[^A-Za-z0-9-]/', '', $key) ?? '';
        $clean = trim($clean, '-');

        return $clean !== '' ? $clean : bin2hex(random_bytes(8));
    }

    private function ensureDirectory(string $subdirectory = ''): void
    {
        $path = $this->directory . ($subdirectory !== '' ? '/' . $subdirectory : '');
        if (!is_dir($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new RuntimeException('Cover directory is not writable: ' . $path);
        }
    }

    /** @param string $path as stored in the database, e.g. "a3/978....webp" */
    public function delete(string $path): void
    {
        $shard = dirname($path);
        $shard = $shard === '.' ? '' : $shard . '/';
        $base = basename($path, '.webp');

        foreach ([$base . '.webp', $base . '-klein.webp'] as $file) {
            $full = $this->directory . '/' . $shard . $file;
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }
}
