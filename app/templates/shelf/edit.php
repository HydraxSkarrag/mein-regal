<?php
/**
 * Editing one book.
 *
 * Grouped the way the questions actually come up: what the book is, how it
 * was read, and how it came into the house. The fields the metadata sources
 * can never supply - price, provenance, the review on the blog - sit in their
 * own group, because those are the ones only she can fill in.
 */
declare(strict_types=1);

$value = static fn (?string $v): string => $v ?? '';
?>
<p class="detail-actions">
  <a href="/buch/<?= e($book['slug']) ?>">&larr; <?= e($book['title']) ?></a>
</p>

<h1><?= e(t('book.edit')) ?></h1>

<?php if (($error ?? '') !== ''): ?>
<p class="form-error"><?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="/buch/<?= e($book['slug']) ?>/bearbeiten" enctype="multipart/form-data" class="edit-form">
  <?= $csrfField ?>

  <div class="edit-grid">
    <div class="panel">
      <h2><?= e(t('edit.group.book')) ?></h2>

      <div class="field">
        <label for="title"><?= e(t('edit.title')) ?></label>
        <input id="title" type="text" name="title" value="<?= e($book['title']) ?>" required maxlength="500">
      </div>

      <div class="field">
        <label for="subtitle"><?= e(t('book.subtitle')) ?></label>
        <input id="subtitle" type="text" name="subtitle" value="<?= e($value($book['subtitle'])) ?>" maxlength="500">
      </div>

      <fieldset class="contributors">
        <legend><?= e(t('edit.contributors')) ?></legend>
        <?php
          $rows = $contributors;
          // Always offer two empty rows, so adding someone needs no extra click.
          $rows[] = ['name' => '', 'role' => 'author'];
          $rows[] = ['name' => '', 'role' => 'author'];
        ?>
        <?php foreach ($rows as $index => $person): ?>
        <div class="contributor-row">
          <label class="visually-hidden" for="an<?= $index ?>"><?= e(t('edit.contributor.name')) ?></label>
          <input id="an<?= $index ?>" type="text" name="author_name[]" value="<?= e($person['name']) ?>"
                 placeholder="<?= e(t('edit.contributor.name')) ?>" maxlength="255">
          <label class="visually-hidden" for="ar<?= $index ?>"><?= e(t('edit.contributor.role')) ?></label>
          <select id="ar<?= $index ?>" name="author_role[]">
            <?php foreach ($roles as $role): ?>
            <option value="<?= e($role) ?>"<?= $person['role'] === $role ? ' selected' : '' ?>><?= e(t('role.' . $role)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
        <p class="note"><?= e(t('edit.contributor.hint')) ?></p>
      </fieldset>

      <div class="field-row">
        <div class="field">
          <label for="publisher"><?= e(t('book.publisher')) ?></label>
          <input id="publisher" type="text" name="publisher" value="<?= e($value($book['publisher'])) ?>" maxlength="255">
        </div>
        <div class="field">
          <label for="published_year"><?= e(t('book.year')) ?></label>
          <input id="published_year" type="text" inputmode="numeric" name="published_year"
                 value="<?= e((string) ($book['published_year'] ?? '')) ?>" maxlength="4">
        </div>
      </div>

      <div class="field-row">
        <div class="field">
          <label for="page_count"><?= e(t('book.pages')) ?></label>
          <input id="page_count" type="text" inputmode="numeric" name="page_count"
                 value="<?= e((string) ($book['page_count'] ?? '')) ?>" maxlength="5">
        </div>
        <div class="field">
          <label for="binding"><?= e(t('book.binding')) ?></label>
          <select id="binding" name="binding">
            <option value=""><?= e(t('common.unknown')) ?></option>
            <?php foreach ($bindings as $option): ?>
            <option value="<?= e($option) ?>"<?= $book['binding'] === $option ? ' selected' : '' ?>><?= e(t('binding.' . $option)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="language"><?= e(t('book.language')) ?></label>
          <select id="language" name="language">
            <option value=""><?= e(t('common.unknown')) ?></option>
            <option value="ger"<?= $book['language'] === 'ger' ? ' selected' : '' ?>><?= e(t('lang.ger')) ?></option>
            <option value="eng"<?= $book['language'] === 'eng' ? ' selected' : '' ?>><?= e(t('lang.eng')) ?></option>
          </select>
        </div>
      </div>

      <div class="field" id="tag-field">
        <label for="tags"><?= e(t('filter.genre')) ?></label>
        <input id="tags" type="text" name="tags" value="<?= e($tagList) ?>" autocomplete="off">
        <p class="note"><?= e(t('edit.tags.hint')) ?></p>
      </div>

      <script type="application/json" id="known-tags"><?= json_for_script($knownTags) ?></script>
      <script type="application/json" id="tag-i18n"><?= json_for_script([
          'placeholder' => t('edit.tags.placeholder'),
          'newTag'      => t('edit.tags.new'),
          'similar'     => t('edit.tags.similar'),
          'remove'      => t('edit.tags.remove'),
          'books'       => t('edit.tags.books'),
      ]) ?></script>

      <div class="field">
        <label for="isbn13"><?= e(t('book.isbn')) ?></label>
        <input id="isbn13" type="text" name="isbn13" inputmode="numeric" autocomplete="off"
               value="<?= e($isbnFormatted) ?>" placeholder="978-3-473-40806-1">
        <p class="note"><?= e(t('edit.isbn.hint')) ?></p>
      </div>
    </div>

    <div>
      <div class="panel">
        <h2><?= e(t('edit.group.cover')) ?></h2>
        <?php if ($cover !== null): ?>
        <div class="cover-current">
          <div style="max-width:110px">
            <?= $view->render('partials.cover', ['book' => $book, 'cover' => $cover, 'authorLine' => '', 'sizes' => '110px']) ?>
          </div>
          <div>
            <p class="note" style="margin-top:0"><?= e(t('cover.from.' . $cover['source'])) ?></p>
            <button class="btn btn--danger" type="submit" form="cover-delete"><?= e(t('cover.remove')) ?></button>
          </div>
        </div>
        <?php endif; ?>
        <div class="field">
          <label for="cover"><?= e(t('edit.cover.upload')) ?></label>
          <input id="cover" type="file" name="cover" accept="image/*" capture="environment">
          <p class="note"><?= e(t('edit.cover.hint')) ?></p>
        </div>

        <div class="cover-search">
          <?php if (($book['isbn13'] ?? null) !== null): ?>
          <button class="btn" type="submit" form="cover-search"><?= e(t('cover.search')) ?></button>
          <p class="note"><?= e(t('cover.search.hint')) ?></p>
          <?php else: ?>
          <!-- Hiding the button without a word looks like a fault. Say why
               it is not there. -->
          <p class="note"><?= e(t('cover.search.no.isbn')) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="panel" style="margin-top:20px">
        <h2><?= e(t('edit.group.reading')) ?></h2>

        <div class="field">
          <label for="reading_status"><?= e(t('filter.status')) ?></label>
          <select id="reading_status" name="reading_status">
            <?php foreach ($statuses as $option): ?>
            <option value="<?= e($option) ?>"<?= $book['reading_status'] === $option ? ' selected' : '' ?>><?= e(t('status.' . $option)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field-row">
          <div class="field">
            <label for="started_at"><?= e(t('book.started')) ?></label>
            <input id="started_at" type="date" name="started_at" value="<?= e($value($book['started_at'])) ?>">
          </div>
          <div class="field">
            <label for="finished_at"><?= e(t('book.finished')) ?></label>
            <input id="finished_at" type="date" name="finished_at" value="<?= e($value($book['finished_at'])) ?>">
          </div>
        </div>

        <div class="field">
          <label for="rating"><?= e(t('book.rating')) ?></label>
          <select id="rating" name="rating">
            <option value=""><?= e(t('book.unrated')) ?></option>
            <?php
              $current = $book['rating'] === null ? null : round((float) $book['rating'] * 2) / 2;
              // Nicht $value nennen: so heißt oben schon die Escape-Hilfe,
              // und ein überschriebener Closure ist ein Fehler, der erst beim
              // nächsten Aufruf auffällt.
              for ($half = 10; $half >= 1; $half--):
                  $stepValue = $half / 2;
                  $parts = App\Core\Formatter::stars($stepValue);
            ?>
            <option value="<?= $stepValue ?>"<?= $current !== null && abs($current - $stepValue) < 0.01 ? ' selected' : '' ?>>
              <?= str_repeat('★', $parts['full']) . ($parts['half'] ? '⯪' : '') ?> (<?= e($parts['text']) ?>)
            </option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="field">
          <label for="notes"><?= e(t('book.notes')) ?></label>
          <textarea id="notes" name="notes" rows="4"><?= e($value($book['notes'])) ?></textarea>
        </div>
      </div>
      <div class="panel" style="margin-top:20px">
        <h2><?= e(t('edit.group.private')) ?></h2>
        <p class="note" style="margin-top:-6px"><?= e(t('edit.group.private.hint')) ?></p>

        <div class="field-row">
          <div class="field">
            <label for="price"><?= e(t('book.price')) ?></label>
            <input id="price" type="text" inputmode="decimal" name="price"
                   value="<?= e($book['price'] !== null ? (string) $book['price'] : '') ?>">
          </div>
          <div class="field">
            <label for="acquisition_type"><?= e(t('book.acquired.as')) ?></label>
            <select id="acquisition_type" name="acquisition_type">
              <option value=""><?= e(t('common.unknown')) ?></option>
              <?php foreach ($acquired as $option): ?>
              <option value="<?= e($option) ?>"<?= $book['acquisition_type'] === $option ? ' selected' : '' ?>><?= e(t('acquired.' . $option)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="field">
          <label for="acquired_at"><?= e(t('book.acquired')) ?></label>
          <input id="acquired_at" type="date" name="acquired_at" value="<?= e($value($book['acquired_at'])) ?>">
          <?php if ((int) ($book['acquired_at_is_bulk'] ?? 0) === 1): ?>
          <p class="note"><?= e(t('edit.bulk.hint')) ?></p>
          <?php endif; ?>
        </div>

        <div class="field">
          <label for="review_url"><?= e(t('book.review')) ?></label>
          <input id="review_url" type="url" name="review_url" value="<?= e($value($book['review_url'] ?? null)) ?>"
                 placeholder="https://www.buecherhausen.de/…" maxlength="500">
          <p class="note"><?= e(t('book.review.url')) ?></p>

        </div>
      </div>
    </div>
  </div>

  <div class="edit-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
    <!-- type="button" matters: a bare button inside a form submits it, and
         this one must not save on its way to asking about deleting. -->
    <button class="btn btn--danger" type="button" data-open-delete><?= e(t('delete.title')) ?></button>
    <a class="btn" href="/buch/<?= e($book['slug']) ?>"><?= e(t('common.cancel')) ?></a>
  </div>
</form>

<?php if ($cover !== null): ?>
<form id="cover-delete" method="post" action="/buch/<?= e($book['slug']) ?>/cover-loeschen" hidden>
  <?= $csrfField ?>
</form>
<?php endif; ?>

<?php if (($book['isbn13'] ?? null) !== null): ?>
<form id="cover-search" method="post" action="/buch/<?= e($book['slug']) ?>/cover-suchen" hidden>
  <?= $csrfField ?>
</form>
<?php endif; ?>

<details class="danger" id="delete-book">
  <summary><?= e(t('delete.title')) ?></summary>
  <div class="danger-body">
    <p class="note"><?= e(t('delete.explain')) ?></p>
    <form method="post" action="/buch/<?= e($book['slug']) ?>/loeschen">
      <?= $csrfField ?>
      <div class="field">
        <label for="confirm"><?= e(t('delete.type', ['word' => 'LÖSCHEN'])) ?></label>
        <input id="confirm" type="text" name="confirm" autocomplete="off" required
               pattern="LOESCHEN|LÖSCHEN" placeholder="LÖSCHEN">
      </div>
      <button class="btn btn--danger" type="submit"><?= e(t('delete.button')) ?></button>
    </form>
  </div>
</details>
