<?php
declare(strict_types=1);

use App\Controller\PageController;

Assert::group('robots.txt');

$default = PageController::robotsTxt('https://regal.example.org/sitemap.xml', true, false);

Assert::same(
    'the sitemap is named',
    str_contains($default, 'Sitemap: https://regal.example.org/sitemap.xml'),
    true
);
Assert::same('the shelf itself is open', str_contains($default, "User-agent: *\nAllow: /\n"), true);
Assert::same('the login is not', str_contains($default, 'Disallow: /login'), true);
Assert::same('nor is the scanner', str_contains($default, 'Disallow: /scan'), true);
Assert::same('nor the administration', str_contains($default, 'Disallow: /admin'), true);

// Three thousand books times six sort orders times two directions is a lot of
// pages that all say the same thing.
Assert::same('a reordering is not a page', str_contains($default, 'Disallow: /*?*sort='), true);
Assert::same('neither is somebody\'s search', str_contains($default, 'Disallow: /*?*q='), true);

// A genre and an author are content, and the sitemap points at both.
Assert::same('genres stay crawlable', str_contains($default, 'Disallow: /*?*tag='), false);
Assert::same('authors too', str_contains($default, 'Disallow: /*?*author='), false);

Assert::group('robots.txt: statistics follow the setting');

Assert::same(
    'public statistics are left in',
    str_contains(PageController::robotsTxt('https://x/sitemap.xml', true, false), 'Disallow: /stats'),
    false
);
Assert::same(
    'private ones are asked to be left out',
    str_contains(PageController::robotsTxt('https://x/sitemap.xml', false, false), 'Disallow: /stats'),
    true
);

Assert::group('robots.txt: the training crawlers');

$blocked = PageController::robotsTxt('https://x/sitemap.xml', true, false);
Assert::same('blocked by default', str_contains($blocked, "User-agent: GPTBot\nDisallow: /"), true);
Assert::same('and not only the one', str_contains($blocked, "User-agent: CCBot\nDisallow: /"), true);
// Blocking a model crawler must not cost the site its place in a search index.
Assert::same('Googlebot is not among them', str_contains($blocked, 'User-agent: Googlebot'), false);
Assert::same('but Google-Extended is', str_contains($blocked, 'User-agent: Google-Extended'), true);

$allowed = PageController::robotsTxt('https://x/sitemap.xml', true, true);
Assert::same('one setting lets them all in', str_contains($allowed, 'GPTBot'), false);
Assert::same('the rest of the file is unchanged', str_contains($allowed, 'Disallow: /admin'), true);

Assert::group('The sitemap names every way into the shelf');

/* A crawler that only has the front page reaches sixty books of three
 * thousand. The four index pages are what make the rest reachable, and the
 * series index was missing from the list while the other three were in it -
 * it was the last of the four to be built.
 */
$source = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/PageController.php');
$sitemap = substr($source, (int) strpos($source, 'public function sitemap'));
$sitemap = substr($sitemap, 0, (int) strpos($sitemap, 'public function manifest'));

foreach (['/genres', '/authors', '/labels', '/series'] as $index) {
    Assert::true(
        $index . ' is in the sitemap',
        str_contains($sitemap, "['" . $index . "',")
    );
}
