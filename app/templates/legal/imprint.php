<?php
/**
 * Impressum.
 *
 * German only, deliberately: this is a legal notice for a German operator and
 * belongs in German whatever the interface is set to.
 *
 * The details come from config.php so a second installation does not publish
 * somebody else's address. Where a value is missing the placeholder is shown
 * rather than an empty line, so a gap is impossible to miss.
 *
 * NOTE: the blog's own Impressum names no responsible party under
 * section 18 (2) MStV. As soon as review texts or blog excerpts appear here,
 * that entry is required. A person should read this page before it goes live.
 */
declare(strict_types=1);

$missing = static fn (string $value, string $hint): string => trim($value) !== ''
    ? $value
    : '⚠ ' . $hint . ' in config.php eintragen';
?>
<h1><?= e(t('legal.imprint')) ?></h1>

<h2>Angaben gemäß § 5 DDG</h2>
<p>
  <?= e($missing((string) ($legal['operator'] ?? ''), 'legal.operator')) ?><br>
  <?= e($missing((string) ($legal['street'] ?? ''), 'legal.street')) ?><br>
  <?= e($missing((string) ($legal['city'] ?? ''), 'legal.city')) ?>
</p>

<h2>Kontakt</h2>
<p>E-Mail: <?= e($missing((string) ($legal['email'] ?? ''), 'legal.email')) ?></p>

<?php if (trim((string) ($legal['mstv_responsible'] ?? '')) !== ''): ?>
<h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2>
<p><?= e($legal['mstv_responsible']) ?></p>
<?php endif; ?>

<h2>Haftung für Inhalte</h2>
<p>
  Als Diensteanbieterin bin ich für eigene Inhalte auf diesen Seiten nach den
  allgemeinen Gesetzen verantwortlich. Ich bin nicht verpflichtet, übermittelte
  oder gespeicherte fremde Informationen zu überwachen oder nach Umständen zu
  forschen, die auf eine rechtswidrige Tätigkeit hinweisen.
</p>

<h2>Haftung für Links</h2>
<p>
  Diese Seite enthält Links zu externen Websites Dritter, auf deren Inhalte ich
  keinen Einfluss habe. Für die Inhalte der verlinkten Seiten ist stets die
  jeweilige Anbieterin oder der jeweilige Anbieter verantwortlich.
</p>

<h2>Urheberrecht</h2>
<p>
  Die auf dieser Seite gezeigten Buchcover sind urheberrechtlich geschützt und
  gehören den jeweiligen Rechteinhabern. Öffentlich gezeigt werden ausschließlich
  selbst aufgenommene Fotografien der eigenen Exemplare sowie Coverabbildungen,
  die von den Verlagen zur Verwendung bereitgestellt werden. Die bibliografischen
  Daten stammen unter anderem von der Deutschen Nationalbibliothek und stehen
  dort unter CC0.
</p>
