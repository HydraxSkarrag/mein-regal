<?php
/**
 * One menu for the whole of the private area.
 *
 * Each screen used to carry its own way back - a left arrow here, a quiet
 * link in a heading there, and nothing at all pointing sideways between them.
 * Every entry is always shown, so the parts that exist are visible from
 * anywhere rather than having to be remembered; the current one is marked
 * rather than removed, because a menu that drops the page you are on tells
 * you less, not more.
 *
 * Only what is not already in the header: scanning has its own entry up
 * there on every page, and listing it twice makes the second one look like a
 * different thing.
 *
 * @var string $adminCurrent which entry is the page being shown
 */
declare(strict_types=1);

$entries = [
    'admin' => ['url' => '/admin',       'label' => t('nav.admin')],
    'data'  => ['url' => '/admin/data',  'label' => t('maintenance.title')],
    'tags'  => ['url' => '/admin/tags',  'label' => t('tags.title')],
];
$current = $adminCurrent ?? '';
?>
<nav class="admin-nav" aria-label="<?= e(t('nav.admin')) ?>">
  <?php foreach ($entries as $key => $entry): ?>
  <a href="<?= e($entry['url']) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><?= e($entry['label']) ?></a>
  <?php endforeach; ?>
</nav>
