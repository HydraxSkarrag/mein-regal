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
<p class="detail-actions">
  <a href="/">&larr; <?= e(t('book.back')) ?></a>
  <?php if ($signedIn): ?>
  <a href="/book/<?= e($book['slug']) ?>/edit"><?= e(t('book.edit')) ?></a>
  <?php endif; ?>
</p>

<div class="book-detail">
  <div>
    <?= $view->render('partials.cover', [
        'book'       => $book,
        'cover'      => $cover,
        'authorLine' => $authorLine,
        'sizes'      => '220px',
    ]) ?>
    <?php if (($cover['attribution'] ?? null) !== null): ?>
    <p class="attribution">
      <?php if ($coverLink !== null): ?>
      <a href="<?= e($coverLink) ?>" target="_blank" rel="noopener nofollow"><?= e($cover['attribution']) ?></a>
      <?php else: ?>
      <?= e($cover['attribution']) ?>
      <?php endif; ?>
    </p>
    <?php endif; ?>
  </div>

  <div>
    <h1><?= e($book['title']) ?></h1>
    <?php if (($book['subtitle'] ?? null) !== null): ?>
    <p class="lede"><?= e($book['subtitle']) ?></p>
    <?php endif; ?>

    <?php if ($series !== null): ?>
    <?php /* Under the title, above the authors: it says which book this is,
             which is the same job the title does and a different one from
             saying who wrote it.
             
             "Band 6 von 12" only where a twelve was written down. Counting
             what is on the shelf instead would turn three volumes of a
             septology into "Band 3 von 3". */ ?>
    <p class="series-line">
      <a href="/reihe/<?= e($series['slug']) ?>"><?= e($series['name']) ?></a>
      <?php if ($book['series_index'] !== null): ?>
        <span class="series-volume"><?= e($series['total'] !== null
            ? t('book.series.of', [
                'number' => App\Core\Formatter::volume($book['series_index']),
                'total'  => $series['total'],
              ])
            : t('book.series.only', ['number' => App\Core\Formatter::volume($book['series_index'])])) ?></span>
      <?php endif; ?>
    </p>

    <?php if ($neighbours['previous'] !== null || $neighbours['next'] !== null): ?>
    <?php /* The reason a volume number is worth typing: from the book in
             hand, the one before and the one after are a click away. Only
             volumes actually on the shelf - a link to a book nobody owns
             leads nowhere. */ ?>
    <p class="series-steps">
      <?php if ($neighbours['previous'] !== null): ?>
      <a href="/book/<?= e($neighbours['previous']['slug']) ?>">&larr; <?= e($neighbours['previous']['title']) ?></a>
      <?php endif; ?>
      <?php if ($neighbours['next'] !== null): ?>
      <a href="/book/<?= e($neighbours['next']['slug']) ?>"><?= e($neighbours['next']['title']) ?> &rarr;</a>
      <?php endif; ?>
    </p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($authors !== []): ?>
    <p class="byline">
      <?php foreach ($authors as $index => $person): ?>
        <?= $index > 0 ? ' · ' : '' ?><a href="/?author=<?= e(rawurlencode($person['name'])) ?>"><?= e($person['name']) ?></a>
      <?php endforeach; ?>
    </p>
    <?php endif; ?>

    <?php /* Everybody else, under what they did. In the byline they read as
             co-authors, which is what a bracket after a name does. */ ?>
    <?php if ($otherRoles !== []): ?>
    <ul class="credits">
      <?php foreach ($otherRoles as $role => $people): ?>
      <li>
        <span class="credits-role"><?= e(t('role.' . $role)) ?>:</span>
        <?php foreach ($people as $index => $person): ?>
          <?= $index > 0 ? ', ' : '' ?><a href="/?author=<?= e(rawurlencode($person['name'])) ?>"><?= e($person['name']) ?></a>
        <?php endforeach; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php $stars = App\Core\Formatter::stars($book['rating']); ?>
    <?php if ($stars !== null): ?>
    <p aria-label="<?= e(t('book.rating')) ?>: <?= e($stars['text']) ?> / 5">
      <?= $view->render('partials.stars', ['rating' => $book['rating'], 'withEmpty' => true]) ?>
      <span class="stars-text"><?= e($stars['text']) ?></span>
    </p>
    <?php endif; ?>

    <?php if (($book['review_url'] ?? null) !== null): ?>
    <p class="review-link">
      <a class="btn" href="<?= e($book['review_url']) ?>" target="_blank" rel="noopener">
        <?= e(t('book.review.read', ['blog' => $blogName])) ?> &rarr;
      </a>
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
      <tr><th><?= e(t('book.rating')) ?></th><td><?= $stars === null ? e(t('book.unrated')) : e($stars['text']) . ' / 5' ?></td></tr>
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

    <?php
      /* Genres and labels, told apart only when there is something to tell
         apart.
         
         Naming both groups on every book would put two headings over two
         words, and on the 43% of this shelf that carry labels alone it would
         introduce a distinction the page cannot demonstrate - the operator's
         vocabulary, spent on a visitor who came to find another book. Where
         a book has both, the headings earn themselves: one link leads to a
         wide shelf and the other to a narrow list, and that is worth knowing
         before clicking.
         
         A description list rather than headings, because that is what this
         is - a name and the things under it - and because the page has one
         heading, an h1, which these are not. */
      $grouped = App\Controller\ShelfController::tagGroups($tags);
      $bothKinds = $grouped['genre'] !== [] && $grouped['label'] !== [];
    ?>
    <?php if ($tags !== []): ?>
      <?php if ($bothKinds): ?>
      <dl class="tag-groups mt-s">
        <?php foreach (['genre' => 'filter.genre', 'label' => 'filter.label'] as $kind => $key): ?>
        <dt><?= e(t($key)) ?></dt>
        <dd>
          <div class="chips">
            <?php foreach ($grouped[$kind] as $tag): ?>
            <a class="chip" href="/?tag=<?= e(rawurlencode($tag['slug'])) ?>"><?= e($tag['name']) ?></a>
            <?php endforeach; ?>
          </div>
        </dd>
        <?php endforeach; ?>
      </dl>
      <?php else: ?>
      <div class="chips mt-s">
        <?php foreach ($tags as $tag): ?>
        <a class="chip" href="/?tag=<?= e(rawurlencode($tag['slug'])) ?>"><?= e($tag['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
