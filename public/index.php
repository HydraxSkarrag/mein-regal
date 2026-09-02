<?php
/**
 * The only PHP file in the document root.
 *
 * Everything else - application code, templates, translations, the schema and
 * the credentials - lives one level above it and is therefore not reachable
 * over HTTP at all. The .htaccess beside this file is a second line of
 * defence, not the first.
 */
declare(strict_types=1);

/*
 * Errors are logged, never shown.
 *
 * A PHP warning printed into the page leaks file paths and, at the wrong
 * moment, fragments of a query. Whether display_errors is on is the host's
 * default and not something to inherit by accident, so it is settled here -
 * before anything can go wrong - rather than hoped for.
 *
 * REGAL_DEBUG=1 turns display back on for local work.
 */
if (getenv('REGAL_DEBUG') === '1') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controller\AuthController;
use App\Controller\BookController;
use App\Controller\CronController;
use App\Controller\MaintenanceController;
use App\Controller\PageController;
use App\Controller\ScanController;
use App\Controller\SetupController;
use App\Controller\ShelfController;
use App\Controller\TagController;
use App\Controller\StatsController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Http\Application;
use App\Repository\PageRepository;

$request = Request::fromGlobals();

try {
    $app = new Application(Config::load(), $request);
} catch (Throwable $e) {
    error_log('[regal] boot failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Die Anwendung konnte nicht gestartet werden.\n";
    exit;
}

$shelf = new ShelfController($app);
$auth  = new AuthController($app);
$scan  = new ScanController($app);
$stats = new StatsController($app);
$pages = new PageController($app);
$cron  = new CronController($app);
$books = new BookController($app);
$setup = new SetupController($app);
$data  = new MaintenanceController($app);
$tags  = new TagController($app);

// Public
$app->router->get('/', $shelf->index(...));
$app->router->get('/unread', $shelf->unread(...));
$app->router->get('/search', $shelf->index(...));
$app->router->get('/genres', $shelf->genres(...));
$app->router->get('/authors', $shelf->authors(...));
$app->router->get('/labels', $shelf->labels(...));
$app->router->get('/book/{slug}', $shelf->detail(...));
$app->router->get('/stats', $stats->page(...));
$app->router->get('/project', $pages->project(...));
$app->router->get('/robots.txt', $pages->robots(...));
$app->router->get('/sitemap.xml', $pages->sitemap(...));
$app->router->get('/manifest.webmanifest', $pages->manifest(...));

/*
 * About, Impressum and privacy policy. One loop rather than nine lines,
 * because the three behave identically: public to read, owner to write, one
 * text per language. PageRepository::EDITABLE is the only place the list
 * lives, so the navigation, the routes and the editor cannot disagree.
 */
foreach (PageRepository::EDITABLE as $slug) {
    $app->router->get('/' . $slug, static fn (): Response => $pages->show($slug));
    $app->router->get('/' . $slug . '/edit', static fn (Request $r): Response => $pages->edit($slug, $r));
    $app->router->post('/' . $slug . '/edit', static fn (Request $r): Response => $pages->edit($slug, $r));
}

// Language switch - a preference, stored in a cookie and on the account.
$app->router->get('/language/{locale}', $auth->setLanguage(...));

// First run. Only answers while no account exists; afterwards it is a 404
// like any other unknown address.
$app->router->get('/setup', $setup->form(...));
$app->router->post('/setup', $setup->submit(...));

// Sign in and out
$app->router->get('/login', $auth->form(...));
$app->router->post('/login', $auth->submit(...));
$app->router->get('/logout', $auth->confirmSignOut(...));
$app->router->post('/logout', $auth->signOut(...));

// Behind the login
$app->router->get('/scan', $scan->page(...));
$app->router->get('/admin', $stats->dashboard(...));
$app->router->get('/admin/data', $data->page(...));
$app->router->get('/admin/tags', $tags->page(...));
$app->router->post('/admin/tags', $tags->save(...));
$app->router->post('/admin/import', $data->import(...));
$app->router->get('/admin/export/{format}', $data->export(...));
$app->router->post('/api/preview', $pages->preview(...));
$app->router->get('/book/{slug}/edit', $books->form(...));
$app->router->post('/book/{slug}/edit', $books->save(...));
$app->router->post('/book/{slug}/delete', $books->delete(...));
$app->router->post('/book/{slug}/cover-delete', $books->deleteCover(...));
$app->router->post('/book/{slug}/cover-find', $books->findCover(...));

// Scheduled work. all-inkl's scheduler calls a URL, so the nightly job needs
// an address; it is guarded by cron_secret from config.php.
$app->router->get('/cron', $cron->run(...));
$app->router->post('/api/lookup', $scan->lookup(...));
$app->router->post('/api/book', $scan->store(...));
$app->router->post('/api/cover', $scan->uploadCover(...));
$app->router->post('/api/cover-delete', $scan->deleteCover(...));

$app->run();
