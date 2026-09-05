<?php
/**
 * A rating drawn as stars, in one place.
 *
 * There is no half-star character worth printing. The trick is the same glyph
 * as the others with a gradient clipped to it - accent on the left half, the
 * border colour on the right - which needs a span of its own, so this cannot
 * be a string a formatter hands back.
 *
 * It was written twice for that reason and the copies drifted: the book page
 * drew the half star and the shelf grid printed a literal "½" next to the
 * full ones, which is a different typeface, a different weight and a
 * different colour from the stars it stands beside.
 *
 * $withEmpty is the one deliberate difference between the two. The book page
 * shows all five, so the rating can be read as a proportion; a grid tile
 * carries the title, the author and the sort value in a few square
 * centimetres, and two greyed-out stars there are two more things to look at.
 *
 * @var int|float|string|null $rating
 * @var bool                  $withEmpty
 */
declare(strict_types=1);

$parts = App\Core\Formatter::stars($rating);
if ($parts === null) {
    return;
}
$withEmpty = $withEmpty ?? true;
?>
<span class="stars"><?= str_repeat('★', $parts['full'])
    ?><?php if ($parts['half']): ?><span class="half">★</span><?php endif;
    ?><?php if ($withEmpty && $parts['empty'] > 0): ?><span class="off"><?= str_repeat('★', $parts['empty']) ?></span><?php endif; ?></span>
