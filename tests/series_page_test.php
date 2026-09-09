<?php
/**
 * The series page: filing, editing, and getting rid of one.
 *
 * Three things that were missing rather than wrong. The A-Z index filed every
 * German series under D, because that is the letter "Die" starts with. The
 * total and the note had a repository method each and no form, so what I told
 * somebody to type on this page could not be typed. And a series made by a
 * slip - "d", one letter, no books - had no way off the shelf.
 */
declare(strict_types=1);

use App\Core\Text;
use App\Repository\BookRepository;
use App\Repository\SeriesRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Filed under the letter the title starts with, not the article');

/* What every library does and has done for a century, and what the catalogue
 * this shelf asks says in the record itself: MARC marks the article with a
 * non-sorting character. A name somebody typed carries no such mark. */
Assert::same('German definite', Text::filingName('Die Chronik der Drachenlanze'), 'Chronik der Drachenlanze');
Assert::same('in any case', Text::filingName('Des Kaisers neue Kleider'), 'Kaisers neue Kleider');
Assert::same('and the indefinite one', Text::filingName('Ein Mann namens Ove'), 'Mann namens Ove');
Assert::same('English', Text::filingName('The Hobbit'), 'Hobbit');
Assert::same('and its other two', Text::filingName('An Ember in the Ashes'), 'Ember in the Ashes');
Assert::same('French', Text::filingName('Le Petit Prince'), 'Petit Prince');
Assert::same('elided, with the apostrophe', Text::filingName('L’Étranger'), 'Étranger');
Assert::same('Italian', Text::filingName('Il Nome della Rosa'), 'Nome della Rosa');

// A name that only looks like it starts with one.
Assert::same('a title is left alone', Text::filingName('Mistborn'), 'Mistborn');
Assert::same('and so is one that starts with the word', Text::filingName('Diebe der Nacht'), 'Diebe der Nacht');

/* The line this stops at. "Einer" is an article in a grammar book and a
 * pronoun in a title, and filing "Einer flog über das Kuckucksnest" under F
 * would be worse than leaving it under E. Where the word alone cannot
 * decide, the word stays. */
Assert::same(
    'an ambiguous one is not guessed at',
    Text::filingName('Einer flog über das Kuckucksnest'),
    'Einer flog über das Kuckucksnest'
);
// An article with nothing after it is the whole name, not an article.
Assert::same('and a bare article is a name', Text::filingName('Die'), 'Die');

Assert::group('A series can be corrected and removed');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');
(new UserRepository($pdo))->create('s@example.org', 'ein-langes-passwort', 'S');

$books  = new BookRepository($pdo);
$series = new SeriesRepository($pdo);

$id = $series->findOrCreate(1, 'Die Chronik der Drachenlanze', $made);
$one = $books->insert(1, ['title' => 'Drachenzwielicht', 'series_id' => $id, 'series_index' => 1.0]);
$two = $books->insert(1, ['title' => 'Drachenjäger', 'series_id' => $id, 'series_index' => 2.0]);

$series->update(1, $id, ['total' => 6, 'note' => 'gezählt wie bei Audible']);
$after = $series->find(1, $id);
Assert::same('the total is written down', (int) $after['total'], 6);
Assert::same('and the note with it', $after['note'], 'gezählt wie bei Audible');

// Renaming moves the address with it, or the old slug would answer for a
// series under a name nobody uses any more.
$series->update(1, $id, ['name' => 'Drachenlanze']);
$renamed = $series->find(1, $id);
Assert::same('a rename takes the name', $renamed['name'], 'Drachenlanze');
Assert::same('and the slug', $renamed['slug'], 'drachenlanze');

/* Removing a series is not removing books. Somebody clearing away a series
 * made by a slip must not find two books gone with it. */
$series->delete(1, $id);
Assert::same('the series is gone', $series->find(1, $id), null);
Assert::same('both books are still there', $books->countAll(1), 2);
Assert::same('and belong to no series', $books->findById(1, $one)['series_id'], null);
Assert::same('with no volume number left over', $books->findById(1, $two)['series_index'], null);

Assert::group('Saving is not removing');

/* This is the bug the repository tests could not see, because it is not in
 * the repository. Request::post() answers '' for a key that is not there and
 * never null, so "if ($request->post('remove') !== null)" was true on every
 * submission - and Speichern deleted the series it was meant to save, taking
 * six books' volume numbers with it. Caught by pressing the button. */
$save = new App\Core\Request('POST', '/reihe/x', [], ['name' => 'X', 'total' => '12']);
Assert::true('a form without the button is not a removal', !$save->postBool('remove'));
Assert::same('and post() would have said otherwise', $save->post('remove'), '');

$remove = new App\Core\Request('POST', '/reihe/x', [], ['name' => 'X', 'remove' => '1']);
Assert::true('the button is what makes it one', $remove->postBool('remove'));

$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ShelfController.php');
Assert::true(
    'and the controller asks that way',
    str_contains($controller, "postBool('remove')")
);

Assert::group('And the page offers all of that');

$page = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/series.php');
Assert::true('the form is on the page', str_contains($page, 'action="/reihe/'));
Assert::true('only when signed in', str_contains($page, 'if ($signedIn)'));
Assert::true('it carries a CSRF token', str_contains($page, '$csrfField'));
Assert::true('the total can be typed', str_contains($page, 'name="total"'));
Assert::true('the note too', str_contains($page, 'name="note"'));
Assert::true('and the series can be removed', str_contains($page, 'name="remove"'));

/* Saying what it does, because "Löschen" beside a page full of covers reads
 * as "delete these books". */
Assert::true(
    'the button does not say Löschen',
    !str_contains(t('series.remove'), 'öschen')
);
Assert::true(
    'and the hint says the books stay',
    str_contains(t('series.remove.hint'), 'bleiben')
);

$routes = (string) file_get_contents(PROJECT_ROOT . '/public/index.php');
Assert::true('the address exists', str_contains($routes, "post('/reihe/{slug}'"));
