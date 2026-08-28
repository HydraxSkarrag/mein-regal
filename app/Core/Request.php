<?php
declare(strict_types=1);

namespace App\Core;

/**
 * The incoming request, read once into something typed.
 *
 * Nothing else in the application touches $_GET, $_POST or $_SERVER, so there
 * is a single place where untrusted input enters and a single place to look
 * when asking where a value came from.
 */
final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . trim($path, '/'),
            $_GET,
            $_POST,
            $_SERVER,
            $_FILES
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query($key, (string) $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function post(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function postBool(string $key): bool
    {
        return in_array($this->post($key), ['1', 'on', 'true', 'yes'], true);
    }

    /** @return array<string,mixed> */
    public function allPost(): array
    {
        return $this->post;
    }

    /** @return array<string,mixed> */
    public function allQuery(): array
    {
        return $this->query;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * The client address, taken from REMOTE_ADDR only.
     *
     * X-Forwarded-For is not trusted here: on shared hosting anyone can send
     * that header, and believing it would let an attacker sidestep the login
     * rate limit by inventing a new address for every attempt.
     */
    public function ip(): ?string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public function acceptLanguage(): ?string
    {
        $value = $this->server['HTTP_ACCEPT_LANGUAGE'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** True for fetch()/XHR, which want JSON rather than a page. */
    public function wantsJson(): bool
    {
        return $this->header('X-Requested-With') === 'fetch'
            || str_contains((string) $this->header('Accept'), 'application/json');
    }
}
