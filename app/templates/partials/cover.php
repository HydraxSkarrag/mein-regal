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

/* What shape the picture actually is.
 *
 * The tile is 2:3 and the image fills it, which is right for the several
 * thousand books that are taller than they are wide and wrong for the comics
 * and the photo books, which are wider: filling a tall box with a wide
 * picture means showing a vertical strip out of the middle of it. Measured on
 * four of them - 771x599, 900x892, 300x227, 900x552 - so between a third and
 * three fifths of the picture was being cut away.
 *
 * The dimensions are recorded when a cover is stored, so this is read rather
 * than guessed. A row from before they were kept has neither, and then
 * nothing here applies and the tile behaves as it always did. */
$coverWidth = (int) ($cover['width'] ?? 0);
$coverHeight = (int) ($cover['height'] ?? 0);
$known = $coverWidth > 0 && $coverHeight > 0;
$wide = $known && $coverWidth > $coverHeight;
?>
<?php if ($url !== null): ?>
<div class="cover<?= $wide ? ' cover--wide' : '' ?>">
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
