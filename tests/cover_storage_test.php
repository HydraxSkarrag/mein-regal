<?php
declare(strict_types=1);

use App\Core\CoverImage;
use App\Core\CoverStorage;

Assert::group('CoverStorage strips EXIF');

$dir = sys_get_temp_dir() . '/regal-cover-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0o755, true);
$storage = new CoverStorage($dir);

// A JPEG carrying EXIF, the way a phone photo arrives. Given real detail,
// because a flat colour is now refused as a placeholder - which is the point
// of that check.
$image = imagecreatetruecolor(600, 900);
for ($x = 0; $x < 600; $x += 6) {
    for ($y = 0; $y < 900; $y += 6) {
        imagefilledrectangle($image, $x, $y, $x + 5, $y + 5,
            imagecolorallocate($image, ($x * 7) % 256, ($y * 3) % 256, ($x + $y) % 256));
    }
}
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

// The same book must always get the same ground, or the shelf reshuffles
// itself on every load.
Assert::same(
    'the ground is stable for one book',
    CoverImage::placeholderClass('9783473408061'),
    CoverImage::placeholderClass('9783473408061')
);
// With a small palette some books share a ground, which is fine. What
// matters is that the whole palette gets used rather than everything
// landing on one or two shades.
$seen = [];
foreach (range(9783000000000, 9783000000400) as $isbn) {
    $seen[CoverImage::placeholderClass((string) $isbn)] = true;
}
Assert::same('the palette is used across its full range', count($seen), 16);
Assert::true('a book with no ISBN still gets one', CoverImage::placeholderClass('') !== '');
// The class names the stylesheet defines, and nothing else: a colour in the
// markup is a colour a theme cannot change.
Assert::same(
    'it is a class, not a colour',
    preg_match('/^ph-([1-9]|1[0-6])$/', CoverImage::placeholderClass('9783473408061')),
    1
);
Assert::same('a stored file becomes a path', CoverImage::url(['source' => 'own', 'path' => 'a3/x.webp', 'external_url' => null]), '/covers/a3/x.webp');
Assert::same(
    'the grid asks for the small copy',
    CoverImage::url(['source' => 'own', 'path' => 'a3/x.webp', 'external_url' => null], true),
    '/covers/a3/x-klein.webp'
);
Assert::same('a linked cover keeps its URL', CoverImage::url(['source' => 'google', 'path' => null, 'external_url' => 'https://x/y.jpg']), 'https://x/y.jpg');
Assert::same('no cover means none', CoverImage::url(null), null);

Assert::group('CoverStorage rejects placeholder images');

$dir2 = sys_get_temp_dir() . '/regal-placeholder-' . bin2hex(random_bytes(4));
mkdir($dir2, 0o755, true);
$storage2 = new CoverStorage($dir2);

// Google answers "no preview available" with a solid-colour image rather than
// an error. Stored, it would look like a cover in the shelf and be worse than
// the generated placeholder, which at least shows the title.
$plain = imagecreatetruecolor(575, 750);
imagefill($plain, 0, 0, imagecolorallocate($plain, 0, 0, 255));
$plainPath = $dir2 . '/plain.png';
imagepng($plain, $plainPath);

$rejected = false;
try {
    $storage2->storeBinary((string) file_get_contents($plainPath), 'plain');
} catch (Throwable $e) {
    $rejected = $e->getMessage() === 'placeholder';
}
Assert::same('a solid-colour image is refused', $rejected, true);

// A real cover has to survive the same test.
$cover = imagecreatetruecolor(300, 450);
for ($x = 0; $x < 300; $x += 5) {
    for ($y = 0; $y < 450; $y += 5) {
        imagefilledrectangle($cover, $x, $y, $x + 4, $y + 4, imagecolorallocate($cover, $x % 256, $y % 256, ($x + $y) % 256));
    }
}
$coverPath = $dir2 . '/cover.png';
imagepng($cover, $coverPath);

$accepted = null;
try {
    $accepted = $storage2->storeBinary((string) file_get_contents($coverPath), 'cover');
} catch (Throwable $e) {
    $accepted = null;
}
Assert::true('a picture with real detail is kept', $accepted !== null);

foreach (glob($dir2 . '/*') ?: [] as $entry) {
    if (is_dir($entry)) {
        array_map('unlink', glob($entry . '/*') ?: []);
        rmdir($entry);
        continue;
    }
    unlink($entry);
}
rmdir($dir2);

Assert::group('Google hands out a bigger cover when asked');

/*
 * The API's thumbnail is 128 pixels wide - a third of what the grid needs.
 * The same address serves a larger rendition, and every cover is fetched
 * through this one rule so a refreshed cover and a freshly found one cannot
 * end up at different sizes.
 */
$thumbnail = 'http://books.google.com/books/content?id=X&printsec=frontcover&img=1&zoom=1&edge=curl&source=gbs_api';
$upgraded = App\Lookup\GoogleBooksLookup::renditionUrl($thumbnail);

Assert::true('a size is asked for', str_contains($upgraded, 'fife=w900'));
Assert::true('http is lifted to https', str_starts_with($upgraded, 'https://'));
Assert::true('the fake page curl is dropped', !str_contains($upgraded, 'edge=curl'));

// Covers stored before this existed are refreshed from their recorded
// address, so the rule runs over its own output. A second size appended
// after the first would leave the first one deciding.
$twice = App\Lookup\GoogleBooksLookup::renditionUrl($upgraded);
Assert::same('running it again changes nothing', $twice, $upgraded);
Assert::same('and does not append a second size', substr_count($twice, 'fife='), 1);

// An address that already asks for a different size is corrected, not added to.
$other = App\Lookup\GoogleBooksLookup::renditionUrl(
    'https://books.google.com/books/content?id=X&fife=w200&source=gbs_api'
);
Assert::same('an existing size is replaced', substr_count($other, 'fife='), 1);
Assert::true('by the one we want', str_contains($other, 'fife=w900'));
Assert::true('and the rest of the address survives', str_contains($other, 'source=gbs_api'));

// The refresh decides what to fetch by comparing addresses: a source with
// nothing better to offer must produce the address it already has, so that
// comparison is what keeps Open Library's covers from being downloaded again
// for nothing.
Assert::true('an address with a query gets another parameter', str_contains(
    App\Lookup\GoogleBooksLookup::renditionUrl('https://books.google.com/books/content?id=X'),
    '&fife=w900'
));
Assert::true('one without a query gets its first', str_contains(
    App\Lookup\GoogleBooksLookup::renditionUrl('https://books.google.com/books/content'),
    '?fife=w900'
));
