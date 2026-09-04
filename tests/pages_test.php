<?php
declare(strict_types=1);

use App\Repository\PageRepository;
use App\Repository\UserRepository;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('PageRepository: one text per language');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
(new UserRepository($pdo))->create('m@example.org', 'ein-langes-passwort', 'M');

$pages = new PageRepository($pdo);

Assert::same('nothing written yet', $pages->find(1, PageRepository::ABOUT, 'de'), null);

$pages->save(1, PageRepository::ABOUT, 'de', 'Über mein Regal', 'Mein Bücherregal.');
$pages->save(1, PageRepository::ABOUT, 'en', 'About my shelf', 'My bookshelf.');

Assert::same('the German text', $pages->find(1, PageRepository::ABOUT, 'de')['title'], 'Über mein Regal');
Assert::same('the English one is its own', $pages->find(1, PageRepository::ABOUT, 'en')['title'], 'About my shelf');
Assert::same('and its own body', $pages->find(1, PageRepository::ABOUT, 'en')['body'], 'My bookshelf.');

// No falling back between languages: a German paragraph under an English
// heading reads like a fault, and the empty state says plainly that nothing
// has been written.
Assert::same('an unwritten language stays empty', $pages->find(1, PageRepository::ABOUT, 'fr'), null);

$pages->save(1, PageRepository::ABOUT, 'de', 'Neuer Titel', 'Neuer Text.');
Assert::same('saving again replaces', $pages->find(1, PageRepository::ABOUT, 'de')['title'], 'Neuer Titel');
Assert::same('and leaves the other language alone', $pages->find(1, PageRepository::ABOUT, 'en')['title'], 'About my shelf');
Assert::same('without creating a second row', (int) $pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn(), 2);

Assert::same('both languages are reported as written', $pages->localesFor(1, PageRepository::ABOUT), ['de', 'en']);

// An empty body is not a written page - the editor's tick would otherwise
// promise a text that is not there.
$pages->save(1, PageRepository::ABOUT, 'en', 'About', null);
Assert::same('an emptied language drops off the list', $pages->localesFor(1, PageRepository::ABOUT), ['de']);

Assert::group('Formatter::stars');

Assert::same('nothing rated', App\Core\Formatter::stars(null), null);
Assert::same('zero is not a rating', App\Core\Formatter::stars(0), null);

$four = App\Core\Formatter::stars(4);
Assert::same('four full', $four['full'], 4);
Assert::same('no half', $four['half'], false);
Assert::same('one empty', $four['empty'], 1);
Assert::same('written as a whole number', $four['text'], '4');

$half = App\Core\Formatter::stars(4.5);
Assert::same('four and a half: four full', $half['full'], 4);
Assert::same('plus the half', $half['half'], true);
Assert::same('and none left over', $half['empty'], 0);
Assert::same('written with a comma', $half['text'], '4,5');

Assert::same('a stray value rounds to the nearest half', App\Core\Formatter::stars(3.3)['text'], '3,5');
Assert::same('and cannot exceed five', App\Core\Formatter::stars(9)['full'], 5);
Assert::same('a string from the database works too', App\Core\Formatter::stars('2.5')['text'], '2,5');

Assert::group('Legal pages fall back to whichever language exists');

/*
 * The about page says "not written yet" when a language is missing, and that
 * is right: a German paragraph under an English heading reads like a fault.
 * An Impressum cannot do that. Missing because the interface happens to be in
 * English is still missing, so the legal pages show the one language they
 * have and say which it is.
 */
$pages->save(1, PageRepository::IMPRINT, 'de', 'Impressum', 'Angaben gemäß § 5 DDG.');

$fallback = $pages->findAnyLocale(1, PageRepository::IMPRINT, 'en');
Assert::same('an English reader still gets the Impressum', $fallback['title'] ?? null, 'Impressum');
Assert::same('and is told which language it is in', $fallback['locale'] ?? null, 'de');

$pages->save(1, PageRepository::IMPRINT, 'en', 'Imprint', 'Operator details.');
Assert::same(
    'once translated, the asked-for language wins',
    $pages->findAnyLocale(1, PageRepository::IMPRINT, 'en')['title'],
    'Imprint'
);

Assert::same(
    'a page nobody has written at all is still null',
    $pages->findAnyLocale(1, PageRepository::PRIVACY, 'en'),
    null
);

// An emptied body must not be offered as a fallback; that would show a blank
// page instead of the notice saying the text is missing.
$pages->save(1, PageRepository::PRIVACY, 'de', 'Datenschutz', null);
Assert::same(
    'an empty body does not count as written',
    $pages->findAnyLocale(1, PageRepository::PRIVACY, 'en'),
    null
);

Assert::group('The seeded legal texts');

$config = new App\Core\Config(['site_name' => 'Testregal']);
$seeded = App\Content\DefaultPages::all($config, 'Erika Mustermann', 'post@example.org');

Assert::same('both legal pages are seeded', array_keys($seeded), ['imprint', 'privacy']);

// The two details the application already has when it seeds these: the name
// and address of the account being created. Asking for them a second time in
// a configuration file is how two answers start to disagree.
Assert::true(
    'the new account is the operator',
    str_contains($seeded['imprint']['body'], 'Erika Mustermann')
);
Assert::true(
    'and its address is the contact',
    str_contains($seeded['imprint']['body'], 'post@example.org')
);

// The postal address and the host cannot be guessed from anything. They are
// marked rather than left blank: an empty line in an Impressum reads like a
// formatting slip and survives for years.
Assert::true(
    'the street is marked as missing',
    str_contains($seeded['imprint']['body'], '⚠')
);
Assert::true(
    'so is the hosting company, in the privacy policy',
    str_contains($seeded['privacy']['body'], '⚠')
);

// The section exists whether or not it applies, because somebody who has
// never heard of it is exactly who needs to be told - and it says how to
// remove itself.
Assert::true(
    'the MStV section is always there',
    str_contains($seeded['imprint']['body'], '18 Abs. 2 MStV')
);
Assert::true(
    'and explains when it can go',
    str_contains($seeded['imprint']['body'], 'gelöscht werden')
);

// The whole reason these moved out of the templates: no hosting company may
// be named in the source, or every installation publishes a false statement.
foreach ($seeded as $slug => $page) {
    Assert::same(
        'no hosting company is baked into ' . $slug,
        stripos($page['body'], 'all-inkl'),
        false
    );
}

// Seeded with nothing at all - the command line creates an account without
// touching the pages, and the editor offers the text when one is opened.
$empty = App\Content\DefaultPages::all(new App\Core\Config([]));
Assert::true(
    'a missing operator is marked, not silently blank',
    str_contains($empty['imprint']['body'], '⚠')
);

// The seeds are markup source, not HTML, and have to survive the renderer.
$rendered = App\Core\Text::prose($seeded['privacy']['body']);
Assert::true('the seeded privacy policy renders headings', str_contains($rendered, '<h2>'));
Assert::true('and its list of cookies', str_contains($rendered, '<ul>'));
Assert::same('with no stray markup characters left', str_contains($rendered, '##'), false);

// A hard-wrapped source line inside a paragraph would arrive in the editor
// already broken at eighty columns, and render with a line break at every
// one of them. The address is the exception, and keeps its breaks.
Assert::true(
    'paragraphs arrive as one line',
    str_contains($seeded['imprint']['body'], 'nach den allgemeinen Gesetzen verantwortlich')
);
Assert::true(
    'but the address keeps its line breaks',
    str_contains($seeded['imprint']['body'], "Erika Mustermann\n⚠ Straße und Hausnummer eintragen")
);
Assert::true(
    'and list items stay on their own lines',
    str_contains($seeded['privacy']['body'], "\n- **Sprach-Cookie**")
);

Assert::group('The public statistics switch');

/*
 * On unless somebody says otherwise, and an old config.php that predates the
 * option must keep behaving as it did.
 */
Assert::true('absent means on', (new App\Core\Config([]))->bool('public_stats', true));
Assert::true('explicitly on', (new App\Core\Config(['public_stats' => true]))->bool('public_stats', true));
Assert::same('explicitly off', (new App\Core\Config(['public_stats' => false]))->bool('public_stats', true), false);
// A default of false must not be flipped by an absent key either.
Assert::same('an absent key never overrides the default', (new App\Core\Config([]))->bool('nothing', false), false);

Assert::group('The seeded texts say they are a draft');

$draft = App\Content\DefaultPages::all(
    new App\Core\Config(['site_name' => 'Testregal']),
    'Erika Mustermann',
    'post@example.org'
);

// A legal page that looks finished is read as finished - by its operator
// most of all, who has every reason to want it to be.
foreach ($draft as $slug => $page) {
    Assert::true('the ' . $slug . ' opens by saying it is a draft', str_starts_with($page['body'], '> **Dieser Text ist ein Entwurf.**'));
    Assert::true('and that it is not legal advice: ' . $slug, str_contains($page['body'], 'keine Rechtsberatung'));
    Assert::true('and how to remove the notice: ' . $slug, str_contains($page['body'], 'gelöscht werden'));
}

// Three claims the application cannot check from the inside. They read as
// statements of fact and are in truth tasks.
Assert::true(
    'the privacy policy names what has to be verified',
    str_contains($draft['privacy']['body'], 'Auftragsverarbeitung besteht, und dass die Seite')
);

Assert::group('The seeded texts describe this installation');

// The shelf shows covers from Google Books and Open Library, downloaded and
// served from its own server. The imprint used to say they came from the
// publishers, which was written before any of it was built.
Assert::true(
    'the real cover sources are named',
    str_contains($draft['imprint']['body'], 'Google Books und Open Library')
);
Assert::same(
    'and no longer credits the publishers for them',
    str_contains($draft['imprint']['body'], 'von den Verlagen zur Verwendung bereitgestellt'),
    false
);

// With the switch turned off nothing ever writes the language cookie, and a
// policy listing one describes processing that does not happen.
$oneLanguage = App\Content\DefaultPages::all(
    new App\Core\Config(['site_name' => 'Testregal', 'language_switcher' => false]),
    'Erika Mustermann',
    'post@example.org'
);
Assert::true('two cookies with the switch on', str_contains($draft['privacy']['body'], 'setzt zwei Cookies'));
Assert::true('one without it', str_contains($oneLanguage['privacy']['body'], 'setzt ein Cookie,'));
Assert::same(
    'and the language cookie is not described',
    str_contains($oneLanguage['privacy']['body'], 'Sprach-Cookie'),
    false
);

// The list has to survive the renderer as a list.
$html = App\Core\Text::prose($draft['privacy']['body']);
Assert::true('the cookies render as list items', str_contains($html, '<li><strong>Sitzungs-Cookie</strong>'));
Assert::same('with no escape sequence left in the text', str_contains($html, 'u{'), false);

Assert::group('The seeded privacy policy describes this program');

$seed = App\Content\DefaultPages::all(
    new App\Core\Config(['site_name' => 'Testregal']),
    'Erika Mustermann',
    'post@example.org'
)['privacy']['body'];

// The session starts in the constructor - the login form needs a CSRF token
// and there is nowhere else to keep one - so the cookie is set for everybody,
// not on signing in. Checked against a live installation; the text used to
// describe a different program.
Assert::true(
    'the session cookie is described as set on every visit',
    str_contains($seed, 'wird bei jedem Besuch gesetzt')
);
Assert::same(
    'and not as something the login does',
    str_contains($seed, 'nur nach dem Anmelden'),
    false
);

// "Deleted after seven days" was in here with nothing behind it. The hoster
// decides, and Art. 13(2)(a) allows naming the criterion when the period
// cannot be given.
Assert::same('no invented retention period', str_contains($seed, 'sieben Tagen gelöscht'), false);
Assert::true(
    'the criterion is given instead',
    str_contains($seed, 'Die Speicherdauer bestimmt der Hoster')
);

// The one place data goes to a third country, and it was not mentioned at all.
Assert::true(
    'the lookups have their own section',
    str_contains($seed, '## Abfragen bei Buchdatenbanken')
);
Assert::true('naming what is sent', str_contains($seed, 'ausschließlich die ISBN'));
Assert::true('and that a visit does not trigger them', str_contains($seed, 'nicht statt, wenn jemand'));

// Which authority is competent follows from the operator's address, so it is
// marked like every other thing the application cannot know.
Assert::true('the supervisory authority is asked for', str_contains($seed, 'Aufsichtsbehörde'));
Assert::same('nothing is left unsubstituted', str_contains($seed, '{{'), false);
