<?php
/**
 * The page frame.
 *
 * @var string      $content
 * @var string      $title
 * @var App\Core\Brand      $brand
 * @var App\Core\Translator $translator
 */
declare(strict_types=1);

use App\Controller\PageController;

$locale = $translator->locale();
$pageTitle = $title ?? $siteName;
$noIndex = $noIndex ?? false;
$current = $current ?? '';
?>
<!DOCTYPE html>
<html lang="<?= e($locale) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($pageTitle === $siteName ? $pageTitle : $pageTitle . ' – ' . $siteName) ?></title>
  <?php if ($noIndex): ?>
  <meta name="robots" content="noindex, nofollow">
  <?php else: ?>
  <meta name="description" content="<?= e($metaDescription ?? t('app.tagline')) ?>">
  <link rel="canonical" href="<?= e($canonical ?? $siteUrl) ?>">
  <?php endif; ?>
  <meta name="theme-color" content="#ed002f">

  <link rel="stylesheet" href="/css/style.css?v=<?= e($assetVersion) ?>">
  <?php if ($brand->url('icon') !== null): ?>
  <link rel="icon" href="<?= e($brand->url('icon')) ?>" sizes="32x32">
  <?php endif; ?>
  <?php if ($brand->url('touch') !== null): ?>
  <link rel="apple-touch-icon" href="<?= e($brand->url('touch')) ?>">
  <?php endif; ?>
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
<?= json_for_script($jsonLd, true) ?>

  </script>
<?php endif; ?>
</head>
<body>
  <a class="skip-link" href="#main"><?= e(t('nav.skip')) ?></a>

  <header class="site-header">
    <div class="site-header-inner">
    <a class="brand" href="/">
      <?php if ($brand->url('logo') !== null): [$logoWidth, $logoHeight] = $brand->logoSize(); ?>
      <img src="<?= e($brand->url('logo')) ?>" alt=""
           width="<?= $logoWidth ?>" height="<?= $logoHeight ?>" class="brand-logo">
      <?php endif; ?>
      <span class="brand-name"><?= e($siteName) ?></span>
    </a>
    <?php /* A shelf need not belong to a blog; with no blog configured the line is simply absent. */ ?>
    <?php if ($blogUrl !== '' && $blogName !== ''): ?>
    <span class="brand-by"><?= e(t('app.by')) ?>
      <a href="<?= e($blogUrl) ?>?utm_source=regal&amp;utm_medium=app&amp;utm_campaign=header"
         target="_blank" rel="noopener"><?= e($blogName) ?></a></span>
    <?php endif; ?>

    <nav class="header-nav" aria-label="<?= e(t('nav.menu')) ?>">
      <a class="nav-desktop-only" href="/"<?= $current === 'shelf' ? ' aria-current="page"' : '' ?>><?= e(t('nav.shelf')) ?></a>
      <a class="nav-desktop-only" href="/unread"<?= $current === 'unread' ? ' aria-current="page"' : '' ?>><?= e(t('nav.unread')) ?></a>
      <?php if ($publicStats || $signedIn): ?>
      <a class="nav-desktop-only" href="/stats"<?= $current === 'stats' ? ' aria-current="page"' : '' ?>><?= e(t('nav.stats')) ?></a>
      <?php endif; ?>
      <?php if ($signedIn): ?>
      <a class="nav-desktop-only" href="/scan"<?= $current === 'scan' ? ' aria-current="page"' : '' ?>><?= e(t('nav.scan')) ?></a>
      <a class="nav-desktop-only" href="/admin"<?= $current === 'admin' ? ' aria-current="page"' : '' ?>><?= e(t('nav.admin')) ?></a>
      <?php endif; ?>
      <a href="/language/<?= $locale === 'de' ? 'en' : 'de' ?>?back=<?= e(rawurlencode($currentPath ?? '/')) ?>"
         hreflang="<?= $locale === 'de' ? 'en' : 'de' ?>"><?= e($locale === 'de' ? 'EN' : 'DE') ?></a>
      <?php if ($signedIn): ?>
      <a href="/logout"><?= e(t('auth.signout')) ?></a>
      <?php else: ?>
      <a href="/login"><?= e(t('auth.signin')) ?></a>
      <?php endif; ?>
    </nav>
    </div>
  </header>

  <main id="main" class="container<?= ($narrow ?? false) ? ' container--prose' : '' ?>">
<?php foreach ($flashes ?? [] as $flash): ?>
    <p class="flash flash--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
<?php endforeach; ?>
<?= $content ?>
  </main>

  <footer class="site-footer">
    <nav class="site-footer-inner">
      <?php if ($blogUrl !== '' && $blogName !== ''): ?>
      <a href="<?= e($blogUrl) ?>?utm_source=regal&amp;utm_medium=app&amp;utm_campaign=footer"
         target="_blank" rel="noopener"><?= e($blogName) ?></a>
      <?php endif; ?>
      <a href="/about"><?= e(t('nav.about')) ?></a>
      <a href="/imprint"><?= e(t('legal.imprint')) ?></a>
      <a href="/privacy"><?= e(t('legal.privacy')) ?></a>
      <a href="/project"><?= e(t('nav.project')) ?></a>
    </nav>
    <?php
      /*
       * Where the software came from, on every installation.
       *
       * The shelf above can be renamed and rebranded freely - that is the
       * point of putting the name in config.php. This line is not part of
       * that: it credits the blog this was written for and points at the
       * source, which is what makes handing the thing to strangers a gift
       * rather than a giveaway. Constants in PageController, not settings.
       */
    ?>
    <p class="site-credit">
      <?= t('app.credit', [
        'app'    => '<a href="/project">' . e(PageController::SOFTWARE_NAME) . '</a>',
        /*
         * Tagged, so the blog can see that the credit is doing something.
         * utm_source names the software rather than this installation: the
         * link travels to every shelf running it, and "regal" would say only
         * that some shelf somewhere sent the visitor.
         */
        'origin' => '<a href="' . e(PageController::ORIGIN_URL
                        . '?utm_source=meinregal&utm_medium=app&utm_campaign=credit')
                    . '" rel="noopener">' . e(PageController::ORIGIN_NAME) . '</a>',
      ]) ?>
    </p>
  </footer>

  <?php
    // The bar is for everyone. Hiding it from signed-out visitors left a
    // phone with no way to reach the unread pile or the statistics at all -
    // the header drops those links below 720px. Only the raised scan button
    // is conditional, because only the owner can scan.
    $navIcons = [
      'shelf'  => '<path d="M4 4h5v16H4zM10 4h4v16h-4zM15.5 5.2l3.9 1 -3 15.2 -3.9-1z"/>',
      'search' => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>',
      'unread' => '<path d="M6 4h12v16l-6-4-6 4z"/>',
      'stats'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
      'admin'  => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    ];
    $navItems = [
      ['key' => 'shelf',  'href' => '/',           'label' => t('nav.shelf')],
      ['key' => 'search', 'href' => '/search',      'label' => t('nav.search')],
      ['key' => 'unread', 'href' => '/unread',        'label' => t('nav.unread')],
    ];
    if ($signedIn) {
      $navItems[] = ['key' => 'admin', 'href' => '/admin', 'label' => t('nav.admin')];
    } elseif ($publicStats) {
      $navItems[] = ['key' => 'stats', 'href' => '/stats', 'label' => t('nav.stats')];
    }
  ?>
  <nav class="bottom-nav" aria-label="<?= e(t('nav.menu')) ?>">
    <?php foreach (array_slice($navItems, 0, 2) as $item): ?>
    <a href="<?= e($item['href']) ?>"<?= $current === $item['key'] ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><?= $navIcons[$item['key']] ?></svg>
      <span><?= e($item['label']) ?></span>
    </a>
    <?php endforeach; ?>

    <?php if ($signedIn): ?>
    <a class="nav-scan" href="/scan" aria-label="<?= e(t('nav.scan')) ?>"<?= $current === 'scan' ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 8V5.5A1.5 1.5 0 015.5 4H8M16 4h2.5A1.5 1.5 0 0120 5.5V8M20 16v2.5a1.5 1.5 0 01-1.5 1.5H16M8 20H5.5A1.5 1.5 0 014 18.5V16M7 12h10"/></svg>
      <span><?= e(t('nav.scan')) ?></span>
    </a>
    <?php endif; ?>

    <?php foreach (array_slice($navItems, 2) as $item): ?>
    <a href="<?= e($item['href']) ?>"<?= $current === $item['key'] ? ' aria-current="page"' : '' ?>>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><?= $navIcons[$item['key']] ?></svg>
      <span><?= e($item['label']) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
<?php foreach ($scripts ?? [] as $script): ?>
  <script src="<?= e($script) ?>?v=<?= e($assetVersion) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
