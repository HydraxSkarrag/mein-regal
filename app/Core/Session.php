<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The session cookie, configured the way a session cookie should be.
 *
 * It is the only cookie the application sets that is not a plain preference,
 * and it is strictly necessary for signing in - which is what keeps the site
 * free of a consent banner. Adding anything that tracks would end that.
 */
final class Session implements SessionStore
{
    private bool $started = false;

    public function __construct(private readonly bool $secure = true)
    {
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('regal_session');
        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** One-shot message, read once and then gone. */
    public function flash(string $message, string $type = 'info'): void
    {
        $_SESSION['_flash'][] = ['message' => $message, 'type' => $type];
    }

    /** @return list<array{message: string, type: string}> */
    public function takeFlashes(): array
    {
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
            setcookie(session_name() ?: 'regal_session', '', [
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
