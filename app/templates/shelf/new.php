<?php
/**
 * A book by hand, with the catalogue as an optional first step.
 *
 * Two ways out of one form. Searching asks the German National Library and
 * comes back with a shortlist; creating takes what is typed and goes straight
 * to the edit page. Neither needs JavaScript, and the search is a button
 * rather than something that happens while you type - a catalogue request per
 * keystroke would be rude to the library and useless to the reader.
 *
 * A picked record travels on in hidden fields. Most carry an ISBN, and one
 * that does arrives on the edit page with a cover already fetched.
 *
 * @var ?string $error
 * @var string  $title
 * @var string  $author
 * @var ?list<array<string,mixed>> $found
 * @var string  $csrfField
 */
declare(strict_types=1);
?>
<div class="page-head">
  <h1><?= e(t('new.title')) ?></h1>
</div>

<div class="card">
  <p class="note mt-0"><?= e(t('new.hint')) ?></p>

  <?php if ($error !== null): ?>
  <p class="form-error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="/book/new">
    <?= $csrfField ?>
    <div class="field mb-s">
      <label for="title"><?= e(t('book.title')) ?></label>
      <?php /* autofocus: this page exists to be typed into, and whoever
               reached it already knows what they are going to type. */ ?>
      <input id="title" type="text" name="title" value="<?= e($title) ?>"
             maxlength="500" autocomplete="off" required autofocus>
    </div>

    <div class="field mb-s">
      <label for="author"><?= e(t('new.author')) ?></label>
      <input id="author" type="text" name="author" value="<?= e($author) ?>"
             maxlength="255" autocomplete="off">
      <p class="note"><?= e(t('new.author.hint')) ?></p>
    </div>

    <div class="edit-actions">
      <button class="btn btn--primary" type="submit" name="action" value="search">
        <?= e(t('new.search')) ?>
      </button>
      <?php /* The way past the catalogue, for the books that are not in it.
               Second, because searching first is right nearly always - and
               it is a plain button, so Enter in the title field runs the
               search rather than creating a bare record by accident. */ ?>
      <button class="btn" type="submit" name="action" value="create">
        <?= e(t('new.create')) ?>
      </button>
      <a class="btn" href="/scan"><?= e(t('common.cancel')) ?></a>
    </div>
  </form>
</div>

<?php if ($found !== null && $found !== []): ?>
<h2 class="mt-m"><?= e(t('new.found', ['count' => count($found)])) ?></h2>
<p class="note"><?= e(t('new.found.hint')) ?></p>

<ul class="candidates">
  <?php foreach ($found as $candidate): ?>
  <li>
    <form method="post" action="/book/new">
      <?= $csrfField ?>
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="title" value="<?= e($candidate['title']) ?>">
      <input type="hidden" name="authors" value="<?= e(implode('|', $candidate['authors'])) ?>">
      <input type="hidden" name="publisher" value="<?= e((string) ($candidate['publisher'] ?? '')) ?>">
      <input type="hidden" name="published_year" value="<?= e((string) ($candidate['year'] ?? '')) ?>">
      <input type="hidden" name="page_count" value="<?= e((string) ($candidate['pages'] ?? '')) ?>">
      <input type="hidden" name="language" value="<?= e((string) ($candidate['language'] ?? '')) ?>">
      <input type="hidden" name="isbn13" value="<?= e((string) ($candidate['isbn13'] ?? '')) ?>">

      <div class="candidate-text">
        <strong><?= e($candidate['title']) ?></strong>
        <?php if ($candidate['authors'] !== []): ?>
        <span class="candidate-people"><?= e(implode(', ', $candidate['authors'])) ?></span>
        <?php endif; ?>
        <span class="note">
          <?php
            /* Publisher, year, pages, ISBN - whatever the record has. Two
               editions of one book differ in exactly these, and this list is
               here to be told apart. */
            $facts = array_filter([
                $candidate['publisher'] ?? null,
                $candidate['year'] !== null ? (string) $candidate['year'] : null,
                $candidate['pages'] !== null ? t('book.pages.n', ['count' => $candidate['pages']]) : null,
                $candidate['isbn13'] !== null ? App\Core\Isbn::format($candidate['isbn13']) : t('new.no.isbn'),
            ]);
          ?>
          <?= e(implode(' · ', $facts)) ?>
        </span>
      </div>

      <button class="btn" type="submit"><?= e(t('new.take')) ?></button>
    </form>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
