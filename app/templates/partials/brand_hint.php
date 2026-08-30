<?php
/**
 * Shown while the installation is still wearing the logo it shipped with.
 *
 * It renders nothing once anything at all sits in the brand directory, so it
 * costs a fresh installation one notice and an established one nothing. The
 * point is that the default mark is easy to overlook: it does not look broken,
 * which is precisely why an installation can keep it for a year without
 * anybody noticing it was never meant to stay.
 *
 * @var App\Core\Brand $brand
 */
declare(strict_types=1);

use App\Core\Brand;

if (!$brand->usesDefaults()) {
    return;
}
?>
<p class="flash flash--hint">
  <?= e(t('brand.defaults', ['folder' => Brand::directory()])) ?>
  <?= e(t('brand.defaults.files', ['files' => implode(', ', Brand::fileNames())])) ?>
</p>
