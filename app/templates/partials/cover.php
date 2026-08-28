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

$url = CoverImage::url($cover ?? null);
$sizes = $sizes ?? '(max-width: 600px) 33vw, 150px';
?>
<?php if ($url !== null): ?>
<div class="cover">
  <img src="<?= e($url) ?>"
       alt="<?= e(t('book.cover.of', ['title' => $book['title']])) ?>"
       loading="lazy" decoding="async" sizes="<?= e($sizes) ?>">
</div>
<?php else: ?>
<div class="cover cover--placeholder"
     style="background: <?= e(CoverImage::placeholderColour((string) ($book['isbn13'] ?? $book['slug'] ?? ''))) ?>"
     role="img"
     aria-label="<?= e(t('book.no.cover')) ?>">
  <span class="ph-title"><?= e($book['title']) ?></span>
  <?php if (($authorLine ?? '') !== ''): ?>
  <span class="ph-author"><?= e($authorLine) ?></span>
  <?php endif; ?>
</div>
<?php endif; ?>
