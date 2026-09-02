<?php
/**
 * Shown while the Impressum or the privacy policy is still a draft.
 *
 * The seeded texts say so at the top of themselves, which is the right place
 * for a visitor and the wrong one for the operator: somebody who never opens
 * the page never reads the notice. This is the same reminder where the work
 * is actually looked at.
 *
 * Renders nothing once both texts have been through and the ⚠ marks are
 * gone, so it costs a finished installation nothing.
 *
 * @var list<string> $legalOpen
 */
declare(strict_types=1);

if (($legalOpen ?? []) === []) {
    return;
}
?>
<p class="flash flash--hint">
  <?= e(t('legal.draft.hint')) ?>
  <?php foreach ($legalOpen as $index => $slug): ?>
    <?= $index > 0 ? ' · ' : '' ?><a href="/<?= e($slug) ?>"><?= e(t('page.' . $slug)) ?></a>
  <?php endforeach; ?>
</p>
