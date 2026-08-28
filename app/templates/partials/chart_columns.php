<?php
/**
 * A column chart as inline SVG, drawn on the server.
 *
 * Not a library, but not austere either: gridlines, a value axis, and a hover
 * state that names the exact figure. The rule the site lives by is that
 * nothing is fetched from another host - self-hosted markup and CSS may be as
 * elaborate as they need to be.
 *
 * @var array<int|string,int> $series
 * @var string                $caption
 * @var bool                  $partialLast last bucket still filling up
 */
declare(strict_types=1);

$series = array_filter($series, static fn (int $n): bool => $n > 0);
if ($series === []) {
    return;
}

$width = 720;
$height = 200;
$padLeft = 34;
$padBottom = 26;
$padTop = 10;

$max = max($series);
// Round the top of the scale up to something a person would choose.
$step = 10 ** max(0, (int) floor(log10(max($max, 1))) - 1);
$niceMax = (int) (ceil($max / ($step * 5)) * $step * 5);
$niceMax = max($niceMax, 1);

$count = count($series);
$plotWidth = $width - $padLeft;
$slot = $plotWidth / $count;
$barWidth = max(1.5, min(30.0, $slot * 0.7));
$plotHeight = $height - $padBottom - $padTop;

$keys = array_keys($series);
$every = (int) max(1, ceil($count / 12));
$labelled = [];
for ($i = 0; $i < $count; $i += $every) {
    $labelled[$i] = true;
}
if ($count > 1 && !isset($labelled[$count - 1])
    && $count - 1 - max(array_keys($labelled)) >= max(2, (int) ceil($every / 2))) {
    $labelled[$count - 1] = true;
}

$chartId = 'c' . substr(sha1($caption), 0, 6);

// Six columns stretched across twelve hundred pixels read as six lonely
// sticks. Give each column a sensible share and let the figure stop there;
// a dense series still fills the full width.
$maxWidth = min(1160, max(360, $padLeft + $count * 84));
?>
<figure class="chart-figure" style="max-width: <?= $maxWidth ?>px">
  <svg class="chart" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img"
       aria-labelledby="<?= $chartId ?>-t" preserveAspectRatio="xMidYMid meet">
    <title id="<?= $chartId ?>-t"><?= e($caption) ?></title>

<?php for ($line = 0; $line <= 4; $line++): ?>
    <?php $value = (int) round($niceMax / 4 * $line); ?>
    <?php $y = $padTop + $plotHeight - ($plotHeight / 4 * $line); ?>
    <line x1="<?= $padLeft ?>" y1="<?= round($y, 1) ?>" x2="<?= $width ?>" y2="<?= round($y, 1) ?>"
          stroke="#e4e4e7" stroke-width="1"<?= $line === 0 ? ' stroke="#d4d4d8"' : '' ?>></line>
    <text x="<?= $padLeft - 7 ?>" y="<?= round($y + 3.5, 1) ?>" text-anchor="end"
          font-size="10" fill="#a1a1aa" font-family="Inter, system-ui, sans-serif"><?= $value ?></text>
<?php endfor; ?>

<?php foreach (array_values($series) as $index => $value): ?>
    <?php
      $barHeight = max(1.5, ($value / $niceMax) * $plotHeight);
      $x = $padLeft + $index * $slot + ($slot - $barWidth) / 2;
      $y = $padTop + $plotHeight - $barHeight;
      $muted = ($partialLast ?? false) && $index === $count - 1;
    ?>
    <g class="chart-col">
      <rect x="<?= round($padLeft + $index * $slot, 2) ?>" y="<?= $padTop ?>"
            width="<?= round($slot, 2) ?>" height="<?= round($plotHeight, 2) ?>"
            fill="transparent" class="chart-hit"></rect>
      <rect x="<?= round($x, 2) ?>" y="<?= round($y, 2) ?>"
            width="<?= round($barWidth, 2) ?>" height="<?= round($barHeight, 2) ?>"
            rx="2" class="chart-bar<?= $muted ? ' chart-bar--muted' : '' ?>"></rect>
      <text x="<?= round($padLeft + $index * $slot + $slot / 2, 2) ?>" y="<?= round($y - 5, 2) ?>"
            text-anchor="middle" font-size="10" class="chart-value"
            font-family="Inter, system-ui, sans-serif"><?= e($formatter->number($value)) ?></text>
      <title><?= e((string) $keys[$index]) ?>: <?= e($formatter->number($value)) ?></title>
    </g>
<?php endforeach; ?>

<?php foreach ($keys as $index => $key): ?>
    <?php if (!isset($labelled[$index])) { continue; } ?>
    <text x="<?= round($padLeft + $index * $slot + $slot / 2, 2) ?>" y="<?= $height - 8 ?>"
          text-anchor="middle" font-size="10.5" fill="#71717a"
          font-family="Inter, system-ui, sans-serif"><?= e((string) $key) ?></text>
<?php endforeach; ?>
  </svg>
<?php if (($note ?? '') !== ''): ?>
  <figcaption class="note"><?= e($note) ?></figcaption>
<?php endif; ?>
</figure>
