<?php
/**
 * No placeholder reaches the screen.
 *
 * The scanner is handed its wording as JSON, because the browser has to say
 * things while nothing is being fetched. Most of those strings are finished
 * sentences; a few still carry a {name}, because only the browser knows what
 * goes there - which volume, how many saved, which ISBN was typed.
 *
 * Handing one over and then not filling it in is invisible everywhere except
 * on the phone of the person reading "Zu {isbn} wurde nichts gefunden". That
 * is how it shipped: the message was given the ISBN on the server side, and
 * the browser's own copy of the same sentence was rendered without it and
 * used whenever the server had nothing to say.
 *
 * So: every {name} handed to the scanner has to be filled by the scanner.
 */
declare(strict_types=1);

Assert::group('Every placeholder handed to the scanner is filled by it');

$scanTemplate = (string) file_get_contents(PROJECT_ROOT . '/app/templates/scan/index.php');
$scannerJs    = (string) file_get_contents(PROJECT_ROOT . '/public/js/scanner.js');

// The one JSON block the scanner reads.
$start = strpos($scanTemplate, "id=\"scan-i18n\"");
Assert::true('the scanner still gets its wording as JSON', $start !== false);
$bundle = substr($scanTemplate, (int) $start);
$bundle = substr($bundle, 0, (int) strpos($bundle, '</script>'));

/* Each entry is  'key' => t('some.key' ...),  and what matters is whether a
 * placeholder is deliberately passed through. Rendering it is the only way
 * to know, so the keys are looked up and filled the way the page does it. */
preg_match_all(
    "/'([a-zA-Z]+)'\s*=>\s*t\(\s*'([a-z0-9.]+)'\s*(,\s*\[(.*?)\]\s*)?\)/s",
    $bundle,
    $entries,
    PREG_SET_ORDER
);
Assert::true('and the entries can still be read', count($entries) > 10);

$unfilled = [];
$unused   = [];
foreach ($entries as $entry) {
    [$whole, $jsName, $langKey] = [$entry[0], $entry[1], $entry[2]];

    $arguments = [];
    if (($entry[4] ?? '') !== '') {
        preg_match_all("/'([a-z_]+)'\s*=>\s*'(\{[a-z_]+\})'/i", $entry[4], $passed, PREG_SET_ORDER);
        foreach ($passed as $pair) {
            $arguments[$pair[1]] = $pair[2];
        }
    }

    $rendered = t($langKey, $arguments);
    if (!preg_match_all('/\{([a-z_]+)\}/i', $rendered, $left)) {
        continue;
    }

    foreach ($left[1] as $name) {
        /* Passed on purpose, so the browser has to put something there -
           either with fill(..., 'name', ...) or with the older spelling,
           .replace('{name}', ...). Both count as filling it in. */
        $filled = str_contains($scannerJs, "'" . $name . "'")
            || str_contains($scannerJs, "'{" . $name . "}'");
        if (!$filled) {
            $unfilled[] = $jsName . ' / {' . $name . '}';
        }
        if (!str_contains($scannerJs, 'text.' . $jsName)) {
            $unused[] = $jsName;
        }
    }
}

sort($unfilled);
sort($unused);
Assert::same('nothing is handed over with a hole in it', $unfilled, []);

/* A string nobody reads cannot be seen to be wrong. scan.not.a.book sat in
 * here with a {code} in it and no line of JavaScript that touched it - the
 * 422 branch shows the server's own wording and falls back to the invalid
 * one. Dead wording is where the next placeholder would have hidden. */
Assert::same('and nothing carrying one is unused', $unused, []);

Assert::group('The messages the server sends are filled in on the server');

$scan = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/ScanController.php');
foreach (['scan.nothing', 'scan.quota', 'scan.unreachable'] as $key) {
    Assert::true(
        $key . " is given its ISBN where it is sent",
        (bool) preg_match("/t\('" . preg_quote($key, '/') . "', \['isbn'/", $scan)
    );
}
