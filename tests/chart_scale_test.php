<?php
/**
 * A column chart's axis says what its lines are.
 *
 * The scale used to be a multiple of five, labelled at each quarter and
 * rounded. On /stats a year with one book drew a column that ended below the
 * line marked 1, with no 2 anywhere on the axis: 0, 1, 3, 4, 5 for lines at
 * 0, 1.25, 2.5, 3.75 and 5. Over every maximum from 1 to 1,000, 525 drew an
 * axis that did not match its own lines. Nothing checked the drawing.
 */
declare(strict_types=1);

use App\Core\ChartScale;
use App\Core\Formatter;
use App\Core\Styles;
use App\Core\View;

Assert::group('The scale');

$scaleFaults = [];
$lowestFill = 1.0;
for ($max = 1; $max <= 5000; $max++) {
    $step = ChartScale::step($max);
    $top = ChartScale::top($max);
    if ($top < $max || $top !== $step * ChartScale::lines($max) || $step < 1) {
        $scaleFaults[] = $max;
    }
    if ($max >= ChartScale::LINES) {
        $lowestFill = min($lowestFill, $max / $top);
    }
}
Assert::same('every maximum fits, in whole steps', $scaleFaults, []);
Assert::true('and the tallest column reaches well up the axis', $lowestFill >= 0.6);
Assert::same('one book, one line', [ChartScale::lines(1), ChartScale::top(1)], [1, 1]);
Assert::same('the axis 295 books always had', ChartScale::top(295), 300);

Assert::group('What is drawn');

$chartView = new View(PROJECT_ROOT . '/app/templates');
$chartView->share('view', $chartView);
$chartView->share('formatter', new Formatter('de'));
$chartView->share('styles', new Styles());

/* Every label read off the drawing and compared with the height a column of
 * exactly that value would have - the thing somebody looking at it compares. */
$axisFaults = [];
foreach ([1, 2, 3, 5, 9, 14, 21, 33, 87, 172, 295, 551, 999, 2669] as $max) {
    $svg = $chartView->render('partials.chart_columns', [
        'series'  => ['2024' => $max, '2025' => max(1, intdiv($max, 3))],
        'caption' => 'Test ' . $max,
    ]);
    preg_match('/<rect x="[^"]+" y="([0-9.]+)"\s+width="[^"]+" height="([0-9.]+)"\s+fill="transparent"/', $svg, $plot);
    [$plotTop, $plotHeight] = [(float) $plot[1], (float) $plot[2]];
    preg_match_all('/<text x="[^"]+" y="([0-9.]+)" text-anchor="end"\s+font-size="10" class="chart-tick">(\d+)<\/text>/', $svg, $ticks, PREG_SET_ORDER);
    $labels = array_map(static fn (array $t): int => (int) $t[2], $ticks);
    $scaleTop = max($labels);
    foreach ($ticks as [, $y, $label]) {
        $lineY = (float) $y - 3.5;
        $columnEnds = $plotTop + $plotHeight - ((int) $label / $scaleTop) * $plotHeight;
        if (abs($lineY - $columnEnds) > 0.1) {
            $axisFaults[] = "max {$max}: \"{$label}\" at {$lineY}, a column of {$label} ends at " . round($columnEnds, 1);
        }
    }
    if ($labels !== array_map(static fn (int $i): int => $i * ChartScale::step($max), range(0, ChartScale::lines($max)))) {
        $axisFaults[] = "max {$max}: labels " . implode(',', $labels);
    }
}
Assert::same('every label sits where a column of that value ends', $axisFaults, []);

$oneBook = $chartView->render('partials.chart_columns', ['series' => ['2025' => 1], 'caption' => 'Ein Buch']);
preg_match_all('/class="chart-tick">(\d+)</', $oneBook, $oneLabels);
Assert::same('a single book is 0 and 1', $oneLabels[1], ['0', '1']);
