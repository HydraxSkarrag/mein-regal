<?php
/** Sign-in form. */
declare(strict_types=1);
?>
<div class="card w-form">
  <h1 class="mb-m"><?= e(t('auth.signin')) ?></h1>

  <?php if (($error ?? '') !== ''): ?>
  <p class="form-error"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="/login">
    <?= $csrfField ?>
    <div class="field">
      <label for="email"><?= e(t('auth.email')) ?></label>
      <input id="email" type="email" name="email" value="<?= e($email ?? '') ?>"
             autocomplete="username" required autofocus>
    </div>
    <div class="field">
      <label for="password"><?= e(t('auth.password')) ?></label>
      <input id="password" type="password" name="password"
             autocomplete="current-password" required>
    </div>
    <div class="field field--check">
      <input id="remember" type="checkbox" name="remember" value="1" checked>
      <label for="remember"><?= e(t('auth.remember')) ?></label>
    </div>
    <button class="btn btn--primary btn--block" type="submit"><?= e(t('auth.signin')) ?></button>
  </form>
</div>
