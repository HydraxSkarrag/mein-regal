<?php
declare(strict_types=1);

Assert::group('json_for_script');

/*
 * A browser ends a <script> element at the first "</script>" it sees, even
 * inside a JSON string. A book title containing one therefore closed the
 * JSON-LD block early and everything after it was parsed as HTML - a stored
 * cross-site scripting hole reachable from a book title, and visible on the
 * public page.
 */
$dangerous = '</script><img src=x onerror=alert(1)>';

$encoded = json_for_script(['name' => $dangerous]);
Assert::same('no raw closing script tag survives', str_contains($encoded, '</script>'), false);
Assert::same('nor a raw opening one', str_contains($encoded, '<img'), false);
// JSON_HEX_TAG replaces them outright, so no angle bracket is left at all.
Assert::same('no angle bracket is left in the output', str_contains($encoded, '<'), false);
Assert::same('they appear as escapes instead', str_contains($encoded, '\u003C'), true);

// It has to stay valid JSON, or the structured data is worthless.
$decoded = json_decode($encoded, true);
Assert::same('it is still valid JSON', is_array($decoded), true);
Assert::same('and the value round-trips exactly', $decoded['name'] ?? null, $dangerous);

// Safe inside an attribute too, so one helper covers both places. JSON's own
// delimiters are still quotes, so what matters is the value, not the frame.
$attribute = json_for_script(['q' => 'a"b\'c&d']);
foreach (["'" => 'apostrophe', '&' => 'ampersand'] as $char => $name) {
    Assert::same('the ' . $name . ' is escaped', str_contains($attribute, $char), false);
}
// Only JSON's own four delimiters remain; the quote inside the value became
// an escape sequence.
Assert::same('only the structural quotes are left', substr_count($attribute, '"'), 4);
Assert::same('the value\'s quote became an escape', str_contains($attribute, '\u0022'), true);
Assert::same('and that still decodes', json_decode($attribute, true)['q'] ?? null, 'a"b\'c&d');

Assert::same('umlauts are left readable', json_for_script(['t' => 'Rückkehr']), '{"t":"Rückkehr"}');
Assert::true('pretty printing is available', str_contains(json_for_script(['a' => 1], true), "\n"));

Assert::group('Templates never hand raw json_encode to a script block');

// The fix is only durable if the next script block cannot repeat the mistake.
$offenders = [];
$directory = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(dirname(__DIR__) . '/app/templates')
);
foreach ($directory as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (str_contains((string) file_get_contents($file->getPathname()), 'json_encode')) {
        $offenders[] = $file->getBasename();
    }
}
Assert::same('every template uses the safe helper', $offenders, []);

Assert::group('e() escapes what it must');

Assert::same('angle brackets', e('<b>'), '&lt;b&gt;');
Assert::same('double quotes, for attributes', e('a"b'), 'a&quot;b');
Assert::same('single quotes too', e("a'b"), 'a&#039;b');
Assert::same('ampersands', e('a&b'), 'a&amp;b');
Assert::same('null is empty, not "null"', e(null), '');
Assert::same('umlauts are left alone', e('Rückkehr'), 'Rückkehr');
