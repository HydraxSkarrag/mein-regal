<?php
/**
 * One horizontal bar split into its parts, with a legend underneath.
 *
 * Better than several separate bars for a part-to-whole question - "how much
 * of the shelf is unread" is read off the width, not by comparing numbers.
 *
 * @var array<string,int> $counts
 * @var callable|null     $label
 */
declare(strict_types=1);

$counts = array_filter($counts, static fn (int $n): bool => $n > 0);
if ($counts === []) {
    return;
}
$label = $label ?? static fn (string $key): string => $key;
$total = array_sum($counts);

// One hue, stepped. Colour here encodes size, not identity, so a second hue
// would only add noise.
$shades = ['#ed002f', '#f2506e', '#f7899d', '#fabecb', '#e4e4e7'];
$index = 0;
?>
<div class="split">
  <div class="split-bar" role="img" aria-label="<?= e($caption ?? '') ?>">
<?php foreach ($counts as $key => $count): ?>
    <span class="split-part" style="width: <?= round($count / $total * 100, 2) ?>%; background: <?= $shades[min($index, 4)] ?>">
      <span class="visually-hidden"><?= e($label((string) $key)) ?>: <?= e($formatter->number($count)) ?></span>
    </span>
    <?php $index++; ?>
<?php endforeach; ?>
  </div>
  <ul class="split-legend">
<?php $index = 0; foreach ($counts as $key => $count): ?>
    <li>
      <span class="swatch" style="background: <?= $shades[min($index, 4)] ?>"></span>
      <span><?= e($label((string) $key)) ?></span>
      <span class="n"><?= e($formatter->number($count)) ?></span>
      <span class="pct"><?= e($formatter->number($count / $total * 100, 1)) ?> %</span>
    </li>
    <?php $index++; ?>
<?php endforeach; ?>
  </ul>
</div>
