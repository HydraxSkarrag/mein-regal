<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\RunLog;
use App\Http\Application;
use Throwable;

/**
 * Scheduled work, reachable over HTTP.
 *
 * all-inkl's scheduler calls a URL rather than running a script - there is no
 * shell on this tariff - so the nightly job needs an address. That address is
 * guarded by a secret from config.php, compared in constant time, and it
 * refuses to run at all while that secret is unset. Without the guard this
 * would be an open button for anyone to spend the day's API quota.
 */
final class CronController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function run(Request $request): Response
    {
        $expected = $this->app->config->str('cron_secret');
        $given = $request->query('key');

        if ($expected === '' || strlen($expected) < 20) {
            return Response::text("cron_secret is unset or too short in config.php\n", 503)->noIndex();
        }
        if ($given === '' || !hash_equals($expected, $given)) {
            // Deliberately the same answer as an unknown path: whether this
            // address exists is not worth confirming to a guesser.
            return $this->app->notFound();
        }

        /* Which of the three, so they need not share a rhythm.
         *
         * They are one call by default because setting up a cron job on this
         * host is a form in a control panel, and one entry is one thing to
         * get right. But they are not one kind of work: the copy takes
         * seconds and has to happen every night without fail, the housekeeping
         * takes milliseconds, and the lookups are minutes of waiting on other
         * people's servers - the only part that can hang, be throttled, or
         * run out of budget.
         *
         * The order already protects the important one: the copy is made
         * first, so a night that runs out of time has still left a backup.
         * What it cannot do is give them different schedules, and there is a
         * good case for that once a shelf is full - the lookups then have
         * almost nothing left to find and could as well run weekly.
         *
         *   /cron?key=…                       all three, as before
         *   /cron?key=…&do=backup,purge       nightly
         *   /cron?key=…&do=enrich             weekly, or whenever
         *
         * An unknown name runs nothing rather than everything: a typo in a
         * cron URL that quietly did the whole job would look like it worked.
         */
        $steps = trim($request->query('do'));
        $wanted = $steps === ''
            ? ['backup', 'enrich', 'purge']
            : array_map('trim', explode(',', strtolower($steps)));

        $doing = static fn (string $step): bool => in_array($step, $wanted, true);

        /* A budget rather than a book count.
         *
         * Enrichment waits between requests on purpose, so a hundred books
         * take minutes - and the cron service on the other end has its own
         * patience. Working to a clock keeps the run inside it; whatever is
         * left waits for tomorrow, which is what a nightly job is for. */
        $budget = max(20, min(240, $request->queryInt('budget', 120)));

        // Room for the budget plus the backup, and the job finishes even if
        // the caller hangs up on it.
        @set_time_limit($budget + 180);
        ignore_user_abort(true);

        $lines = [];
        $started = microtime(true);

        // The copy comes first. It takes seconds; the lookups take minutes,
        // and a night that runs out of time should still have left a backup.
        if ($doing('backup')) {
            try {
                require_once PROJECT_ROOT . '/bin/backup.php';
                $result = backup(
                    $this->app->pdo,
                    PROJECT_ROOT . '/storage/backup',
                    (int) max(1, min(365, $request->queryInt('keep', 30))),
                    false
                );
                $lines[] = sprintf(
                    'backup: %d files, %.1f MB, %d old ones removed',
                    count($result['files']),
                    $result['bytes'] / 1024 / 1024,
                    $result['removed']
                );
            } catch (Throwable $e) {
                error_log('[regal] cron backup failed: ' . $e->getMessage());
                $lines[] = 'backup: FAILED - ' . $e->getMessage();
            }
        }

        if ($doing('enrich')) {
            try {
                require_once PROJECT_ROOT . '/bin/enrich.php';
                $stats = enrich(
                    $this->app->pdo,
                    $this->app->config,
                    (int) max(1, min(500, $request->queryInt('limit', 500))),
                    $this->app->ownerId,
                    false,
                    $budget
                );
                $lines[] = sprintf(
                    'enrich: looked up %d, covers %d, metadata %d, misses %d%s',
                    $stats['looked_up'],
                    $stats['covers'],
                    $stats['metadata'],
                    $stats['misses'],
                    $stats['stopped_early'] ? ' (budget reached, rest waits for tomorrow)' : ''
                );
            } catch (Throwable $e) {
                error_log('[regal] cron enrich failed: ' . $e->getMessage());
                $lines[] = 'enrich: FAILED - ' . $e->getMessage();
            }
        }

        if ($doing('purge')) {
            try {
                // Expired sign-in tokens and stale login attempts. Server logs
                // are the host's business; this is ours.
                (new Auth($this->app->pdo, $this->app->session, $this->app->users, $this->app->cookies))
                    ->purgeExpired();
                $lines[] = 'purge: expired tokens and old login attempts removed';
            } catch (Throwable $e) {
                $lines[] = 'purge: FAILED - ' . $e->getMessage();
            }
        }

        // A call that asked for nothing recognisable says so, rather than
        // reporting a run that consisted of taking the time.
        if ($lines === []) {
            $lines[] = 'nothing to do: no known step in "' . $steps . '" (backup, enrich, purge)';
        }

        $lines[] = sprintf('took %.1fs', microtime(true) - $started);

        /* The same lines, kept. The answer below is read once, by whoever or
           whatever made the call; a fortnight later the question is usually
           "since when has it been finding nothing", and that needs a run
           before this one to compare with. */
        RunLog::record($lines);

        return Response::text(implode("\n", $lines) . "\n")->noIndex();
    }
}
