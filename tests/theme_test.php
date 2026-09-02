<?php
declare(strict_types=1);

use App\Core\Styles;
use App\Core\Theme;

Assert::group('Styles: the measurements a page cannot know in advance');

$styles = new Styles();
Assert::true('nothing collected yet', $styles->isEmpty());

$a = $styles->width(62.44);
$b = $styles->width(31.0);
Assert::same('a class comes back, not a colour', preg_match('/^u\d+$/', $a), 1);
Assert::same('two values, two classes', $a === $b, false);

// A shelf of sixty covers must not produce sixty identical rules.
Assert::same('the same value shares a class', $styles->width(62.44), $a);
Assert::same('the rule says what it was asked', str_contains($styles->css(), 'width:62.4%'), true);

// Values come from data, so they are clamped rather than trusted: a division
// that goes wrong should make a full bar, not a broken stylesheet.
$edge = new Styles();
$edge->width(140.0);
$edge->width(-12.0);
Assert::same('above a hundred is a hundred', str_contains($edge->css(), 'width:100.0%'), true);
Assert::same('below zero is zero', str_contains($edge->css(), 'width:0.0%'), true);
Assert::same('and nothing else got in', preg_match('/^(\.u\d+\{width:\d+\.\d%\})+$/', $edge->css()), 1);

Assert::group('Theme: three layers, in order');

$root = dirname(__DIR__);

$none = new Theme($root, '');
Assert::same('no theme named, nothing extra loaded', $none->urls(), []);
Assert::same('and nothing is reported missing', $none->missing(), false);

$shipped = new Theme($root, 'buecherhausen');
Assert::same('a shipped theme is found', count($shipped->urls()), 1);
Assert::same(
    'and loaded from the themes directory',
    str_starts_with($shipped->urls()[0], '/css/themes/buecherhausen.css?v='),
    true
);

$typo = new Theme($root, 'buecherhaus');
Assert::same('a name that is not there loads nothing', $typo->urls(), []);
Assert::same('and says so, so the setup page can', $typo->missing(), true);

// config.php is not a threat - anybody editing it has already won - but a
// typo with a slash should read as "no such theme" rather than send the
// application looking around the file system.
$escape = new Theme($root, '../../../../etc/passwd');
Assert::same('a path is not a theme name', $escape->slug(), 'etcpasswd');
Assert::same('and finds nothing', $escape->urls(), []);

Assert::group('Theme: the chrome colour comes out of the CSS');

// It used to be written a second time in config.php. Four hours later the
// development configuration said #faf9fe and the real one #f9fefd, and
// neither was any theme's background. Hence: read it.
$neutral = (new Theme($root, ''))->metaColours();
Assert::same('the default background', $neutral['light'], '#fbfbf9');
Assert::same('one scheme, so one tag', $neutral['dark'], null);

$red = (new Theme($root, 'buecherhausen'))->metaColours();
Assert::same('a theme overrules the default', $red['light'], '#f9fefd');
Assert::same('and adds no dark scheme', $red['dark'], null);

// night.css touches nothing outside its media query, so the light colour has
// to come through from the layer underneath.
$night = (new Theme($root, 'night'))->metaColours();
Assert::same('light stays the default underneath', $night['light'], '#fbfbf9');
Assert::same('and dark is the theme\'s own', $night['dark'], '#131318');

Assert::group('Theme: reading a stylesheet');

$parse = static function (string $css): array {
    $method = new ReflectionMethod(Theme::class, 'split');
    $method->setAccessible(true);
    [$plain, $night] = $method->invoke(null, $css);
    $colour = new ReflectionMethod(Theme::class, 'colourIn');
    $colour->setAccessible(true);

    return [$colour->invoke(null, $plain), $colour->invoke(null, $night)];
};

// The query holds rules with braces of their own, so the first closing brace
// is never the right one. A regular expression got this wrong; brace
// counting does not.
Assert::same(
    'a dark block with nested rules is read whole',
    $parse(':root{--bg:#fff}@media (prefers-color-scheme: dark){:root{--bg:#000}.x{color:red}}'),
    ['#fff', '#000']
);
// A query that is not about the colour scheme is skipped rather than read as
// either half: a background that only applies on a narrow screen is not what
// the address bar should be painted in.
Assert::same(
    'a query about something else is neither half',
    $parse(':root{--bg:#fff}@media (min-width: 40em){:root{--bg:#eee}}'),
    ['#fff', null]
);
Assert::same(
    'whitespace in the query does not matter',
    $parse('@media(prefers-color-scheme:dark){:root{--bg:#111}}'),
    [null, '#111']
);
// A theme that wants the chrome in something other than the page background.
Assert::same(
    'an explicit colour wins over the background',
    $parse(':root{--bg:#fff;--meta-theme-colour:#ed002f}'),
    ['#ed002f', null]
);
// Colour functions are not matched on purpose: a meta tag takes a hex.
Assert::same('nothing usable is nothing', $parse(':root{--bg:rgb(1,2,3)}'), [null, null]);
