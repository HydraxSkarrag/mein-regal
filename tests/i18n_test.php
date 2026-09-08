<?php
declare(strict_types=1);

use App\Core\Formatter;
use App\Core\Translator;

Assert::group('Translator');

$de = new Translator('de');
$en = new Translator('en');

Assert::same('German locale', $de->locale(), 'de');
Assert::same('English locale', $en->locale(), 'en');
Assert::same('an unsupported locale falls back', (new Translator('fr'))->locale(), 'de');
Assert::same('a regional tag is shortened', Translator::normalizeLocale('en-GB'), 'en');
Assert::same('an unknown key returns itself, not an empty string', $de->translate('no.such.key'), 'no.such.key');

Assert::group('Translator::negotiate');

Assert::same('plain German header', Translator::negotiate('de-DE,de;q=0.9'), 'de');
Assert::same('plain English header', Translator::negotiate('en-GB,en;q=0.9'), 'en');
Assert::same('English preferred over German by quality', Translator::negotiate('de;q=0.4,en;q=0.9'), 'en');
Assert::same('an unsupported language falls back', Translator::negotiate('fr-FR,fr;q=0.9'), 'de');
Assert::same('an empty header falls back', Translator::negotiate(''), 'de');
Assert::same('a missing header falls back', Translator::negotiate(null), 'de');

Assert::group('Formatter: formats move with the language');

$deFormat = new Formatter('de');
$enFormat = new Formatter('en');

Assert::same('German thousands separator', $deFormat->number(723783), '723.783');
Assert::same('English thousands separator', $enFormat->number(723783), '723,783');
Assert::same('a null number is blank, not zero', $deFormat->number(null), '');

Assert::true('German money puts the symbol last', str_ends_with($deFormat->money(12.9), '€'));
Assert::true('English money puts the symbol first', str_starts_with($enFormat->money(12.9), '€'));

Assert::same('German date', $deFormat->date('2022-02-23'), '23.02.2022');
Assert::true('English date is not the German one', $enFormat->date('2022-02-23') !== '23.02.2022');
Assert::same('a null date is blank', $deFormat->date(null), '');
Assert::same('machine-readable date', $deFormat->iso('2022-02-23'), '2022-02-23');

Assert::group('Language files stay in step');

$deMessages = require dirname(__DIR__) . '/app/lang/de.php';
$enMessages = require dirname(__DIR__) . '/app/lang/en.php';

// A key that exists in one file and not the other is how half-translated
// interfaces happen; catching it here is cheaper than spotting it on a page.
Assert::same('no key is missing from English', array_keys(array_diff_key($deMessages, $enMessages)), []);
Assert::same('no key is orphaned in English', array_keys(array_diff_key($enMessages, $deMessages)), []);
Assert::same('no message is empty', array_keys(array_filter($deMessages, static fn ($v) => trim((string) $v) === '')), []);
Assert::same('no English message is empty', array_keys(array_filter($enMessages, static fn ($v) => trim((string) $v) === '')), []);

// Placeholders must match, or a translated string silently loses its number.
$mismatched = [];
foreach ($deMessages as $key => $german) {
    preg_match_all('/\{(\w+)\}/', (string) $german, $a);
    preg_match_all('/\{(\w+)\}/', (string) ($enMessages[$key] ?? ''), $b);
    sort($a[1]);
    sort($b[1]);
    if ($a[1] !== $b[1]) {
        $mismatched[] = $key;
    }
}
Assert::same('placeholders match across languages', $mismatched, []);

Assert::group('Half stars survive the font');

/*
 * The obvious character for a half star, U+2BEA, is missing from most system
 * fonts and arrived on the page as a question mark. The detail page draws its
 * half with CSS, but a dropdown option and an escaped label cannot hold
 * markup, so those get plain text instead.
 */
Assert::same('a whole rating', Formatter::starsText(4), '★★★★');
Assert::same('a half one', Formatter::starsText(3.5), '★★★½');
Assert::same('rounded to the nearest half', Formatter::starsText(3.7), '★★★½');
Assert::same('and 3.8 rounds up to four', Formatter::starsText(3.8), '★★★★');
Assert::same('the top of the scale', Formatter::starsText(5), '★★★★★');
Assert::same('unrated is null, not an empty row of stars', Formatter::starsText(null), null);
Assert::same('and so is zero', Formatter::starsText(0), null);

// The character that caused the report must not come back anywhere.
foreach (['app/templates', 'public/js', 'public/css'] as $directory) {
    $found = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $directory)
    );
    foreach ($walk as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), "\u{2BEA}")) {
            $found[] = $file->getBasename();
        }
    }
    Assert::same('no unrenderable half star in ' . $directory, $found, []);
}

Assert::group('The language switch is optional');

/*
 * A shelf read in one language has no use for an EN/DE link. The flag is
 * absent from every config.php written before it existed, so its absence has
 * to mean "as before" - the switch stays on unless it is explicitly turned
 * off.
 */
Assert::true('absent means on', (new App\Core\Config([]))->bool('language_switcher', true));
Assert::true('true means on', (new App\Core\Config(['language_switcher' => true]))->bool('language_switcher', true));
Assert::same('false means off', (new App\Core\Config(['language_switcher' => false]))->bool('language_switcher', true), false);

// With the switch off the configured locale is what everyone gets, so it has
// to survive normalisation - a typo there must not silently become German.
Assert::same('the configured locale is used as is', Translator::normalizeLocale('en'), 'en');
Assert::same('a regional configured locale is shortened', Translator::normalizeLocale('de-AT'), 'de');

$base = file_get_contents(dirname(__DIR__) . '/app/templates/layout/base.php') ?: '';
$switch = strpos($base, '/language/');
$guard = strpos($base, 'if ($multilingual)');
Assert::true('the header link is present', $switch !== false);
Assert::true('and sits behind the flag', $guard !== false && $guard < $switch);

// Hiding the link is not enough on its own: the address it pointed at has to
// stop working too, or a bookmark still switches the language.
$controller = file_get_contents(dirname(__DIR__) . '/app/Controller/AuthController.php') ?: '';
Assert::true(
    'the route refuses when the switch is off',
    str_contains($controller, 'if (!$this->app->multilingual())')
);

// The sample config has to document the flag, or a second installation never
// learns it exists.
$sample = file_get_contents(dirname(__DIR__) . '/config.sample.php') ?: '';
Assert::true('the sample config offers it', str_contains($sample, "'language_switcher'"));

Assert::group('A language code the list has never heard of');

/*
 * The sources hand out MARC codes, and not only the two anybody thinks of:
 * the shelf holds gmh (Middle High German), mul (multilingual) and zxx ("no
 * linguistic content", which is what a wimmelbook gets). The edit form
 * offered German and English alone, so a book in any of the others had no
 * matching option, the browser showed the empty one, and saving the form
 * wiped the language without anybody touching that field.
 */
Assert::same('a translated code reads as a word', Formatter::language('ger'), 'Deutsch');
Assert::same('and so does an unusual one', Formatter::language('gmh'), 'Mittelhochdeutsch');

// An unknown code is shown as itself. "XYZ" tells a reader something;
// "lang.xyz" - the raw translation key - tells them nothing at all.
Assert::same('an unknown code stays readable', Formatter::language('xyz'), 'XYZ');

Assert::group('Language files: a key written twice loses one of them');

/* PHP array literals take the last of a repeated key and say nothing. So a
 * string can sit in the file, be read by nobody, and look perfectly correct
 * to anyone reviewing the diff.
 *
 * That is not hypothetical. 'shelf.all' was added for the shelf heading
 * without checking, twenty rows above an existing 'shelf.all' that labels the
 * filter chip - the chip won, the heading came out reading "Alle", and it
 * took a look at the deployed page to notice. Nothing failed; one of the two
 * lines was simply never there.
 *
 * So the file is read as text rather than as an array, because as an array
 * the evidence is already gone.
 */
foreach (['de', 'en'] as $locale) {
    $source = file_get_contents(PROJECT_ROOT . '/app/lang/' . $locale . '.php');
    preg_match_all("/^\s*'([^']+)'\s*=>/m", (string) $source, $matches);

    $seen = array_count_values($matches[1]);
    $twice = array_keys(array_filter($seen, static fn (int $n): bool => $n > 1));

    Assert::same($locale . '.php defines every key exactly once', $twice, []);
}

Assert::group('Language files: every key a template asks for exists');

/* A missing key shows as its own name - "book.title" printed where "Titel"
 * belongs. Nothing fails, nothing is logged, and the page renders; it is
 * simply wrong in one spot, which makes it the sort of thing found by whoever
 * happens to look at that spot. This one was found by the person using it.
 *
 * Only literal keys are checked. Plenty are built from a value - t('binding.'
 * . $book['binding']) - and those cannot be known from the source alone; they
 * are the reason the translator returns the key rather than an empty string
 * in the first place.
 */
$german = require PROJECT_ROOT . '/app/lang/de.php';
$missing = [];

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(PROJECT_ROOT . '/app/templates')
);
foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    // t('key') and nothing else: a quote followed by anything but a closing
    // one rules out concatenation.
    preg_match_all("/\bt\('([a-z0-9._]+)'\s*[,)]/i", $source, $found);
    foreach ($found[1] as $key) {
        if (!array_key_exists($key, $german)) {
            $missing[$key] = basename($file->getPathname());
        }
    }
}

Assert::same('no template asks for a key that is not there', $missing, []);

Assert::group('The three doors into adding a book each say what they are for');

/* A name alone is three labels; a name and a line about when to use it is a
 * choice. Worth an assertion because the descriptions are the whole point of
 * the desktop layout and are easy to lose in a later tidy-up - and because a
 * card rendering a bare key would be the loudest possible bug on that page.
 */
foreach (['camera', 'manual', 'blank'] as $door) {
    $name = t('scan.mode.' . $door);
    $hint = t('scan.mode.' . $door . '.hint');

    Assert::true($door . ' has a name', $name !== 'scan.mode.' . $door);
    Assert::true('and says what it is for', $hint !== 'scan.mode.' . $door . '.hint');
    Assert::true('in a sentence rather than a word', str_word_count($hint) > 4);
}

Assert::group('Deutsch liest sich wie Deutsch, nicht wie übersetztes Englisch');

/* The complaint that started this group: the German read like the English
 * sentence with German words dropped into it. "Umdrehen: aufsteigend" for a
 * sort toggle, "im Fuß" where a German says "in der Fußzeile",
 * "Befüllungsgrad der Felder" for how complete the records are. All three
 * parse. None is what anybody would have written in German first.
 *
 * Nothing here can judge a sentence. What it can do is hold on to every
 * wording that was already caught by a person, so the same one cannot come
 * back through a later tidy-up - which is the only part of this that a test
 * is actually good at. The list grows by one line each time somebody spots
 * another; that is the point of it.
 */
$german = require PROJECT_ROOT . '/app/lang/de.php';

$banned = [
    'Befüllungsgrad'     => 'Amtsdeutsch for "how complete the records are"',
    'im Fuß'             => 'a page has a Fußzeile, not a Fuß',
    'Autor oder Autorin' => 'the shelf says Autor:in everywhere else',
    'Umdrehen:'          => '"Turn round: ascending" is not a German tooltip',
    'hinterlegt'         => 'a cover is gespeichert, not hinterlegt',
    'Titelbild'          => 'the shelf calls it a Cover throughout',
];

foreach ($banned as $phrase => $why) {
    $hits = array_keys(array_filter(
        $german,
        static fn ($text): bool => str_contains((string) $text, $phrase)
    ));
    Assert::same('no "' . $phrase . '" - ' . $why, $hits, []);
}

Assert::group('German typography');

/* A dash in German is the en dash with spaces around it. The ASCII hyphen is
 * the English habit and came in with the English drafts; the em dash is the
 * American one and turned up once, in a single string, where every neighbour
 * had the right character - which is exactly how nobody notices.
 *
 * editor.hint is exempt: it prints Markdown syntax, so its "- Aufzählung" is
 * the character being demonstrated rather than a dash.
 */
$exempt = ['editor.hint'];

$hyphenated = [];
$emDashed   = [];
$dotted     = [];
$straight   = [];

foreach ($german as $key => $text) {
    $text = (string) $text;
    if (in_array($key, $exempt, true)) {
        continue;
    }
    if (str_contains($text, ' - ')) {
        $hyphenated[] = $key;
    }
    if (str_contains($text, '—')) {
        $emDashed[] = $key;
    }
    if (str_contains($text, '...')) {
        $dotted[] = $key;
    }
    if (str_contains($text, '"')) {
        $straight[] = $key;
    }
}

Assert::same('an aside uses the en dash, not a hyphen', $hyphenated, []);
Assert::same('and not the em dash either', $emDashed, []);
Assert::same('three dots are the ellipsis character', $dotted, []);
Assert::same('quotation marks are the German ones', $straight, []);

Assert::group('One word per thing');

/* "Ausgabe" means the printing - "Jahr der Ausgabe". It also used to mean the
 * binding, until that field was relabelled "Format" and one hint was left
 * behind explaining that "Taschenbücher" is "die Ausgabe". Two meanings for
 * one word in one interface, and the older of them no longer on any label.
 */
Assert::true(
    'the binding is called Format',
    str_contains($german['tags.field.hint'], 'ist das Format')
);
Assert::same('and the field label agrees', $german['book.binding'], 'Format');

// A heading that repeats one of the options underneath it labels nothing. The
// edit page had "Gelesen" over a dropdown whose first entry was "Gelesen".
Assert::true(
    'no group heading repeats a status below it',
    $german['edit.group.reading'] !== $german['status.read']
);

Assert::group('A setting that is present and empty is not a setting');

/* The sample config ships most keys present and empty - a list of every
 * option is easier to fill in than a list of the ones somebody thought to
 * mention - so "present but empty" is the normal state of a fresh
 * installation, and the default was reachable only by deleting the line.
 *
 * It showed on the project page. The sample says of repository_url "left
 * empty it points at the original repository"; what shipped was <a href="">,
 * a button that reloaded the page it was on. Both shelves had it, and so
 * would every installation that followed the sample.
 */
$config = new App\Core\Config(['repository_url' => '', 'site_name' => '']);

Assert::same(
    'an empty value falls back',
    $config->str('repository_url', 'https://github.com/HydraxSkarrag/mein-regal'),
    'https://github.com/HydraxSkarrag/mein-regal'
);
Assert::same('and so does an absent one', $config->str('nothing.here', 'Mein Regal'), 'Mein Regal');
Assert::same('a real value still wins', (new App\Core\Config(['site_name' => 'Das Regal']))->str('site_name', 'Mein Regal'), 'Das Regal');

/* Callers that mean "empty is a real answer" pass no default, and for them
 * nothing changes: an empty blog_url is what makes a shelf without a blog
 * contact nobody at all. */
Assert::same('empty stays empty where that is the answer', $config->str('repository_url'), '');
Assert::same('and an absent key too', $config->str('blog_url'), '');

// The page that started it: the link has to lead somewhere.
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/PageController.php');
Assert::true(
    'the project page offers the repository as the default',
    str_contains($controller, "str('repository_url', self::REPOSITORY)")
);
Assert::true('and that is a real address', str_starts_with(App\Controller\PageController::REPOSITORY, 'https://'));

/* The sample documents this reading, and the code now matches what it
 * promises rather than the other way round. */
$sample = (string) file_get_contents(PROJECT_ROOT . '/config.sample.php');
Assert::true(
    'the sample says empty means the original',
    str_contains($sample, 'left empty it points at the original repository')
);
