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
            // The message can contain credentials - never let it reach a page.
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed.', 0, $e);
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

    public static function driver(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
