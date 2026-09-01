<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Http\Application;

/**
 * Two separate pages, deliberately.
 *
 * /stats is public and shows the same thing to everyone. A page that
 * quietly grows extra sections once you are signed in is disorienting - you
 * cannot tell whether a number is missing or hidden.
 *
 * /admin is the owner's dashboard: the full figures, how complete the
 * records are, and what is still worth doing.
 *
 * Charts are drawn server-side as inline SVG. That is a constraint about
 * where code comes from, not about how it may look: nothing is fetched from
 * anywhere else, because the first external request would cost the site both
 * its strict content-security policy and its freedom from a consent banner.
 * Self-hosted assets are entirely fair game.
 */
final class StatsController
{
    public function __construct(private readonly Application $app)
    {
    }

    /** The public page: the shape of the collection, identical for everyone. */
    public function page(Request $request): Response
    {
        /*
         * Openable, and open by default.
         *
         * Somebody who does not want the figures published should not have to
         * settle for a hidden link, so the page itself asks for a sign-in
         * rather than merely disappearing from the navigation.
         */
        if (!$this->app->publicStats()) {
            $guard = $this->app->requireSignIn();
            if ($guard !== null) {
                return $guard;
            }
        }

        $owner = $this->app->ownerId;
        $ratings = $this->app->books->countBy($owner, 'rating');
        unset($ratings['']);

        $body = $this->app->view->render('stats.index', [
            'totals'         => $this->app->books->totals($owner),
            'authorCount'    => $this->app->authors->count($owner),
            'statuses'       => $this->app->books->countBy($owner, 'reading_status'),
            'bindings'       => $this->arrayWithoutEmptyKey($this->app->books->countBy($owner, 'binding')),
            'genres'         => $this->app->tags->listWithCounts($owner, 10),
            'ratings'        => $ratings,
            'finishedYears'  => $this->app->books->finishedPerYear($owner),
            'publishedYears' => $this->publishedYears($owner),
            'view'           => $this->app->view,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'   => $body,
            'title'     => t('stats.title'),
            'current'   => 'stats',
            'canonical' => $this->app->url('/stats'),
        ]));
    }

    /** The owner's dashboard. Everything the public page deliberately omits. */
    public function dashboard(Request $request): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $owner = $this->app->ownerId;

        $body = $this->app->view->render('admin.dashboard', [
            'totals'        => $this->app->books->totals($owner),
            'authorCount'   => $this->app->authors->count($owner),
            'acquisitions'  => $this->arrayWithoutEmptyKey($this->app->books->countBy($owner, 'acquisition_type')),
            'coverage'      => $this->app->books->fieldCoverage($owner),
            'coverSources'  => $this->app->covers->countBySource($owner),
            'todo'          => $this->todo($owner),
            'bulkDated'     => $this->bulkDatedCount($owner),
            'recentlyAdded' => $this->recentlyAdded($owner),
            'view'          => $this->app->view,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('nav.admin'),
            'current' => 'admin',
            'noIndex' => true,
        ]))->noIndex();
    }

    /** How many acquisition dates come from the Bookstats bulk entry. */
    private function bulkDatedCount(int $owner): int
    {
        $statement = $this->app->pdo->prepare(
            'SELECT COUNT(*) FROM books WHERE owner_id = ? AND acquired_at_is_bulk = 1'
        );
        $statement->execute([$owner]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function recentlyAdded(int $owner): array
    {
        $statement = $this->app->pdo->prepare(
            'SELECT id, title, slug, isbn13, created_at FROM books
              WHERE owner_id = ? ORDER BY id DESC LIMIT 8'
        );
        $statement->execute([$owner]);

        return $statement->fetchAll();
    }

    /** @return array<int,int> */
    private function publishedYears(int $owner): array
    {
        $counts = $this->app->books->countBy($owner, 'published_year');
        $years = [];
        foreach ($counts as $year => $count) {
            $year = (int) $year;
            if ($year >= 1950 && $year <= (int) date('Y') + 2) {
                $years[$year] = $count;
            }
        }
        ksort($years);

        return $years;
    }

    /**
     * What is still worth doing. This is what turns the dashboard from a
     * pretty page into the place she can see where the gaps are.
     *
     * @return array<string,int>
     */
    private function todo(int $owner): array
    {
        $withoutCover = $this->app->pdo->prepare(
            'SELECT COUNT(*) FROM books b
              WHERE b.owner_id = ? AND NOT EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id AND c.rejected_at IS NULL)'
        );
        $withoutCover->execute([$owner]);

        $withoutIsbn = $this->app->pdo->prepare(
            'SELECT COUNT(*) FROM books WHERE owner_id = ? AND isbn13 IS NULL'
        );
        $withoutIsbn->execute([$owner]);

        $withoutRating = $this->app->pdo->prepare(
            "SELECT COUNT(*) FROM books WHERE owner_id = ? AND rating IS NULL AND reading_status = 'read'"
        );
        $withoutRating->execute([$owner]);

        return [
            'no_cover'  => (int) $withoutCover->fetchColumn(),
            'no_isbn'   => (int) $withoutIsbn->fetchColumn(),
            'no_rating' => (int) $withoutRating->fetchColumn(),
        ];
    }

    /** @param array<string,int> $counts @return array<string,int> */
    private function arrayWithoutEmptyKey(array $counts): array
    {
        unset($counts['']);

        return $counts;
    }
}
