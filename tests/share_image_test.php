<?php
/**
 * The picture a shared link to the shelf carries.
 *
 * Outside the book pages the shelf named none, so a link to it arrived on
 * Facebook, WhatsApp or Signal as a grey box with a line of text. One picture
 * per installation now, drawn from what the installation already says about
 * itself, kept as a file and drawn again only when something on it changes.
 */
declare(strict_types=1);

use App\Core\Brand;
use App\Core\Config;
use App\Core\Request;
use App\Core\ShareImage;
use App\Core\Theme;
use App\Http\Application;
use App\Repository\BookRepository;
use App\Repository\CoverRepository;
use App\Repository\UserRepository;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/support/TestDatabase.php';

/* An installation of its own in a temporary directory: the real stylesheet
 * and fonts, a logo, and three covers. */
$shareRoot = sys_get_temp_dir() . '/regal-share-' . getmypid();
foreach (['/public/css', '/public/assets/brand', '/public/covers/aa', '/app'] as $dir) {
    @mkdir($shareRoot . $dir, 0775, true);
}
copy(PROJECT_ROOT . '/public/css/style.css', $shareRoot . '/public/css/style.css');
@symlink(PROJECT_ROOT . '/app/fonts', $shareRoot . '/app/fonts');
$sharePicture = static function (string $file, int $w, int $h, array $rgb, string $type): void {
    $image = imagecreatetruecolor($w, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    $type === 'png' ? imagepng($image, $file) : imagewebp($image, $file);
};
$sharePicture($shareRoot . '/public/assets/brand/logo.png', 120, 90, [200, 30, 40], 'png');
foreach (['one', 'two', 'three'] as $i => $name) {
    $sharePicture($shareRoot . "/public/covers/aa/{$name}.webp", 400, 600, [40 * $i, 90, 160], 'webp');
}
$shareMake = static function (string $name, array $covers) use ($shareRoot): ShareImage {
    return new ShareImage(
        $shareRoot,
        'https://regal.example.org',
        new Brand($shareRoot),
        new Theme($shareRoot, ''),
        $name,
        'von Bücherhausen',
        static fn (): array => ['count' => '3.042 Bücher', 'covers' => $covers],
    );
};
$shareCovers = ['aa/one.webp', 'aa/two.webp', 'aa/three.webp'];

Assert::group('One picture, drawn once');

Assert::true('this machine can draw it', ShareImage::canDraw(PROJECT_ROOT . '/app/fonts'));
Assert::true('the fonts are shipped with their licence', is_file(PROJECT_ROOT . '/app/fonts/LICENSE.txt'));

$shareCard = $shareMake('Mein Regal', $shareCovers)->card(null);
Assert::true('there is one', $shareCard !== null);
Assert::true('at an address the platforms can fetch on their own', str_starts_with((string) ($shareCard['url'] ?? ''), 'https://regal.example.org/covers/share/regal-'));
Assert::same('the size they ask for', [$shareCard['width'] ?? null, $shareCard['height'] ?? null], [1200, 630]);
Assert::same('and a description of it', $shareCard['alt'] ?? null, 'Mein Regal – von Bücherhausen');

$shareFile = $shareRoot . '/public' . substr((string) $shareCard['url'], strlen('https://regal.example.org'));
$shareSize = @getimagesize($shareFile);
Assert::same('it is a JPEG of that size on disk', [$shareSize[0] ?? 0, $shareSize[1] ?? 0, $shareSize['mime'] ?? ''], [1200, 630, 'image/jpeg']);
Assert::true('small enough for a messenger to show', filesize($shareFile) < 300_000);

/* The same things on it, the same file: nothing is drawn again. */
touch($shareFile, time() - 3600);
$shareAgain = $shareMake('Mein Regal', $shareCovers)->card(null);
clearstatcache();
Assert::same('asked again, the same address', $shareAgain['url'] ?? null, $shareCard['url']);
Assert::true('and the file was not drawn a second time', filemtime($shareFile) < time() - 1800);

/* Anything on it changes, and so does its name - which is what makes the
 * platforms fetch it again, since they keep an image by its address. */
$shareRenamed = $shareMake('Anderes Regal', $shareCovers)->card(null);
Assert::true('another name, another address', ($shareRenamed['url'] ?? '') !== $shareCard['url']);
Assert::true('and the old file is gone', !is_file($shareFile));
Assert::true('a new book on the shelf is a new picture', ($shareMake('Anderes Regal', ['aa/three.webp', 'aa/one.webp'])->card(null)['url'] ?? '') !== $shareRenamed['url']);

Assert::group('Where it is not used');

$shareBook = ['url' => 'https://regal.example.org/covers/0c/x.webp', 'width' => 400, 'height' => 600, 'alt' => 'Cover von X'];
Assert::same('a page with a picture of its own keeps it', $shareMake('Mein Regal', $shareCovers)->card($shareBook), $shareBook);

copy($shareRoot . '/public/assets/brand/logo.png', $shareRoot . '/public/assets/brand/og.png');
$shareOwn = $shareMake('Mein Regal', $shareCovers)->card(null);
Assert::true('a designed one in the brand folder replaces the drawn one', str_starts_with((string) ($shareOwn['url'] ?? ''), 'https://regal.example.org/assets/brand/og.png'));
Assert::same('with its own size', [$shareOwn['width'] ?? null, $shareOwn['height'] ?? null], [120, 90]);
unlink($shareRoot . '/public/assets/brand/og.png');

Assert::true('without the fonts nothing is drawn', !ShareImage::canDraw($shareRoot . '/nowhere'));

Assert::group('Which covers stand on it');

/* config.php can name the books, by ISBN or by the address of their page;
 * the newest fill whatever is left, and one named twice counts once. */
$sharePdo = TestDatabase::fresh();
(new UserRepository($sharePdo))->create('d@example.org', 'ein-langes-passwort', 'D');
$shareBooks = new BookRepository($sharePdo);
$shareCoverRepo = new CoverRepository($sharePdo);
$shareIds = [];
foreach (['9783453274419' => 'Das Blut der Herzlosen', '9783442245109' => 'Drachenzwielicht', '9781942788812' => 'Team Topologies', '9783407795595' => 'Zogg'] as $isbn => $title) {
    $isbn = (string) $isbn; // a numeric key comes back as an int
    $id = $shareBooks->insert(1, ['title' => $title, 'isbn13' => $isbn]);
    $shareCoverRepo->save($id, CoverRepository::SOURCE_MVB, "aa/{$isbn}.webp", null, null, 400, 600);
    $shareIds[$isbn] = $id;
}
$noCover = $shareBooks->insert(1, ['title' => 'Ohne Bild', 'isbn13' => '9783551684059']);

$shareApp = static function (array $named) use ($sharePdo): array {
    $app = new Application(
        new Config(['site_url' => 'https://regal.example.org', 'share_books' => $named]),
        new Request('GET', '/'),
        $sharePdo
    );

    return (new ReflectionMethod(Application::class, 'shareCovers'))->invoke($app, 3);
};
$drachen = $shareBooks->findByIsbn(1, '9783442245109');
Assert::same('nothing named: the newest three', $shareApp([]), ['aa/9783407795595.webp', 'aa/9781942788812.webp', 'aa/9783442245109.webp']);
Assert::same(
    'named by ISBN and by address, in that order',
    $shareApp(['9783453274419', 'https://regal.example.org/book/' . $drachen['slug'], '978-1-942788-81-2']),
    ['aa/9783453274419.webp', 'aa/9783442245109.webp', 'aa/9781942788812.webp']
);
Assert::same(
    'one named, the newest after it, and a book without a cover left out',
    $shareApp(['9783551684059', '9783453274419', '9783453274419']),
    ['aa/9783453274419.webp', 'aa/9783407795595.webp', 'aa/9781942788812.webp']
);

Assert::group('What the page says about it');

$shareLayout = (string) file_get_contents(PROJECT_ROOT . '/app/templates/layout/base.php');
Assert::true('the page asks for its card', str_contains($shareLayout, '$shareImage->card($ogImage ?? null)'));
Assert::true('and names its size and what it shows', str_contains($shareLayout, 'og:image:width') && str_contains($shareLayout, 'og:image:height') && str_contains($shareLayout, 'og:image:alt'));
$shareShelf = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ShelfController.php');
Assert::true('a book page hands over its cover with its size', str_contains($shareShelf, "'ogImage'         => \$this->publicOgImage(\$bookId, (string) \$book['title'])"));

/* Tidy up. */
$shareWalk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($shareRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($shareWalk as $leftover) {
    $leftover->isLink() || !$leftover->isDir() ? @unlink($leftover->getPathname()) : @rmdir($leftover->getPathname());
}
@rmdir($shareRoot);
