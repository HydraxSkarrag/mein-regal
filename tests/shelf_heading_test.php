<?php
/**
 * The heading names the selection, not the site.
 *
 * On the unfiltered shelf it used to say "Mein Regal" - which is the name of
 * the installation, already in the header a few centimetres above and again
 * in the browser tab. The same three words three times on one screen, and on
 * an installation named something else, two different names for the same
 * thing.
 *
 * A heading here answers "which books are these". When they are all of them,
 * the answer is "all of them" - and the status filters keep the names their
 * readers use, which is why this and not simply the count.
 */
declare(strict_types=1);

use App\Controller\ShelfController;

/** @param array<string,mixed> $filters */
function heading(array $filters, ?string $author = null, array $names = []): string
{
    $names['author'] = $names['author'] ?? $author;

    return (new ReflectionMethod(ShelfController::class, 'headingFor'))->invoke(null, $filters, $names);
}

/** @param array<string,mixed> $filters */
function documentTitle(array $filters, string $heading): string
{
    return (new ReflectionMethod(ShelfController::class, 'documentTitle'))
        ->invoke(null, $filters, $heading, 'Mein Regal');
}

Assert::group('Shelf heading: the selection, never the site');

Assert::same('with nothing filtered it names the whole shelf', heading([]), t('shelf.all.books'));
Assert::true('and that is not the site name', heading([]) !== 'Mein Regal');

// The pile is called SuB in the navigation and by the person who owns it.
Assert::same('the unread pile keeps its own name', heading(['status' => 'unread']), t('nav.unread'));
Assert::same('the other three are their status', heading(['status' => 'read']), t('status.read'));
Assert::same('reading too', heading(['status' => 'reading']), t('status.reading'));

// An author's shelf is that author, whatever else is set.
Assert::same(
    'an author outranks a status',
    heading(['author' => 'cornelia-funke', 'status' => 'unread'], 'Cornelia Funke'),
    'Cornelia Funke'
);

/* Sorting is not a selection. Clicking "Titel" narrows nothing, and a
 * heading that changed when you re-sorted would be saying the list had
 * become something else. */
Assert::same('sorting leaves the heading alone', heading(['sort' => 'title', 'dir' => 'asc']), t('shelf.all.books'));

/* A search is the most deliberate thing on the page - a status is picked from
 * four chips, an author from a list, but a term was typed - so it outranks
 * both. "Alle Bücher" over three results of three thousand was simply wrong.
 */
Assert::same(
    'a search says what was searched for',
    heading(['search' => 'milla']),
    t('shelf.found', ['term' => 'milla'])
);
Assert::same(
    'and it outranks a status',
    heading(['search' => 'milla', 'status' => 'unread']),
    t('shelf.found', ['term' => 'milla'])
);
Assert::same(
    'and an author',
    heading(['search' => 'milla', 'author' => 'cornelia-funke'], 'Cornelia Funke'),
    t('shelf.found', ['term' => 'milla'])
);
// An empty q= in the address is not a search, and must not become a heading.
Assert::same('an empty term is no search', heading(['search' => '   ']), t('shelf.all.books'));

Assert::group('Shelf heading: the browser tab is a different question');

/* "Alle Bücher – Mein Regal" is a fine title for a selection and a poor one
 * for a front page. What belongs in a tab, a bookmark and a search result for
 * the unfiltered shelf is the name of the shelf - and the layout drops the
 * suffix when the two match, so this comes out as plain "Mein Regal" rather
 * than the site name written twice. */
Assert::same('the front page is titled after the site', documentTitle([], t('shelf.all.books')), 'Mein Regal');
Assert::same(
    'a filtered one is titled after its selection',
    documentTitle(['status' => 'unread'], t('nav.unread')),
    t('nav.unread')
);
Assert::same(
    'an author page too',
    documentTitle(['author' => 'cornelia-funke'], 'Cornelia Funke'),
    'Cornelia Funke'
);

// A sort is still the front page, so the tab keeps saying so.
Assert::same('sorting does not retitle the tab', documentTitle(['sort' => 'title'], t('shelf.all.books')), 'Mein Regal');

Assert::same(
    'a search retitles the tab',
    documentTitle(['search' => 'milla'], t('shelf.found', ['term' => 'milla'])),
    t('shelf.found', ['term' => 'milla'])
);
Assert::same(
    'an empty one does not',
    documentTitle(['search' => ''], t('shelf.all.books')),
    'Mein Regal'
);

Assert::group('Every filter, not three of them');

/* Ten filters can be set and three of them reached the heading, so the other
 * seven all came out as "Alle Bücher". Measured on the running shelf: that
 * heading stood over one book under a genre, over six of a series, over 158
 * in one language and over the four with no cover. The same fault the search
 * had, at seven more addresses.
 */
Assert::same(
    'a genre is its name',
    heading(['tag' => 'high-fantasy'], null, ['tag' => 'High Fantasy']),
    'High Fantasy'
);
Assert::same(
    'a series is its name',
    heading(['series' => 'die-chronik-der-drachenlanze'], null, ['series' => 'Die Chronik der Drachenlanze']),
    'Die Chronik der Drachenlanze'
);
Assert::same('a language is the language', heading(['language' => 'ger']), t('lang.ger'));
Assert::same('books with no cover say so', heading(['cover' => 'no']), t('filter.cover.no'));
Assert::same('and books with one too', heading(['cover' => 'yes']), t('filter.cover.yes'));
Assert::same('the same for the ISBN', heading(['isbn' => 'no']), t('filter.isbn.no'));
Assert::same('and for the review', heading(['review' => 'yes']), t('filter.review.yes'));

// Reached from the dashboard, and the heading is the sentence that linked here.
Assert::same('a work list keeps its own words', heading(['missing' => 'genre']), t('stats.no.genre'));
Assert::same('all three of them', heading(['missing' => 'rating']), t('stats.no.rating'));

/* Order: something with a name of its own first, then a work list, then a
 * property a book has or has not, then the status, then the language. Only
 * one heading fits on a page, so the most particular one wins. */
Assert::same(
    'a series outranks a status',
    heading(['series' => 's', 'status' => 'unread'], null, ['series' => 'Alea Aquarius']),
    'Alea Aquarius'
);
Assert::same(
    'and an author outranks a series',
    heading(['author' => 'a', 'series' => 's'], 'Cornelia Funke', ['series' => 'Reckless']),
    'Cornelia Funke'
);
Assert::same(
    'a status outranks a language',
    heading(['status' => 'unread', 'language' => 'ger']),
    t('nav.unread')
);
Assert::same(
    'and a search still outranks everything',
    heading(['search' => 'milla', 'tag' => 't', 'status' => 'read'], null, ['tag' => 'Fantasy']),
    t('shelf.found', ['term' => 'milla'])
);

/* A slug naming nothing is handed back as it stands, which is what the author
 * lookup has always done. A shared link to a genre since taken out of use
 * names something the shelf has nothing under, and the slug over "0 Bücher"
 * says exactly that. "Alle Bücher" over an empty page would not, and that is
 * what stood there. */
Assert::same(
    'a genre that is gone still names itself',
    heading(['tag' => 'gibt-es-nicht-mehr'], null, ['tag' => 'gibt-es-nicht-mehr']),
    'gibt-es-nicht-mehr'
);
// Escaped where it is drawn, like every other value on the page.
Assert::true(
    'the heading goes through the escaping helper',
    str_contains(
        (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/index.php'),
        '<h1><?= e($heading) ?></h1>'
    )
);

/* And the tab follows the heading rather than a second list of filters. The
 * list was three filters long while the shelf had ten, so a genre page was
 * titled with the site name and headed with its genre. */
Assert::same(
    'a filtered page is titled by its selection',
    documentTitle(['tag' => 'x'], 'High Fantasy'),
    'High Fantasy'
);
Assert::same(
    'and only the whole shelf is titled by the site',
    documentTitle([], t('shelf.all.books')),
    'Mein Regal'
);

/* And the stars, once the filter could be set at all. Before it was wired up
 * this was the seventh filter that came out as "Alle Bücher". */
Assert::same('four stars and up says so', heading(['rating' => '4']), t('filter.rating.from', ['stars' => '4']));
Assert::same('and one star has its own line, singular', heading(['rating' => '1']), t('filter.rating.one'));
