<?php
/**
 * First run.
 *
 * Reachable only while no account exists. Everything else on the site is
 * unreachable until this is done, so it says as much rather than dropping
 * someone into a form with no explanation.
 */
declare(strict_types=1);
?>
<div class="card w-setup">
  <h1 class="mb-s"><?= e(t('setup.title')) ?></h1>
  <p class="note mt-0"><?= e(t('setup.intro')) ?></p>

  <?= $view->render('partials.brand_hint') ?>

  <?php if (($error ?? '') !== ''): ?>
  <p class="form-error"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="/setup">
    <?= $csrfField ?>
    <div class="field">
      <label for="name"><?= e(t('setup.name')) ?></label>
      <input id="name" type="text" name="name" value="<?= e($name) ?>" required autofocus
             autocomplete="name" maxlength="120">
    </div>
    <div class="field">
      <label for="email"><?= e(t('auth.email')) ?></label>
      <input id="email" type="email" name="email" value="<?= e($email) ?>" required
             autocomplete="username" maxlength="190">
    </div>
    <div class="field">
      <label for="password"><?= e(t('auth.password')) ?></label>
      <input id="password" type="password" name="password" required
             autocomplete="new-password" minlength="<?= (int) $minPassword ?>">
      <p class="note"><?= e(t('setup.password.hint', ['min' => $minPassword])) ?></p>
    </div>
    <div class="field">
      <label for="password_repeat"><?= e(t('setup.password.repeat')) ?></label>
      <input id="password_repeat" type="password" name="password_repeat" required
             autocomplete="new-password" minlength="<?= (int) $minPassword ?>">
    </div>
    <button class="btn btn--primary btn--block" type="submit"><?= e(t('setup.submit')) ?></button>
  </form>
</div>
