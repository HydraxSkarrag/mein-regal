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
        }

        return $pdo;
    }

    public static function driver(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
