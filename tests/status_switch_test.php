<?php
/**
 * Read or unread, in one press.
 *
 * The scanner carries a single switch for a whole run, it sits on the screen
 * you leave before the camera starts, and it remembers what it was told weeks
 * ago. So a run put away under the wrong one is not a slip of the finger, it
 * is the arrangement working as built.
 *
 * Two answers, and they are the two halves of the same thing: the card now
 * says what it is about to do and can be pressed to change it, so the mistake
 * is visible where it happens; and a tile carries the switch, so a run of
 * books that already went in wrong is a screen of covers and a run of clicks
 * rather than four steps per book.
 */
declare(strict_types=1);

use App\Controller\BookController;

Assert::group('Where a tile press comes back to');

/* The address arrives in the form, because only the page knows which filters
 * and which page number somebody is standing on. It is taken apart and built
 * again from the parameters the shelf actually has - a return address off a
 * form is otherwise somebody else's redirect.
 */
$backTo = static function (string $back): string {
    $method = new ReflectionMethod(BookController::class, 'shelfUrl');

    return $method->invoke(null, $back);
};

Assert::same('the filters and the page survive', $backTo('status=unread&sort=recent&page=2'), '/?status=unread&sort=recent&page=2');
Assert::same('a leading question mark is no obstacle', $backTo('?tag=fantasy'), '/?tag=fantasy');
Assert::same('nothing means the shelf', $backTo(''), '/');

// Anything the shelf does not have is dropped rather than carried along.
Assert::same('an unknown parameter does not travel', $backTo('status=read&utm_source=woanders'), '/?status=read');
Assert::same('and an empty one does not either', $backTo('status=&sort=title'), '/?sort=title');

/* The one that matters: a whole address in the field. It is parsed as a query
 * string, so the host ends up as the name of a parameter nobody knows, and
 * nothing is kept. The path is never taken from the form at all.
 */
foreach ([
    'https://evil.example.org/?q=x',
    '//evil.example.org/',
    '/admin/data',
    'javascript:alert(1)',
] as $forged) {
    Assert::true(
        'a forged return address leads to the shelf: ' . $forged,
        str_starts_with($backTo($forged), '/?') || $backTo($forged) === '/'
    );
    Assert::true(
        'and never off this site: ' . $forged,
        !str_contains($backTo($forged), 'evil.example.org')
    );
}

Assert::group('The switch on a tile');

$page = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/index.php');
$routes = (string) file_get_contents(PROJECT_ROOT . '/public/index.php');
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/BookController.php');

Assert::true('the address exists', str_contains($routes, "post('/book/{slug}/status'"));
Assert::true('and only for somebody signed in', str_contains($controller, '$this->app->requireSignIn()'));

/* A form inside an anchor is not markup any browser has to make sense of, and
 * the press must not also open the book. So the form is a sibling of the tile
 * link, which is why the tile and not the link is the positioned ancestor. */
$linkEnds = strpos($page, '</a>', (int) strpos($page, '<li class="book">'));
$formAt = strpos($page, 'class="tile-status"');
Assert::true('the form is outside the tile link', $formAt !== false && $formAt > $linkEnds);

$stylesheet = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');
Assert::true('and the tile carries the positioning', str_contains($stylesheet, '.book { position: relative; }'));

/* Drawn on hover, and on focus so it can still be reached from the keyboard.
 * A circle on every one of sixty covers is a lot of furniture for a control
 * used in bursts.
 *
 * Where there is no hover it is not drawn at all, rather than drawn
 * invisibly. An invisible button still takes the tap: aiming at the top left
 * of a cover would set the status instead of opening the book. */
Assert::true(
    'it appears on hover and on focus',
    str_contains($stylesheet, ".book:hover .tile-status button,
.tile-status button:focus-visible { opacity: 1; }")
);
Assert::true(
    'and is absent, not invisible, where nothing hovers',
    (bool) preg_match('/@media \(hover: none\) \{\s*\.tile-status \{ display: none; \}/', $stylesheet)
);

/* The button names what it will do, not what the book is. That is what keeps
 * it honest for a book that is being read now or was abandoned: it offers
 * "als gelesen" and does exactly that, instead of pretending the shelf has
 * two states. */
Assert::true(
    'the target is read unless the book already is',
    str_contains($page, "\$wantsRead = \$book['reading_status'] !== 'read';")
);
Assert::true(
    'and it is posted, not worked out again on arrival',
    str_contains($page, '<input type="hidden" name="status" value="<?= $wantsRead ? \'read\' : \'unread\' ?>">')
);

// Only the owner sees it, and only the owner gets the script.
Assert::true('the switch is behind the sign-in', str_contains($page, "<?php if (\$signedIn): ?>\n        <?php /* Outside the link"));

$shelfController = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ShelfController.php');
Assert::true(
    'and so is the script that saves the reload',
    str_contains($shelfController, "'scripts'   => \$signedIn ? ['/js/shelf.js'] : []")
);

/* Without scripting the form posts and the endpoint redirects, which is the
 * whole fallback. The script only saves the reload - and hands back to the
 * form if anything goes wrong. */
$script = (string) file_get_contents(PROJECT_ROOT . '/public/js/shelf.js');
Assert::true('the script stops the navigation', str_contains($script, 'event.preventDefault()'));
Assert::true('and falls back to the form on any failure', str_contains($script, 'form.submit()'));

Assert::group('The switch on the scan card');

/* It is the same switch, not a second one. Pressing the card moves the
 * checkbox on the first screen and lets its own handler run, so what the
 * scanner remembers and what the card says cannot drift apart. */
$scanner = (string) file_get_contents(PROJECT_ROOT . '/public/js/scanner.js');

Assert::true('the card says what will happen', str_contains($scanner, 'id="result-status"'));
Assert::true(
    'pressing it moves the one switch there is',
    str_contains($scanner, 'readToggle.checked = !readToggle.checked;')
);
Assert::true(
    'through that switch\'s own handler, so nothing drifts',
    str_contains($scanner, "readToggle.dispatchEvent(new Event('change'));")
);
Assert::true(
    'and it says which state it is in',
    str_contains($scanner, "aria-pressed=\"' +\n          (readToggle.checked ? 'true' : 'false')")
);
