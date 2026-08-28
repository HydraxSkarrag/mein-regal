<?php
/**
 * One book.
 *
 * Price, acquisition type and notes are only rendered for a signed-in
 * visitor. That is partly privacy and partly caution: 304 of these books are
 * review copies, and a public list that labels free copies as such invites a
 * discussion about advertising disclosure. Not showing it is simpler than
 * labelling it correctly.
 *
 * @var array<string,mixed> $book
 */
declare(strict_types=1);
?>
<p><a href="/">&larr; <?= e(t('book.back')) ?></a></p>

<div class="book-detail">
  <div>
    <?= $view->render('partials.cover', [
        'book'       => $book,
        'cover'      => $cover,
        'authorLine' => $authorLine,
        'sizes'      => '220px',
    ]) ?>
    <?php if (($cover['attribution'] ?? null) !== null): ?>
    <p class="attribution"><?= e($cover['attribution']) ?></p>
    <?php endif; ?>
  </div>

  <div>
    <h1><?= e($book['title']) ?></h1>
    <?php if (($book['subtitle'] ?? null) !== null): ?>
    <p style="color:var(--muted);margin:2px 0 12px"><?= e($book['subtitle']) ?></p>
    <?php endif; ?>

    <?php if ($contributors !== []): ?>
    <p style="margin:6px 0 18px">
      <?php foreach ($contributors as $index => $person): ?>
        <?= $index > 0 ? ' · ' : '' ?><?= e($person['name']) ?><?php if ($person['role'] !== 'author'): ?><span style="color:var(--muted)"> (<?= e(t('role.' . $person['role'])) ?>)</span><?php endif; ?>
      <?php endforeach; ?>
    </p>
    <?php endif; ?>

    <?php if ($book['rating'] !== null): ?>
    <p class="stars" aria-label="<?= e(t('book.rating')) ?>: <?= (int) $book['rating'] ?>/5">
      <?php for ($star = 1; $star <= 5; $star++): ?><span<?= $star > (int) $book['rating'] ? ' class="off"' : '' ?>>&#9733;</span><?php endfor; ?>
    </p>
    <?php endif; ?>

    <table class="meta">
      <tbody>
      <?php if (($book['publisher'] ?? null) !== null): ?>
      <tr><th><?= e(t('book.publisher')) ?></th><td><?= e($book['publisher']) ?></td></tr>
      <?php endif; ?>
      <?php if ($book['published_year'] !== null): ?>
      <tr><th><?= e(t('book.year')) ?></th><td><?= e((string) $book['published_year']) ?></td></tr>
      <?php endif; ?>
      <?php if ($book['page_count'] !== null): ?>
      <tr><th><?= e(t('book.pages')) ?></th><td><?= e($formatter->number((int) $book['page_count'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($book['audio_minutes'] !== null): ?>
      <tr><th><?= e(t('book.duration')) ?></th><td><?= e($duration) ?></td></tr>
      <?php endif; ?>
      <?php if ($book['binding'] !== null): ?>
      <tr><th><?= e(t('book.binding')) ?></th><td><?= e(t('binding.' . $book['binding'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($book['language'] !== null): ?>
      <tr><th><?= e(t('book.language')) ?></th><td><?= e(t('lang.' . $book['language'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($book['isbn13'] !== null): ?>
      <tr><th><?= e(t('book.isbn')) ?></th><td><?= e($isbnFormatted) ?></td></tr>
      <?php endif; ?>
      <tr><th><?= e(t('book.rating')) ?></th><td><?= $book['rating'] === null ? e(t('book.unrated')) : e((string) $book['rating']) . ' / 5' ?></td></tr>
      <?php if ($book['started_at'] !== null): ?>
      <tr><th><?= e(t('book.started')) ?></th><td><time datetime="<?= e($formatter->iso($book['started_at'])) ?>"><?= e($formatter->date($book['started_at'])) ?></time></td></tr>
      <?php endif; ?>
      <?php if ($book['finished_at'] !== null): ?>
      <tr><th><?= e(t('book.finished')) ?></th><td><time datetime="<?= e($formatter->iso($book['finished_at'])) ?>"><?= e($formatter->date($book['finished_at'])) ?></time></td></tr>
      <?php endif; ?>

      <?php if ($signedIn): ?>
        <?php if ($book['price'] !== null): ?>
      <tr><th><?= e(t('book.price')) ?></th><td><?= e($formatter->money((float) $book['price'], (string) $book['price_currency'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($book['acquisition_type'] !== null): ?>
      <tr><th><?= e(t('book.acquired.as')) ?></th><td><?= e(t('acquired.' . $book['acquisition_type'])) ?></td></tr>
        <?php endif; ?>
        <?php if ($book['acquired_at'] !== null && (int) $book['acquired_at_is_bulk'] === 0): ?>
      <tr><th><?= e(t('book.acquired')) ?></th><td><time datetime="<?= e($formatter->iso($book['acquired_at'])) ?>"><?= e($formatter->date($book['acquired_at'])) ?></time></td></tr>
        <?php endif; ?>
        <?php if (($book['notes'] ?? null) !== null): ?>
      <tr><th><?= e(t('book.notes')) ?></th><td><?= nl2br(e($book['notes'])) ?></td></tr>
        <?php endif; ?>
      <?php endif; ?>
      </tbody>
    </table>

    <?php if ($tags !== []): ?>
    <div class="chips" style="margin-top:18px">
      <?php foreach ($tags as $tag): ?>
      <a class="chip" href="/?tag=<?= e(rawurlencode($tag['slug'])) ?>"><?= e($tag['name']) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
