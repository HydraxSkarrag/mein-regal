<?php
/**
 * A labelled bar list - the same shape the quiz on the neighbouring subdomain
 * uses, and the reason no charting library is needed here.
 *
 * @var array<string,int> $counts
 * @var callable|null     $label
 */
declare(strict_types=1);

$max = $counts === [] ? 1 : max(1, max($counts));
$label = $label ?? static fn (string $key): string => $key;
?>
<ul class="bars">
<?php foreach ($counts as $key => $count): ?>
  <li>
    <div class="row">
      <span><?= e($label((string) $key)) ?></span>
      <span class="n"><?= e($formatter->number($count)) ?></span>
    </div>
    <div class="bar">
      <div class="bar-fill <?= e($styles->width($count / $max * 100)) ?>"></div>
    </div>
  </li>
<?php endforeach; ?>
</ul>
