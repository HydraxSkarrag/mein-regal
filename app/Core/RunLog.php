<?php
declare(strict_types=1);

namespace App\Core;

/**
 * What the nightly job did, kept where somebody can read it later.
 *
 * The job already says what it did: it answers the cron call with a handful
 * of lines, and all-inkl can send those on by mail. What it had no answer for
 * was "why has it found no covers for three nights" - each run existed only
 * as one HTTP response, and once that was gone so was the evidence.
 *
 * So the same lines are kept in storage/, above the document root, and shown
 * on the maintenance page. Not in the database: a run that failed because the
 * database was unreachable is exactly the run worth reading afterwards.
 *
 * The newest runs are the ones that matter, which is the opposite of
 * ErrorLog - there the first entry is the one that explains the outage, and
 * the file stops growing rather than losing it. Here the file keeps the last
 * few weeks and drops what falls off the back.
 */
final class RunLog
{
    public const FILE = 'storage/cron.log';

    /** A month of nightly runs, and enough of a burst to see somebody testing. */
    private const KEEP = 40;

    /** Begins a block, and is what splits the file back into runs. */
    private const MARK = '=== ';

    /**
     * Add one run.
     *
     * Every failure to write is swallowed. The caller is a cron job whose
     * actual work is already done by this point, and a logger that throws
     * while recording a success would turn a good night into a bad one.
     *
     * @param list<string> $lines
     */
    public static function record(array $lines, string $directory = PROJECT_ROOT): void
    {
        $path = $directory . '/' . self::FILE;
        $entry = self::MARK . date('c') . "\n" . implode("\n", $lines) . "\n";

        $runs = self::split(@file_get_contents($path) ?: '');
        $runs[] = $entry;

        if (count($runs) > self::KEEP) {
            $runs = array_slice($runs, -self::KEEP);
        }

        @file_put_contents($path, implode('', $runs), LOCK_EX);
    }

    /**
     * The runs, newest first.
     *
     * @return list<array{at: ?string, lines: list<string>}>
     */
    public static function read(int $limit = 20, string $directory = PROJECT_ROOT): array
    {
        $runs = self::split(@file_get_contents($directory . '/' . self::FILE) ?: '');
        $runs = array_slice($runs, -$limit);

        $out = [];
        foreach (array_reverse($runs) as $run) {
            $lines = explode("\n", trim($run, "\n"));
            $head = array_shift($lines);

            /* The timestamp is read back rather than reformatted here: a
               template knows the reader's language and this does not. An
               entry written by an older version, or a file somebody edited,
               has no timestamp and says so by leaving it out. */
            $at = str_starts_with((string) $head, self::MARK)
                ? trim(substr((string) $head, strlen(self::MARK)))
                : null;

            $out[] = [
                'at'    => $at,
                'lines' => array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== '')),
            ];
        }

        return $out;
    }

    /**
     * Split a file into whole runs, each keeping its own marker line.
     *
     * @return list<string>
     */
    private static function split(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        $parts = preg_split('/^(?=' . preg_quote(self::MARK, '/') . ')/m', $contents) ?: [];

        return array_values(array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }
}
