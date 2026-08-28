<?php
/**
 * Import a Bookstats CSV export.
 *
 *   php bin/import.php --file=Bücher.csv                 dry run, writes nothing
 *   php bin/import.php --file=Bücher.csv --commit        actually import
 *   php bin/import.php --file=Bücher.csv --sqlite=/tmp/x.sqlite   local trial
 *
 * Always run it without --commit first and read the report.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Import\CsvReader;
use App\Import\Importer;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\TagRepository;

$options = getopt('', ['file:', 'commit', 'owner::', 'sqlite::', 'encoding::']);
$file = $options['file'] ?? null;
if (!is_string($file) || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/import.php --file=<export.csv> [--commit] [--owner=1]\n");
    exit(1);
}

$dryRun = !array_key_exists('commit', $options);
$ownerId = (int) ($options['owner'] ?? 1);

if (isset($options['sqlite']) && $options['sqlite'] !== false) {
    // Local trial run against a throwaway database, no config.php needed.
    require dirname(__DIR__) . '/tests/support/SqliteSchema.php';
    $path = (string) $options['sqlite'];
    $fresh = !is_file($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($fresh) {
        Tests\Support\SqliteSchema::apply($pdo, dirname(__DIR__) . '/schema.sql');
    }
} else {
    $pdo = Database::connect(Config::load());
}

$reader = new CsvReader($file, (string) ($options['encoding'] ?? 'ISO-8859-1'));
$importer = new Importer(
    $pdo,
    new BookRepository($pdo),
    new AuthorRepository($pdo),
    new TagRepository($pdo)
);

$started = microtime(true);
$report = $importer->run($reader, $ownerId, $dryRun);
echo $report->asText($dryRun);
printf("\nDauer: %.1f s\n", microtime(true) - $started);

exit($report->errors === [] ? 0 : 1);
