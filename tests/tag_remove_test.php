<?php
/**
 * Removing a tag in one press, and taking it back in the same place.
 *
 * The × led to a page asking "remove?", and that page guarded nothing:
 * removing only hides a tag, every link is kept, and the book count it showed
 * was already beside the ×. What it cost was two page loads per tag and a
 * landing at the top of a list of three hundred and eighty - while clearing
 * out "collection:Forgotten Realms" and the rest of what an import left.
 *
 * The safety moved from before to after. The row turns into "removed" with a
 * button that takes it back, where the × was pressed.
 */
declare(strict_types=1);

use App\Core\Formatter;
use App\Core\View;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

$view = new View(PROJECT_ROOT . '/app/templates');
$view->share('formatter', new Formatter('de'));
$view->share('view', $view);

$tag = static fn (int $id, string $name, ?string $dropped = null, string $kind = 'label'): array => [
    'id' => $id, 'name' => $name, 'slug' => 'x' . $id, 'kind' => $kind,
    'dropped_at' => $dropped, 'book_count' => 3,
];

Assert::group('A row in use');

$live = $view->render('partials.tag_row', ['tag' => $tag(7, 'collection:Ravenloft')]);

Assert::true('the × removes at once, posting', str_contains($live, 'formaction="/admin/tags/7/remove"'));
Assert::true('and is a button, not a link to a page that asks', !str_contains($live, '<a '));

/* Not a button of the genre form it sits in. A submit button there would be
 * the form's default: Enter in the list would remove the first tag instead of
 * saving, and a removal would post every unsaved tick along with it. */
Assert::true('it belongs to the form outside the list', str_contains($live, 'form="tag-actions"'));

Assert::true('the genre field is still there', str_contains($live, 'name="genre[7]" value="0"'));
Assert::true(
    'and a screen reader hears which tag, not "remove" three hundred times',
    str_contains($live, 'aria-label="„collection:Ravenloft“ entfernen"')
);

$hostile = $view->render('partials.tag_row', ['tag' => $tag(8, '<b>Fett</b>')]);
Assert::true('a name is text, in the label and in the button', !str_contains($hostile, '<b>'));

Assert::group('The row a removal leaves');

$gone = $view->render('partials.tag_row', ['tag' => $tag(7, 'collection:Ravenloft', '2026-09-13 10:00:00')]);

Assert::true('it says what happened', str_contains($gone, '„collection:Ravenloft“ entfernt'));
Assert::true('and takes it back from where it stands', str_contains($gone, 'formaction="/admin/tags/7/restore"'));
Assert::true('through the same form outside the list', str_contains($gone, 'form="tag-actions"'));
Assert::true('under the id the address scrolls to', str_contains($gone, 'id="tag-row-7"') && str_contains($live, 'id="tag-row-7"'));

/* The genre form saves exactly the tags it mentions. A removed row that still
 * carried its field would be one more tag the save speaks about, for a tag
 * that is not in use. */
Assert::true('it carries no genre field, so a save passes it by', !str_contains($gone, 'name="genre['));

Assert::group('The list, reached without a script');

$page = static function (int $removedId) use ($view, $tag): string {
    return $view->render('admin.tags', [
        'tags' => [
            $tag(1, 'Abenteuer', null, 'genre'),
            $tag(2, 'collection:Forgotten Realms', '2026-09-13 10:00:00'),
            $tag(3, 'Längst entfernt', '2026-01-01 10:00:00'),
            $tag(4, 'Fantasy'),
        ],
        'countLine'   => '1 von 2 sind Genres',
        'removedId'   => $removedId,
        'fieldValues' => [],
        'notation'    => 0,
        'error'       => '',
        'csrfField'   => '<input type="hidden" name="_csrf" value="t">',
    ]);
};

$justRemoved = $page(2);
// The select boxes above name every tag too, so positions count from the list.
$listStarts = (int) strpos($justRemoved, 'class="tag-sort"');
$listEnds = strpos($justRemoved, '</ul>', $listStarts);
$undoAt = strpos($justRemoved, 'class="tag-undo"');

Assert::true('the tag just removed stands in the list, as its undo', $undoAt !== false && $undoAt > $listStarts && $undoAt < $listEnds);
Assert::true(
    'in its alphabetical place, between Abenteuer and Fantasy',
    strpos($justRemoved, 'Abenteuer', $listStarts) < $undoAt && $undoAt < strpos($justRemoved, 'Fantasy', $listStarts)
);
Assert::same('and nowhere else on the page', substr_count($justRemoved, 'collection:Forgotten Realms'), 1);
Assert::true('the select boxes do not offer it', !str_contains($justRemoved, '<option value="2"'));
Assert::true('an older removal stays below', strpos($justRemoved, 'Längst entfernt') > $listEnds);

$later = $page(0);
Assert::true('on the next visit it is simply one of the removed', !str_contains($later, 'class="tag-undo"'));
Assert::true('down there with the others', strpos($later, 'collection:Forgotten Realms') > strpos($later, 'id="tag-actions"'));

/* The form every × submits comes after the genre form closes. Inside it, the
 * browser would have to make sense of a form in a form, and none has to. */
$genreFormEnds = strpos($later, '</form>', (int) strpos($later, 'action="/admin/tags">'));
$actionsAt = strpos($later, '<form id="tag-actions"');
Assert::true('the form the buttons belong to is outside the genre form', $actionsAt !== false && $actionsAt > $genreFormEnds);
Assert::true('and carries the token', str_contains($later, '<form id="tag-actions" method="post" action="/admin/tags"><input type="hidden" name="_csrf"'));

Assert::group('No page asks first, except where it cannot be undone');

$routes = (string) file_get_contents(PROJECT_ROOT . '/public/index.php');
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/TagController.php');

Assert::true('removing is a post and nothing else', str_contains($routes, "post('/admin/tags/{id}/remove'"));
Assert::true('the page that asked is gone', !str_contains($routes, "get('/admin/tags/{id}/remove'") && !str_contains($controller, 'confirmRemove'));
Assert::true('deleting for good still asks', str_contains($routes, "get('/admin/tags/{id}/purge', \$tags->confirmPurge(...))"));

/* Back to the row, not to the top: the top of this screen is the one place
 * nobody working down the list is looking. */
Assert::true('a browser comes back scrolled to the row', str_contains($controller, "'#tag-row-' . \$tagId"));
Assert::true('and a script gets the row itself', str_contains($controller, "'partials.tag_row', ['tag' => \$tag]"));

Assert::group('Asking twice does no harm');

/* The script hands a request to the form when the answer goes missing, so a
 * removal that did arrive may arrive again. It must change nothing the second
 * time - in particular not the moment it was removed. */
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');
$tags = new TagRepository($pdo);

$junk = $tags->findOrCreate(1, 'collection:Ravenloft');
$pdo->exec("UPDATE tags SET dropped_at = '2026-09-13 10:00:00' WHERE id = " . $junk);
$tags->drop(1, $junk);
Assert::same('removing again keeps the first moment', $tags->find(1, $junk)['dropped_at'], '2026-09-13 10:00:00');

$tags->restore(1, $junk);
$tags->restore(1, $junk);
Assert::same('restoring again leaves it restored', $tags->find(1, $junk)['dropped_at'], null);

Assert::group('The script');

$script = (string) file_get_contents(PROJECT_ROOT . '/public/js/tag-admin.js');
Assert::true('it stops the navigation', str_contains($script, 'event.preventDefault()'));
Assert::true(
    'and on any failure hands over to the form, pointed where the button points',
    str_contains($script, 'form.action = button.formAction;')
        && str_contains($script, 'HTMLFormElement.prototype.submit.call(form)')
);
Assert::true('only on this screen', str_contains($controller, "'scripts' => ['/js/tag-admin.js']"));

/* A second tags.js already exists: the genre picker on the edit form. The two
 * are different screens and must not share a name. */
Assert::true(
    'and it is not the genre picker of the edit form',
    str_contains((string) file_get_contents(PROJECT_ROOT . '/public/js/tags.js'), 'Picking a genre from the ones that already exist')
);
