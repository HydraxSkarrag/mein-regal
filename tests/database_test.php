<?php
/**
 * Pointing a maintenance script at the wrong database.
 *
 * Running one without --sqlite reaches for config.php, and an SQLite DSN
 * there is opened by creating an empty file when none exists. What came back
 * was "no such table: covers" from somewhere deep in a query - a message
 * about the symptom, from which the actual mistake cannot be read.
 */
declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;
use Tests\Support\SqliteSchema;

require_once __DIR__ . '/support/SqliteSchema.php';

Assert::group('Database::assertSchema');

$empty = new PDO('sqlite::memory:');
$empty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$message = '';
try {
    Database::assertSchema($empty, 'config.php -> sqlite:/tmp/nothing.sqlite');
} catch (RuntimeException $e) {
    $message = $e->getMessage();
}

Assert::true('an empty database is refused', $message !== '');
Assert::true('it says which database was opened', str_contains($message, '/tmp/nothing.sqlite'));
Assert::true('and where the local one is', str_contains($message, 'storage/dev.sqlite'));

$ready = new PDO('sqlite::memory:');
$ready->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ready->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
SqliteSchema::apply($ready, dirname(__DIR__) . '/schema.sql');

$threw = false;
try {
    Database::assertSchema($ready, 'storage/dev.sqlite');
} catch (RuntimeException $e) {
    $threw = true;
}
Assert::same('a database with the schema passes', $threw, false);

// An empty shelf is not a missing one: a fresh installation before the first
// import has the tables and no books, and must not be refused.
$ready->exec('DELETE FROM books');
$threw = false;
try {
    Database::assertSchema($ready, 'storage/dev.sqlite');
} catch (RuntimeException $e) {
    $threw = true;
}
Assert::same('and so does an empty one', $threw, false);

Assert::group('Database::describe');

Assert::same(
    'a DSN is named as it stands',
    Database::describe(new Config(['db_dsn' => 'sqlite:/tmp/x.sqlite'])),
    'config.php -> sqlite:/tmp/x.sqlite'
);

// The message ends up in a terminal somebody else may be looking at.
$mysql = Database::describe(new Config([
    'db_name' => 'regal',
    'db_host' => 'db.example.org',
    'db_user' => 'regal_user',
    'db_pass' => 'a-real-password',
]));
Assert::true('the MySQL database is named', str_contains($mysql, 'regal'));
Assert::true('and its host', str_contains($mysql, 'db.example.org'));
Assert::true('but never the password', !str_contains($mysql, 'a-real-password'));
