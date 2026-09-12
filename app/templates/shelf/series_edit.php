<?php
/**
 * Changing one series: what it is called, how many volumes it has, and how
 * this shelf counts them.
 *
 * Its own page rather than a fold on the series page, because that is what
 * this shelf does with a thing you change - a book has /book/{slug}/edit
 * behind a button in the same bar. Folding away is reserved for deleting a
 * book and for the filters on a phone, and neither of those is "edit this".
 *
 * @var array{id: int, name: string, slug: string, total: ?int, note: ?string} $series
 * @var int $owned
 */
declare(strict_types=1);
?>
<h1><?= e(t('series.edit')) ?></h1>

<form method="post" action="/reihe/<?= e($series['slug']) ?>/edit">
  <?= $csrfField ?>

  <div class="field">
    <label for="series-name"><?= e(t('book.series')) ?></label>
    <input id="series-name" type="text" name="name" maxlength="190"
           value="<?= e($series['name']) ?>" autocomplete="off">
  </div>

  <div class="field">
    <label for="series-total"><?= e(t('series.total')) ?></label>
    <input id="series-total" type="text" name="total" inputmode="numeric" autocomplete="off"
           value="<?= e($series['total'] === null ? '' : (string) $series['total']) ?>"
           placeholder="<?= e(t('series.total.unknown')) ?>">
    <p class="note"><?= e(t('series.total.hint', ['owned' => $owned])) ?></p>
  </div>

  <div class="field">
    <label for="series-note"><?= e(t('series.note')) ?></label>
    <input id="series-note" type="text" name="note" maxlength="255" autocomplete="off"
           value="<?= e((string) ($series['note'] ?? '')) ?>">
    <p class="note"><?= e(t('series.note.hint')) ?></p>
  </div>

  <div class="edit-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
    <a class="btn" href="/reihe/<?= e($series['slug']) ?>"><?= e(t('common.cancel')) ?></a>
  </div>
</form>

<?php /* Where this shelf keeps a way of removing something, so it is looked
         for in one place. No typed-out word, unlike a book: nothing is lost
         here, and asking for one would suggest otherwise. */ ?>
<details class="danger" id="remove-series">
  <summary><?= e(t('series.remove')) ?></summary>
  <div class="danger-body">
    <p class="note"><?= e(t('series.remove.hint')) ?></p>
    <form method="post" action="/reihe/<?= e($series['slug']) ?>/edit">
      <?= $csrfField ?>
      <button class="btn btn--danger" type="submit" name="remove" value="1"><?= e(t('series.remove')) ?></button>
    </form>
  </div>
</details>
