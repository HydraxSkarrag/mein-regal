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

Assert::group('A landscape cover is drawn whole');

$view = new View(PROJECT_ROOT . '/app/templates');
$view->share('view', $view);

$render = static fn (array $cover): string => $view->render('partials.cover', [
    'book'       => ['title' => 'Simons Katze', 'isbn13' => '9783442312337', 'slug' => 'simons-katze'],
    'cover'      => $cover + ['source' => 'mvb', 'path' => 'dd/x-mvb.webp', 'external_url' => null],
    'authorLine' => '',
]);

$landscape = $render(['width' => 771, 'height' => 599]);

Assert::true('it is marked as wide', str_contains($landscape, 'class="cover cover--wide"'));
Assert::true('and carries its own dimensions', str_contains($landscape, 'width="771" height="599"'));

/* The attributes are the picture's, not a size on the page: the browser works
 * the ratio out from them and leaves the right amount of room before the
 * image arrives. CSS decides how large it is drawn. */
Assert::true('the stylesheet still sizes it', !str_contains($landscape, 'style='));

Assert::group('And a portrait one is left exactly as it was');

$portrait = $render(['width' => 600, 'height' => 900]);

Assert::true('no wide class', !str_contains($portrait, 'cover--wide'));
Assert::true('but the dimensions are there', str_contains($portrait, 'width="600" height="900"'));

// The three thousand books this must not touch: a cover an inch off 2:3 still
// fills its tile, because only actually-landscape ones are marked.
$almost = $render(['width' => 400, 'height' => 403]);
Assert::true('nearly square but still taller', !str_contains($almost, 'cover--wide'));

$square = $render(['width' => 900, 'height' => 892]);
Assert::true('wider than tall by eight pixels counts as wide', str_contains($square, 'cover--wide'));

Assert::group('A cover from before the dimensions were read back');

/* Rows written by an older version, and any path that stores a cover without
 * measuring it. Nothing is claimed about a picture nobody measured: no class,
 * no attributes, and the tile behaves exactly as it always did. */
$unknown = $render(['width' => null, 'height' => null]);

Assert::true('not called wide', !str_contains($unknown, 'cover--wide'));
Assert::true('and no invented dimensions', !str_contains($unknown, 'width="'));
Assert::true('it is still a cover', str_contains($unknown, '<img src='));

Assert::group('What the stylesheet does with it');

$css = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');

/* In the grid the tile keeps its shape - a shelf of tiles that each did as
 * they pleased would not read as a shelf - and the picture sits inside it
 * whole rather than being cropped to fit. */
Assert::true('a wide cover is contained, not cropped', str_contains($css, '.cover--wide img { object-fit: contain; }'));
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
