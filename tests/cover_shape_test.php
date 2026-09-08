<?php
/**
 * A book is not always taller than it is wide.
 *
 * The tile is 2:3 and the picture fills it, which is right for the three
 * thousand books that are portrait and wrong for the comics and the photo
 * books. Filling a tall box with a wide picture shows a strip out of the
 * middle of it: measured on the four that prompted this - 771x599, 900x892,
 * 300x227, 900x552 - between a third and three fifths of the cover was being
 * cut away, and Simons Katze was a slice of cat.
 */
declare(strict_types=1);

use App\Core\View;
use App\Repository\BookRepository;
use App\Repository\CoverRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('The shape is read, not guessed');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);
$covers = new CoverRepository($pdo);

/* Both dimensions have been recorded since covers were first stored; only the
 * width was ever selected back, which is why nothing downstream could know a
 * picture's shape. */
$wide = $books->insert(1, ['title' => 'Simons Katze', 'isbn13' => '9783442312337']);
$covers->save($wide, CoverRepository::SOURCE_MVB, 'dd/x-mvb.webp', null, null, 771, 599);

$best = $covers->bestFor($wide, true);
Assert::same('the width comes back', (int) $best['width'], 771);
Assert::same('and so does the height', (int) $best['height'], 599);

$many = $covers->bestForMany([$wide], true);
Assert::same('the shelf listing has it too', (int) $many[$wide]['height'], 599);

Assert::group('A cover the tile cannot hold without cutting into it');

/* The first rule here asked whether the cover was wider than tall. It caught
 * the comics and missed the audiobooks, which are square - and a square in a
 * 2:3 box loses exactly a third of its width, sides first, which is where the
 * title is. Asking about the shape was the wrong question; the useful one is
 * how much gets cut.
 *
 * Measured over the 3,064 covers on the larger shelf: 73% keep 90% or more
 * and never look cropped. Below four fifths there are 358 - the squares at
 * 67%, the landscape ones down to 44% - and each of those loses a piece of
 * the design rather than a margin.
 */
$view = new View(PROJECT_ROOT . '/app/templates');
$view->share('view', $view);

$render = static fn (array $cover): string => $view->render('partials.cover', [
    'book'       => ['title' => 'Simons Katze', 'isbn13' => '9783442312337', 'slug' => 'simons-katze'],
    'cover'      => $cover + ['source' => 'mvb', 'path' => 'dd/x-mvb.webp', 'external_url' => null],
    'authorLine' => '',
]);

$landscape = $render(['width' => 771, 'height' => 599]);   // Simons Katze, 52% survives

Assert::true('a landscape cover is shown whole', str_contains($landscape, 'class="cover cover--contain"'));
Assert::true('and carries its own dimensions', str_contains($landscape, 'width="771" height="599"'));

// The one that started this: a Random House Audio cover, exactly square.
$square = $render(['width' => 599, 'height' => 599]);
Assert::true('and so is a square audiobook cover', str_contains($square, 'cover--contain'));

/* The attributes are the picture's, not a size on the page: the browser works
 * the ratio out from them and leaves the right amount of room before the
 * image arrives. CSS decides how large it is drawn. */
Assert::true('the stylesheet still sizes it', !str_contains($landscape, 'style='));

Assert::group('And the thousands that fill their tile are left alone');

$portrait = $render(['width' => 600, 'height' => 900]);      // exactly 2:3
Assert::true('a 2:3 cover fills its tile', !str_contains($portrait, 'cover--contain'));
Assert::true('but the dimensions are there', str_contains($portrait, 'width="600" height="900"'));

/* The common trade shapes, all measured off the shelf. None of them looks
 * cropped, and letterboxing them would put bars around a quarter of the
 * grid. */
foreach ([[400, 546], [400, 532], [314, 500], [377, 599], [400, 505]] as [$w, $h]) {
    Assert::true(
        $w . 'x' . $h . ' still fills its tile',
        !str_contains($render(['width' => $w, 'height' => $h]), 'cover--contain')
    );
}

// And the ones just past the line, where the crop starts eating the design.
foreach ([[400, 400], [400, 464], [300, 227], [400, 267]] as [$w, $h]) {
    Assert::true(
        $w . 'x' . $h . ' is shown whole',
        str_contains($render(['width' => $w, 'height' => $h]), 'cover--contain')
    );
}

Assert::group('A cover from before the dimensions were read back');

/* Rows written by an older version, and any path that stores a cover without
 * measuring it. Nothing is claimed about a picture nobody measured: no class,
 * no attributes, and the tile behaves exactly as it always did. */
$unknown = $render(['width' => null, 'height' => null]);

Assert::true('not called off-shape', !str_contains($unknown, 'cover--contain'));
Assert::true('and no invented dimensions', !str_contains($unknown, 'width="'));
Assert::true('it is still a cover', str_contains($unknown, '<img src='));

Assert::group('What the stylesheet does with it');

$css = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');

/* In the grid the tile keeps its shape - a shelf of tiles that each did as
 * they pleased would not read as a shelf - and the picture sits inside it
 * whole rather than being cropped to fit. */
Assert::true('an off-shape cover is contained, not cropped', str_contains($css, '.cover--contain img { object-fit: contain; }'));
Assert::true('while the default still fills', str_contains($css, '.cover img { width: 100%; height: 100%; object-fit: cover;'));

/* The detail page has one book on it and nothing to line it up with, so the
 * frame takes the shape of the picture: no strip out of the middle, and no
 * bars around a wide one either. */
Assert::true(
    'the detail frame follows the picture',
    str_contains($css, '.book-detail .cover:not(.cover--placeholder) {')
        && str_contains($css, 'aspect-ratio: auto;')
);

/* The stand-in is drawn rather than photographed and has no shape of its own,
 * so it keeps 2:3 - which is what the :not() in those two rules is for. */
$detailRules = substr($css, (int) strpos($css, '.book-detail .cover:not(.cover--placeholder)'), 400);
Assert::same(
    'both detail rules exclude the stand-in',
    substr_count($detailRules, ':not(.cover--placeholder)'),
    2
);
Assert::true('and the tile is still 2:3 by default', str_contains($css, 'aspect-ratio: 2 / 3;'));

Assert::group('The card showed an empty frame for the covers that work best');

/* Scanning an ISBN drew a blank rectangle where the cover belongs, and then
 * the book appeared on the shelf a second later with its cover on it. Both
 * halves were the same URL:
 *
 *   without a browser's user agent   a 599x599 JPEG
 *   with one                         "Making sure you're not a bot!", 4 KB
 *
 * So the server could fetch it and the browser could not, and the fault was
 * invisible on the one screen where it happened. The same catalogue, the same
 * bot check, that made the by-hand candidate list need CoverPreviews - a
 * second place, found the same way and fixed the same way.
 */
$scan = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ScanController.php');
$script = (string) file_get_contents(PROJECT_ROOT . '/public/js/scanner.js');

Assert::true('the server fetches the thumbnail', str_contains($scan, '$previews->forUrl('));
Assert::true('and the card draws that', str_contains($script, 'book.cover_preview'));
Assert::true(
    'rather than the catalogue address',
    !str_contains($script, "esc(book.cover_url) + '\" alt=\"\">")
);

/* The address still travels on to be saved: it is the display that was
 * broken, not the cover. */
Assert::true('the address is still sent to be stored', str_contains($script, "body.append('cover_url'"));

/* Fetching is also a stronger test than the HEAD request it replaces. Bytes
 * that decode as an image prove there is a cover; a 200 only proved there was
 * an answer, and the bot check answers 200. */
Assert::true('no bare probe is left', !str_contains($scan, 'hasCover'));

Assert::group('Nothing on any page fetches a picture from anywhere else');

/* The list of image hosts existed for exactly one screen - the scan card -
 * and that screen no longer needs it. An image request carries data in its
 * address, so seven allowed hosts were seven places to send it.
 */
$policy = (new App\Core\Csp())->header();

Assert::true('only this server and data:', str_contains($policy, "img-src 'self' data:;"));
foreach (['portal.dnb.de', 'books.google.com', 'covers.openlibrary.org', 'archive.org'] as $host) {
    Assert::true($host . ' is not allowed', !str_contains($policy, $host));
}

// The fetch allowlist is a different thing and stays: it is what the server
// itself may reach, and it is the reason a cover can be downloaded at all.
$storage = (string) file_get_contents(PROJECT_ROOT . '/app/Core/CoverStorage.php');
Assert::true('the server may still fetch from the catalogue', str_contains($storage, 'portal.dnb.de'));
