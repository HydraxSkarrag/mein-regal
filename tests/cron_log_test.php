<?php
/**
 * What the nightly job did, after the night is over.
 *
 * The job says what it did - it answers the cron call with a handful of lines,
 * and all-inkl can pass those on by mail. What it had no answer for was "since
 * when has it been finding nothing": each run existed only as one HTTP
 * response, and once that was read the evidence was gone.
 */
declare(strict_types=1);

use App\Core\Formatter;
use App\Core\RunLog;

$dir = sys_get_temp_dir() . '/regal-runlog-' . bin2hex(random_bytes(4));
mkdir($dir . '/storage', 0o755, true);

Assert::group('A run is kept, and reads back as one run');

RunLog::record(['backup: 3 files, 4.9 MB, 0 old ones removed',
                'enrich: looked up 12, covers 1, metadata 2, misses 0',
                'took 85.9s'], $dir);

$runs = RunLog::read(20, $dir);

Assert::same('one run', count($runs), 1);
Assert::same('with its three lines', count($runs[0]['lines']), 3);
Assert::same('in the order the job wrote them', $runs[0]['lines'][0], 'backup: 3 files, 4.9 MB, 0 old ones removed');
Assert::true('and a moment attached', $runs[0]['at'] !== null);

// Two runs on one night are told apart by the clock, which is why the log
// keeps a time and not only a date.
Assert::true('the moment carries the time of day', str_contains((string) $runs[0]['at'], 'T'));
Assert::true('and reads as a sentence', (new Formatter('de'))->dateTime($runs[0]['at']) !== '');

Assert::group('Newest first, because that is the one being looked for');

RunLog::record(['enrich: looked up 0, covers 0, metadata 0, misses 0'], $dir);
$runs = RunLog::read(20, $dir);

Assert::same('two runs now', count($runs), 2);
Assert::same('the newest is at the top', $runs[0]['lines'][0], 'enrich: looked up 0, covers 0, metadata 0, misses 0');
Assert::same('and the first one is still there', count($runs[1]['lines']), 3);

Assert::group('The file does not grow for ever');

/* Unlike the boot error log, where the first entry is the one that explains
 * the outage and the file stops growing rather than lose it. Here the last
 * few weeks are what anybody wants, so what falls off the back is dropped. */
for ($i = 0; $i < 60; $i++) {
    RunLog::record(['run ' . $i], $dir);
}
$all = RunLog::read(1000, $dir);

Assert::true('it is capped', count($all) <= 40);
Assert::same('and keeps the newest', $all[0]['lines'][0], 'run 59');
Assert::true('the oldest are gone', $all[count($all) - 1]['lines'][0] !== 'backup: 3 files, 4.9 MB, 0 old ones removed');

Assert::group('Nothing recorded yet is not an error');

$empty = sys_get_temp_dir() . '/regal-runlog-empty-' . bin2hex(random_bytes(4));
mkdir($empty . '/storage', 0o755, true);
Assert::same('an absent file reads as no runs', RunLog::read(20, $empty), []);

Assert::group('It lives above the document root');

/* A log of what the machine has been doing is nobody else's business, and it
 * names file counts and sizes. storage/ is not served. */
Assert::true('under storage', str_starts_with(RunLog::FILE, 'storage/'));

$ignore = (string) file_get_contents(PROJECT_ROOT . '/.gitignore');
Assert::true('and never committed', str_contains($ignore, 'storage/'));

Assert::group('One address per job');

/* The two jobs are not one kind of work and do not want one rhythm: the copy
 * takes seconds and is worth making every night for ever, while the lookups
 * are minutes of waiting on other people's servers and have almost nothing
 * left to find once a shelf is full.
 *
 * The job is named in the path and not in a parameter. A mistyped path is a
 * 404, which a cron service reports as a failure and somebody notices; a
 * mistyped parameter would have left the endpoint deciding what was probably
 * meant and answering 200 either way, which is the same as not noticing. */
$cron = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/CronController.php');
$routes = (string) file_get_contents(PROJECT_ROOT . '/public/index.php');

foreach (['/cron', '/cron/backup', '/cron/enrich'] as $path) {
    Assert::true($path . ' is a route', str_contains($routes, "get('" . $path . "'"));
}
Assert::true('nothing names a step in a parameter', !str_contains($cron, "query('do')"));

/* /cron is the one that can still promise an order: the copy before the
 * lookups, so a night that runs out of time has at least left a backup. Split
 * into two entries that becomes a question of how they were scheduled. */
Assert::true('the combined job runs both', str_contains($cron, "['backup', 'enrich']"));
$combined = strpos($cron, "\$this->perform(\$request, ['backup', 'enrich'])");
Assert::true('and it is the one behind /cron', $combined !== false);

$backupAt = strpos($cron, "in_array('backup', \$steps, true)");
$enrichAt = strpos($cron, "in_array('enrich', \$steps, true)");
Assert::true('the copy is made first', $backupAt !== false && $backupAt < (int) $enrichAt);

/* Housekeeping has no address. It is milliseconds and always safe, nobody
 * would schedule it on a rhythm of its own, and offering the choice would be
 * one more thing to decide wrongly. */
Assert::true('no route for the housekeeping', !str_contains($routes, '/cron/purge'));
Assert::true('it runs whichever job it was', str_contains($cron, '$lines[] = $this->tidy();'));

// Every address is behind the same secret, or one of them is a way in.
Assert::same('the guard is written once', substr_count($cron, 'hash_equals'), 1);
Assert::same('and every job goes through it', substr_count($cron, '$this->perform($request,'), 3);

// And every run is recorded, whichever job it was.
$recordAt = strpos($cron, 'RunLog::record(');
$reportAt = strrpos($cron, 'return Response::text(implode(');
Assert::true('the log is written before the answer', $recordAt !== false && $recordAt < (int) $reportAt);

$refusals = substr($cron, 0, (int) $recordAt);
Assert::true(
    'a call with a wrong key records nothing',
    str_contains($refusals, 'hash_equals') && !str_contains($refusals, 'RunLog::record(')
);

Assert::group('The maintenance page shows them');

$page = (string) file_get_contents(PROJECT_ROOT . '/app/templates/admin/maintenance.php');
$controller = (string) file_get_contents(PROJECT_ROOT . '/app/Controller/MaintenanceController.php');

Assert::true('the page is handed the runs', str_contains($controller, 'RunLog::read('));
Assert::true('and lists them', str_contains($page, '$runs as $run'));
Assert::true('with the moment as a real time element', str_contains($page, '<time datetime='));

/* A failed line is the one worth finding in a wall of successful nights, and
 * it is marked on the word the job itself writes. */
Assert::true('a failure stands out', str_contains($page, "str_contains(\$line, 'FAILED')"));

// An empty log says which of the two it is as far as it can tell.
$german = require PROJECT_ROOT . '/app/lang/de.php';
Assert::true('and an empty log has its own sentence', isset($german['maintenance.cron.none']));

array_map('unlink', glob($dir . '/storage/*') ?: []);
@rmdir($dir . '/storage');
@rmdir($dir);
@rmdir($empty . '/storage');
@rmdir($empty);

Assert::group('The page that says a page is missing');

/* Everything on this shelf is left-aligned; the error page was the last thing
 * still centred. On a wide screen a centred 404 puts the heading, the
 * sentence and the way back in three different places, and the eye starts
 * again at each one. */
$css = (string) file_get_contents(PROJECT_ROOT . '/public/css/style.css');
$rule = substr($css, (int) strpos($css, '.page-empty {'), 120);

Assert::true('the error page is not centred', !str_contains($rule, 'text-align: center'));

/* The aside sits under the plain sentence rather than instead of it: somebody
 * who has landed here wants to know what happened first. And only 404 gets
 * one - a 500 means something is actually broken, and being funny about that
 * is being funny at the reader's expense. */
$app = (string) file_get_contents(PROJECT_ROOT . '/app/Http/Application.php');
$notFound = substr($app, (int) strpos($app, 'public function notFound()'), 400);
$serverError = substr($app, (int) strpos($app, 'public function serverError()'), 400);

Assert::true('404 has a line of its own', str_contains($notFound, "t('error.404.quip')"));
Assert::true('500 has none', !str_contains($serverError, 'quip'));

$template = (string) file_get_contents(PROJECT_ROOT . '/app/templates/errors/simple.php');
$bodyAt = strpos($template, '$body');
$quipAt = strpos($template, '$quip');
Assert::true('and it comes after the plain sentence', $bodyAt !== false && $quipAt !== false && $bodyAt < $quipAt);
Assert::true('an error page without one still renders', str_contains($template, "(\$quip ?? '') !== ''"));

foreach (['de', 'en'] as $locale) {
    $messages = require PROJECT_ROOT . '/app/lang/' . $locale . '.php';
    Assert::true($locale . ' has the line', ($messages['error.404.quip'] ?? '') !== '');
    Assert::true('and it is not the same as the plain one', $messages['error.404.quip'] !== $messages['error.404.body']);
}

Assert::group('The local server finds the local database');

/* Started by dev.sh there is a REGAL_CONFIG and all is well. Started any
 * other way there is none, Config falls back to config.php, and on a
 * developer's machine that is the file with the server's credentials in it -
 * so every page answers with a PDOException, which reads like a broken
 * application rather than a server started without its configuration. */
$router = (string) file_get_contents(PROJECT_ROOT . '/router.dev.php');

Assert::true('the dev router fills the gap', str_contains($router, "putenv('REGAL_CONFIG="));
Assert::true(
    'but only when nobody said otherwise',
    str_contains($router, "getenv('REGAL_CONFIG') === false")
);
Assert::true('and only if that file is there', str_contains($router, "is_file(__DIR__ . '/config.dev.php')"));

// It is a development file and must never be deployed with the application.
$workflow = glob(PROJECT_ROOT . '/.github/workflows/*.yml');
foreach ($workflow as $file) {
    $yaml = (string) file_get_contents($file);
    if (str_contains($yaml, 'router.dev.php')) {
        Assert::true(basename($file) . ' excludes it', str_contains($yaml, 'exclude'));
    }
}
