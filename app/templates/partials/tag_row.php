<?php
/**
 * One row of the genre-or-label list, in either of its two states.
 *
 * In use: the checkbox that says genre, the name, the number of books, and
 * the × that removes it. Just removed: a line saying so and a button that
 * puts it back, standing where the row stood, because that is where somebody
 * who pressed the wrong × is looking.
 *
 * Drawn by the page and by the two endpoints when a script asks, so the row
 * exists once and not a second time in JavaScript.
 *
 * Neither state holds a form of its own. Both buttons belong to
 * form#tag-actions, outside the list: a form cannot sit inside another, and a
 * submit button inside the genre form would be the one Enter presses - on the
 * first row, that would remove a tag instead of saving.
 *
 * The removed state has no genre field, which is what keeps it out of a save:
 * the save only touches the tags the form mentions.
 *
 * @var array{id: int, name: string, kind: string, dropped_at: ?string, book_count: int} $tag
 */
declare(strict_types=1);

$id = (string) (int) $tag['id'];
?>
<?php if ($tag['dropped_at'] === null): ?>
<li id="tag-row-<?= e($id) ?>">
  <input type="hidden" name="genre[<?= e($id) ?>]" value="0">
  <input type="checkbox" id="tag-<?= e($id) ?>"
         name="genre[<?= e($id) ?>]" value="1"
         <?= $tag['kind'] === 'genre' ? 'checked' : '' ?>>
  <label for="tag-<?= e($id) ?>">
    <span><?= e($tag['name']) ?></span>
    <span class="n"><?= e($formatter->number((int) $tag['book_count'])) ?></span>
  </label>
  <button class="tag-remove" type="submit" form="tag-actions"
          formaction="/admin/tags/<?= e($id) ?>/remove"
          title="<?= e(t('tags.remove')) ?>"
          aria-label="<?= e(t('tags.remove.named', ['name' => $tag['name']])) ?>">&times;</button>
</li>
<?php else: ?>
<li id="tag-row-<?= e($id) ?>" class="tag-undo">
  <span><?= e(t('tags.removed.inline', ['name' => $tag['name']])) ?></span>
  <button class="link-button" type="submit" form="tag-actions"
          formaction="/admin/tags/<?= e($id) ?>/restore"><?= e(t('tags.undo')) ?></button>
</li>
<?php endif; ?>
