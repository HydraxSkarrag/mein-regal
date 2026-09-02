<?php
declare(strict_types=1);

namespace App\Controller;

use App\Content\DefaultPages;
use App\Core\Request;
use App\Core\Response;
use App\Http\Application;
use Throwable;

/**
 * First run, from the browser.
 *
 * The hosting this is built for has no shell, so "run bin/setup.php" is not
 * an instruction anyone can follow there. Without this page a fresh
 * installation has no account, and no way to make one.
 *
 * It is only reachable while the users table is empty. Once an account
 * exists the route answers exactly like any unknown address - no message
 * saying setup is finished, since that would confirm the page exists to
 * anyone who goes looking.
 */
final class SetupController
{
    private const MIN_PASSWORD = 12;

    public function __construct(private readonly Application $app)
    {
    }

    public function form(Request $request): Response
    {
        if (!$this->isFirstRun()) {
            return $this->app->notFound();
        }

        return $this->render();
    }

    public function submit(Request $request): Response
    {
        if (!$this->isFirstRun()) {
            return $this->app->notFound();
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->render(t('error.csrf'), $request->post('email'), $request->post('name'));
        }

        $email = trim($request->post('email'));
        $name = trim($request->post('name'));
        $password = $request->post('password');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->render(t('setup.email.invalid'), $email, $name);
        }
        if ($name === '') {
            return $this->render(t('setup.name.required'), $email, $name);
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            return $this->render(t('setup.password.short', ['min' => self::MIN_PASSWORD]), $email, $name);
        }
        if ($password !== $request->post('password_repeat')) {
            return $this->render(t('setup.password.mismatch'), $email, $name);
        }

        try {
            $userId = $this->app->users->create(
                $email,
                $password,
                $name,
                $this->app->translator->locale()
            );
        } catch (Throwable $e) {
            error_log('[regal] setup failed: ' . $e->getMessage());

            return $this->render(t('error.500.title'), $email, $name);
        }

        $this->seedPages($userId, $name, $email);

        // Straight in, rather than sending someone who just chose a password
        // to a form asking for it again.
        $user = $this->app->users->findById($userId);
        if ($user !== null) {
            $this->app->auth->signIn($user, true);
        }
        $this->app->session->flash(t('setup.done'), 'ok');

        return Response::redirect('/admin');
    }

    /**
     * Give the new installation its legal pages.
     *
     * Only a starting point, and only in German - see DefaultPages for why
     * that is deliberate rather than lazy. From here on the texts belong to
     * the operator and are edited in the browser, which is the only way a
     * legal text ever gets corrected.
     *
     * A failure here must not lose the account that was just created: an
     * installation with no Impressum is a job to finish, an installation with
     * no owner is one to start over.
     */
    private function seedPages(int $userId, string $operator, string $email): void
    {
        try {
            foreach (DefaultPages::all($this->app->config, $operator, $email) as $slug => $page) {
                if ($this->app->pages->find($userId, $slug, DefaultPages::SEEDED_LOCALE) !== null) {
                    continue;
                }
                $this->app->pages->save(
                    $userId,
                    $slug,
                    DefaultPages::SEEDED_LOCALE,
                    $page['title'],
                    $page['body']
                );
            }
        } catch (Throwable $e) {
            error_log('[regal] seeding the legal pages failed: ' . $e->getMessage());
        }
    }

    /**
     * No accounts yet.
     *
     * Deliberately not a flag in a file: a file can be deleted or restored by
     * a careless upload, and this cannot be wrong. If an account exists, setup
     * is over.
     */
    private function isFirstRun(): bool
    {
        try {
            return $this->app->users->count() === 0;
        } catch (Throwable $e) {
            // No users table means the schema was never loaded; that is a
            // different problem and the page below says so.
            return false;
        }
    }

    private function render(string $error = '', string $email = '', string $name = ''): Response
    {
        $body = $this->app->view->render('auth.setup', [
            'error'       => $error,
            'email'       => $email,
            'name'        => $name,
            'minPassword' => self::MIN_PASSWORD,
            'csrfField'   => $this->app->csrf->field(),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('setup.title'),
            'noIndex' => true,
            'narrow'  => true,
        ]), $error === '' ? 200 : 422)->noIndex();
    }
}
