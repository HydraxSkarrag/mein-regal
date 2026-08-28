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
use App\Controller\PageController;
use App\Controller\ScanController;
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

// Public
$app->router->get('/', $shelf->index(...));
$app->router->get('/sub', $shelf->unread(...));
$app->router->get('/suche', $shelf->index(...));
$app->router->get('/buch/{slug}', $shelf->detail(...));
$app->router->get('/statistik', $stats->page(...));
$app->router->get('/impressum', $pages->imprint(...));
$app->router->get('/datenschutz', $pages->privacy(...));
$app->router->get('/robots.txt', $pages->robots(...));
$app->router->get('/sitemap.xml', $pages->sitemap(...));
$app->router->get('/manifest.webmanifest', $pages->manifest(...));

// Language switch - a preference, stored in a cookie and on the account.
$app->router->get('/sprache/{locale}', $auth->setLanguage(...));

// Sign in and out
$app->router->get('/anmelden', $auth->form(...));
$app->router->post('/anmelden', $auth->submit(...));
$app->router->get('/abmelden', $auth->confirmSignOut(...));
$app->router->post('/abmelden', $auth->signOut(...));

// Behind the login
$app->router->get('/erfassen', $scan->page(...));
$app->router->get('/verwaltung', $stats->dashboard(...));
$app->router->post('/api/lookup', $scan->lookup(...));
$app->router->post('/api/buch', $scan->store(...));
$app->router->post('/api/cover', $scan->uploadCover(...));

$app->run();
