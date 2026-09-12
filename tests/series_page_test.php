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

Assert::group('Changed from a button, on a page of its own');

/* Which is what this shelf does with a thing you change: a book has
 * /book/{slug}/edit behind a link in the same bar. Folding a form away is
 * what it does for deleting a book and for the filters on a phone, and
 * neither of those is "edit this" - so a fold here read as neither. */
$page = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/series.php');
Assert::true('the series page has the same action bar', str_contains($page, 'class="detail-actions"'));
Assert::true('with a way to the edit page', str_contains($page, '/edit"><?= e(t(\'series.edit\'))'));
// At the foot, like the book page: see the group below.
Assert::true(
    'and it stands after the volumes, not before them',
    strpos($page, 'class="detail-actions"') > strpos($page, '<ul class="shelf">')
);
Assert::true('only when signed in', str_contains($page, 'if ($signedIn)'));
Assert::true('and no form folded into it', !str_contains($page, '<details'));

$form = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/series_edit.php');
Assert::true('the form is its own page', str_contains($form, 'action="/reihe/'));
Assert::true('it carries a CSRF token', str_contains($form, '$csrfField'));
Assert::true('the total can be typed', str_contains($form, 'name="total"'));
Assert::true('the note too', str_contains($form, 'name="note"'));
Assert::true('and the series can be removed', str_contains($form, 'name="remove"'));

/* Removing is folded, because that is where this shelf keeps a way of
 * removing something - but without the typed-out word a book asks for.
 * Nothing is lost here, and asking for one would say otherwise. */
Assert::true('removal sits in the same fold a book uses', str_contains($form, 'class="danger"'));
Assert::true('and asks for no typed word', !str_contains($form, 'name="confirm"'));

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
Assert::true('the page has an address', str_contains($routes, "get('/reihe/{slug}/edit'"));
Assert::true('and so has saving it', str_contains($routes, "post('/reihe/{slug}/edit'"));

Assert::group('A fold that opens a form has to look like one');

/* The series form was folded into a <details> and given no rules at all, so
 * its summary was a line of text that happened to open something: no pointer
 * under the mouse, no colour that moved. Both other <details> on this shelf
 * set exactly that, which is what made the omission invisible to me and
 * obvious to somebody using it.
 *
 * So: every fold in the templates has a rule that says it can be clicked. */
$stylesheet = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');

$folds = [];
foreach (['shelf/index.php', 'shelf/series_edit.php', 'shelf/edit.php'] as $template) {
    $markup = (string) file_get_contents(PROJECT_ROOT . '/app/templates/' . $template);
    if (preg_match_all('/<details class="([a-z-]+)/', $markup, $found)) {
        $folds = array_merge($folds, $found[1]);
    }
}
$folds = array_values(array_unique($folds));
Assert::true('there are folds to check', count($folds) >= 2);

$unclickable = [];
foreach ($folds as $fold) {
    // The rule for that fold's summary, wherever in the file it sits.
    $rule = preg_match('/\.' . preg_quote($fold, '/') . '[^{]*summary[^{]*\{([^}]*)\}/', $stylesheet, $body)
        ? $body[1]
        : '';
    if (!str_contains($rule, 'cursor: pointer')) {
        $unclickable[] = $fold;
    }
}
sort($unclickable);
Assert::same('every one of them shows a pointer', $unclickable, []);

/* The suggestion list, and how wide it is allowed to be.
 *
 * It was built into the input's own field, which is one column of a two column
 * row - so "Die Chronik der Drachenlanze" broke over three lines in a box half
 * the width of the row, next to a field holding the number 1. It is a grid item
 * of the row now, spanning both columns.
 *
 * Both halves are checked, because either one alone puts it back: the script
 * has to hang it on the row, and the rule has to let it span. */
$script = (string) file_get_contents(PROJECT_ROOT . '/public/js/series.js');

Assert::true(
    'the list hangs on the row, not on the field around the input',
    str_contains($script, 'field.appendChild(list)') && !str_contains($script, 'input.parentNode.insertBefore(list')
);
Assert::true(
    'and the row lets it span every column it has',
    (bool) preg_match(
        '/\.field-row--picker > \.picker-list,\s*\.field-row--picker > \.picker-warning \{[^}]*grid-column: 1 \/ -1/',
        $stylesheet
    )
);

/* And the row it sits in has its columns spelled out.
 *
 * The other field rows use auto-fit, which collapses a track nothing is in.
 * A list spanning 1/-1 is in every track, so the moment the suggestions
 * appeared the row went from two wide columns to as many narrow ones as fit -
 * measured at 1400px, the field under the cursor shrank from 257 pixels to
 * 167 the instant it was clicked into. A field that changes size when you
 * focus it is not a field anybody trusts. */
Assert::true(
    'the picker row does not depend on a track being empty',
    (bool) preg_match(
        '/\.field-row--picker \{ grid-template-columns: minmax\(0, 2fr\) minmax\(0, 1fr\); \}/',
        $stylesheet
    )
);

/* The A-Z lists, for the same reason on a different page: CSS columns give an
 * entry the column width and never more, so twelve letter groups holding one
 * series each sat in 269 of 1160 pixels with the name wrapped onto a second
 * line. A grid whose empty tracks collapse gives a group of one the whole row
 * and leaves a group of 226 exactly as it was - both measured. */
Assert::true(
    'the facet list is a grid that collapses what it does not need',
    str_contains($stylesheet, 'grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));')
        && !str_contains($stylesheet, 'columns: 260px;')
);

Assert::group('Nothing stands between the reader and the heading');

/* A row of ways out above the title was the first thing read on a page about
 * one book: three links, before the book they are about. That is right where
 * a page is a list and the controls act on it, and wrong where the page is
 * one object - the object comes first.
 *
 * So it is a rule and not one page's arrangement: no template puts a back
 * link above its own heading. Where a form already carries a cancel button in
 * its action row, the row above the title was not moved but dropped: it led
 * to the same address the button leads to, and two ways back to one place is
 * one too many.
 */
$templates = [];
foreach (['admin', 'auth', 'errors', 'pages', 'scan', 'shelf', 'stats'] as $area) {
    foreach (glob(PROJECT_ROOT . '/app/templates/' . $area . '/*.php') ?: [] as $file) {
        $templates[] = $file;
    }
}
Assert::true('there are templates to check', count($templates) > 10);

$early = [];
foreach ($templates as $file) {
    $markup = (string) file_get_contents($file);
    $heading = strpos($markup, '<h1');
    $back = strpos($markup, '&larr;');
    if ($heading !== false && $back !== false && $back < $heading) {
        $early[] = basename(dirname($file)) . '/' . basename($file);
    }
}
sort($early);
Assert::same('no page leads away before it says what it is', $early, []);

/* And the row that does lead away has one look, because it now stands in one
 * place. It carried a --foot modifier while there were two, and a modifier
 * that is always set is a word that has stopped meaning anything. */
$stylesheet = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');
Assert::true(
    'the foot treatment is the rule rather than a variant',
    str_contains($stylesheet, '.detail-actions {') && !str_contains($stylesheet, '.detail-actions--foot')
);
