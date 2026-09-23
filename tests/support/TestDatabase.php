<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Config;
use App\Core\Database;
use PDO;

require_once __DIR__ . '/SqliteSchema.php';

/**
 * A fresh, empty database with the schema in it, for one test.
 *
 * SQLite in memory unless told otherwise, which is what runs on a machine
 * with no database server and costs nothing. With REGAL_TEST_MYSQL_HOST set it
 * is a real MySQL or MariaDB instead, reached through the application's own
 * Database::connect and loaded from schema.sql as it stands, untranslated.
 *
 * That second mode exists because production is MySQL and every test used to
 * be SQLite, and the difference is where the expensive faults of this project
 * came from: a DECIMAL that comes back as a string, a keyword one engine has
 * and the other does not, an index the other one would refuse. The CI runs
 * the whole suite once each way.
 *
 *   REGAL_TEST_MYSQL_HOST=127.0.0.1 REGAL_TEST_MYSQL_USER=root \
 *   REGAL_TEST_MYSQL_PASS=secret php tests/run.php
 *
 * Every call makes its own database, named after the process and a counter,
 * and drops it again when the run ends. A test that holds two at once - some
 * do, to compare - gets two.
 *
 * Test support only - never loaded by the application.
 */
final class TestDatabase
{
    private static int $made = 0;

    public static function fresh(): PDO
    {
        $host = getenv('REGAL_TEST_MYSQL_HOST');
        if ($host === false || $host === '') {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            SqliteSchema::apply($pdo, PROJECT_ROOT . '/schema.sql');

            return $pdo;
        }

        $user = (string) getenv('REGAL_TEST_MYSQL_USER');
        $pass = (string) getenv('REGAL_TEST_MYSQL_PASS');
        $name = sprintf('regal_test_%d_%d', getmypid(), ++self::$made);

        $server = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        register_shutdown_function(static function () use ($server, $name): void {
            $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        });

        $pdo = Database::connect(new Config([
            'db_host' => $host,
            'db_name' => $name,
            'db_user' => $user,
            'db_pass' => $pass,
        ]));
        foreach (self::statements((string) file_get_contents(PROJECT_ROOT . '/schema.sql')) as $statement) {
            $pdo->exec($statement);
        }

        return $pdo;
    }

    public static function isMysql(): bool
    {
        $host = getenv('REGAL_TEST_MYSQL_HOST');

        return $host !== false && $host !== '';
    }

    /**
     * schema.sql as phpMyAdmin would run it: one statement at a time, the
     * comments gone. The file has no semicolons inside strings, which is the
     * one thing a split this plain would get wrong.
     *
     * @return list<string>
     */
    private static function statements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));
    }
}
