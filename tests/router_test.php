<?php
declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

Assert::group('Router');

$router = new Router();
$router->get('/', static fn () => Response::text('shelf'));
$router->get('/buch/{slug}', static fn ($request, $params) => Response::text('book:' . $params['slug']));
$router->post('/login', static fn () => Response::text('login'));

Assert::true('the root path matches', $router->match('GET', '/') !== null);
Assert::same('a parameter is captured', $router->match('GET', '/buch/milla-9783473408061')['params']['slug'], 'milla-9783473408061');
Assert::same('an unknown path does not match', $router->match('GET', '/nope'), null);
Assert::same('the wrong method does not match', $router->match('GET', '/login'), null);
Assert::true('the right method does', $router->match('POST', '/login') !== null);

// A slug is one segment: a path with an extra slash must not be swallowed.
Assert::same('a parameter does not cross a slash', $router->match('GET', '/buch/a/b'), null);

$response = $router->dispatch(new Request('GET', '/buch/test-slug'));
Assert::same('dispatch runs the handler', $response?->body(), 'book:test-slug');

Assert::group('Request');

$request = new Request(
    'POST',
    '/scan',
    ['q' => '  Tollkühn  ', 'page' => '3'],
    ['isbn' => ' 9783473408061 ', 'remember' => 'on'],
    ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1', 'HTTPS' => 'on']
);

Assert::same('query values are trimmed', $request->query('q'), 'Tollkühn');
Assert::same('numeric query values', $request->queryInt('page'), 3);
Assert::same('a missing query value falls back', $request->queryInt('missing', 1), 1);
Assert::same('post values are trimmed', $request->post('isbn'), '9783473408061');
Assert::same('checkbox reading', $request->postBool('remember'), true);
Assert::same('an absent checkbox is false', $request->postBool('nope'), false);
Assert::same('HTTPS detected', $request->isSecure(), true);

// X-Forwarded-For is attacker-controlled on shared hosting; trusting it would
// let someone sidestep the login rate limit with a new address per attempt.
Assert::same('the address comes from REMOTE_ADDR, not the header', $request->ip(), '192.0.2.1');

$noIp = new Request('GET', '/', [], [], ['REMOTE_ADDR' => 'not-an-ip']);
Assert::same('a malformed address is discarded', $noIp->ip(), null);

Assert::group('Response');

Assert::same('JSON sets its content type', Response::json(['a' => 1])->headers()['Content-Type'], 'application/json; charset=utf-8');
Assert::same('redirect sets Location', Response::redirect('/login')->headers()['Location'], '/login');
Assert::same('redirect status', Response::redirect('/login')->status(), 302);
Assert::same('noIndex adds the header', Response::html('x')->noIndex()->headers()['X-Robots-Tag'], 'noindex, nofollow');
Assert::same('umlauts survive JSON encoding', Response::json(['t' => 'Tollkühn'])->body(), '{"t":"Tollkühn"}');

Assert::group('Autoloading: one class per file');

// The autoloader maps a class name straight onto a path, so a second class
// hiding in another class's file is invisible to it - and the failure only
// shows up at runtime, not in a test that never names it.
$offenders = [];
$directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/app'));
foreach ($directory as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    if (!preg_match_all('/^(?:final |abstract )?(?:class|interface|trait|enum)\s+(\w+)/m', $source, $matches)) {
        continue;
    }
    $expected = $file->getBasename('.php');
    foreach ($matches[1] as $declared) {
        if ($declared !== $expected) {
            $offenders[] = $declared . ' declared in ' . $file->getBasename();
        }
    }
}
Assert::same('every class sits in the file named after it', $offenders, []);
