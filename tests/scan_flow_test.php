<?php
/**
 * One way in, one way on, three doors.
 *
 * Adding a book has three entrances - the camera, a typed ISBN, and the
 * catalogue searched by title - and each of them used to end somewhere
 * different. The camera came back for the next book; typing an ISBN left you
 * on the searching card with its bar still moving; picking a record out of
 * the catalogue dropped you on the edit page of the book you had just added,
 * with nothing to edit.
 *
 * Two of the three were dead ends, and the third was only not one because a
 * checkbox happened to be ticked. So: every ending goes back to the door it
 * came through, the checkbox is gone because its answer was always yes, and
 * the way out is the link to the book that was just put away.
 */
declare(strict_types=1);

use App\Lookup\CoverFinder;
use App\Repository\BookRepository;
use App\Repository\CoverRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

$scanner = (string) file_get_contents(PROJECT_ROOT . '/public/js/scanner.js');

Assert::group('The scanner: no ending leaves the searching card on screen');

/* Four ways a lookup can finish without a book, and every one of them used to
 * return from lookup() with the animated card still showing. Nothing was
 * broken in a way a test could see - the message was even correct - but there
 * was no way on except the browser's back button. */
$endings = [
    'the quota is spent'      => 'reply.status === 429',
    'the ISBN does not check' => 'reply.status === 422',
    'the book is already in'  => 'reply.data.duplicate',
    'nothing was found'       => '!reply.data.found',
];

foreach ($endings as $what => $marker) {
    $at = strpos($scanner, $marker);
    Assert::true($what . ' is still handled', $at !== false);

    /* The branch it opens has to reach backToOrigin before it returns. Cut
     * at the closing brace of that branch - a fixed number of characters was
     * enough for three of the four and stopped one line short of the fourth,
     * which is the sort of test that passes by luck. */
    $branch = substr($scanner, (int) $at);
    $end = strpos($branch, "\n    }\n");
    $branch = $end === false ? $branch : substr($branch, 0, (int) $end);

    $returnAt = strpos($branch, 'return;');
    $backAt = strpos($branch, 'backToOrigin()');
    Assert::true(
        'and it goes back to the door it came through',
        $backAt !== false && $returnAt !== false && $backAt < $returnAt
    );
}

Assert::group('The scanner: which door, remembered');

/* backToOrigin cannot guess. The camera has a viewfinder to return to and the
 * typed ISBN has a field to clear and focus, and putting somebody back at the
 * wrong one is worse than the dead end was. */
Assert::true('the camera door says so', str_contains($scanner, "origin = 'camera';"));
Assert::true('the typing door too', str_contains($scanner, "origin = 'manual';"));
Assert::true('and stopping ends the run', str_contains($scanner, "origin = 'choose';"));

Assert::true(
    'the typed door comes back ready for the next number',
    str_contains($scanner, "isbnInput.value = '';") && str_contains($scanner, 'isbnInput.focus();')
);

Assert::group('The scanner: still pointed at the same book');

/* Going back to the viewfinder after every ending is only safe if the repeat
 * guard survives the trip. The camera has not moved - it is looking at the
 * barcode it just answered about - so a cleared guard means the same code is
 * read on the next frame: message, lookup, message, several times a second,
 * at the catalogue's expense.
 *
 * The four-second guard and the ten seconds after a save are what handle
 * "still in view"; going back is only about where the screen goes. */
Assert::same(
    'the guard is set up once and cleared nowhere',
    substr_count($scanner, "lastCode = '';"),
    1
);
Assert::true('that once being where it is declared', str_contains($scanner, "var lastCode = '';"));
Assert::true(
    'and the same code inside four seconds is ignored',
    str_contains($scanner, 'now - lastCodeAt < 4000')
);

Assert::group('The scanner: every run is a series');

/* The checkbox is gone from the script, the template and both language files.
 * A book at a time is the short case of a run, never the other way round, and
 * the way out has always been the button to the book just saved - which is on
 * screen the whole time and needs no deciding beforehand. */
Assert::true('the script consults no switch', !str_contains($scanner, 'seriesToggle'));
Assert::true('and asks for no such element', !str_contains($scanner, "getElementById('series')"));

$template = (string) file_get_contents(PROJECT_ROOT . '/app/templates/scan/index.php');
Assert::true('the template offers no checkbox', !str_contains($template, 'id="series"'));
Assert::true('but keeps the one about reading', str_contains($template, 'id="read"'));

foreach (['de', 'en'] as $locale) {
    $messages = require PROJECT_ROOT . '/app/lang/' . $locale . '.php';
    Assert::true($locale . ' has no string left for it', !isset($messages['scan.series']));
    Assert::true('and none for its hint', !isset($messages['scan.series.hint']));
    Assert::true('while the reading switch keeps its own', isset($messages['scan.read']));
}

Assert::group('The catalogue search comes back to itself');

$form = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/new.php');

/* Which of the two buttons was pressed is said outright rather than guessed
 * from the fields: a catalogue record with no ISBN and no publisher looks
 * exactly like a typed title, and the search turns up plenty of those. */
Assert::true(
    'a picked record says where it came from',
    str_contains($form, '<input type="hidden" name="from" value="search">')
);
Assert::same(
    'and only the candidates carry it',
    substr_count($form, 'name="from" value="search"'),
    1
);

$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/BookController.php');
Assert::true(
    'the controller reads it',
    str_contains($controller, "\$request->post('from') === 'search'")
);
Assert::true(
    'a picked record goes back to the search',
    str_contains($controller, "Response::redirect('/book/new?book='")
);
Assert::true(
    'and the page can show what was just added',
    str_contains($controller, "\$request->query('book')") && str_contains($form, '$justAdded')
);

Assert::group('A search that answers below the fold answered nothing');

/* The form posts, the browser draws the shortlist under a card that fills a
 * phone screen, and what the reader sees is the form they just submitted.
 *
 * A POST carries the fragment of the address it was sent to, which scrolls
 * the page with no JavaScript at all - measured in a browser: the heading
 * came to rest exactly at its scroll-margin. Two things had to be true for
 * it, and both are easy to undo by accident.
 *
 * One: the fragment belongs to the search button rather than the form, or
 * "Ohne Suche anlegen" - which redirects away - drags a #results onto the
 * address of the edit page. */
Assert::true(
    'only the search button carries the fragment',
    str_contains($form, 'formaction="/book/new#results"')
);
Assert::same('and no other form does', substr_count($form, '/book/new#results'), 1);
Assert::true('there is something for it to find', str_contains($form, 'id="results"'));

/* Two: focus beats a fragment. With autofocus on the title field the browser
 * put the cursor there and left the results eight hundred pixels down, which
 * is the bug with an extra step in it. The field keeps its autofocus for the
 * empty page, where typing is the only thing to do. */
Assert::true(
    'the field only grabs focus when there is nothing to read',
    str_contains($form, "\$found === null ? ' autofocus' : ''")
);
Assert::same('and never unconditionally', substr_count($form, ' autofocus>'), 0);

$css = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');
Assert::true('the heading is not flush to the edge', str_contains($css, '#results { scroll-margin-top'));

Assert::group('The round scan button on the page it leads to');

/* Every other entry in the bottom bar shows "you are here" by turning accent
 * coloured. This one is a filled accent circle already, so that rule painted
 * the icon in the colour of the disc behind it: on the scanning page, the one
 * screen where you most want to see what the button is, it went blank. */
$css = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');

$generic = strpos($css, '.bottom-nav a[aria-current="page"]');
$specific = strpos($css, '.bottom-nav .nav-scan[aria-current="page"]');

Assert::true('the generic rule is still there', $generic !== false);
Assert::true('and the circle has its own', $specific !== false);
Assert::true('which comes after it, so it wins', (int) $specific > (int) $generic);

$rule = substr($css, (int) $specific, 320);
Assert::true('the icon stays legible', str_contains($rule, 'color: var(--on-accent)'));
Assert::true('and the disc says so another way', str_contains($rule, 'var(--accent-dark)'));

Assert::group('A cover too small to stop at');

/* The detail page draws a cover 220 CSS pixels wide - 440 real ones on a
 * phone - and the sources disagree wildly about what they hand over for the
 * same book: MVB answers a German ISBN at 599, Open Library at 316 to 333 for
 * the English editions to hand, Google at 900 where a full scan exists and
 * 300 where it does not. Which of those it will be cannot be known before
 * fetching it, so the rule is about the picture rather than the source. */
Assert::true('the threshold is above what a phone draws', CoverFinder::MIN_GOOD_WIDTH >= 440);

$finder = (string) file_get_contents(PROJECT_ROOT . '/app/Lookup/CoverFinder.php');
Assert::true(
    'and it is what stops the asking',
    str_contains($finder, "\$stored['width'] >= self::MIN_GOOD_WIDTH")
);
Assert::true(
    'while everything fetched is still kept',
    str_contains($finder, '$best === null ||')
);

$scan = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ScanController.php');
Assert::true(
    'a scanned book gets the same second look',
    str_contains($scan, '$width < CoverFinder::MIN_GOOD_WIDTH')
);

Assert::group('Which of several covers a book shows');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$books = new BookRepository($pdo);
$covers = new CoverRepository($pdo);

/* This is what makes fetching a second one safe: the rows sit side by side
 * and the repository decides. A small picture that arrived first is not in
 * the way of a better one, and a better one is not in the way of a
 * photograph of the actual copy. */
$bookId = $books->insert(1, ['title' => 'Snow Crash', 'isbn13' => '9780553380958']);
$covers->save($bookId, CoverRepository::SOURCE_GOOGLE, 'a/x-google.webp', null, null, 300, 450);

Assert::same('the small one shows while it is alone', $covers->bestFor($bookId, true)['source'], 'google');

$covers->save($bookId, CoverRepository::SOURCE_OPENLIBRARY, 'a/x-ol.webp', null, null, 316, 475);
Assert::same(
    'equal standing is settled by size',
    $covers->bestFor($bookId, true)['source'],
    'openlibrary'
);

$covers->save($bookId, CoverRepository::SOURCE_MVB, 'a/x-mvb.webp', null, null, 599, 900);
Assert::same('and the publisher file outranks both', $covers->bestFor($bookId, true)['source'], 'mvb');

Assert::group('Searching for a cover by hand replaces the one on show');

/* An own photograph outranks everything, which is right for the nightly job -
 * it must never quietly paint over a picture of the actual copy. It made the
 * button on the edit page do nothing visible, though: it said "Cover gefunden
 * und gespeichert" and the page came back showing the same photograph,
 * because the found one had been filed behind it.
 *
 * A button pressed by the owner, on this book, now, is not the nightly job. */
$own = $books->insert(1, ['title' => 'Eigenes Foto', 'isbn13' => '9783473408061']);
$covers->save($own, CoverRepository::SOURCE_OWN, 'b/x-own.webp', null, null, 700, 1000);
$covers->save($own, CoverRepository::SOURCE_MVB, 'b/x-mvb.webp', null, null, 599, 900);

Assert::same('the photograph wins on standing', $covers->bestFor($own, true)['source'], 'own');

// What findCover does once it has stored something: set aside whatever was
// showing before, so the picture on the page is the one just found.
$paths = $covers->remove($own, CoverRepository::SOURCE_OWN);

Assert::same('the file comes back to be unlinked', $paths, ['b/x-own.webp']);
Assert::same('and the found cover is what shows', $covers->bestFor($own, true)['source'], 'mvb');

Assert::true(
    'the controller only does that when something was stored',
    str_contains($controller, "if (\$result['stored']) {")
        && str_contains($controller, "\$before['source'] !== \$result['source']")
);

/* And it says which source is now on the page, because "Cover gefunden und
 * gespeichert" was exactly the sentence that turned out not to mean the
 * picture had changed. */
Assert::true(
    'and names the source it settled on',
    str_contains($controller, "t('cover.from.' . \$result['source'])")
);

$german = require PROJECT_ROOT . '/app/lang/de.php';
Assert::true(
    'the hint warns before the press, not after',
    str_contains($german['cover.search.hint'], 'ersetzen')
);
