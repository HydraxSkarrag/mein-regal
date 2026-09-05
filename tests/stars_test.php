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
