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
 * @var array<string,string> $previews ISBN => data: URI
 * @var ?array<string,mixed> $justAdded the book the last submission dealt with
 * @var string  $csrfField
 */
declare(strict_types=1);
?>
<div class="page-head">
  <h1><?= e(t('new.title')) ?></h1>
</div>

<?php if ($justAdded !== null): ?>
<?php /* What the last submission did, and the way to it - the same block the
         scanner shows after saving, for the same reason: the message alone
         leaves you wondering which edition went in, and the book is not worth
         going and searching for when it was in your hand a second ago.
         
         Above the form rather than below the results, because the form is
         where the eyes are: the fields are empty again and the next title is
         about to be typed. */ ?>
<div class="card after-added">
  <p class="mt-0"><strong><?= e($justAdded['title']) ?></strong></p>
  <div class="form-actions">
    <a class="btn" href="/book/<?= e($justAdded['slug']) ?>"><?= e(t('scan.open.book')) ?></a>
    <a class="btn" href="/book/<?= e($justAdded['slug']) ?>/edit"><?= e(t('book.edit')) ?></a>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <p class="note mt-0"><?= e(t('new.hint')) ?></p>

  <?php if ($error !== null): ?>
  <p class="form-error" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="/book/new">
    <?= $csrfField ?>
    <div class="field mb-s">
      <label for="title"><?= e(t('book.title')) ?></label>
      <?php /* autofocus until there is something to read.
               
               This page exists to be typed into, and whoever reached it
               already knows what they are going to type - but once the
               catalogue has answered, the answer is the point. Focus wins
               over a fragment: measured, the browser put the cursor in this
               field and left the results eight hundred pixels below the
               fold, which on a phone is a search that appears to have done
               nothing at all. */ ?>
      <input id="title" type="text" name="title" value="<?= e($title) ?>"
             maxlength="500" autocomplete="off" required<?= $found === null ? ' autofocus' : '' ?>>
    </div>

    <div class="field mb-s">
      <label for="author"><?= e(t('new.author')) ?></label>
      <input id="author" type="text" name="author" value="<?= e($author) ?>"
             maxlength="255" autocomplete="off">
      <p class="note"><?= e(t('new.author.hint')) ?></p>
    </div>

    <div class="form-actions">
      <?php /* formaction rather than the form's own action, so the fragment
               belongs to this button alone. A POST carries the fragment of
               the address it was sent to, which is what puts the results on
               screen without a line of JavaScript - and "Ohne Suche anlegen"
               redirects away, where a leftover #results would only make an
               odd address. */ ?>
      <button class="btn btn--primary" type="submit" name="action" value="search"
              formaction="/book/new#results">
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
<?php /* Where the search button sends the page. Nothing scrolls here without
         it: the form posts, the browser draws the answer below a card that
         fills a phone screen, and the reader sees the same form they just
         submitted. */ ?>
<h2 class="mt-m" id="results"><?= e(t('new.found', ['count' => count($found)])) ?></h2>
<p class="note"><?= e(t('new.found.hint')) ?></p>

<ul class="candidates">
  <?php foreach ($found as $candidate): ?>
  <li>
    <form method="post" action="/book/new">
      <?= $csrfField ?>
      <?php /* Inside the form, which is the flex row: beside the text and the
               button, not stacked above them. It was a sibling of the form to
               begin with, and there it was an inline span in a list item -
               width and height do not apply to those, so it had no size at
               all and neither the tile nor the picture ever appeared.
               
               The ground is always there and the picture is laid over it, so a
               record the catalogue has no cover for shows a coloured tile
               rather than a broken image. Measured across three real searches:
               12 of 24 candidates had a cover, 7 had none and 5 had no ISBN to
               ask with - broken icons would have been as common as pictures. */ ?>
      <span class="candidate-cover <?= e(App\Core\CoverImage::placeholderClass((string) ($candidate['isbn13'] ?? $candidate['title']))) ?><?php
          if ($candidate['isbn13'] !== null): ?> cover-<?= e($candidate['isbn13']) ?><?php endif; ?>" aria-hidden="true"></span>
      <input type="hidden" name="action" value="create">
      <?php /* Which button this was, so the controller knows where to send
               the reader afterwards: a picked record is complete and the run
               carries on here, a bare title has to be finished on the edit
               page. Not guessed from the fields - a catalogue record without
               an ISBN or a publisher looks exactly like a typed one. */ ?>
      <input type="hidden" name="from" value="search">
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
   * The picture itself is here rather than a link to it - the server fetched
   * and shrank it while the search ran, so this is a few kilobytes of base64
   * and the browser contacts nobody. That is not only tidier: the catalogue
   * puts a bot check in front of a browser's first request, so a page loading
   * these itself got HTML where it wanted a cover, and got it or not
   * depending on whether that browser had been to the DNB before.
   *
   * Not App\Core\Styles: that class takes numbers and formats them itself,
   * which is the property that makes emitting nonce-bearing CSS safe, and a
   * method there accepting a string would give that up for every later
   * caller. Here the safety comes from the values instead - the selector is
   * thirteen digits that have been through Isbn::normalize, and the URI is
   * base64 this process just produced. The check below says so out loud
   * rather than trusting it.
   *
   * A candidate the catalogue has no picture for gets no rule at all, and
   * keeps the coloured ground the tile already has. */
  $rules = [];
  foreach ($found as $candidate) {
      $isbn = (string) ($candidate['isbn13'] ?? '');
      if (preg_match('/^[0-9]{13}$/', $isbn) !== 1 || !isset($previews[$isbn])) {
          continue;
      }
      $rules['.cover-' . $isbn] = '.cover-' . $isbn
          . '{background-image:url("' . $previews[$isbn] . '")}';
  }
?>
<?php if ($rules !== []): ?>
<style nonce="<?= e($cspNonce) ?>"><?= implode("\n", $rules) ?></style>
<?php endif; ?>
<?php endif; ?>
