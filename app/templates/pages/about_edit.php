<?php
/** Editing the about page. */
declare(strict_types=1);
?>
<p class="detail-actions"><a href="/ueber">&larr; <?= e(t('about.title')) ?></a></p>

<h1><?= e(t('about.edit')) ?></h1>

<div class="chips" style="margin-bottom:18px">
  <?php foreach (App\Core\Translator::SUPPORTED as $option): ?>
  <a class="chip" href="/ueber/bearbeiten?sprache=<?= e($option) ?>"
     aria-current="<?= $option === $locale ? 'true' : 'false' ?>">
    <?= e(t('lang.' . $option)) ?><?= in_array($option, $written, true) ? ' ✓' : '' ?>
  </a>
  <?php endforeach; ?>
</div>
<p class="note" style="margin-top:-8px"><?= e(t('about.perlanguage')) ?></p>

<?php if (($error ?? '') !== ''): ?>
<p class="form-error"><?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="/ueber/bearbeiten?sprache=<?= e($locale) ?>">
  <?= $csrfField ?>

  <div class="field">
    <label for="title"><?= e(t('edit.title')) ?></label>
    <input id="title" type="text" name="title" maxlength="200" required
           value="<?= e($page['title'] ?? t('about.title')) ?>">
  </div>

  <div class="field">
    <label for="body"><?= e(t('about.body')) ?></label>
    <textarea id="body" name="body" rows="14" placeholder="<?= e($suggested) ?>"><?= e($page['body'] ?? '') ?></textarea>
    <p class="note"><?= e(t('about.body.hint')) ?></p>
  </div>

  <div class="edit-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
    <a class="btn" href="/ueber"><?= e(t('common.cancel')) ?></a>
  </div>
</form>
