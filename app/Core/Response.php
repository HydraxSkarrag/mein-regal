<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A response, built before anything is sent.
 *
 * Headers and body travel together so a handler can be tested by inspecting
 * what it returns rather than by capturing output.
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** Keep this page out of search results - login and admin pages use it. */
    public function noIndex(): self
    {
        return $this->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @param string $csp the Content-Security-Policy, or "" for none. It is
     *                    passed in rather than set per response because every
     *                    response wants the same one, and a route that forgot
     *                    it would be the one route that mattered.
     */
    public function send(string $csp = ''): void
    {
        http_response_code($this->status);
        if ($csp !== '') {
            header('Content-Security-Policy: ' . $csp);
        }
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        /* A HEAD answer is the headers and nothing else. The router sends it
           through the GET handler, so the body exists by the time it gets
           here and has to be dropped on the way out. Left to the web server
           it would depend on the web server: mod_php discards it, php-fpm
           behind a proxy may not. */
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
            return;
        }

        echo $this->body;
    }
}
