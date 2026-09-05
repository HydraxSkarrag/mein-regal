<?php
/**
 * Every asset answers for itself.
 *
 * There used to be one version number for all of them, and it was style.css's
 * timestamp. Change scanner.js, deploy, and the address it is served under
 * did not move - .htaccess caches scripts for seven days, so the fix reached
 * nobody who had visited that week. It presented as a bug that would not die:
 * scanning was broken on the desktop, was fixed, was deployed, stayed broken,
 * and came right only when the browser was cleared by hand.
 *
 * That failure is invisible from inside the application. Nothing is wrong on
 * the server - the file is correct, the deploy succeeded, the tests pass. So
 * it is worth a test that says out loud what the rule is: one file changing
 * moves one address.
 */
declare(strict_types=1);

use App\Http\Application;

Assert::group('Assets are versioned one by one');

// Not static, so it is fetched the way the application fetches it - and it
// deliberately takes no $this, which is what makes that possible here.
$asset = (new ReflectionMethod(Application::class, 'assetVersion'))->invoke(null);

$css = PROJECT_ROOT . '/public/css/style.css';
$js  = PROJECT_ROOT . '/public/js/scanner.js';

Assert::true('the fixtures for this test are the real files', is_file($css) && is_file($js));

Assert::same(
    'the stylesheet carries its own timestamp',
    $asset('/css/style.css'),
    '/css/style.css?v=' . filemtime($css)
);
Assert::same(
    'and the scanner carries its own, not the stylesheet\'s',
    $asset('/js/scanner.js'),
    '/js/scanner.js?v=' . filemtime($js)
);

/* The whole point, stated as one assertion: these two numbers come from two
 * files. Should they ever be equal it is because the files were written in
 * the same second, never because one is standing in for the other. */
Assert::true(
    'the two are read from two different files',
    $asset('/js/scanner.js') !== '/js/scanner.js?v=' . filemtime($css)
        || filemtime($css) === filemtime($js)
);

// A theme that an installation does not have must not get a made-up version
// hung on it - and must not stop the page rendering either.
Assert::same(
    'a file that is not there gets no parameter',
    $asset('/css/themes/keins.css'),
    '/css/themes/keins.css'
);

/* Asked twice in one request - and the layout does ask more than once when a
 * theme sits on top of the stylesheet - the same file has to give the same
 * answer, or the browser fetches one page's assets twice. */
Assert::same('the same path answers the same twice', $asset('/css/style.css'), $asset('/css/style.css'));

/* Theme stylesheets do not come through here at all: Theme::urls() stamps
 * each with its own file's timestamp already, and running them past this a
 * second time would hang one ?v= on the end of another. Worth an assertion
 * because the layout renders both kinds of stylesheet three lines apart. */
$theme = new App\Core\Theme(PROJECT_ROOT . '/public', 'buecherhausen');
foreach ($theme->urls() as $url) {
    Assert::same('a theme brings its own version', substr_count($url, '?v='), 1);
}
