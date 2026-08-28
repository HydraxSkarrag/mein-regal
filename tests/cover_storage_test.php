<?php
declare(strict_types=1);

use App\Core\CoverImage;
use App\Core\CoverStorage;

Assert::group('CoverStorage strips EXIF');

$dir = sys_get_temp_dir() . '/regal-cover-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0o755, true);
$storage = new CoverStorage($dir);

// A JPEG carrying EXIF, the way a phone photo arrives.
$image = imagecreatetruecolor(600, 900);
imagefill($image, 0, 0, imagecolorallocate($image, 90, 60, 120));
$sourceJpeg = $dir . '/source.jpg';
imagejpeg($image, $sourceJpeg, 90);

// APP1/EXIF with a GPS tag is what makes this matter: a cover photographed at
// home would otherwise publish the home's coordinates.
$exif = "Exif\x00\x00MM\x00\x2a\x00\x00\x00\x08\x00\x01\x88\x25\x00\x04\x00\x00\x00\x01\x00\x00\x00\x1a\x00\x00\x00\x00";
$segment = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
$jpeg = file_get_contents($sourceJpeg);
$withExif = substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
file_put_contents($sourceJpeg, $withExif);

Assert::true('the fixture really carries an EXIF marker', str_contains(file_get_contents($sourceJpeg), "Exif\x00\x00"));

$stored = $storage->storeBinary(file_get_contents($sourceJpeg), '9783473408061');

// Covers are spread over 256 subdirectories: six thousand files in one
// directory would be slow to list and painful over FTP, which is the only
// way onto this server.
$shard = CoverStorage::shardFor('9783473408061');
Assert::same('the file is named after the ISBN, not the upload', $stored['path'], $shard . '/9783473408061.webp');
Assert::same('the shard is two hex characters', preg_match('/^[0-9a-f]{2}$/', $shard), 1);
Assert::true('the image was written', is_file($dir . '/' . $stored['path']));
Assert::true('a smaller copy for the grid exists too', is_file($dir . '/' . $shard . '/9783473408061-klein.webp'));
Assert::same('the same key always lands in the same shard', CoverStorage::shardFor('9783473408061'), $shard);

$written = file_get_contents($dir . '/' . $stored['path']);
Assert::same('no EXIF block survives re-encoding', str_contains($written, "Exif\x00\x00"), false);
Assert::same('and no GPS block either', str_contains($written, 'GPS'), false);
Assert::same('the result is WebP', substr($written, 8, 4), 'WEBP');
Assert::true('the stored image is not upscaled beyond the source', $stored['width'] <= 600);

Assert::group('CoverStorage rejects what is not an image');

$rejected = false;
try {
    $storage->storeBinary('this is not an image at all', 'x');
} catch (Throwable $e) {
    $rejected = true;
}
Assert::same('a non-image is refused', $rejected, true);

// A crafted key must not escape the cover directory. The stored path now
// legitimately contains a slash for the shard, so the check is that the
// resolved file really sits underneath the cover root.
$traversal = $storage->storeBinary(file_get_contents($dir . '/source.jpg'), '../../etc/passwd');
Assert::same('no parent reference survives', str_contains($traversal['path'], '..'), false);
Assert::same(
    'the file lands inside the cover directory',
    str_starts_with((string) realpath($dir . '/' . $traversal['path']), (string) realpath($dir)),
    true
);
Assert::same('exactly one slash, for the shard', substr_count($traversal['path'], '/'), 1);

foreach (glob($dir . '/*') ?: [] as $entry) {
    if (is_dir($entry)) {
        array_map('unlink', glob($entry . '/*') ?: []);
        rmdir($entry);
        continue;
    }
    unlink($entry);
}
rmdir($dir);

Assert::group('CoverImage placeholder');

// The same book must always get the same colour, or the shelf reshuffles
// itself on every load.
Assert::same(
    'the colour is stable for one book',
    CoverImage::placeholderColour('9783473408061'),
    CoverImage::placeholderColour('9783473408061')
);
// With a small palette some books share a colour, which is fine. What
// matters is that the whole palette gets used rather than everything
// landing on one or two shades.
$seen = [];
foreach (range(9783000000000, 9783000000400) as $isbn) {
    $seen[CoverImage::placeholderColour((string) $isbn)] = true;
}
Assert::same('the palette is used across its full range', count($seen), 16);
Assert::true('a book with no ISBN still gets a colour', CoverImage::placeholderColour('') !== '');
Assert::same('a stored file becomes a path', CoverImage::url(['source' => 'own', 'path' => 'a3/x.webp', 'external_url' => null]), '/covers/a3/x.webp');
Assert::same(
    'the grid asks for the small copy',
    CoverImage::url(['source' => 'own', 'path' => 'a3/x.webp', 'external_url' => null], true),
    '/covers/a3/x-klein.webp'
);
Assert::same('a linked cover keeps its URL', CoverImage::url(['source' => 'google', 'path' => null, 'external_url' => 'https://x/y.jpg']), 'https://x/y.jpg');
Assert::same('no cover means none', CoverImage::url(null), null);
