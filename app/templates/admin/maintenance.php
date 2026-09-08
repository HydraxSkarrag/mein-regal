<?php
/**
 * Import, export, and the note about what deliberately is not here.
 */
declare(strict_types=1);
?>
<?= $view->render('partials.admin_nav', ['adminCurrent' => 'data']) ?>

<h1><?= e(t('maintenance.title')) ?></h1>

<?php if (($error ?? '') !== ''): ?>
<p class="form-error"><?= e($error) ?></p>
<?php endif; ?>

<?php if (($report ?? '') !== ''): ?>
<div class="panel mb-l">
  <h2><?= e(t('maintenance.import.report')) ?></h2>
  <pre class="report"><?= e($report) ?></pre>
</div>
<?php endif; ?>

<div class="panel">
  <h2><?= e(t('maintenance.export')) ?></h2>
  <p class="note mt-0"><?= e(t('maintenance.export.hint')) ?></p>
  <ul class="download-list">
    <li>
      <a href="/admin/export/bookstats"><?= e(t('maintenance.export.bookstats')) ?></a>
      <span class="note"><?= e(t('maintenance.export.bookstats.hint')) ?></span>
    </li>
    <li>
      <a href="/admin/export/full"><?= e(t('maintenance.export.full')) ?></a>
      <span class="note"><?= e(t('maintenance.export.full.hint')) ?></span>
    </li>
    <li>
      <a href="/admin/export/json"><?= e(t('maintenance.export.json')) ?></a>
      <span class="note"><?= e(t('maintenance.export.json.hint')) ?></span>
    </li>
  </ul>
</div>

<div class="panel mt-l">
  <h2><?= e(t('maintenance.import')) ?></h2>
  <p class="note mt-0"><?= e(t('maintenance.import.hint')) ?></p>

  <?php if ($bookCount > 0): ?>
  <p class="note note--danger">
    <?= e(t('maintenance.import.notempty', ['count' => $bookCount])) ?>
  </p>
  <?php endif; ?>

  <form method="post" action="/admin/import" enctype="multipart/form-data">
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

<div class="panel mt-l">
  <h2><?= e(t('maintenance.cron')) ?></h2>
  <p class="note mt-0"><?= e(t('maintenance.cron.hint')) ?></p>

  <?php if ($runs === []): ?>
  <?php /* Either it has not run yet or it is not set up, and the page cannot
           tell those apart - so it says what it knows and points at the one
           thing that would explain both. */ ?>
  <p class="note"><?= e(t('maintenance.cron.none')) ?></p>
  <?php else: ?>
  <h3><?= e(t('maintenance.cron.runs')) ?></h3>
  <?php /* Newest first, and no paging: forty runs is a month, and the reason
           anybody opens this is to compare last night with the nights before
           it. */ ?>
  <ul class="runs">
    <?php foreach ($runs as $run): ?>
    <li>
      <?php if ($run['at'] !== null): ?>
      <time datetime="<?= e($run['at']) ?>"><?= e($formatter->dateTime($run['at'])) ?></time>
      <?php endif; ?>
      <ul>
        <?php foreach ($run['lines'] as $line): ?>
        <?php /* A line that says FAILED is the one worth finding in a wall of
                 successful nights. Matched on the word the job itself writes,
                 not on a status carried alongside it. */ ?>
        <li<?= str_contains($line, 'FAILED') ? ' class="run-failed"' : '' ?>><?= e($line) ?></li>
        <?php endforeach; ?>
      </ul>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
