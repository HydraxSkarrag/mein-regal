<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The value axis of a column chart: four equal steps from nought.
 *
 * It used to round the top of the scale to a multiple of five and then label
 * each quarter of it, rounded. Five is not divisible by four, and neither is
 * ten, fifteen or 110, so the lines lay at 1.25, 2.5 and 3.75 and were
 * labelled 1, 3 and 4 - a bar for one book ended below the line marked 1 and
 * there was no 2 at all. Measured over every maximum from 1 to 1,000: 525 of
 * them drew an axis that did not match its own lines.
 *
 * So the step is chosen and the top follows from it, not the other way round.
 * A step is a whole number - these are books - of a shape a person would pick:
 * 1, 1.5, 2, 2.5, 3, 4, 5, 6, 7.5 or 8 times a power of ten, the smallest that
 * holds the tallest column in four steps. The in-between shapes keep the
 * tallest column near the top: 295 books still get the 0-300 axis they had,
 * and 551 get 0-600 where the old rounding gave 0-1,000.
 */
final class ChartScale
{
    private const SHAPES = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 7.5, 8];

    public const LINES = 4;

    /**
     * How many lines above nought. Four, except where there are fewer books
     * than that at the top: one book gets one line at 1 rather than a column
     * a quarter of the way up an axis to 4.
     */
    public static function lines(int $max): int
    {
        return max(1, min(self::LINES, $max));
    }

    public static function step(int $max): int
    {
        if ($max < self::LINES) {
            return 1;
        }
        $needed = max(1, $max) / self::LINES;
        for ($power = 1; ; $power *= 10) {
            foreach (self::SHAPES as $shape) {
                $step = $shape * $power;
                if ($step >= $needed && floor($step) === (float) $step) {
                    return (int) $step;
                }
            }
        }
    }

    public static function top(int $max): int
    {
        return self::step($max) * self::lines($max);
    }
}
