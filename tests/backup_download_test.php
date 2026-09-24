<?php
/**
 * Taking the nightly copy off the server.
 *
 * The cron has written a backup to storage/backup every night since it was
 * set up, and that is the same server the shelf runs on: when the server is
 * the problem, the backup goes with it. Nothing offered a way to get it off
 * without a shell, and the two things only it contains - the database and the
 * covers - are not in any export. Now the data page lists the copies and
 * hands them out behind the sign-in.
 */
declare(strict_types=1);

use App\Core\BackupFiles;
use App\Core\Response;

Assert::group('What is offered');

$shelfDir = sys_get_temp_dir() . '/regal-backups-' . getmypid();
@mkdir($shelfDir);
foreach ([
    'regal-2026-09-21.sql' => 'older dump',
    'regal-2026-09-22.sql' => 'the dump',
    'regal-2026-09-22.csv' => 'csv',
    'regal-covers-2026-09-22.zip' => 'zip',
    'cron.log'              => 'not a backup',
    'regal-notes.txt'       => 'nor this',
] as $name => $contents) {
    file_put_contents($shelfDir . '/' . $name, $contents);
}
$offered = new BackupFiles($shelfDir);

Assert::same(
    'the copies, newest night first and the database first within a night',
    array_column($offered->all(), 'name'),
    ['regal-2026-09-22.sql', 'regal-covers-2026-09-22.zip', 'regal-2026-09-22.csv', 'regal-2026-09-21.sql']
);
Assert::same('with their size', $offered->all()[0]['bytes'], strlen('the dump'));
Assert::same('and what they are', array_column($offered->all(), 'kind'), ['sql', 'zip', 'csv', 'sql']);

Assert::group('What is not');

/* The name in the address is checked against the pattern and the listing,
 * never joined onto a path as it came. */
foreach ([
    'cron.log',
    'regal-notes.txt',
    '../config.php',
    '..%2Fconfig.php',
    'regal-2026-09-22.sql/../../config.php',
    "regal-2026-09-22.sql\0.zip",
    'regal-2099-01-01.sql',
    '',
] as $refused) {
    Assert::same('refused: ' . json_encode($refused), $offered->path($refused), null);
}
Assert::same('a real one is found', $offered->path('regal-2026-09-22.sql'), $shelfDir . '/regal-2026-09-22.sql');
Assert::same('an empty directory offers nothing', (new BackupFiles($shelfDir . '/missing'))->all(), []);

Assert::group('Sent without holding it');

/* The cover archive of the larger shelf is 198 MB, more than PHP may hold on
 * most hosts. A download is read from disk as it goes out. */
$download = Response::file($shelfDir . '/regal-covers-2026-09-22.zip', 'regal-covers-2026-09-22.zip', BackupFiles::contentType('x.zip'));
Assert::same('the body is not in memory', $download->body(), '');
Assert::same('the file is', $download->streamedFile(), $shelfDir . '/regal-covers-2026-09-22.zip');
Assert::same('as a download', $download->headers()['Content-Disposition'], 'attachment; filename="regal-covers-2026-09-22.zip"');
Assert::same('of the right length', $download->headers()['Content-Length'], '3');
Assert::same('and type', $download->headers()['Content-Type'], 'application/zip');
Assert::same('an ordinary response streams nothing', Response::html('x')->streamedFile(), null);

$source = (string) file_get_contents(PROJECT_ROOT . '/app/Core/Response.php');
Assert::true('sending reads the file instead of echoing a body', str_contains($source, 'readfile($this->file);'));
Assert::true('with nothing buffered in front of it', str_contains($source, 'ob_end_clean();'));

Assert::group('Where it is offered');

$routes = (string) file_get_contents(PROJECT_ROOT . '/public/index.php');
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/MaintenanceController.php');
$template = (string) file_get_contents(PROJECT_ROOT . '/app/templates/admin/maintenance.php');
Assert::true('one address per copy', str_contains($routes, "get('/admin/backup/{name}', \$data->backup(...))"));
Assert::true('behind the sign-in', (bool) preg_match('/function backup\(Request \$request, array \$params\): Response\s*\{\s*\$guard = \$this->app->requireSignIn\(\);/', $controller));
Assert::true('from the directory the cron writes to', str_contains($controller, "new BackupFiles(PROJECT_ROOT . '/storage/backup')"));
Assert::true('the data page is handed the list', str_contains($controller, "'backups'   => \$this->backups()->all(),"));
Assert::true('and shows it', str_contains($template, 'foreach ($backups as $backup)') && str_contains($template, '/admin/backup/'));
Assert::true('saying why they should leave the server', str_contains(t('maintenance.backups.hint'), 'demselben Server'));

foreach (glob($shelfDir . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir($shelfDir);
