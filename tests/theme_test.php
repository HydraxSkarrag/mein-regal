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
