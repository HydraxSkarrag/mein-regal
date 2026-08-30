<?php
/**
 * Writing a prose page.
 *
 * The toolbar inserts markup characters into the textarea; it does not edit
 * HTML. That is the whole point: a rich editor stores markup the browser
 * produced, and then someone has to decide forever which of it is safe. Here
 * what is stored is exactly what was typed, and the rendering decides what
 * becomes a tag - so the worst a determined author can do to their own page
 * is make it ugly.
 *
 * Without JavaScript the toolbar and the live preview are simply absent and
 * the textarea still works, which is the same bargain the scanner makes.
 */
declare(strict_types=1);
?>
<p class="detail-actions"><a href="/<?= e($slug) ?>">&larr; <?= e($heading) ?></a></p>

<h1><?= e(t('page.edit', ['page' => $heading])) ?></h1>

<div class="chips" style="margin-bottom:18px">
  <?php foreach (App\Core\Translator::SUPPORTED as $option): ?>
  <a class="chip" href="/<?= e($slug) ?>/edit?lang=<?= e($option) ?>"
     aria-current="<?= $option === $locale ? 'true' : 'false' ?>">
    <?= e(t('lang.' . $option)) ?><?= in_array($option, $written, true) ? ' ✓' : '' ?>
  </a>
  <?php endforeach; ?>
</div>
<p class="note" style="margin-top:-8px"><?= e(t('about.perlanguage')) ?></p>

<?php if (($error ?? '') !== ''): ?>
<p class="form-error"><?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="/<?= e($slug) ?>/edit?lang=<?= e($locale) ?>">
  <?= $csrfField ?>

  <div class="field">
    <label for="title"><?= e(t('edit.title')) ?></label>
    <input id="title" type="text" name="title" maxlength="200" required
           value="<?= e($page['title'] ?? $heading) ?>">
  </div>

  <div class="field">
    <label for="body"><?= e(t('about.body')) ?></label>

    <div class="editor" data-editor>
      <div class="editor-bar" data-editor-bar hidden>
        <?php
        /*
         * label, what it wraps or prefixes, and the title shown on hover.
         * Kept here rather than in the script so the words stay translatable.
         */
        $tools = [
            ['b',  'bold',    '<strong>F</strong>'],
            ['i',  'italic',  '<em>K</em>'],
            ['h',  'heading', 'H'],
            ['ul', 'list',    '&bull;'],
            ['ol', 'numbers', '1.'],
            ['q',  'quote',   '&rdquo;'],
            ['a',  'link',    '&#128279;'],
        ];
        foreach ($tools as [$key, $name, $glyph]):
        ?>
        <button type="button" class="editor-tool" data-tool="<?= e($key) ?>"
                data-placeholder="<?= e(t('editor.' . $name)) ?>"
                title="<?= e(t('editor.' . $name)) ?>" aria-label="<?= e(t('editor.' . $name)) ?>"><?= $glyph ?></button>
        <?php endforeach; ?>
        <button type="button" class="editor-tool editor-tool--right" data-editor-toggle
                aria-pressed="false"><?= e(t('editor.preview')) ?></button>
      </div>

      <textarea id="body" name="body" rows="18" data-editor-input
                placeholder="<?= e($suggested) ?>"><?= e($page['body'] ?? '') ?></textarea>

      <div class="prose editor-preview" data-editor-preview hidden aria-live="polite"></div>
    </div>

    <p class="note"><?= e(t('editor.hint')) ?></p>
  </div>

  <div class="edit-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
    <a class="btn" href="/<?= e($slug) ?>"><?= e(t('common.cancel')) ?></a>
  </div>
</form>
