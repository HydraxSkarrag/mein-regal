<?php
/**
 * Write the whole collection to a file.
 *
 *   php bin/export.php --format=bookstats --out=storage/
 *   php bin/export.php --format=full
 *   php bin/export.php --format=json --out=-        (to stdout)
 *
 * Formats:
 *   bookstats  the original export's 19 columns, Latin-1 and semicolons.
 *              Lossy, but reads back in through bin/import.php.
 *   full       every column, UTF-8. The everyday backup.
 *   json       everything, including contributors, tags and cover sources.
 *
 * Meant for the nightly cron as much as for the command line. The point of
 * the whole project was a service that shut down, so a copy that outlives
 * this application is not a nicety.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Export\Exporter;

$options = getopt('', ['format::', 'out::', 'owner::', 'sqlite::']);
$format = (string) ($options['format'] ?? 'full');
if (!in_array($format, ['bookstats', 'full', 'json'], true)) {
    fwrite(STDERR, "Unknown format. Use bookstats, full or json.\n");
    exit(1);
}
$ownerId = (int) ($options['owner'] ?? 1);

if (isset($options['sqlite']) && $options['sqlite'] !== false) {
    $pdo = new PDO('sqlite:' . $options['sqlite']);
    Database::sqliteDefaults($pdo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} else {
    $pdo = Database::connect(Config::load());
}

$extension = $format === 'json' ? 'json' : 'csv';
$name = sprintf('regal-%s-%s.%s', $format, date('Y-m-d'), $extension);

$out = (string) ($options['out'] ?? (PROJECT_ROOT . '/storage/'));
if ($out === '-') {
    $target = 'php://stdout';
} else {
    if (is_dir($out) || str_ends_with($out, '/')) {
        if (!is_dir($out) && !mkdir($out, 0o755, true) && !is_dir($out)) {
            fwrite(STDERR, "Cannot create $out\n");
            exit(1);
        }
        $target = rtrim($out, '/') . '/' . $name;
    } else {
        $target = $out;
    }
}

$handle = fopen($target, 'w');
if ($handle === false) {
    fwrite(STDERR, "Cannot write to $target\n");
    exit(1);
}

$exporter = new Exporter($pdo);
$started = microtime(true);

$count = match ($format) {
    'bookstats' => $exporter->bookstatsCsv($ownerId, $handle),
    'full'      => $exporter->fullCsv($ownerId, $handle),
    'json'      => (function () use ($exporter, $ownerId, $handle): int {
        $data = $exporter->json($ownerId);
        fwrite($handle, (string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $data['count'];
    })(),
};

fclose($handle);

if ($target !== 'php://stdout') {
    printf(
        "%d books written as %s: %s (%s, %.1f s)\n",
        $count,
        $format,
        $target,
        number_format((int) filesize($target) / 1024, 0, ',', '.') . ' KB',
        microtime(true) - $started
    );
}
