<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * The few places where MySQL and SQLite genuinely disagree.
 *
 * Production runs MySQL; the tests run SQLite because the development machine
 * has no MySQL. That split is only safe if the differences are named in one
 * place - a query written in SQLite's dialect and never exercised against
 * MySQL is a bug that only appears after deployment.
 */
final class Dialect
{
    private readonly string $driver;

    public function __construct(PDO $pdo)
    {
        $this->driver = Database::driver($pdo);
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /**
     * Insert, silently doing nothing if the row is already there.
     * MySQL spells it "INSERT IGNORE"; SQLite spells it "INSERT OR IGNORE".
     *
     * @param list<string> $columns
     */
    public function insertIgnore(string $table, array $columns): string
    {
        $keyword = $this->isSqlite() ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        return sprintf('%s %s (%s) VALUES (%s)', $keyword, $table, implode(', ', $columns), $placeholders);
    }

    /**
     * Insert, or overwrite the given columns if the row already exists.
     *
     * @param list<string> $columns
     * @param list<string> $conflictColumns the unique key that decides "exists"
     * @param list<string> $updateColumns
     */
    public function upsert(string $table, array $columns, array $conflictColumns, array $updateColumns): string
    {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $base = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            $placeholders
        );

        if ($this->isSqlite()) {
            $assignments = implode(', ', array_map(
                static fn (string $c): string => sprintf('%s = excluded.%s', $c, $c),
                $updateColumns
            ));

            return sprintf(
                '%s ON CONFLICT (%s) DO UPDATE SET %s',
                $base,
                implode(', ', $conflictColumns),
                $assignments
            );
        }

        $assignments = implode(', ', array_map(
            static fn (string $c): string => sprintf('%s = VALUES(%s)', $c, $c),
            $updateColumns
        ));

        return sprintf('%s ON DUPLICATE KEY UPDATE %s', $base, $assignments);
    }

    /**
     * The character that escapes a wildcard in a LIKE pattern.
     *
     * Not the backslash, which is the obvious choice and the wrong one. The
     * clause has to name it - ESCAPE '<char>' - and MySQL reads a backslash
     * inside a string literal as escaping whatever follows it, so naming a
     * lone backslash there swallows the closing quote of its own literal and
     * the statement never parses. SQLite gives a backslash no such meaning,
     * so the same SQL runs there - which is how a search that works on every
     * test can fail on every live shelf.
     *
     * An exclamation mark has no meaning in either dialect, so one clause is
     * correct in both, and the difference stops being something to remember.
     */
    public const LIKE_ESCAPE = '!';

    /** Escape the wildcards in a user-supplied search term. */
    public static function escapeLike(string $term): string
    {
        // The escape character itself first, or escaping a % would then have
        // its own escape character escaped.
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term
        );
    }
}
