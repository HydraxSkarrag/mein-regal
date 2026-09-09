<?php
/**
 * The cover search button, and what it must not take with it.
 *
 * It posted a form of its own and the answer was a redirect, so pressing it
 * halfway through editing threw away everything typed and not yet saved. A
 * button that goes and fetches a picture has no business discarding a
 * paragraph of notes, and there is no way to get it back.
 *
 * Two things came out of that: the same request answers JSON when the page
 * asks with fetch, so the cover block is swapped where it stands; and the
 * button also sits on the book page, where a missing cover is what you
 * actually notice, and comes back there rather than dropping you into a form.
 */
declare(strict_types=1);

$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/BookController.php');
$script = (string) file_get_contents(PROJECT_ROOT . '/public/js/edit.js');
$editPage = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/edit.php');
$bookPage = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/detail.php');

Assert::group('Nothing is thrown away to fetch a picture');

Assert::true(
    'the endpoint answers a page or JSON, depending on who asked',
    str_contains($controller, '$request->wantsJson()')
);
Assert::true(
    'and the script stops the form from navigating',
    str_contains($script, 'event.preventDefault()')
);

/* The button stays a submit button in a real form. That is the whole of the
   fallback: with scripting off nothing listens, the form posts, the endpoint
   redirects, and the page behaves exactly as it did before any of this. */
Assert::true(
    'the button is still a submit in a form that exists',
    str_contains($editPage, 'type="submit" form="cover-search"')
        && str_contains($editPage, 'id="cover-search" method="post"')
);

Assert::group('Coming back where it was pressed');

/* Two fixed addresses built here, never one posted along: a return address
   off a form is somebody else's redirect. */
Assert::true(
    'the destination is built from the slug, not from the form',
    str_contains($controller, "\$request->post('from') === 'book' ? '' : '/edit'")
);
Assert::true(
    'and the book page says where it is pressing from',
    str_contains($bookPage, 'name="from" value="book"')
);

/* Only where there is nothing to see. Replacing a cover that is already there
   is an edit, and the edit page has every way of doing it. */
Assert::true(
    'the button appears on a book with no cover and an ISBN',
    str_contains($bookPage, "\$cover === null && (\$book['isbn13'] ?? null) !== null")
);

Assert::group('One copy of the block, and a button that leads somewhere');

/* The edit page and the reply both draw the cover block. Rendered twice from
   one partial, or they drift - and the copy nobody looks at is the one that
   goes wrong. */
Assert::true(
    'the endpoint renders the partial',
    str_contains($controller, "'partials.cover_current'")
);
Assert::true(
    'and so does the page, instead of holding its own copy',
    str_contains($editPage, "\$view->render('partials.cover_current'")
        && !str_contains($editPage, 'class="cover-current"')
);

/* A cover that arrives without a page reload brings a remove button with it,
   and a button whose form is not on the page does nothing at all. So the
   delete form is rendered whether or not there is a cover yet. */
$formAt = strpos($editPage, 'id="cover-delete"');
Assert::true('the delete form is there', $formAt !== false);
Assert::true(
    'and not behind a test for a cover that may arrive later',
    !str_contains($editPage, "<?php if (\$cover !== null): ?>\n<form id=\"cover-delete\"")
);
