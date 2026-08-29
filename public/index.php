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

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controller\AuthController;
use App\Controller\BookController;
use App\Controller\CronController;
use App\Controller\MaintenanceController;
use App\Controller\PageController;
use App\Controller\ScanController;
use App\Controller\SetupController;
use App\Controller\ShelfController;
use App\Controller\StatsController;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Http\Application;

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

// Public
$app->router->get('/', $shelf->index(...));
$app->router->get('/sub', $shelf->unread(...));
$app->router->get('/suche', $shelf->index(...));
$app->router->get('/buch/{slug}', $shelf->detail(...));
$app->router->get('/statistik', $stats->page(...));
$app->router->get('/ueber', $pages->about(...));
$app->router->get('/impressum', $pages->imprint(...));
$app->router->get('/datenschutz', $pages->privacy(...));
$app->router->get('/robots.txt', $pages->robots(...));
$app->router->get('/sitemap.xml', $pages->sitemap(...));
$app->router->get('/manifest.webmanifest', $pages->manifest(...));

// Language switch - a preference, stored in a cookie and on the account.
$app->router->get('/sprache/{locale}', $auth->setLanguage(...));

// First run. Only answers while no account exists; afterwards it is a 404
// like any other unknown address.
$app->router->get('/einrichten', $setup->form(...));
$app->router->post('/einrichten', $setup->submit(...));

// Sign in and out
$app->router->get('/anmelden', $auth->form(...));
$app->router->post('/anmelden', $auth->submit(...));
$app->router->get('/abmelden', $auth->confirmSignOut(...));
$app->router->post('/abmelden', $auth->signOut(...));

// Behind the login
$app->router->get('/erfassen', $scan->page(...));
$app->router->get('/verwaltung', $stats->dashboard(...));
$app->router->get('/verwaltung/daten', $data->page(...));
$app->router->post('/verwaltung/import', $data->import(...));
$app->router->get('/verwaltung/export/{format}', $data->export(...));
$app->router->get('/ueber/bearbeiten', $pages->editAbout(...));
$app->router->post('/ueber/bearbeiten', $pages->editAbout(...));
$app->router->get('/buch/{slug}/bearbeiten', $books->form(...));
$app->router->post('/buch/{slug}/bearbeiten', $books->save(...));
$app->router->post('/buch/{slug}/loeschen', $books->delete(...));
$app->router->post('/buch/{slug}/cover-loeschen', $books->deleteCover(...));
$app->router->post('/buch/{slug}/cover-suchen', $books->findCover(...));

// Scheduled work. all-inkl's scheduler calls a URL, so the nightly job needs
// an address; it is guarded by cron_secret from config.php.
$app->router->get('/cron', $cron->run(...));
$app->router->post('/api/lookup', $scan->lookup(...));
$app->router->post('/api/buch', $scan->store(...));
$app->router->post('/api/cover', $scan->uploadCover(...));
$app->router->post('/api/cover-loeschen', $scan->deleteCover(...));

$app->run();
