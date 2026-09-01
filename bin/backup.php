<?php
/**
 * Make a copy of everything.
 *
 *   php bin/backup.php
 *   php bin/backup.php --keep=14 --out=/pfad/zum/backup-space
 *
 * Writes three things per run:
 *
 *   regal-YYYY-MM-DD.sql        the database
 *   regal-YYYY-MM-DD.csv        the collection in the original export format
 *   regal-covers-YYYY-MM-DD.zip the cover photographs
 *
 * The covers are in there because they are the one thing that exists nowhere
 * else: bibliographic data can be fetched again from the DNB, but a
 * photograph of her own copy cannot.
 *
 * There is no mysqldump on all-inkl's Privat+ tariff and no shell to run it
 * from, so the dump is written through PDO. That is slower than mysqldump and
 * entirely adequate for a few thousand rows.
 *
 * For the nightly cron this is called through /cron, not from a shell.
 */
declare(strict_types=1);

/*
 * No "exit unless CLI" guard here, deliberately.
 *
 * The /cron endpoint includes this file for its functions, and PHP_SAPI is
 * never 'cli' under a web server - it is 'cgi-fcgi' on the hosting this
 * targets. Such a guard does not protect the file; it kills the request that
 * legitimately included it. That is exactly how the nightly job came to
 * answer 404 and do nothing at all.
 *
 * The file needs no guard: bin/ lives outside the document root and cannot be
 * fetched over HTTP. Whether it *runs* is decided at the bottom, where it
 * checks that it is the program being executed rather than merely included.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

/**
 * @return array{files: list<string>, bytes: int, removed: int}
 */
function backup(PDO $pdo, string $directory, int $keepDays, bool $verbose = true): array
{
    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create ' . $directory);
    }

    $stamp = date('Y-m-d');
    $files = [];

    // ---- the database ---------------------------------------------------
    $sqlPath = $directory . '/regal-' . $stamp . '.sql';
    $rows = dumpDatabase($pdo, $sqlPath);
    $files[] = $sqlPath;
    if ($verbose) {
        printf("  %-44s %7d rows\n", basename($sqlPath), $rows);
    }

    // ---- the collection, in the format that outlives this application ----
    $csvPath = $directory . '/regal-' . $stamp . '.csv';
    $handle = fopen($csvPath, 'w');
    if ($handle === false) {
        throw new RuntimeException('Cannot write ' . $csvPath);
    }
    $books = (new App\Export\Exporter($pdo))->bookstatsCsv(1, $handle);
    fclose($handle);
    $files[] = $csvPath;
    if ($verbose) {
        printf("  %-44s %7d books\n", basename($csvPath), $books);
    }

    // ---- the covers -----------------------------------------------------
    $zipPath = $directory . '/regal-covers-' . $stamp . '.zip';
    $covers = archiveCovers(PROJECT_ROOT . '/public/covers', $zipPath);
    if ($covers > 0) {
        $files[] = $zipPath;
        if ($verbose) {
            printf("  %-44s %7d files\n", basename($zipPath), $covers);
        }
    }

    $bytes = 0;
    foreach ($files as $file) {
        $bytes += (int) filesize($file);
    }

    return ['files' => $files, 'bytes' => $bytes, 'removed' => prune($directory, $keepDays, $verbose)];
}

/**
 * A portable SQL dump.
 *
 * Written through PDO because there is no shell to run mysqldump from. Every
 * value is quoted by the driver rather than by hand - a dump that mangles an
 * apostrophe in a book title is worse than no dump, because the damage only
 * shows on the day it is needed.
 */
function dumpDatabase(PDO $pdo, string $path): int
{
    $handle = fopen($path, 'w');
    if ($handle === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $tables = $driver === 'sqlite'
        ? $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    fwrite($handle, "-- Mein Regal, backup taken " . date('c') . "\n");
    fwrite($handle, "-- To restore: import this file in phpMyAdmin.\n");
    fwrite($handle, "-- The tables must exist first; load schema.sql before this.\n");
    fwrite($handle, "--\n");
    fwrite($handle, "-- Note: notes and page texts may contain line breaks, so a single\n");
    fwrite($handle, "-- INSERT statement can span several lines. That is valid SQL; reading\n");
    fwrite($handle, "-- the file line by line will not work.\n");
    fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $total = 0;
    foreach ($tables as $table) {
        // Sessions and rate-limit counters are worthless a day later, and
        // auth tokens are live credentials - none of it belongs in a copy
        // that gets carried around.
        if (in_array($table, ['sessions', 'login_attempts', 'auth_tokens', 'lookup_hits'], true)) {
            continue;
        }

        fwrite($handle, "\n-- " . $table . "\n");
        $rows = $pdo->query('SELECT * FROM ' . $table);

        foreach ($rows as $row) {
            $columns = array_keys($row);
            $values = array_map(
                static fn ($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                array_values($row)
            );
            fwrite($handle, sprintf(
                "INSERT INTO %s (%s) VALUES (%s);\n",
                $table,
                implode(', ', $columns),
                implode(', ', $values)
            ));
            $total++;
        }
    }

    fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    return $total;
}

/** The photographs, which exist nowhere else. */
function archiveCovers(string $directory, string $path): int
{
    if (!is_dir($directory) || !class_exists(ZipArchive::class)) {
        return 0;
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return 0;
    }

    $count = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'webp') {
            continue;
        }
        // Skip the small copies: they are regenerated from the originals.
        if (str_ends_with($file->getBasename('.webp'), '-klein')) {
            continue;
        }
        $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($directory) + 1));
        $count++;
    }
    $zip->close();

    if ($count === 0) {
        @unlink($path);
    }

    return $count;
}

/**
 * Split a dump into statements without tripping over the contents.
 *
 * A note or a page text may contain a semicolon or a newline, so neither can
 * be used as a boundary on its own. This walks the string and only ends a
 * statement on a semicolon that is outside a quoted value.
 *
 * Restoring through phpMyAdmin needs none of this - it parses SQL properly.
 * It exists so the dump can be checked automatically, which is the only way
 * to know a backup works before the day it is needed.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($inString) {
            $current .= $char;
            if ($char === "'") {
                // Doubled quote inside a string is an escaped quote.
                if ($i + 1 < $length && $sql[$i + 1] === "'") {
                    $current .= $sql[++$i];
                    continue;
                }
                $inString = false;
            }
            continue;
        }

        if ($char === "'") {
            $inString = true;
            $current .= $char;
            continue;
        }
        if ($char === ';') {
            $statement = stripLeadingComments($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }

    $tail = stripLeadingComments($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * Drop the comment lines that precede a statement, keeping the statement.
 *
 * The dump puts a "-- tablename" line before each table's rows, so the first
 * INSERT of every table arrives with a comment glued to its front. Discarding
 * anything that begins with "--" silently threw that row away - one book, one
 * author, one tag, one page, one user, every time. Nothing errored; the copy
 * was simply, quietly incomplete.
 */
function stripLeadingComments(string $statement): string
{
    $lines = preg_split('/\R/', trim($statement)) ?: [];

    // Blank lines have to be skipped too. Several empty tables in a row put
    // their heading comments one after another with blank lines between, and
    // stopping at the first blank left the whole block glued to the front of
    // the next real statement - which then no longer looked like an INSERT
    // and was dropped without a word.
    while ($lines !== []) {
        $line = ltrim($lines[0]);
        if ($line !== '' && !str_starts_with($line, '--')) {
            break;
        }
        array_shift($lines);
    }

    return trim(implode("\n", $lines));
}

/** Older copies, so the backup space does not fill up unattended. */
function prune(string $directory, int $keepDays, bool $verbose): int
{
    if ($keepDays <= 0) {
        return 0;
    }
    $cutoff = time() - $keepDays * 86400;
    $removed = 0;

    foreach (glob($directory . '/regal-*') ?: [] as $file) {
        if (!is_file($file) || filemtime($file) >= $cutoff) {
            continue;
        }
        @unlink($file);
        $removed++;
        if ($verbose) {
            printf("  entfernt: %s\n", basename($file));
        }
    }

    return $removed;
}

// Only when this file is the program being run - including it for its
// functions must not start a run of its own.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $options = getopt('', ['out::', 'keep::', 'sqlite::']);

    if (isset($options['sqlite']) && $options['sqlite'] !== false) {
        $pdo = new PDO('sqlite:' . $options['sqlite']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } else {
        $pdo = Database::connect(Config::load());
    }

    $directory = (string) ($options['out'] ?? (PROJECT_ROOT . '/storage/backup'));
    $keep = (int) ($options['keep'] ?? 30);

    $started = microtime(true);
    $result = backup($pdo, $directory, $keep);

    printf(
        "\n%d files, %s, in %s (%.1f s)\n",
        count($result['files']),
        number_format($result['bytes'] / 1024 / 1024, 1) . ' MB',
        $directory,
        microtime(true) - $started
    );
}
