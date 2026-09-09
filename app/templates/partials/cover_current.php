<?php
/**
 * The cover a book has right now, with where it came from and a way out.
 *
 * Its own partial because two things render it: the edit page, and the reply
 * to the search button, which swaps this block in place rather than reloading
 * the page and throwing away everything typed into the form around it. One
 * copy, so the two cannot drift apart.
 *
 * @var array<string,mixed>       $book
 * @var array<string,mixed>|null  $cover
 * @var App\Core\View             $view
 */
declare(strict_types=1);
?>
<?php if ($cover !== null): ?>
<div class="cover-current">
  <div class="w-thumb">
    <?= $view->render('partials.cover', ['book' => $book, 'cover' => $cover, 'authorLine' => '', 'sizes' => '110px']) ?>
  </div>
  <div>
    <p class="note mt-0"><?= e(t('cover.from.' . $cover['source'])) ?></p>
    <button class="btn btn--danger" type="submit" form="cover-delete"><?= e(t('cover.remove')) ?></button>
  </div>
</div>
<?php endif; ?>
