<?php
/**
 * The page frame.
 *
 * @var string      $content
 * @var string      $title
 * @var App\Core\Translator $translator
 */
declare(strict_types=1);

$locale = $translator->locale();
$pageTitle = $title ?? t('app.shelf');
$noIndex = $noIndex ?? false;
$current = $current ?? '';
?>
<!DOCTYPE html>
<html lang="<?= e($locale) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($pageTitle) ?> – <?= e($siteName) ?></title>
  <?php if ($noIndex): ?>
  <meta name="robots" content="noindex, nofollow">
  <?php else: ?>
  <meta name="description" content="<?= e($metaDescription ?? t('app.tagline')) ?>">
  <link rel="canonical" href="<?= e($canonical ?? $siteUrl) ?>">
  <?php endif; ?>
  <meta name="theme-color" content="#ed002f">

  <link rel="stylesheet" href="/css/style.css?v=<?= e($assetVersion) ?>">
  <link rel="icon" href="/assets/favicon-32x32.png" sizes="32x32">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
  <link rel="manifest" href="/manifest.webmanifest">
<?php if (!$noIndex): ?>

  <meta property="og:type" content="<?= e($ogType ?? 'website') ?>">
  <meta property="og:locale" content="<?= e($locale === 'en' ? 'en_GB' : 'de_DE') ?>">
  <meta property="og:site_name" content="<?= e($siteName) ?>">
  <meta property="og:title" content="<?= e($ogTitle ?? $pageTitle) ?>">
  <meta property="og:description" content="<?= e($metaDescription ?? t('app.tagline')) ?>">
  <meta property="og:url" content="<?= e($canonical ?? $siteUrl) ?>">
  <?php if (($ogImage ?? null) !== null): ?>
  <meta property="og:image" content="<?= e($ogImage) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <?php else: ?>
  <meta name="twitter:card" content="summary">
  <?php endif; ?>
<?php endif; ?>
<?php if (($jsonLd ?? null) !== null): ?>

  <script type="application/ld+json">
<?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>

  </script>
<?php endif; ?>
</head>
<body>
  <a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>

  <header class="site-header">
    <a class="brand" href="/">
      <img src="/assets/logo.png" alt="" width="47" height="36" class="brand-logo">
      <span class="brand-name"><?= e(t('app.shelf')) ?></span>
    </a>
    <span class="brand-by"><?= e(t('app.by')) ?>
      <a href="<?= e($blogUrl) ?>?utm_source=regal&amp;utm_medium=app&amp;utm_campaign=header"
         target="_blank" rel="noopener"><?= e($blogName) ?></a></span>

    <nav class="header-nav" aria-label="<?= e(t('nav.menu')) ?>">
      <a class="nav-desktop-only" href="/"<?= $current === 'shelf' ? ' aria-current="page"' : '' ?>><?= e(t('nav.shelf')) ?></a>
      <a class="nav-desktop-only" href="/sub"<?= $current === 'sub' ? ' aria-current="page"' : '' ?>><?= e(t('nav.sub')) ?></a>
      <a class="nav-desktop-only" href="/statistik"<?= $current === 'stats' ? ' aria-current="page"' : '' ?>><?= e(t('nav.stats')) ?></a>
      <?php if ($signedIn): ?>
      <a class="nav-desktop-only" href="/erfassen"<?= $current === 'scan' ? ' aria-current="page"' : '' ?>><?= e(t('nav.scan')) ?></a>
      <?php endif; ?>
      <a href="/sprache/<?= $locale === 'de' ? 'en' : 'de' ?>?zurueck=<?= e(rawurlencode($currentPath ?? '/')) ?>"
         hreflang="<?= $locale === 'de' ? 'en' : 'de' ?>"><?= e($locale === 'de' ? 'EN' : 'DE') ?></a>
      <?php if ($signedIn): ?>
      <a href="/abmelden"><?= e(t('auth.signout')) ?></a>
      <?php else: ?>
      <a href="/anmelden"><?= e(t('auth.signin')) ?></a>
      <?php endif; ?>
    </nav>
  </header>

  <main id="main" class="container<?= ($narrow ?? false) ? ' container--prose' : '' ?>">
<?php foreach ($flashes ?? [] as $flash): ?>
    <p class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
<?php endforeach; ?>
<?= $content ?>
  </main>

  <footer class="site-footer">
    <nav>
      <a href="<?= e($blogUrl) ?>" target="_blank" rel="noopener"><?= e($blogName) ?></a>
      <a href="/impressum"><?= e(t('legal.imprint')) ?></a>
      <a href="/datenschutz"><?= e(t('legal.privacy')) ?></a>
    </nav>
  </footer>

  <?php if ($signedIn): ?>
  <nav class="bottom-nav" aria-label="<?= e(t('nav.menu')) ?>">
    <a href="/"<?= $current === 'shelf' ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 4h5v16H4zM10 4h4v16h-4zM15.5 5.2l3.9 1 -3 15.2 -3.9-1z"/></svg>
      <span><?= e(t('nav.shelf')) ?></span>
    </a>
    <a href="/suche"<?= $current === 'search' ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/></svg>
      <span><?= e(t('nav.search')) ?></span>
    </a>
    <a class="nav-scan" href="/erfassen" aria-label="<?= e(t('nav.scan')) ?>"<?= $current === 'scan' ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 8V5.5A1.5 1.5 0 015.5 4H8M16 4h2.5A1.5 1.5 0 0120 5.5V8M20 16v2.5a1.5 1.5 0 01-1.5 1.5H16M8 20H5.5A1.5 1.5 0 014 18.5V16M7 12h10"/></svg>
      <span><?= e(t('nav.scan')) ?></span>
    </a>
    <a href="/sub"<?= $current === 'sub' ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 4h12v16l-6-4-6 4z"/></svg>
      <span><?= e(t('nav.sub')) ?></span>
    </a>
    <a href="/statistik"<?= $current === 'stats' ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
      <span><?= e(t('nav.stats')) ?></span>
    </a>
  </nav>
  <?php endif; ?>
<?php foreach ($scripts ?? [] as $script): ?>
  <script src="<?= e($script) ?>?v=<?= e($assetVersion) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
