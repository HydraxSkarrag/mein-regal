<?php
/**
 * One series, in volume order, with the gaps named.
 *
 * A grid of the six volumes somebody owns looks complete. "Band 4 fehlt" is
 * the sentence they came for, and it is the only thing this page does that
 * the shelf filtered by series would not.
 *
 * @var array{id: int, name: string, slug: string, total: ?int, note: ?string} $series
 * @var list<array<string,mixed>> $volumes
 * @var list<int>                 $gaps
 */
declare(strict_types=1);

use App\Core\Formatter;
?>
<div class="page-head">
  <h1><?= e($series['name']) ?></h1>
  <span class="count"><?= e($series['total'] !== null
      ? t('series.owned.of', ['owned' => count($volumes), 'total' => $series['total']])
      : t('series.owned', ['owned' => count($volumes)])) ?></span>
</div>

<?php if (($series['note'] ?? '') !== ''): ?>
<?php /* "gezählt wie bei Audible, mit den Novellen" - the sentence that stops
         the same question being answered differently next year. The
         catalogue itself does not agree: the same Sanderson novel is volume 7
         in the Heyne records and 8 in the audio ones. */ ?>
<p class="note"><?= e($series['note']) ?></p>
<?php endif; ?>

<?php if ($gaps !== []): ?>
<p class="series-gaps">
  <?= e(count($gaps) === 1
      ? t('series.gap.one', ['number' => $gaps[0]])
      : t('series.gap.many', ['numbers' => implode(', ', $gaps)])) ?>
</p>
<?php endif; ?>

<?php if ($signedIn): ?>
<?php /* Folded away, because the page is for looking at a series and not for
         editing one - but on the page itself, because that is where somebody
         is standing when they notice the name is wrong.
         
         Removing a series is not removing books: the volumes stay on the
         shelf and stop belonging to a series. That is why it takes a click
         and not a typed-out word, unlike deleting a book - and why the button
         says so rather than saying "Löschen". */ ?>
<details class="series-edit">
  <summary><?= e(t('series.edit')) ?></summary>
  <form method="post" action="/reihe/<?= e($series['slug']) ?>">
    <?= $csrfField ?>
    <div class="field-row">
      <div class="field">
        <label for="series-name"><?= e(t('book.series')) ?></label>
        <input id="series-name" type="text" name="name" maxlength="190"
               value="<?= e($series['name']) ?>" autocomplete="off">
      </div>
      <div class="field">
        <label for="series-total"><?= e(t('series.total')) ?></label>
        <input id="series-total" type="text" name="total" inputmode="numeric" autocomplete="off"
               value="<?= e($series['total'] === null ? '' : (string) $series['total']) ?>" placeholder="<?= e(t('series.total.unknown')) ?>">
      </div>
    </div>
    <div class="field">
      <label for="series-note"><?= e(t('series.note')) ?></label>
      <input id="series-note" type="text" name="note" maxlength="255" autocomplete="off"
             value="<?= e((string) ($series['note'] ?? '')) ?>">
      <p class="note"><?= e(t('series.note.hint')) ?></p>
    </div>
    <div class="edit-actions">
      <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
      <button class="btn btn--danger" type="submit" name="remove" value="1"><?= e(t('series.remove')) ?></button>
    </div>
    <p class="note"><?= e(t('series.remove.hint')) ?></p>
  </form>
</details>
<?php endif; ?>

<?php if ($volumes === []): ?>
<p class="note"><?= e(t('series.empty')) ?></p>
<?php else: ?>
<ul class="shelf">
  <?php foreach ($volumes as $book): ?>
  <li class="book">
    <a href="/book/<?= e($book['slug']) ?>">
      <?= $view->render('partials.cover', [
          'book'       => $book,
          'cover'      => $covers[(int) $book['id']] ?? null,
          'authorLine' => $authorLines[(int) $book['id']] ?? '',
          'small'      => true,
      ]) ?>
      <?php if ($book['reading_status'] === 'unread'): ?>
      <span class="badge-unread" title="<?= e(t('status.unread')) ?>"></span>
      <?php endif; ?>
      <p class="book-title"><?= e($book['title']) ?></p>
      <?php if (($authorLines[(int) $book['id']] ?? '') !== ''): ?>
      <p class="book-author"><?= e($authorLines[(int) $book['id']]) ?></p>
      <?php endif; ?>
      <?php /* The volume number, because that is what this page is ordered
               by and an order you cannot read is an order you have to
               trust. */ ?>
      <p class="book-sortvalue">
        <?= e($book['series_index'] !== null
            ? t('book.series.only', ['number' => Formatter::volume($book['series_index'])])
            : t('series.unnumbered')) ?>
      </p>
    </a>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<p class="mt-l"><a class="btn" href="/series"><?= e(t('series.all')) ?></a></p>
