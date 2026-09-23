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
            'bulkDated'     => $this->app->books->countBulkDated($owner),
            'recentlyAdded' => $this->app->books->recentlyAdded($owner),
            // The legal texts ship as drafts and say so; this is the same
            // reminder somewhere it will actually be seen.
            'legalOpen'     => $this->app->pages->unfinishedLegal($owner),
            'view'          => $this->app->view,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('nav.admin'),
            'current' => 'admin',
            'noIndex' => true,
        ]))->noIndex();
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
        /* Each through the filter its number links to on the dashboard, so
           the number and the list behind it are one query and cannot say
           different things. */
        $count = fn (array $filter): int => $this->app->books->countMatching($owner, $filter);

        return [
            'no_cover'  => $count(['cover' => 'no']),
            'no_isbn'   => $count(['isbn' => 'no']),
            'no_rating' => $count(['missing' => 'rating']),
            'no_genre'  => $count(['missing' => 'genre']),
            'no_author' => $count(['missing' => 'author']),
        ];
    }

    /** @param array<string,int> $counts @return array<string,int> */
    private function arrayWithoutEmptyKey(array $counts): array
    {
        unset($counts['']);

        return $counts;
    }
}
