<?php
declare(strict_types=1);

namespace Tests\Support;

use PDO;

/**
 * Runs schema.sql against SQLite.
 *
 * Production is MySQL; the development machine has no MySQL at all. Rather
 * than keep a second schema file that would quietly drift out of step, the one
 * authoritative file is translated on the fly. The translation only has to
 * cover the DDL this project actually writes, which is deliberately plain.
 *
 * Test support only - never loaded by the application.
 */
final class SqliteSchema
{
    public static function apply(PDO $pdo, string $schemaFile): void
    {
        foreach (self::statements(file_get_contents($schemaFile) ?: '') as $statement) {
            $pdo->exec($statement);
        }
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        // Strip comments and the MySQL-only session setup.
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*SET\s+NAMES[^;]*;/mi', '', $sql) ?? $sql;

        $statements = [];
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $raw) {
            if (!str_starts_with(strtoupper($raw), 'CREATE TABLE')) {
                continue;
            }
            foreach (self::convertCreateTable($raw) as $converted) {
                $statements[] = $converted;
            }
        }

        return $statements;
    }

    /** @return list<string> the table, then its indexes */
    private static function convertCreateTable(string $sql): array
    {
        // Table options after the closing bracket are MySQL-specific.
        $sql = preg_replace('/\)\s*ENGINE=.*$/is', ')', $sql) ?? $sql;

        preg_match('/CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\((.*)\)\s*$/is', $sql, $match);
        if ($match === []) {
            return [];
        }
        [$table, $body] = [$match[1], $match[2]];

        $columns = [];
        $indexes = [];
        $primaryKey = null;

        foreach (self::splitTopLevel($body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^PRIMARY KEY\s*\((.+)\)$/i', $line, $m) === 1) {
                $primaryKey = self::stripPrefixLengths($m[1]);
                continue;
            }
            if (preg_match('/^UNIQUE KEY\s+(\w+)\s*\((.+)\)$/i', $line, $m) === 1) {
                $indexes[] = sprintf(
                    'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s)',
                    $m[1],
                    $table,
                    self::stripPrefixLengths($m[2])
                );
                continue;
            }
            if (preg_match('/^KEY\s+(\w+)\s*\((.+)\)$/i', $line, $m) === 1) {
                $indexes[] = sprintf(
                    'CREATE INDEX IF NOT EXISTS %s ON %s (%s)',
                    $m[1],
                    $table,
                    self::stripPrefixLengths($m[2])
                );
                continue;
            }

            $columns[] = self::convertColumn($line);
        }

        // A single-column integer primary key becomes SQLite's rowid alias, so
        // AUTOINCREMENT keeps working.
        if ($primaryKey !== null) {
            $pkColumn = trim($primaryKey);
            $autoIndex = null;
            foreach ($columns as $index => $column) {
                if (str_starts_with($column, $pkColumn . ' ') && str_contains($column, 'AUTOINCREMENT')) {
                    $autoIndex = $index;
                    break;
                }
            }
            if ($autoIndex === null) {
                $columns[] = 'PRIMARY KEY (' . $primaryKey . ')';
            }
        }

        return array_merge(
            [sprintf("CREATE TABLE IF NOT EXISTS %s (\n  %s\n)", $table, implode(",\n  ", $columns))],
            $indexes
        );
    }

    private static function convertColumn(string $line): string
    {
        if (str_contains(strtoupper($line), 'AUTO_INCREMENT')) {
            $name = strtok($line, " \t") ?: $line;

            return $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $replacements = [
            '/\bINT\s+UNSIGNED\b/i'      => 'INTEGER',
            '/\bSMALLINT\s+UNSIGNED\b/i' => 'INTEGER',
            '/\bTINYINT\s+UNSIGNED\b/i'  => 'INTEGER',
            '/\bTINYINT\(1\)/i'          => 'INTEGER',
            '/\bMEDIUMTEXT\b/i'          => 'TEXT',
            '/\bVARBINARY\(\d+\)/i'      => 'BLOB',
            '/\bDECIMAL\(\d+,\s*\d+\)/i' => 'NUMERIC',
            '/\bCHAR\(\d+\)/i'           => 'TEXT',
            '/\bVARCHAR\(\d+\)/i'        => 'TEXT',
            '/\bDATETIME\b/i'            => 'TEXT',
            '/\bDATE\b(?!TIME)/i'        => 'TEXT',
        ];

        return trim((string) preg_replace(
            array_keys($replacements),
            array_values($replacements),
            $line
        ));
    }

    /** MySQL prefix indexes - "title(100)" - have no SQLite equivalent. */
    private static function stripPrefixLengths(string $columns): string
    {
        return trim(preg_replace('/\((\d+)\)/', '', $columns) ?? $columns);
    }

    /** @return list<string> split on commas that are not inside brackets */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($body) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return $parts;
    }
}
