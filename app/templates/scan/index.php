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
    <?php /* Three doors, each saying what it is for.
             
             Stacked on a phone, where they are thumb-sized targets and the
             camera is the obvious first one. Side by side from 760px, where
             the camera is the least likely of the three - a desktop webcam
             pointed at a barcode is a poor tool, and somebody at a keyboard
             is there to type. The order does not change, because the order is
             about what is tried first and not about which screen it is on. */ ?>
    <div class="scan-modes">
      <button class="scan-mode scan-mode--first" type="button" id="pick-camera">
        <span class="scan-mode-name"><?= e(t('scan.mode.camera')) ?></span>
        <span class="scan-mode-hint"><?= e(t('scan.mode.camera.hint')) ?></span>
      </button>
      <button class="scan-mode" type="button" id="pick-manual">
        <span class="scan-mode-name"><?= e(t('scan.mode.manual')) ?></span>
        <span class="scan-mode-hint"><?= e(t('scan.mode.manual.hint')) ?></span>
      </button>
      <?php /* The third is a link rather than a step: it leaves the scanner
               for the ordinary edit page, because a book no catalogue has is
               not something a lookup can help with. */ ?>
      <a class="scan-mode" href="/book/new">
        <span class="scan-mode-name"><?= e(t('scan.mode.blank')) ?></span>
        <span class="scan-mode-hint"><?= e(t('scan.mode.blank.hint')) ?></span>
      </a>
    </div>

    <?php /* The two settings belong here and not next to the viewfinder: they
             are decided once for a session, not per book.
             
             Under a heading that says which of the three doors they are
             about, because they are not about all of them. Series scanning
             needs a running camera to have anywhere to return to, and
             recording as read is a way of not touching the form at all -
             which is the point of scanning and beside the point of the third
             door, where the form is what you came for and the status is a
             field on it. */ ?>
    <h2 class="scan-options-heading"><?= e(t('scan.options')) ?></h2>
    <div class="field field--check">
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
    <?php /* Built like the by-hand page, because it is the same act: a card,
             a line saying what belongs in the field, the field, and the two
             ways on underneath it. Loose on the page it read as three
             elements that happened to land near each other.
             
             "Zurück" moves inside the form and keeps type="button" - a bare
             button in a form submits it, and this one leads away. */ ?>
    <div class="card">
      <p class="note mt-0"><?= e(t('scan.manual.note')) ?></p>

      <form id="manual">
        <div class="field mb-s">
          <label for="isbn"><?= e(t('scan.manual')) ?></label>
          <input id="isbn" type="text" inputmode="numeric" autocomplete="off"
                 placeholder="<?= e(t('scan.manual.hint')) ?>">
        </div>

        <div class="edit-actions">
          <button class="btn btn--primary" type="submit"><?= e(t('scan.lookup')) ?></button>
          <button class="btn" type="button" id="manual-back"><?= e(t('scan.back')) ?></button>
        </div>
      </form>
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
