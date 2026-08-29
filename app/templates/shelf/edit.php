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

      <div class="field">
        <label for="tags"><?= e(t('filter.genre')) ?></label>
        <input id="tags" type="text" name="tags" value="<?= e($tagList) ?>">
        <p class="note"><?= e(t('edit.tags.hint')) ?></p>
      </div>

      <?php if ($isbnFormatted !== ''): ?>
      <p class="note"><?= e(t('book.isbn')) ?>: <?= e($isbnFormatted) ?></p>
      <?php endif; ?>
    </div>

    <div>
      <div class="panel">
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
            <?php for ($stars = 5; $stars >= 1; $stars--): ?>
            <option value="<?= $stars ?>"<?= (int) ($book['rating'] ?? 0) === $stars ? ' selected' : '' ?>>
              <?= str_repeat('★', $stars) ?> (<?= $stars ?>)
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

      <div class="panel" style="margin-top:20px">
        <h2><?= e(t('edit.group.cover')) ?></h2>
        <?php if ($cover !== null): ?>
        <div style="max-width:120px;margin-bottom:12px">
          <?= $view->render('partials.cover', ['book' => $book, 'cover' => $cover, 'authorLine' => '', 'sizes' => '120px']) ?>
        </div>
        <?php endif; ?>
        <div class="field">
          <label for="cover"><?= e(t('edit.cover.upload')) ?></label>
          <input id="cover" type="file" name="cover" accept="image/*" capture="environment">
          <p class="note"><?= e(t('edit.cover.hint')) ?></p>
        </div>
      </div>
    </div>
  </div>

  <div class="edit-actions">
    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
    <a class="btn" href="/buch/<?= e($book['slug']) ?>"><?= e(t('common.cancel')) ?></a>
  </div>
</form>
