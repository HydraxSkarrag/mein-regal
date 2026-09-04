<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * A log file the operator can actually reach.
 *
 * PHP's error_log() goes wherever the host decided. On all-inkl it goes
 * nowhere at all until error_log is given a path in .user.ini - their own
 * instructions say so - which means every diagnosis this application wrote
 * for itself was discarded, and the person in front of the broken page was
 * told to look in a control panel for a file that was never written.
 *
 * So it writes its own, next to config.php: above the document root, not
 * fetchable over HTTP, and reachable with the FTP client that put config.php
 * there. error_log() is still called as well, for hosts that do keep one.
 *
 * Both callers are catch blocks, which is what shapes the rest of it. It
 * appends but stops at 64 KB, because a site that is broken and busy should
 * not fill a disk with one repeated sentence and the first entry is the one
 * that matters. Every failure to write is swallowed: a logger that throws
 * while handling an error replaces a diagnosis with a blank page.
 */
final class ErrorLog
{
    public const FILE = 'boot-error.log';

    private const MAX_BYTES = 65536;

    public static function record(Throwable $e, string $phase): void
    {
        error_log('[regal] ' . $phase . ': ' . $e->getMessage());

        $path = PROJECT_ROOT . '/' . self::FILE;
        if (is_file($path) && filesize($path) >= self::MAX_BYTES) {
            return;
        }

        $entry = sprintf(
            "[%s] %s while %s\n  %s\n  at %s:%d\n",
            date('c'),
            $e::class,
            $phase,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        $previous = $e->getPrevious();
        if ($previous !== null) {
            // The cause, which is usually the sentence somebody needs: a
            // StartupError says "the database refused the connection", and
            // the PDOException underneath it says which host and why.
            $entry .= '  caused by ' . $previous::class . ': ' . $previous->getMessage() . "\n";
        }

        @file_put_contents($path, $entry, FILE_APPEND);
    }
}
