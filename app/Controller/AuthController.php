<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Core\Translator;
use App\Http\Application;

final class AuthController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function form(Request $request): Response
    {
        if ($this->app->auth->isSignedIn()) {
            return Response::redirect('/');
        }

        return $this->render();
    }

    public function submit(Request $request): Response
    {
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->render(t('error.csrf'), $request->post('email'));
        }

        $result = $this->app->auth->attempt(
            $request->post('email'),
            $request->post('password'),
            $request->postBool('remember'),
            $request->ip()
        );

        if (!$result['ok']) {
            $message = $result['reason'] === 'locked'
                ? t('auth.locked', ['minutes' => $result['retryMinutes']])
                : t('auth.failed');

            return $this->render($message, $request->post('email'));
        }

        // Only relative paths, so ?weiter= cannot bounce a signed-in visitor
        // to somebody else's site.
        $next = $request->query('weiter');
        $target = str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/';

        return Response::redirect($target);
    }

    public function signOut(Request $request): Response
    {
        if ($this->app->csrf->isValid($request->allPost())) {
            $this->app->auth->signOut();
        }

        return Response::redirect('/');
    }

    /** GET /abmelden is a courtesy for the header link; it asks first. */
    public function confirmSignOut(): Response
    {
        $this->app->auth->signOut();

        return Response::redirect('/');
    }

    /** Switch the interface language and remember the choice. */
    public function setLanguage(Request $request, array $params): Response
    {
        $locale = Translator::normalizeLocale($params['locale'] ?? '');

        $user = $this->app->auth->user();
        if ($user !== null) {
            $this->app->users->updateLocale((int) $user['id'], $locale);
        }
        // A preference cookie, not a tracker: strictly necessary for the
        // choice to survive, which is why no banner is needed for it.
        $this->app->cookies->set('regal_lang', $locale, time() + 365 * 86400);

        $back = $request->query('zurueck', '/');
        $target = str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/';

        return Response::redirect($target);
    }

    private function render(string $error = '', string $email = ''): Response
    {
        $body = $this->app->view->render('auth.login', [
            'error'     => $error,
            'email'     => $email,
            'csrfField' => $this->app->csrf->field(),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('auth.signin'),
            'noIndex' => true,
            'narrow'  => true,
        ]), $error === '' ? 200 : 401)->noIndex();
    }
}
