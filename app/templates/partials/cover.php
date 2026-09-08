<?php
/**
 * One cover, or a generated stand-in.
 *
 * @var array<string,mixed>  $book
 * @var array<string,mixed>|null $cover
 * @var string $authorLine
 */
declare(strict_types=1);

use App\Core\CoverImage;

$url = CoverImage::url($cover ?? null, $small ?? false);
$sizes = $sizes ?? '(max-width: 600px) 33vw, 150px';

/* How much of the picture survives being poured into the tile.
 *
 * The tile is 2:3 and the image fills it, which is right for the thousands of
 * books built to roughly that shape and wrong for everything else: the box
 * scales the picture until it covers, and whatever sticks out is cut off.
 *
 * The first version asked whether the cover was wider than tall. That caught
 * the comics and missed the audiobooks, which are square - and a square in a
 * 2:3 box loses exactly a third of its width, sides first, which is where the
 * title usually is. Asking about the shape was the wrong question; the useful
 * one is how much gets cut.
 *
 * Measured over the 3,064 covers on the larger shelf: 73% of them keep 90% or
 * more and never look cropped at all. Below four fifths there are 358 - the
 * squares at 67%, the landscape ones down to 44% - and every one of those is
 * losing a piece of the design rather than a margin.
 *
 * The dimensions are recorded when a cover is stored, so this is read rather
 * than guessed. A row from before they were kept has neither, and then
 * nothing here applies and the tile behaves as it always did. */
const TILE_RATIO = 2 / 3;
const KEEP_AT_LEAST = 0.80;

$coverWidth = (int) ($cover['width'] ?? 0);
$coverHeight = (int) ($cover['height'] ?? 0);
$known = $coverWidth > 0 && $coverHeight > 0;

$visible = 1.0;
if ($known) {
    $ratio = $coverWidth / $coverHeight;
    $visible = min($ratio, TILE_RATIO) / max($ratio, TILE_RATIO);
}
$offShape = $known && $visible < KEEP_AT_LEAST;
?>
<?php if ($url !== null): ?>
<div class="cover<?= $offShape ? ' cover--contain' : '' ?>">
  <?php /* width and height are the picture's own, not a size on the page: the
           browser works the ratio out from them and leaves the right amount
           of room before the image arrives. CSS decides how big it is
           drawn. */ ?>
  <img src="<?= e($url) ?>"
       alt="<?= e(t('book.cover.of', ['title' => $book['title']])) ?>"
       <?php if ($known): ?>width="<?= e((string) $coverWidth) ?>" height="<?= e((string) $coverHeight) ?>" <?php endif; ?>
       loading="lazy" decoding="async" sizes="<?= e($sizes) ?>">
</div>
<?php else: ?>
<div class="cover cover--placeholder <?= e(CoverImage::placeholderClass((string) ($book['isbn13'] ?? $book['slug'] ?? ''))) ?>"
     role="img"
     aria-label="<?= e(t('book.no.cover')) ?>">
  <span class="ph-title"><?= e($book['title']) ?></span>
  <?php if (($authorLine ?? '') !== ''): ?>
  <span class="ph-author"><?= e($authorLine) ?></span>
  <?php endif; ?>
</div>
<?php endif; ?>
