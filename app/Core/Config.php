<?php
declare(strict_types=1);

namespace App\Core;

use ParseError;
use RuntimeException;

/**
 * Read-only access to config.php.
 *
 * Branding, URLs and credentials all live in that one file so a second
 * installation only has to edit it - nothing is hard-coded in the source.
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @param ?string $path defaults to config.php next to app/, or whatever
     *                      REGAL_CONFIG names - which is how a second
     *                      instance runs side by side without swapping files
     *                      around underneath the first one.
     */
    public static function load(?string $path = null): self
    {
        $path ??= getenv('REGAL_CONFIG') ?: PROJECT_ROOT . '/config.php';
        if (!is_file($path)) {
            throw new StartupError(
                'config.php was not found at ' . $path . ' - it belongs beside app/, '
                . 'one level above the document root. Copy config.sample.php and fill it in.'
            );
        }
        /* A typo in config.php is the likeliest way a first installation
           fails, because on hosting without a shell the file is written in
           whatever editor the control panel offers - and those highlight
           PHP loosely enough that a missing comma or quote looks fine. The
           bare word "ParseError" on a page is not much help; the line
           number is nearly the whole answer. */
        try {
            $values = require $path;
        } catch (ParseError $e) {
            throw new StartupError(
                'config.php has a syntax error on line ' . $e->getLine() . ': ' . $e->getMessage()
                . ' - a missing comma, quote or bracket, usually.',
                0,
                $e
            );
        }
        if (!is_array($values)) {
            throw new StartupError(
                'config.php does not return an array. It has to end with a return statement: '
                . "return [ 'db_host' => '...', ... ];"
            );
        }

        return new self($values);
    }

    /** Dot notation: get('mail.from'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $cursor = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * A flag, defaulting to whatever the application does without it.
     *
     * An absent key means "not configured", which must land on the default -
     * so a config.php written before the option existed keeps behaving the
     * way it did. Only an explicit false turns something off.
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : (bool) $value;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
