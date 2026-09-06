<?php
/**
 * A labelled bar list - the same shape the quiz on the neighbouring subdomain
 * uses, and the reason no charting library is needed here.
 *
 * @var array<string,int> $counts
 * @var callable|null     $label
 * @var bool|null         $labelIsMarkup
 */
declare(strict_types=1);

$max = $counts === [] ? 1 : max(1, max($counts));
$label = $label ?? static fn (string $key): string => $key;

/* Labels are escaped, because most of them are names out of the shelf - a
 * genre, an author, a binding. One caller draws stars instead, and half a
 * star is a span with a gradient clipped to it rather than a character worth
 * printing; it opts in, and what it passes is markup it built itself from
 * numbers. Nothing that arrives here from a form or a catalogue may. */
$labelIsMarkup = $labelIsMarkup ?? false;
?>
<ul class="bars">
<?php foreach ($counts as $key => $count): ?>
  <li>
    <div class="row">
      <span><?= $labelIsMarkup ? $label((string) $key) : e($label((string) $key)) ?></span>
      <span class="n"><?= e($formatter->number($count)) ?></span>
    </div>
    <div class="bar">
      <div class="bar-fill <?= e($styles->width($count / $max * 100)) ?>"></div>
    </div>
  </li>
<?php endforeach; ?>
</ul>
