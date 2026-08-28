<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cookie writing, behind an interface.
 *
 * Two cookies exist in this application and both are strictly necessary:
 * the "stay signed in" token and the chosen interface language. Neither
 * tracks anything, which is what keeps the site free of a consent banner.
 *
 * Wrapping setcookie() also means Auth can be tested without a live request.
 */
interface Cookies
{
    public function get(string $name): ?string;

    public function set(string $name, string $value, int $expiresAt): void;

    public function delete(string $name): void;
}

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
