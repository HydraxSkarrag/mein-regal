<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cookies on a real request.
 *
 * Both cookies this application sets are strictly necessary - the sign-in
 * token and the chosen interface language - which is what keeps the site free
 * of a consent banner. Nothing here tracks anything.
 */
final class HttpCookies implements Cookies
{
    public function __construct(private readonly bool $secure = true)
    {
    }

    public function get(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function set(string $name, string $value, int $expiresAt): void
    {
        setcookie($name, $value, [
            'expires'  => $expiresAt,
            'path'     => '/',
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value;
    }

    public function delete(string $name): void
    {
        setcookie($name, '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'secure'   => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$name]);
    }
}
