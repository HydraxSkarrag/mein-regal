<?php
/**
 * The scanning screen, one step at a time.
 *
 * Everything used to be on screen at once: the viewfinder, its two buttons, a
 * hint, two checkboxes and the manual form, with the found book underneath
 * all of it. On a phone that put the answer below the fold - you scanned a
 * barcode and then had to scroll to find out what had been read, and the
 * shutter button for the cover photo ended up so far from the picture that
 * you could not see both.
 *
 * So: one step visible at a time, chosen by data-step on the container and
 * switched from scanner.js. Barcode and typing are two ways in, not two
 * halves of one screen, and neither is a fallback hidden under the other -
 * a scuffed or missing barcode is common enough that typing has to be a
 * first-class choice.
 */
declare(strict_types=1);
?>
<div class="scanner" id="scanner" data-step="choose">
  <h1><?= e(t('scan.title')) ?></h1>

  <?php /* Above the steps, not after them. Anything said here used to land at
           the very end of the page - which is to say against whichever button
           happened to be last, "wird gesucht" and "steht jetzt im Regal"
           alike. A message belongs where the reading starts. */ ?>
  <div id="status" role="status" aria-live="polite"></div>

  <section data-when="choose">
    <div class="scan-modes">
      <button class="btn btn--primary btn--block" type="button" id="pick-camera">
        <?= e(t('scan.mode.camera')) ?>
      </button>
      <button class="btn btn--block" type="button" id="pick-manual">
        <?= e(t('scan.mode.manual')) ?>
      </button>
    </div>

    <?php /* The two settings belong here and not next to the viewfinder:
             they are decided once for a session, not per book. */ ?>
    <div class="field field--check mt-m">
      <input id="series" type="checkbox" checked>
      <label for="series"><?= e(t('scan.series')) ?> <span class="note">– <?= e(t('scan.series.hint')) ?></span></label>
    </div>

    <div class="field field--check">
      <input id="read" type="checkbox">
      <label for="read"><?= e(t('scan.read')) ?> <span class="note">– <?= e(t('scan.read.hint')) ?></span></label>
    </div>
  </section>

  <section data-when="camera">
    <div class="scanner-frame" id="frame">
      <video id="video" playsinline muted></video>
      <div class="scanner-reticle" id="reticle" aria-hidden="true"></div>
      <p class="scanner-overlay" id="overlay" hidden></p>
    </div>
    <p class="scanner-hint" id="hint"><?= e(t('scan.aim')) ?></p>
    <div class="scanner-actions">
      <button class="btn btn--block" type="button" id="stop"><?= e(t('scan.back')) ?></button>
    </div>
  </section>

  <section data-when="manual">
    <form id="manual">
      <div class="field mb-s">
        <label for="isbn"><?= e(t('scan.manual')) ?></label>
        <input id="isbn" type="text" inputmode="numeric" autocomplete="off"
               placeholder="<?= e(t('scan.manual.hint')) ?>">
      </div>
      <button class="btn btn--primary btn--block" type="submit"><?= e(t('scan.lookup')) ?></button>
    </form>
    <div class="scanner-actions">
      <button class="btn btn--block" type="button" id="manual-back"><?= e(t('scan.back')) ?></button>
    </div>
  </section>

  <?php /* Filled in by scanner.js. They are their own steps, so a found book
           and a cover being photographed each have the screen to themselves. */ ?>
  <section data-when="result">
    <div id="result"></div>
  </section>

  <p class="note" id="counter" hidden></p>
</div>

<?= $csrfField ?>

<script type="application/json" id="scan-i18n">
<?= json_for_script([
    'searching'   => t('scan.searching'),
    'sources'     => t('scan.sources'),
    'detected'    => t('scan.detected'),
    'nothingShort'=> t('scan.nothing.short'),
    'save'        => t('scan.save'),
    'photo'       => t('scan.photo'),
    'openBook'    => t('scan.open.book'),
    'skip'        => t('scan.skip'),
    'shoot'       => t('scan.shoot'),
    'shutter'     => t('scan.shutter'),
    'aimCover'    => t('scan.aim.cover'),
    'reviewShot'  => t('scan.review'),
    'keepShot'    => t('scan.keep'),
    'retake'      => t('scan.retake'),
    'dropCover'   => t('scan.drop.cover'),
    'cancel'      => t('common.cancel'),
    'again'       => t('scan.again'),
    'markedRead'  => t('scan.read.on'),
    'markedUnread'=> t('scan.read.off'),
    'nothing'     => t('scan.nothing'),
    'duplicate'   => t('scan.duplicate'),
    'noCamera'    => t('scan.no.camera'),
    'noDecoder'   => t('scan.no.decoder'),
    'noHttps'     => t('scan.no.https'),
    'denied'      => t('scan.denied'),
    'invalidIsbn' => t('scan.invalid.isbn'),
    'notABook'    => t('scan.not.a.book'),
    'aim'         => t('scan.aim'),
    'count'       => t('scan.count', ['count' => '{count}']),
    'error'       => t('error.500.title'),
]) ?>
</script>
