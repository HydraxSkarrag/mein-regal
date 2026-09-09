<?php
/**
 * The sidebar gets what it reads.
 *
 * A partial is handed one array and sees nothing else, which is the point of
 * them - and the trap. The series facet was written, styled, tested against
 * the partial and shipped to two shelves, and never appeared: the controller
 * put seriesList and seriesTotal in the page's data and the page's compact()
 * did not name them, so the partial read two variables that were never there.
 * Both silently empty, both hidden by exactly the rule that says "no series,
 * no heading". Working code, correct rule, nothing on screen.
 *
 * Nothing about that is specific to series, so this checks the join itself:
 * every variable the sidebar reads and does not make for itself has to be in
 * the list that is handed to it.
 */
declare(strict_types=1);

/* Every test file is required into the same scope, so the names in here are
 * deliberately long: $made and $names belong to somebody else three files
 * further on, and a collision here surfaces as a type error over there. */

Assert::group('Every variable the sidebar reads is handed to it');

$sidebarPartial = (string) file_get_contents(PROJECT_ROOT . '/app/templates/partials/shelf_filters.php');
$shelfIndexSource   = (string) file_get_contents(PROJECT_ROOT . '/app/templates/shelf/index.php');

// What the partial makes for itself: assigned, or bound by a foreach, or the
// closure parameters and PHP's own superglobals.
$sidebarMade = [];
preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=[^=]/', $sidebarPartial, $sidebarAssigned);
preg_match_all('/as\s+\$([a-zA-Z_][a-zA-Z0-9_]*)/', $sidebarPartial, $sidebarBound);
// Both spellings of a closure, and both halves of a keyed foreach.
preg_match_all('/\bfn\s*\(([^)]*)\)|function\s*\(([^)]*)\)/', $sidebarPartial, $sidebarParams);
$sidebarParams[1] = array_merge($sidebarParams[1], $sidebarParams[2]);
preg_match_all('/=>\s*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $sidebarPartial, $sidebarKeyed);
foreach ($sidebarParams[1] as $sidebarParamList) {
    preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $sidebarParamList, $sidebarNames);
    $sidebarMade = array_merge($sidebarMade, $sidebarNames[1]);
}
$sidebarMade = array_merge(
    $sidebarMade,
    $sidebarAssigned[1],
    $sidebarBound[1],
    $sidebarKeyed[1],
    ['this', 'view']
);

preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $sidebarPartial, $sidebarUsed);

// The one list the page hands over, as written in the template.
preg_match('/\$filterData\s*=\s*compact\((.*?)\);/s', $shelfIndexSource, $sidebarCompact);
Assert::true('the page still hands over one named list', $sidebarCompact !== []);
$sidebarHanded = [];
preg_match_all("/'([a-zA-Z_][a-zA-Z0-9_]*)'/", $sidebarCompact[1] ?? '', $sidebarNames);
$sidebarHanded = $sidebarNames[1];

$sidebarMissing = [];
foreach (array_unique($sidebarUsed[1]) as $sidebarName) {
    if (in_array($sidebarName, $sidebarMade, true) || in_array($sidebarName, $sidebarHanded, true)) {
        continue;
    }
    $sidebarMissing[] = $sidebarName;
}
sort($sidebarMissing);
Assert::same('and nothing the sidebar reads is left out of it', $sidebarMissing, []);

/* The other direction is not an error - a page may hand over more than one
 * partial needs - so it is not asserted. This one only catches the silence. */
Assert::true('the series facet is in the list', in_array('seriesList', $sidebarHanded, true));
Assert::true('with its total', in_array('seriesTotal', $sidebarHanded, true));
Assert::true('and the reading dates that decide a sort', in_array('datedCount', $sidebarHanded, true));
