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
      <?php /* "Zurück", like the other two doors out of the scan screen, and
               not "Abbrechen": nothing has been started here that could be
               cancelled, and the three ways in should not be worded as
               though one of them were riskier than the others. */ ?>
      <a class="btn" href="/scan"><?= e(t('scan.back')) ?></a>
    </div>
  </form>
</div>

<?php if ($found !== null && $found !== []): ?>
<h2 class="mt-m"><?= e(t('new.found', ['count' => count($found)])) ?></h2>
<p class="note"><?= e(t('new.found.hint')) ?></p>

<ul class="candidates">
  <?php foreach ($found as $candidate): ?>
  <li>
    <?php /* The ground is always there and the picture is laid over it, so a
             record the catalogue has no cover for shows a coloured tile
             rather than a broken image. Measured across three real searches:
             12 of 24 candidates had a cover, 7 had none and 5 had no ISBN to
             ask with - broken icons would have been as common as pictures. */ ?>
    <span class="candidate-cover <?= e(App\Core\CoverImage::placeholderClass((string) ($candidate['isbn13'] ?? $candidate['title']))) ?><?php
        if ($candidate['isbn13'] !== null): ?> cover-<?= e($candidate['isbn13']) ?><?php endif; ?>" aria-hidden="true"></span>
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

<?php
  /* One background rule per candidate, in a nonce-bearing style element.
   *
   * Not App\Core\Styles: that class takes numbers and formats them itself,
   * which is the property that makes emitting nonce-bearing CSS safe, and a
   * method there accepting a URL would give that up for every later caller.
   *
   * Here the safety comes from the value instead. Every one of these has been
   * through Isbn::normalize, so it is thirteen digits and cannot carry a
   * quote, a bracket or a space out of the catalogue and into a stylesheet.
   * The check below says so out loud rather than trusting it.
   *
   * Fetched by the browser rather than by the server: ten HEAD requests
   * before rendering would put three seconds on every search, and this page
   * is only ever seen by whoever is signed in - the same standing under which
   * a cover's own source URL is shown on the edit page. */
  $rules = [];
  foreach ($found as $candidate) {
      $isbn = (string) ($candidate['isbn13'] ?? '');
      if (preg_match('/^[0-9]{13}$/', $isbn) !== 1) {
          continue;
      }
      $rules['.cover-' . $isbn] = '.cover-' . $isbn
          . '{background-image:url("' . App\Lookup\MvbCoverLookup::coverUrl($isbn) . '")}';
  }
?>
<?php if ($rules !== []): ?>
<style nonce="<?= e($cspNonce) ?>"><?= implode("\n", $rules) ?></style>
<?php endif; ?>
<?php endif; ?>
