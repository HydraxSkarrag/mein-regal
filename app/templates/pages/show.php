<?php
/**
 * A prose page: about, Impressum or privacy policy.
 *
 * The text comes from the database, written by whoever owns the shelf. Until
 * someone writes it, the page says so plainly rather than showing an invented
 * placeholder that might quietly stay forever - and for the legal pages it
 * says so loudly, because a missing Impressum is a problem and not a style.
 */
declare(strict_types=1);

use App\Core\Text;
use App\Repository\PageRepository;

$written = ($page['body'] ?? null) !== null && trim((string) $page['body']) !== '';
$legal = $slug !== PageRepository::ABOUT;
?>
<h1><?= e($heading) ?></h1>

<?php if ($written): ?>
<div class="prose"><?= Text::prose($page['body']) ?></div>
  <?php if ($legal && ($page['locale'] ?? $locale) !== $locale): ?>
  <p class="note" style="margin-top:24px"><?= e(t('page.only.language', ['language' => t('lang.' . $page['locale'])])) ?></p>
  <?php endif; ?>
<?php else: ?>
<p class="<?= $legal ? 'form-error' : 'empty' ?>">
  <?= e($legal ? t('page.legal.empty') : t('about.empty')) ?>
  <?php if (!$legal && $otherWith !== []): ?>
  <br><span class="note"><?= e(t('about.other.language')) ?></span>
  <?php endif; ?>
  <?php if ($signedIn): ?>
  <br><a href="/<?= e($slug) ?>/edit?lang=<?= e($locale) ?>"><?= e(t('about.write')) ?></a>
  <?php endif; ?>
</p>
<?php endif; ?>

<?php if ($signedIn): ?>
<p style="margin-top:28px"><a href="/<?= e($slug) ?>/edit?lang=<?= e($locale) ?>"><?= e(t('book.edit')) ?></a></p>
<?php endif; ?>
