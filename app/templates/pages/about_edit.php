<?php
/** Editing the about page. */
declare(strict_types=1);
?>
<p class="detail-actions"><a href="/ueber">&larr; <?= e(t('about.title')) ?></a></p>

<h1><?= e(t('about.edit')) ?></h1>

<?php if (($error ?? '') !== ''): ?>
<p class="form-error"><?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="/ueber/bearbeiten">
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
