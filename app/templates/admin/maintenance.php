<?php
/**
 * Import, export, and the note about what deliberately is not here.
 */
declare(strict_types=1);
?>
<p class="detail-actions"><a href="/verwaltung">&larr; <?= e(t('nav.admin')) ?></a></p>

<h1><?= e(t('maintenance.title')) ?></h1>

<?php if (($error ?? '') !== ''): ?>
<p class="form-error"><?= e($error) ?></p>
<?php endif; ?>

<?php if (($report ?? '') !== ''): ?>
<div class="panel" style="margin-bottom:24px">
  <h2><?= e(t('maintenance.import.report')) ?></h2>
  <pre class="report"><?= e($report) ?></pre>
</div>
<?php endif; ?>

<div class="panel">
  <h2><?= e(t('maintenance.export')) ?></h2>
  <p class="note" style="margin-top:0"><?= e(t('maintenance.export.hint')) ?></p>
  <ul class="download-list">
    <li>
      <a href="/verwaltung/export/bookstats"><?= e(t('maintenance.export.bookstats')) ?></a>
      <span class="note"><?= e(t('maintenance.export.bookstats.hint')) ?></span>
    </li>
    <li>
      <a href="/verwaltung/export/full"><?= e(t('maintenance.export.full')) ?></a>
      <span class="note"><?= e(t('maintenance.export.full.hint')) ?></span>
    </li>
    <li>
      <a href="/verwaltung/export/json"><?= e(t('maintenance.export.json')) ?></a>
      <span class="note"><?= e(t('maintenance.export.json.hint')) ?></span>
    </li>
  </ul>
</div>

<div class="panel" style="margin-top:24px">
  <h2><?= e(t('maintenance.import')) ?></h2>
  <p class="note" style="margin-top:0"><?= e(t('maintenance.import.hint')) ?></p>

  <?php if ($bookCount > 0): ?>
  <p class="note" style="color:#8a0a22">
    <?= e(t('maintenance.import.notempty', ['count' => $bookCount])) ?>
  </p>
  <?php endif; ?>

  <form method="post" action="/verwaltung/import" enctype="multipart/form-data">
    <?= $csrfField ?>
    <div class="field">
      <label for="csv"><?= e(t('maintenance.import.file')) ?></label>
      <input id="csv" type="file" name="csv" accept=".csv,text/csv" required>
    </div>
    <div class="field">
      <label for="encoding"><?= e(t('maintenance.import.encoding')) ?></label>
      <select id="encoding" name="encoding">
        <option value="latin1"><?= e(t('maintenance.import.latin1')) ?></option>
        <option value="utf8"><?= e(t('maintenance.import.utf8')) ?></option>
      </select>
    </div>
    <div class="field field--check">
      <input id="commit" type="checkbox" name="commit" value="1">
      <label for="commit"><?= e(t('maintenance.import.commit')) ?></label>
    </div>
    <p class="note"><?= e(t('maintenance.import.dryrun')) ?></p>
    <button class="btn btn--primary" type="submit"><?= e(t('maintenance.import.run')) ?></button>
  </form>
</div>

<div class="panel" style="margin-top:24px">
  <h2><?= e(t('maintenance.cron')) ?></h2>
  <p class="note" style="margin-top:0"><?= e(t('maintenance.cron.hint')) ?></p>
</div>
