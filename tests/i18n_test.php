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
