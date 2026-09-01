<?php
/**
 * First-run setup: create the schema and the first account.
 *
 *   php bin/setup.php --email=... --name=... [--password=...]
 *   php bin/setup.php --sqlite=/tmp/x.sqlite --email=... --name=...
 *
 * On all-inkl there is no shell, so schema.sql is normally run once through
 * phpMyAdmin and this script is used only to add the account - it creates
 * tables when they are missing and leaves them alone when they are not.
 *
 * A password may be generated rather than passed on the command line, where
 * it would end up in the shell history.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Repository\UserRepository;

$options = getopt('', ['email:', 'name:', 'password::', 'locale::', 'sqlite::']);

$email = $options['email'] ?? null;
$name = $options['name'] ?? null;
if (!is_string($email) || !is_string($name) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php bin/setup.php --email=<address> --name=<display name> [--password=...]\n");
    exit(1);
}

$generated = null;
$password = $options['password'] ?? null;
if (!is_string($password) || $password === '') {
    // Readable, long, and not in the shell history.
    $generated = bin2hex(random_bytes(9));
    $password = $generated;
}

if (isset($options['sqlite']) && $options['sqlite'] !== false) {
    require dirname(__DIR__) . '/tests/support/SqliteSchema.php';
    $path = (string) $options['sqlite'];
    $pdo = new PDO('sqlite:' . $path);
    Database::sqliteDefaults($pdo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    Tests\Support\SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
} else {
    $pdo = Database::connect(Config::load());
    $tables = $pdo->query("SHOW TABLES LIKE 'books'")->fetchAll();
    if ($tables === []) {
        fwrite(STDERR, "The tables are missing. Load schema.sql first, e.g. through phpMyAdmin.\n");
        exit(1);
    }
}

$users = new UserRepository($pdo);
if ($users->findByEmail($email) !== null) {
    fwrite(STDERR, "Es gibt bereits ein Konto mit dieser Adresse.\n");
    exit(1);
}

$id = $users->create($email, $password, $name, (string) ($options['locale'] ?? 'de'), true);

printf("Konto angelegt (id %d): %s\n", $id, $email);
if ($generated !== null) {
    printf("Passwort: %s\n", $generated);
    echo "Write it down now - only its hash is stored, and it cannot be read back.\n";
}
