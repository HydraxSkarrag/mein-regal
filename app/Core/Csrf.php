<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cross-site request forgery tokens.
 *
 * Written once and used by every form that changes something. Without a
 * framework this is one of the pieces nothing else provides, so it is
 * deliberately small enough to read in full and hard to use incorrectly:
 * there is one way to emit a token and one way to check it.
 */
final class Csrf
{
    private const KEY = '_csrf';
    private const FIELD = '_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    /** Ready-made hidden input, so a form cannot forget the field name. */
    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            e($this->token())
        );
    }

    /** @param array<string,mixed> $input */
    public function isValid(array $input): bool
    {
        $submitted = $input[self::FIELD] ?? '';
        $expected = $this->session->get(self::KEY);

        return is_string($submitted)
            && is_string($expected)
            && $submitted !== ''
            && hash_equals($expected, $submitted);
    }

    public static function fieldName(): string
    {
        return self::FIELD;
    }
}
