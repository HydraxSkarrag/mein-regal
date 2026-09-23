<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The session cookie, configured the way a session cookie should be - and
 * only there when something needs it.
 *
 * It is the only cookie the application sets that is not a plain preference,
 * and it is strictly necessary for signing in - which is what keeps the site
 * free of a consent banner. Adding anything that tracks would end that.
 *
 * It used to be started for every request, so every visitor who only read
 * the shelf got one, and every page - the sitemap included - went out with
 * PHP's session headers, Cache-Control: no-store among them. "Necessary"
 * was hard to argue for somebody who never signs in or sends a form, and
 * no-store kept browsers from keeping a page for the back button.
 *
 * Now a session is picked up if the visitor brought one, and begun the first
 * time something is written into it: the form token of a page with a form - the sign-in page is one -
 * a message to show after a redirect, the signed-in owner. Reading from a
 * session nobody has begun is simply reading nothing.
 */
final class Session implements SessionStore
{
    private const NAME = 'regal_session';

    private bool $started = false;

    public function __construct(private readonly bool $secure = true)
    {
    }

    /** Resume the visitor's session if they brought one; begin none. */
    public function start(): void
    {
        if ($this->isActive() || !isset($_COOKIE[self::NAME])) {
            return;
        }
        $this->begin();
    }

    public function isActive(): bool
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
        }

        return $this->started;
    }

    private function begin(): void
    {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name(self::NAME);
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (!$this->isActive()) {
            $this->begin();
        }
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        if ($this->isActive()) {
            unset($_SESSION[$key]);
        }
    }

    /** One-shot message, read once and then gone. */
    public function flash(string $message, string $type = 'info'): void
    {
        if (!$this->isActive()) {
            $this->begin();
        }
        $_SESSION['_flash'][] = ['message' => $message, 'type' => $type];
    }

    /** @return list<array{message: string, type: string}> */
    public function takeFlashes(): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($flashes) ? $flashes : [];
    }

    /**
     * New session id, same contents. Called on sign-in so a session id an
     * attacker planted before login cannot be used after it.
     */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: self::NAME, '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }
}
