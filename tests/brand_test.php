<?php
/**
 * Which logo an installation shows.
 *
 * The important case is not that an override wins - it is that the shipped
 * default is still there to fall back to, and that the notice telling an
 * operator to replace it goes quiet the moment they have. A hint that keeps
 * nagging after it has been acted on is a hint people learn to ignore.
 */
declare(strict_types=1);

use App\Core\Brand;

Assert::group('Brand');

$root = sys_get_temp_dir() . '/regal-brand-' . bin2hex(random_bytes(4));
mkdir($root . '/public/assets/brand', 0777, true);

/** A one-pixel PNG, so getimagesize has something real to read. */
$png = static function (string $path, int $w, int $h): void {
    $im = imagecreatetruecolor($w, $h);
    imagepng($im, $path);
};

file_put_contents($root . '/public/assets/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
$png($root . '/public/assets/favicon-32x32.png', 32, 32);

$brand = new Brand($root);

Assert::true('the shipped logo is found', str_starts_with((string) $brand->url('logo'), '/assets/logo.svg?v='));
Assert::true('the shipped icon is found', str_starts_with((string) $brand->url('icon'), '/assets/favicon-32x32.png?v='));
Assert::same('a size that ships nothing stays null', $brand->url('touch'), null);
Assert::true('with nothing overridden, the notice shows', $brand->usesDefaults());
Assert::same('an SVG is assumed square', $brand->logoSize(36), [36, 36]);

// An override of a different shape: the header height is fixed, the width follows.
$png($root . '/public/assets/brand/logo.png', 122, 93);
$brand = new Brand($root);

Assert::true('an override wins over the shipped file', str_starts_with((string) $brand->url('logo'), '/assets/brand/logo.png?v='));
Assert::same('a wide logo keeps its proportions', $brand->logoSize(36), [47, 36]);
Assert::true('the notice goes quiet once a logo is there', !$brand->usesDefaults());
Assert::true(
    'sizes not overridden still fall back to the shipped ones',
    str_starts_with((string) $brand->url('icon'), '/assets/favicon-32x32.png?v=')
);

// The URL carries the file's timestamp, or a replaced logo would sit in
// caches behind the one it replaced.
touch($root . '/public/assets/brand/logo.png', 1000000000);
$brand = new Brand($root);
Assert::same('the URL carries the timestamp', $brand->url('logo'), '/assets/brand/logo.png?v=1000000000');

Assert::same('the directory is the one the notice names', Brand::directory(), 'public/assets/brand/');
Assert::true('the notice lists a name for every kind', count(Brand::fileNames()) >= 4);

// Every name the notice offers must be one the lookup actually accepts,
// which is the whole reason both come from the same list.
$accepted = true;
foreach (Brand::fileNames() as $name) {
    $probe = sys_get_temp_dir() . '/regal-brand-probe-' . bin2hex(random_bytes(4));
    mkdir($probe . '/public/assets/brand', 0777, true);
    if (str_ends_with($name, '.svg')) {
        file_put_contents($probe . '/public/assets/brand/' . $name, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    } else {
        $png($probe . '/public/assets/brand/' . $name, 8, 8);
    }
    $accepted = $accepted && !(new Brand($probe))->usesDefaults();
    array_map('unlink', glob($probe . '/public/assets/brand/*') ?: []);
    foreach (['/public/assets/brand', '/public/assets', '/public', ''] as $dir) {
        @rmdir($probe . $dir);
    }
}
Assert::true('every advertised file name is one the lookup accepts', $accepted);

array_map('unlink', glob($root . '/public/assets/brand/*') ?: []);
array_map('unlink', glob($root . '/public/assets/*.*') ?: []);
foreach (['/public/assets/brand', '/public/assets', '/public', ''] as $dir) {
    @rmdir($root . $dir);
}
