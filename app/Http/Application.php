<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Cookies;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Formatter;
use App\Core\HttpCookies;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\Translator;
use App\Core\View;
use App\Lookup\DnbLookup;
use App\Lookup\GoogleBooksLookup;
use App\Lookup\HttpClient;
use App\Lookup\LookupChain;
use App\Lookup\OpenLibraryLookup;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\CoverRepository;
use App\Repository\PageRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use PDO;
use Throwable;

/**
 * Wires the application together.
 *
 * Everything is built once here and handed to the controllers. There is no
 * container: with this many pieces, a list of assignments is easier to follow
 * than a resolution graph, and it fails at startup rather than mid-request.
 */
final class Application
{
    public readonly PDO $pdo;
    public readonly Session $session;
    public readonly Cookies $cookies;
    public readonly Csrf $csrf;
    public readonly Auth $auth;
    public readonly Translator $translator;
    public readonly Formatter $formatter;
    public readonly View $view;
    public readonly Router $router;

    public readonly BookRepository $books;
    public readonly AuthorRepository $authors;
    public readonly TagRepository $tags;
    public readonly CoverRepository $covers;
    public readonly PageRepository $pages;
    public readonly UserRepository $users;
    public readonly LookupChain $lookup;

    /** The collection being shown. One installation, one owner, for now. */
    public readonly int $ownerId;

    public function __construct(
        public readonly Config $config,
        public readonly Request $request,
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo ?? Database::connect($config);
        $secure = $request->isSecure();

        $this->session = new Session($secure);
        $this->session->start();
        $this->cookies = new HttpCookies($secure);
        $this->csrf = new Csrf($this->session);

        $this->users = new UserRepository($this->pdo);
        $this->auth = new Auth($this->pdo, $this->session, $this->users, $this->cookies);

        $this->books = new BookRepository($this->pdo);
        $this->authors = new AuthorRepository($this->pdo);
        $this->tags = new TagRepository($this->pdo);
        $this->covers = new CoverRepository($this->pdo);
        $this->pages = new PageRepository($this->pdo);

        $http = new HttpClient($config->str('api_contact'));
        $this->lookup = new LookupChain(
            new DnbLookup($http),
            new GoogleBooksLookup($http, $config->str('google_books_key')),
            new OpenLibraryLookup($http)
        );

        $this->translator = new Translator($this->resolveLocale());
        Translator::setInstance($this->translator);
        $this->formatter = new Formatter($this->translator->locale());

        $this->ownerId = $this->resolveOwnerId();

        $this->view = new View(APP_ROOT . '/templates');
        $this->shareViewDefaults();

        $this->router = new Router();
    }

    /**
     * Interface language: the signed-in owner's stored choice wins, then a
     * cookie set by the language switch, then the browser's preference.
     */
    private function resolveLocale(): string
    {
        $user = $this->auth->user();
        if ($user !== null && isset($user['locale'])) {
            return Translator::normalizeLocale((string) $user['locale']);
        }

        $cookie = $this->cookies->get('regal_lang');
        if ($cookie !== null) {
            return Translator::normalizeLocale($cookie);
        }

        return Translator::negotiate($this->request->acceptLanguage());
    }

    /**
     * Whose shelf is on show. A signed-in owner sees their own; a visitor sees
     * the first account, which is the public collection.
     */
    private function resolveOwnerId(): int
    {
        $userId = $this->auth->userId();
        if ($userId !== null) {
            return $userId;
        }
        $first = $this->pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();

        return $first === false ? 1 : (int) $first;
    }

    private function shareViewDefaults(): void
    {
        $this->view->share('translator', $this->translator);
        $this->view->share('formatter', $this->formatter);
        $this->view->share('signedIn', $this->auth->isSignedIn());
        $this->view->share('user', $this->auth->user());
        $this->view->share('siteName', $this->config->str('site_name', 'Mein Regal'));
        $this->view->share('siteUrl', rtrim($this->config->str('site_url'), '/'));
        $this->view->share('blogUrl', $this->config->str('blog_url'));
        $this->view->share('blogName', $this->config->str('blog_name', 'Bücherhausen'));
        $this->view->share('currentPath', $this->request->path);
        $this->view->share('csrfField', $this->csrf->field());
        $this->view->share('assetVersion', $this->assetVersion());
        $this->view->share('flashes', $this->session->takeFlashes());
        $this->view->share('scripts', []);
        $this->view->share('current', '');
        $this->view->share('narrow', false);
        $this->view->share('noIndex', false);
    }

    /** Cache-busting for CSS and JS; the file's own timestamp is enough. */
    private function assetVersion(): string
    {
        $css = PROJECT_ROOT . '/public/css/style.css';

        return (string) (is_file($css) ? filemtime($css) : 1);
    }

    public function url(string $path = '/'): string
    {
        return rtrim($this->config->str('site_url'), '/') . $path;
    }

    public function run(): void
    {
        try {
            $response = $this->router->dispatch($this->request) ?? $this->notFound();
        } catch (Throwable $e) {
            error_log('[regal] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $response = $this->serverError();
        }

        $response->send();
    }

    public function notFound(): Response
    {
        return Response::html($this->view->page('errors.simple', [
            'title'   => t('error.404.title'),
            'heading' => t('error.404.title'),
            'body'    => t('error.404.body'),
            'noIndex' => true,
        ]), 404);
    }

    public function serverError(): Response
    {
        return Response::html($this->view->page('errors.simple', [
            'title'   => t('error.500.title'),
            'heading' => t('error.500.title'),
            'body'    => t('error.500.body'),
            'noIndex' => true,
        ]), 500);
    }

    /** Guard for anything that changes data or shows private fields. */
    public function requireSignIn(): ?Response
    {
        if ($this->auth->isSignedIn()) {
            return null;
        }
        $this->session->flash(t('auth.required'), 'error');

        return Response::redirect('/anmelden?weiter=' . rawurlencode($this->request->path));
    }
}
