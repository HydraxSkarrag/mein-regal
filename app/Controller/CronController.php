<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
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

        // A cron run must not be limited by the web request timeout, and must
        // not leave a half-finished job if the caller hangs up.
        @set_time_limit(300);
        ignore_user_abort(true);

        $lines = [];
        $started = microtime(true);

        try {
            require_once PROJECT_ROOT . '/bin/enrich.php';
            $stats = enrich(
                $this->app->pdo,
                $this->app->config,
                (int) max(1, min(500, $request->queryInt('limit', 150))),
                $this->app->ownerId,
                false
            );
            $lines[] = sprintf(
                'enrich: looked up %d, covers %d, metadata %d, misses %d',
                $stats['looked_up'],
                $stats['covers'],
                $stats['metadata'],
                $stats['misses']
            );
        } catch (Throwable $e) {
            error_log('[regal] cron enrich failed: ' . $e->getMessage());
            $lines[] = 'enrich: FAILED - ' . $e->getMessage();
        }

        try {
            // Expired sign-in tokens and stale login attempts. Server logs are
            // the host's business; this is ours.
            (new Auth($this->app->pdo, $this->app->session, $this->app->users, $this->app->cookies))
                ->purgeExpired();
            $lines[] = 'purge: expired tokens and old login attempts removed';
        } catch (Throwable $e) {
            $lines[] = 'purge: FAILED - ' . $e->getMessage();
        }

        $lines[] = sprintf('took %.1fs', microtime(true) - $started);

        return Response::text(implode("\n", $lines) . "\n")->noIndex();
    }
}
