<?php
/**
 * Half a star, drawn the same way in both places.
 *
 * There is no half-star character worth printing. The one that exists, "½",
 * is a different typeface, weight and colour from the stars it would stand
 * beside - so the book page draws it instead: the same glyph as the others
 * with a gradient clipped to the text, accent on the left half and the border
 * colour on the right.
 *
 * The shelf grid did not. It was the second place the same thing was written,
 * and it printed "★★★½" while the book page showed a proper half star for the
 * identical rating. Now both go through one partial, which is the only way
 * two of anything stay the same.
 */
declare(strict_types=1);

use App\Core\Formatter;
use App\Core\View;

$stars = static function (int|float|null $rating, bool $withEmpty = true): string {
    return trim((new View(PROJECT_ROOT . '/app/templates'))
        ->render('partials.stars', ['rating' => $rating, 'withEmpty' => $withEmpty]));
};

Assert::group('Stars: the half one is drawn, never written');

$half = $stars(3.5);

Assert::true('a half rating gets its own span', str_contains($half, '<span class="half">★</span>'));
Assert::true('and never the ½ character', !str_contains($half, '½'));
Assert::same('three whole stars come before it', substr_count(explode('<span class="half">', $half)[0], '★'), 3);

// The CSS that paints half of the glyph is scoped to .stars, so the wrapper
// is not decoration - without it the half star is simply a whole one.
Assert::true('the wrapper the gradient hangs on is there', str_contains($half, '<span class="stars">'));

$whole = $stars(4);
Assert::true('a whole rating has no half span', !str_contains($whole, 'class="half"'));
Assert::same('four whole and one empty', substr_count($whole, '★'), 5);

Assert::group('Stars: the grid shows fewer of them');

/* A book page shows all five so the rating reads as a proportion. A grid tile
 * carries a cover, a title, an author and the sort value in a few square
 * centimetres, and two greyed-out stars there are two more things to look at.
 * That is the one difference between the two, and it is an argument rather
 * than an accident, so it is a parameter rather than a second copy. */
$grid = $stars(3.5, false);
Assert::true('no empty stars in the grid', !str_contains($grid, 'class="off"'));
Assert::true('but the half star is the same one', str_contains($grid, '<span class="half">★</span>'));
Assert::same('so only the three earned ones are drawn', substr_count($grid, '★'), 4);

Assert::group('Stars: nothing to draw draws nothing');

// 68% of the collection is unrated. An empty row of five grey stars on two
// thousand tiles would be the loudest thing on the shelf.
Assert::same('an unrated book renders nothing at all', $stars(null), '');
Assert::same('and the formatter agrees there is nothing', Formatter::stars(null), null);

/* The text form stays, for the places that cannot take markup: the rating
 * dropdown on the edit page is a list of <option> labels, and "★★★½" is
 * exactly right inside one. */
Assert::same('the option label still uses the character', Formatter::starsText(3.5), '★★★½');

Assert::group('Ratings chart: every step that occurs, and none invented');

/* The chart looked up [5, 4, 3, 2, 1] by name, so a book rated three and a
 * half was in no bucket and left the chart without a word. The bars stopped
 * adding up to the number of rated books - on this shelf by exactly one,
 * which is the sort of wrong number nobody catches by looking.
 *
 * The column has held halves since it was made and the edit page has offered
 * them just as long; only the chart had opinions about which ratings exist.
 */
$counts = ['1' => 10, '2' => 16, '3' => 61, '3.5' => 1, '4' => 328, '5' => 551];

$steps = array_keys($counts);
usort($steps, static fn ($a, $b): int => (float) $b <=> (float) $a);

/* Cast before comparing, because PHP turns an array key that looks like an
 * integer into one: this list is 5, 4, "3.5", 3, 2, 1 - three of them ints
 * and one a string, out of one query. The template casts for the same reason,
 * and a comparison that did not would be asserting PHP's habits rather than
 * the chart's order. */
Assert::same(
    'best first, halves in their place',
    array_map('strval', $steps),
    ['5', '4', '3.5', '3', '2', '1']
);
Assert::same('and nothing dropped on the way', array_sum($counts), 967);

/* Keyed by the string the database gave. Casting to a float and back would
 * turn "4.0" into "4" and lose the row it came from. */
$roundTripped = array_map(static fn (string $k): string => (string) (float) $k, ['4.0', '3.5']);
Assert::same('which is why the key is not rebuilt', $roundTripped, ['4', '3.5']);

Assert::group('Bar labels are escaped unless a caller draws them');

/* Most labels are names out of the shelf - a genre, an author - and are
 * escaped. The ratings chart passes markup it built itself from numbers, and
 * has to say so. A label that arrived from a form or a catalogue never may.
 */
$bars = static function (array $counts, bool $markup) {
    $view = new View(PROJECT_ROOT . '/app/templates');
    $view->share('styles', new App\Core\Styles());

    return $view->render('partials.barlist', [
        'counts'        => $counts,
        'formatter'     => new App\Core\Formatter('de'),
        'labelIsMarkup' => $markup,
    ]);
};

$dangerous = ['<script>alert(1)</script>' => 3];
Assert::true('a genre named like an attack is text', str_contains($bars($dangerous, false), '&lt;script&gt;'));
Assert::true('and never a tag', !str_contains($bars($dangerous, false), '<script>'));
Assert::true('the flag is off unless asked for', !str_contains($bars($dangerous, false), '<script>'));
