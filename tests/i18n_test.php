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
