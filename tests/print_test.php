<?php
/**
 * What comes out of the printer.
 *
 * A book's page printed as two sheets, the second one carrying a back link,
 * the footer and nothing else. Three things at once did that.
 *
 * An A4 page box is 210mm wide - 794 pixels at 96dpi - and the browser's
 * usual margin is 0.4in a side, which leaves about 717. The phone layout
 * starts at 720. So every A4 print got the phone: the bar that is fixed to
 * the bottom of a screen across the foot of every sheet, and 148 pixels of
 * height held clear for a thumb.
 *
 * On top of that body is min-height 100vh, and in print a vh is a sheet, so
 * the page was a full sheet tall whatever was on it and the footer sat at the
 * bottom of it.
 *
 * And the print dialogue leaves background graphics off by default, which is
 * fine for a page whose colour is decoration and not fine for a cover
 * stand-in, a bar chart or half a star, all of which are drawn as grounds.
 *
 * These are rules in one stylesheet with no markup of their own, so what is
 * checkable here is that they exist, that they sit where they win, and that
 * they name every screen rule they have to undo.
 */
declare(strict_types=1);

$printCss = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');

/* The print block, from its @media line to the brace that closes it. */
$printStart = strpos($printCss, '@media print {');
Assert::true('there is a print block at all', $printStart !== false);
$printBlock = substr($printCss, (int) $printStart);
$printBefore = substr($printCss, 0, (int) $printStart);

Assert::group('It sits where it wins');

/* No !important anywhere but the one hiding rule: the block is last in the
 * file, so plain source order settles every other override. Move it up and
 * the screen rules it undoes start winning again, silently. */
Assert::same(
    'nothing in the stylesheet comes after it',
    trim(substr($printBlock, strrpos($printBlock, '}') + 1)),
    ''
);

/* The phone rules it has to undo are the ones an A4 page box is narrow
 * enough to match. Each is named here so that adding another without a
 * counterpart in print is the kind of thing somebody notices. */
$phoneStart = strpos($printCss, '@media (max-width: 720px)');
Assert::true('the phone layout is what A4 lands in', $phoneStart !== false && $phoneStart < $printStart);

foreach (['.bottom-nav', '.container', '.site-footer'] as $undone) {
    Assert::true(
        $undone . ' is spoken for on paper',
        str_contains($printBlock, $undone)
    );
}

Assert::group('Nothing that is only there to be pressed');

foreach (['.bottom-nav', '.header-nav', '.skip-link', '.detail-actions', '.pager', '.searchbar', '.sidebar', 'button'] as $gone) {
    Assert::true($gone . ' is not printed', str_contains($printBlock, "\n  " . $gone . ",\n") || str_contains($printBlock, "\n  " . $gone . " {"));
}
/* The footer keeps the one line every installation keeps and loses the row
 * of links above it. The line is also held small on purpose: a tall footer
 * is what ends up alone on a sheet of its own. */
preg_match('/\n((?:  [^\n]*,\n)+  [^\n]*\{\n    display: none !important;)/', $printBlock, $hidden);
Assert::true('the credit is not among what is hidden', !str_contains($hidden[1] ?? '', '.site-credit'));
Assert::true('the row of links above it is', str_contains($hidden[1] ?? '', '.site-footer nav'));
Assert::true('and the credit carries no margin into the page break', str_contains($printBlock, '.site-credit { margin: 0; }'));

Assert::group('One sheet for one book');

Assert::true('the body stops being a screen tall', str_contains($printBlock, 'min-height: 0;'));

Assert::true('and stops being a column with a footer at the bottom of it', str_contains($printBlock, 'display: block;'));
Assert::true('the room kept for the bar goes with the bar', str_contains($printBlock, '.container { max-width: none; padding: 0 0 4px; }'));
Assert::true('at both ends of it', str_contains($printBlock, '.site-footer { margin-bottom: 0;'));

/* Sizes are in rem, and a rem is the html's size. The body carried 11pt for
 * a while, and the sheets came out the same to the sheet with and without it. */
Assert::true('the smaller type is set where a rem reads it', str_contains($printBlock, '  html { font-size: 14px; }'));
Assert::true('not on the body, where it did nothing', !preg_match('/  body \{[^}]*font-size/', $printBlock));

/* The two columns are a screen rule from 700px up, and a wider margin
 * setting drops an A4 page box below that. */
Assert::true('the cover keeps its column whatever the margins are', str_contains($printBlock, '.book-detail { grid-template-columns:'));

Assert::group('And across, where the page box is wider than a desktop window');

/* A4 across leaves about 1,046 pixels, past the 900 where the shelf and the
 * filter column become one grid with a named 200-pixel first track. Hiding
 * the filters takes the sidebar out of the grid and leaves the track, so the
 * shelf fell into it: eleven books one above the other over six sheets,
 * upright it was fine, which made it look like the browser's doing. */
$sidebarGrid = strpos($printCss, '.layout-with-sidebar {');
Assert::true('the two columns are a grid with a track the sidebar does not own', $sidebarGrid !== false && $sidebarGrid < $printStart);
Assert::true('which the sidebar is hidden out of', str_contains($printBlock, '.sidebar'));
Assert::true('so on paper there is no grid to fall into', str_contains($printBlock, '.layout-with-sidebar { display: block; }'));

/* The shelf's own grid never had that fault, but it is a grid being broken
 * over sheets, which Firefox does carefully rather than tightly: sixty
 * covers came to seven sheets across where Chrome made five. Inline blocks
 * wrap at a line end, and both browsers then agree to the sheet. */
Assert::true('the shelf is not a grid on paper', str_contains($printBlock, '.shelf { display: block; }'));
Assert::true('but a run of blocks of one width', str_contains($printBlock, 'display: inline-block;') && str_contains($printBlock, 'width: 118px;'));

/* The statistics' two columns are a grid too, and Firefox moved it whole
 * to the next sheet rather than start it under a chart: half a sheet empty.
 * Floats break across a page in both browsers. */
Assert::true('the statistics are not a grid on paper either', str_contains($printBlock, '.stat-grid { display: block; }'));
Assert::true('but two floated columns', str_contains($printBlock, '.stat-grid > * { float: left; width: 47%; }'));
Assert::true('which something clears', str_contains($printBlock, '.stat-grid::after { content: ""; display: block; clear: both; }'));

Assert::group('The palette, against a theme loaded after it');

/* A theme file is a second stylesheet, loaded after this one, and it sets
 * the same tokens. An even match would lose on order, so the print block
 * takes one notch of specificity - which is only worth anything as long as
 * the themes stay on the bare :root they use today. */
Assert::true('the print palette is a notch more specific', str_contains($printBlock, 'html:root {'));

foreach (glob(PROJECT_ROOT . '/public/css/themes/*.css') ?: [] as $themeFile) {
    $theme = (string) file_get_contents($themeFile);
    Assert::true(
        basename($themeFile) . ' sets its tokens on a bare :root',
        str_contains($theme, ':root {') && !str_contains($theme, 'html:root')
    );
}

foreach (['--bg: #fff', '--text: #000', '--surface: #fff'] as $token) {
    Assert::true('ink on white: ' . $token, str_contains($printBlock, $token));
}

Assert::group('Colour that is not decoration');

/* Backgrounds are off by default in the dialogue. For most of the page that
 * is what it is; for these it is the difference between a chart and a row of
 * empty rails, and between an unread book and a white ring on its cover. */
preg_match('/\n((?:  [^\n]*,\n)+  [^\n]*\{\n    -webkit-print-color-adjust: exact;)/', $printBlock, $exact);
foreach (['.bar-fill', '.split-part', '.split-legend .swatch', '.badge-unread'] as $meaningful) {
    Assert::true($meaningful . ' asks for its ink', str_contains($exact[1] ?? '', $meaningful));
}
Assert::true('by name', str_contains($printBlock, 'print-color-adjust: exact;'));
Assert::true('and for the browsers that still want the prefix', str_contains($printBlock, '-webkit-print-color-adjust: exact;'));

/* Text drawn on an accent ground loses the ground and keeps its colour,
 * which is white: the chosen chip printed as a ghost in Chrome. */
Assert::true('text meant for an accent ground is ink', str_contains($printBlock, '--on-accent: #000;'));

Assert::group('Stars, which are text whose shade is the number');

/* The half star is a gradient clipped to the glyph on screen. Asking for
 * that background worked in Chrome and printed in Firefox as a rectangle:
 * Firefox prints the gradient and not the clip. So it is not among the
 * backgrounds that ask for ink - it is drawn as two letters instead. */
Assert::true('the half star is not a background asking for ink', !str_contains($exact[1] ?? '', '.stars .half'));
Assert::true('it gives the gradient up on paper', str_contains($printBlock, "  .stars .half {\n    position: relative;\n    display: inline-block;\n    background: none;"));
Assert::true('and lays a black star over a grey one', str_contains($printBlock, '.stars .half::before {') && str_contains($printBlock, 'content: "\\2605";'));
Assert::true('cut where the screen cuts it', str_contains($printBlock, 'width: 44%;') && str_contains($printCss, 'var(--accent) 44%, var(--border) 44%'));

/* Firefox darkens pale text on paper, and an empty star is pale text: three
 * stars printed as five black ones. */
Assert::true('the stars keep their shades', str_contains($printBlock, "  .stars {\n    -webkit-print-color-adjust: exact;\n    print-color-adjust: exact;\n  }"));

/* The stand-in is the other way round: its ground is a colour and its text
 * was picked to sit on that colour, so printing the text alone leaves pale
 * letters on white. It becomes a framed label instead. */
Assert::true('the cover stand-in gives up its ground', str_contains($printBlock, '.cover--placeholder { background: none;'));
Assert::true('and takes a frame and dark text instead', str_contains($printBlock, 'color: #000; border: 1px solid var(--border); }'));
Assert::true(
    'after the sixteen grounds, so it is the one that counts',
    str_contains($printBefore, '.ph-16 { background: var(--placeholder-16); }')
);
