<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO factory.
 *
 * Production is MySQL on all-inkl. A full PDO DSN may be configured instead,
 * which is what the local test setup uses to run against SQLite - there is no
 * MySQL on the development machine.
 */
final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = $config->str('db_dsn');
        $user = null;
        $pass = null;

        if ($dsn === '') {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $config->str('db_host', 'localhost'),
                $config->str('db_name'),
                $config->str('db_charset', 'utf8mb4')
            );
            $user = $config->str('db_user');
            $pass = $config->str('db_pass');
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            /* PDO puts the whole DSN in its message, user included, so that
               goes to the log and nothing else. What the page gets is the
               fact plus the driver's own code - "2002" is a host that does
               not answer, "1045" a name or password, "1049" a database that
               is not there - which is the difference between three guesses
               and one. No advice about what the host "usually" is: it is
               localhost on some hosts and never on others, and guessing at
               that in a message is how somebody is sent to change the one
               setting that was already right. */
            error_log('Database connection failed: ' . $e->getMessage());
            $code = (string) $e->getCode();
            throw new StartupError(
                'The database refused the connection' . ($code !== '' ? ' (' . $code . ')' : '')
                . '. Check db_host, db_name, db_user and db_pass in config.php. The number '
                . 'is the driver\'s own: 2002 is a host that does not answer, 1045 a name '
                . 'or password, 1049 a database that is not there.',
                0,
                $e
            );
        }

        if (self::driver($pdo) === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::sqliteDefaults($pdo);
        }

        return $pdo;
    }

    /**
     * SQLite allows one writer, and by default gives up on a busy file at
     * once. That is fine for a request and wrong for anything long: the
     * nightly enrichment writes every few seconds for hours, and a backup or
     * a maintenance script touching the same file would end it with "database
     * is locked" - after the work was done, with no way to tell how far it
     * got. Waiting is almost always the right answer instead.
     *
     * MySQL, which is what a server runs, has its own lock waiting and needs
     * none of this.
     */
    public static function sqliteDefaults(PDO $pdo): void
    {
        $pdo->exec('PRAGMA busy_timeout = 20000');
    }

    /**
     * Refuse to work on a database that holds no catalogue.
     *
     * A maintenance script run without --sqlite reaches for config.php, and an
     * SQLite DSN in there is "opened" by creating an empty file when it does
     * not exist. Every later query then fails with "no such table: covers",
     * which names the symptom and hides the mistake - the script is looking at
     * the wrong database, not a broken one.
     *
     * @param string $where the connection in words, for the message
     */
    public static function assertSchema(PDO $pdo, string $where): void
    {
        try {
            $pdo->query('SELECT 1 FROM books LIMIT 1');
        } catch (PDOException $e) {
            throw new RuntimeException(
                'No catalogue in ' . $where . " - the tables are not there.\n"
                . "Either that is the wrong database, or schema.sql has never been loaded into it.\n"
                . "Locally the shelf lives in storage/dev.sqlite:\n"
                . "  --sqlite=storage/dev.sqlite\n"
                . '  REGAL_CONFIG=config.dev.php ...',
                0,
                $e
            );
        }
    }

    /**
     * Where a connection points, in words that are safe to print.
     *
     * The password is never part of it: this ends up in error messages, and
     * on a shared machine those are read by whoever is passing.
     */
    public static function describe(Config $config): string
    {
        $dsn = $config->str('db_dsn');
        if ($dsn !== '') {
            return 'config.php -> ' . $dsn;
        }

        return sprintf(
            'config.php -> mysql database %s on %s',
            $config->str('db_name'),
            $config->str('db_host', 'localhost')
        );
    }

    public static function driver(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
