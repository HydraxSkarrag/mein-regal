<?php
/**
 * The scanning screen.
 *
 * Camera first, manual entry always available underneath - a barcode that is
 * scuffed, wrapped in film or simply missing is common enough that typing the
 * ISBN cannot be a hidden fallback.
 */
declare(strict_types=1);
?>
<div class="scanner">
  <h1><?= e(t('scan.title')) ?></h1>

  <div class="scanner-frame" id="frame" hidden>
    <video id="video" playsinline muted></video>
    <div class="scanner-reticle" aria-hidden="true"></div>
  </div>

  <div class="scanner-actions">
    <button class="btn btn--primary" type="button" id="start"><?= e(t('scan.start')) ?></button>
    <button class="btn" type="button" id="stop" hidden><?= e(t('scan.stop')) ?></button>
  </div>
  <p class="scanner-hint" id="hint"><?= e(t('scan.aim')) ?></p>

  <div class="field field--check" style="margin-top:14px">
    <input id="series" type="checkbox" checked>
    <label for="series"><?= e(t('scan.series')) ?> <span class="note">– <?= e(t('scan.series.hint')) ?></span></label>
  </div>

  <form id="manual" class="card" style="margin-top:18px">
    <div class="field" style="margin-bottom:10px">
      <label for="isbn"><?= e(t('scan.manual')) ?></label>
      <input id="isbn" type="text" inputmode="numeric" autocomplete="off"
             placeholder="<?= e(t('scan.manual.hint')) ?>">
    </div>
    <button class="btn btn--block" type="submit"><?= e(t('scan.lookup')) ?></button>
  </form>

  <div id="status" role="status" aria-live="polite"></div>
  <div id="result" hidden></div>

  <p class="note" id="counter" hidden></p>
</div>

<?= $csrfField ?>

<script type="application/json" id="scan-i18n">
<?= json_encode([
    'searching'   => t('scan.searching'),
    'save'        => t('scan.save'),
    'photo'       => t('scan.photo'),
    'again'       => t('scan.again'),
    'nothing'     => t('scan.nothing'),
    'duplicate'   => t('scan.duplicate'),
    'noCamera'    => t('scan.no.camera'),
    'noHttps'     => t('scan.no.https'),
    'denied'      => t('scan.denied'),
    'invalidIsbn' => t('scan.invalid.isbn'),
    'notABook'    => t('scan.not.a.book'),
    'aim'         => t('scan.aim'),
    'count'       => t('scan.count', ['count' => '{count}']),
    'error'       => t('error.500.title'),
], JSON_UNESCAPED_UNICODE) ?>
</script>
