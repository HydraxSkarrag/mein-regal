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
 * shell on this tariff - so the nightly job needs an address. Every address
 * here is guarded by a secret from config.php, compared in constant time, and
 * refuses to run at all while that secret is unset. Without the guard this
 * would be an open button for anyone to spend the day's API quota.
 *
 * Two jobs, one address each, and one that does both:
 *
 *   /cron/backup   the copy. Seconds, and the reason this shelf exists.
 *   /cron/enrich   the lookups. Minutes of waiting on other people's servers.
 *   /cron          both, the copy first.
 *
 * They are separate because they are not one kind of work and do not want one
 * rhythm: a copy is worth making every night for ever, while the lookups have
 * almost nothing left to find once a shelf is full.
 *
 * A step named in the path rather than in a parameter, deliberately. A
 * mistyped path is a 404, which a cron service reports as a failure and
 * somebody notices; a mistyped parameter would leave the endpoint deciding
 * what was probably meant and answering 200 either way, which is the same as
 * not noticing at all.
 *
 * /cron exists alongside them because most installations want one entry in
 * the control panel, and because it is the one place that can still promise
 * an order: the copy is made before the lookups, so a night that runs out of
 * time has at least left a backup. Split them and that promise becomes a
 * question of how they are scheduled.
 */
final class CronController
{
    private const BUDGET_DEFAULT = 120;
    private const BUDGET_MIN     = 20;
    private const BUDGET_MAX     = 240;

    public function __construct(private readonly Application $app)
    {
    }

    /** GET /cron - the copy, then the lookups. */
    public function run(Request $request): Response
    {
        return $this->perform($request, ['backup', 'enrich']);
    }

    /** GET /cron/backup - the copy on its own. */
    public function backup(Request $request): Response
    {
        return $this->perform($request, ['backup']);
    }

    /** GET /cron/enrich - the lookups on their own. */
    public function enrich(Request $request): Response
    {
        return $this->perform($request, ['enrich']);
    }

    /**
     * How long one run may take, in seconds.
     *
     * A budget rather than a book count. Enrichment waits between requests on
     * purpose, so a hundred books take minutes, and the cron service on the
     * other end has its own patience. Working to a clock keeps the run inside
     * it; whatever is left waits for tomorrow, which is what a nightly job is
     * for.
     *
     * Clamped rather than refused: the number arrives in a query string, and
     * a cron entry with a typo in it should still do a night's work rather
     * than none. Its own function so the clamp can be checked without
     * starting a run.
     */
    public static function budgetFor(int $requested): int
    {
        return max(self::BUDGET_MIN, min(self::BUDGET_MAX, $requested));
    }

    /**
     * @param list<string> $steps
     */
    private function perform(Request $request, array $steps): Response
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

        $budget = self::budgetFor($request->queryInt('budget', self::BUDGET_DEFAULT));

        // Room for the budget plus the backup, and the job finishes even if
        // the caller hangs up on it.
        @set_time_limit($budget + 180);
        ignore_user_abort(true);

        $lines = [];
        $started = microtime(true);

        // The copy comes first when both are asked for. It takes seconds; the
        // lookups take minutes, and a night that runs out of time should still
        // have left a backup.
        if (in_array('backup', $steps, true)) {
            $lines[] = $this->copy($request);
        }

        if (in_array('enrich', $steps, true)) {
            $lines[] = $this->lookUp($request, $budget);
        }

        /* Housekeeping is not a step and has no address of its own.
         *
         * Expired sign-in tokens and stale login attempts, in milliseconds,
         * always safe. Nobody would ever schedule that on a rhythm of its
         * own, so offering the choice would only be one more thing to decide
         * wrongly. It happens at the end of whichever job ran. */
        $lines[] = $this->tidy();

        $lines[] = sprintf('took %.1fs', microtime(true) - $started);

        /* The same lines, kept. The answer below is read once, by whoever or
           whatever made the call; a fortnight later the question is usually
           "since when has it been finding nothing", and that needs a run
           before this one to compare with. */
        RunLog::record($lines);

        return Response::text(implode("\n", $lines) . "\n")->noIndex();
    }

    private function copy(Request $request): string
    {
        try {
            require_once PROJECT_ROOT . '/bin/backup.php';
            $result = backup(
                $this->app->pdo,
                PROJECT_ROOT . '/storage/backup',
                (int) max(1, min(365, $request->queryInt('keep', 30))),
                false
            );

            return sprintf(
                'backup: %d files, %.1f MB, %d old ones removed',
                count($result['files']),
                $result['bytes'] / 1024 / 1024,
                $result['removed']
            );
        } catch (Throwable $e) {
            error_log('[regal] cron backup failed: ' . $e->getMessage());

            return 'backup: FAILED - ' . $e->getMessage();
        }
    }

    private function lookUp(Request $request, int $budget): string
    {
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

            return sprintf(
                'enrich: looked up %d, covers %d, metadata %d, misses %d%s',
                $stats['looked_up'],
                $stats['covers'],
                $stats['metadata'],
                $stats['misses'],
                $stats['stopped_early'] ? ' (budget reached, rest waits for tomorrow)' : ''
            );
        } catch (Throwable $e) {
            error_log('[regal] cron enrich failed: ' . $e->getMessage());

            return 'enrich: FAILED - ' . $e->getMessage();
        }
    }

    private function tidy(): string
    {
        try {
            // Server logs are the host's business; this is ours.
            (new Auth($this->app->pdo, $this->app->session, $this->app->users, $this->app->cookies))
                ->purgeExpired();

            return 'purge: expired tokens and old login attempts removed';
        } catch (Throwable $e) {
            return 'purge: FAILED - ' . $e->getMessage();
        }
    }
}
