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
function heading(array $filters, ?string $author = null): string
{
    return (new ReflectionMethod(ShelfController::class, 'headingFor'))->invoke(null, $filters, $author);
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
