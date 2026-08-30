<?php
/**
 * What this shelf is.
 *
 * The text comes from the database, written by whoever owns the shelf. Until
 * someone writes it, the page says so plainly rather than showing an
 * invented placeholder that might quietly stay forever.
 */
declare(strict_types=1);

use App\Core\Text;
?>
<h1><?= e($page['title'] ?? t('about.title')) ?></h1>

<?php if (($page['body'] ?? null) !== null && trim((string) $page['body']) !== ''): ?>
<div class="prose"><?= Text::prose($page['body']) ?></div>
<?php else: ?>
<p class="empty">
  <?= e(t('about.empty')) ?>
  <?php if ($otherWith !== []): ?>
  <br><span class="note"><?= e(t('about.other.language')) ?></span>
  <?php endif; ?>
  <?php if ($signedIn): ?>
  <br><a href="/ueber/bearbeiten?sprache=<?= e($locale) ?>"><?= e(t('about.write')) ?></a>
  <?php endif; ?>
</p>
<?php endif; ?>

<?php if ($signedIn): ?>
<p style="margin-top:28px"><a href="/ueber/bearbeiten?sprache=<?= e($locale) ?>"><?= e(t('book.edit')) ?></a></p>
<?php endif; ?>
