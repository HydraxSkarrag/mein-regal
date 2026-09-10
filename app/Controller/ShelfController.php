<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Input;
use App\Core\Isbn;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Http\Application;
use App\Repository\BookRepository;
use App\Repository\SeriesRepository;
use App\Repository\TagRepository;

/**
 * The shelf and a single book - the public half of the site.
 */
final class ShelfController
{
    /**
     * How many entries a sidebar list shows before "all of them" takes over.
     *
     * One number for every list rather than fourteen here and twelve there:
     * a sidebar is scanned, not read, and lists of different lengths make it
     * look like the shorter ones are complete.
     */
    private const FACET_ROWS = 10;

    private const PER_PAGE = 60;

    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search'   => $request->query('q'),
            'status'   => $this->oneOf($request->query('status'), ['read', 'unread', 'abandoned', 'reading']),
            'tag'      => $request->query('tag'),
            'series'   => $request->query('series'),
            'author'   => $request->query('author'),
            /* Whatever the sources have actually put in the field, rather
               than a list of languages someone thought of: the DNB delivers
               gmh and zxx as readily as ger, and a filter that cannot name
               them would leave those books unreachable. */
            'language' => $this->oneOf(
                $request->query('language'),
                array_keys($this->app->books->countBy($this->app->ownerId, 'language'))
            ),
            /* Whole stars only, and read as "at least". A half belongs to the
               step below it: four and a half is a book somebody liked more
               than four, and it shows under four. */
            'rating'   => $this->oneOf($request->query('rating'), ['1', '2', '3', '4', '5']),
            'review'   => $this->oneOf($request->query('review'), ['yes', 'no']),
            'cover'    => $this->oneOf($request->query('cover'), ['yes', 'no']),
            'isbn'     => $this->oneOf($request->query('isbn'), ['yes', 'no']),
            'missing'  => $this->oneOf($request->query('missing'), ['genre', 'author', 'rating']),
            'sort'     => $this->oneOf($request->query('sort'), BookRepository::sorts(), 'recent'),
            'dir'      => $this->oneOf($request->query('dir'), ['asc', 'desc']),
        ];

        return $this->renderShelf($request, $filters, $this->heading($filters), $this->current($filters));
    }

    /**
     * What the page calls itself.
     *
     * It follows the filter rather than the route. "SuB" used to be the
     * heading only at /unread, so the same 331 books were headed "Mein
     * Regal" when the reader had clicked the chip instead - the same list,
     * with two names, depending on which of two doors was used.
     *
     * With no filter at all it used to say "Mein Regal", which is the name of
     * the site and not of a selection - and the site already says that in the
     * header three centimetres above, and in the browser tab. Three times on
     * one screen. A heading here answers "which books are these", and when
     * they are all of them, the answer is "all of them".
     *
     * @param array<string,mixed> $filters
     */
    private function heading(array $filters): string
    {
        return self::headingFor($filters, $this->filterNames($filters));
    }

    /**
     * The names behind the slugs, looked up once and handed to the heading.
     *
     * Three of the filters point at a thing that has a name of its own, and
     * a slug is not it: "die-chronik-der-drachenlanze" is an address, not a
     * heading. The lookups happen here so the decision itself stays a
     * function of its arguments and can be checked without a database.
     *
     * A slug that names nothing is handed back as it stands, which is what
     * the author lookup has always done. That is the case of a shared link to
     * a tag since taken out of use: the address names something, the shelf
     * has nothing under it, and "gibt-es-nicht-mehr" over "0 Bücher" says so.
     * Falling through to "Alle Bücher" over an empty page would not.
     *
     * @param  array<string,mixed> $filters
     * @return array<string,?string>
     */
    private function filterNames(array $filters): array
    {
        $names = ['author' => null, 'series' => null, 'tag' => null];

        if (($filters['author'] ?? '') !== '') {
            $names['author'] = $this->authorName((string) $filters['author']);
        }
        if (($filters['series'] ?? '') !== '') {
            $series = $this->app->series->bySlug($this->app->ownerId, (string) $filters['series']);
            $names['series'] = $series === null ? (string) $filters['series'] : (string) $series['name'];
        }
        if (($filters['tag'] ?? '') !== '') {
            $statement = $this->app->pdo->prepare(
                'SELECT name FROM tags WHERE owner_id = ? AND slug = ? AND dropped_at IS NULL LIMIT 1'
            );
            $statement->execute([$this->app->ownerId, (string) $filters['tag']]);
            $found = $statement->fetchColumn();
            $names['tag'] = $found === false ? (string) $filters['tag'] : (string) $found;
        }

        return $names;
    }

    /**
     * The decision itself, with the author already looked up.
     *
     * Static and separate so it can be checked without a database behind it -
     * the same arrangement pageNumbers() is in, and for the same reason.
     *
     * @param array<string,mixed> $filters
     */
    private static function headingFor(array $filters, array $names = []): string
    {
        /* A search outranks everything, because it is the most deliberate
           thing on the page: a status is picked from four chips, an author
           from a list, but a search term was typed. "Alle Bücher" over three
           results of three thousand was simply wrong. */
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            return t('shelf.found', ['term' => $search]);
        }

        /* Then the rest, from the most particular selection to the broadest.
         *
         * Only three of ten filters used to reach this far, so the other
         * seven all came out as "Alle Bücher" - measured on the running
         * shelf: that heading stood over one book under a genre, over six of
         * a series, and over the four without a cover. The same fault the
         * search had, at seven more addresses.
         *
         * Something with a name of its own goes first: an author, a series, a
         * genre. Then the work lists, then a property a book either has or
         * has not, then the status, then the language. Any order would need
         * defending; this one runs from "these particular books" down to
         * "books with this one broad thing in common", and only one heading
         * fits on a page.
         *
         * The names are resolved before this runs, and a slug that names
         * nothing arrives as itself rather than as null. See filterNames()
         * for why. */
        foreach (['author', 'series', 'tag'] as $named) {
            if (($filters[$named] ?? '') !== '' && ($names[$named] ?? null) !== null) {
                return (string) $names[$named];
            }
        }

        // Reached from the dashboard's list of what is still to do, and the
        // heading is the same sentence that linked here.
        $missing = (string) ($filters['missing'] ?? '');
        if ($missing !== '') {
            return t('stats.no.' . $missing);
        }

        // "Mit Cover", "Ohne ISBN", "Ohne Rezension" - the filter's own
        // labels, which are already whole phrases rather than words.
        foreach (['cover', 'isbn', 'review'] as $yesNo) {
            $value = (string) ($filters[$yesNo] ?? '');
            if ($value !== '') {
                return t('filter.' . $yesNo . '.' . $value);
            }
        }

        $rating = (string) ($filters['rating'] ?? '');
        if ($rating !== '') {
            return $rating === '1'
                ? t('filter.rating.one')
                : t('filter.rating.from', ['stars' => $rating]);
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            // The pile has a name of its own in the navigation, and it is the
            // name its readers use. The other three are just their status.
            return $status === 'unread' ? t('nav.unread') : t('status.' . $status);
        }

        $language = (string) ($filters['language'] ?? '');
        if ($language !== '') {
            return t('lang.' . $language);
        }

        /* Not 'shelf.all' - that one is the "Alle" chip in the filter row,
           and it has to stay a chip's length. Two keys because they are two
           sentences in the same language: one labels a button, the other
           names what you are looking at. */
        return t('shelf.all.books');
    }

    /**
     * What the browser tab calls it, which is not always the same thing.
     *
     * "Alle Bücher – Mein Regal" is a fine title for a selection and a poor
     * one for a front page: what belongs in a tab, a bookmark and a search
     * result for the unfiltered shelf is the name of the shelf. The layout
     * drops the suffix when the two match, so this comes out as plain "Mein
     * Regal" rather than the site name twice over.
     *
     * @param array<string,mixed> $filters
     */
    private static function documentTitle(array $filters, string $heading, string $siteName): string
    {
        /* Read off the heading rather than listed again here. The list was
           three filters long while the shelf had ten, so a genre page was
           titled "Mein Regal" in the tab and named its genre on the page -
           and every filter added since would have had to be remembered in
           two places. Only the catch-all heading means nothing is filtered,
           which is exactly the question this asks. */
        return $heading === t('shelf.all.books') ? $siteName : $heading;
    }

    /** Which navigation entry is the one you are on. */
    private function current(array $filters): string
    {
        return ($filters['status'] ?? '') === 'unread' ? 'unread' : 'shelf';
    }

    /**
     * The pile of unread books, which used to be a route of its own.
     *
     * It was one page too many. The filter lived in the code rather than in
     * the address, so every sort and facet link in the sidebar - all of which
     * are built from the address - quietly dropped it: one click on "Titel"
     * took 331 books to 3,042. Paging had already needed a special case to
     * work around that, and the sidebar never got one.
     *
     * As a status filter it composes with everything else for free, and the
     * pile keeps its name in the navigation and in the heading.
     */
    public function unread(): Response
    {
        return Response::redirect('/?status=unread', 301);
    }

    /** @param array<string,string> $filters */
    private function renderShelf(Request $request, array $filters, string $heading, string $current): Response
    {
        $page = max(1, $request->queryInt('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $result = $this->app->books->search($this->app->ownerId, $filters, self::PER_PAGE, $offset);
        $books = $result['rows'];

        $ids = array_map(static fn (array $b): int => (int) $b['id'], $books);
        $signedIn = $this->app->auth->isSignedIn();

        $query = $request->allQuery();
        $urlFor = static function (array $changes) use ($query): string {
            $next = array_filter(
                array_merge($query, $changes),
                static fn ($value): bool => $value !== '' && $value !== null
            );
            unset($next['page']);

            return $next === [] ? '/' : '/?' . http_build_query($next);
        };

        /* Page links, not a "load more" button.
         *
         * The button said "load more" and then replaced what was on screen,
         * because there is no JavaScript behind it - it was a page link
         * wearing the wrong word. With fifty-one pages, the honest control is
         * the one that says which page this is and lets you jump.
         *
         * The base path matters: the old link always pointed at "/", so
         * paging out of the unread pile quietly landed you in the whole
         * shelf. */
        $base = '/';
        $kept = array_filter($query, static fn ($v, string $k): bool => $k !== 'page' && $v !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);

        $pageUrl = static function (int $number) use ($base, $kept): string {
            $parameters = $number > 1 ? array_merge($kept, ['page' => $number]) : $kept;

            return $parameters === [] ? $base : $base . '?' . http_build_query($parameters);
        };

        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $body = $this->app->view->render('shelf.index', [
            /* Not "this search found nothing" but "there is nothing yet".
               Only asked when the page is empty, so a shelf with books on it
               never pays for the question. */
            'shelfIsEmpty'  => $books === [] && $this->app->books->countAll($this->app->ownerId) === 0,
            'heading'       => $heading,
            'books'         => $books,
            'total'         => $result['total'],
            'offset'        => $offset,
            'covers'        => $this->app->covers->bestForMany($ids, $signedIn),
            'authorLines'   => $this->authorLines($ids),
            /* Only where there is something to filter by. A shelf with no
               series should not carry a heading for one, and the sidebar is
               already at the ten values that were agreed. */
            'seriesList'    => array_slice(
                array_values(array_filter(
                    $this->app->series->listForOwner($this->app->ownerId),
                    static fn (array $row): bool => (int) $row['owned'] > 0
                )),
                0,
                self::FACET_ROWS
            ),
            'seriesTotal'   => count($this->app->series->listForOwner($this->app->ownerId)),
            'tags'          => $this->app->tags->listWithCounts($this->app->ownerId, self::FACET_ROWS, TagRepository::KIND_GENRE),
            'tagTotal'      => $this->app->tags->count($this->app->ownerId, TagRepository::KIND_GENRE),
            'labels'        => $this->app->tags->listWithCounts($this->app->ownerId, self::FACET_ROWS, TagRepository::KIND_LABEL),
            'labelTotal'    => $this->app->tags->count($this->app->ownerId, TagRepository::KIND_LABEL),
            'topAuthors'    => $this->app->authors->listWithCounts($this->app->ownerId, self::FACET_ROWS),
            'languageCounts' => $this->app->books->countBy($this->app->ownerId, 'language'),
            'authorTotal'   => $this->app->authors->count($this->app->ownerId),
            'statusCounts'  => $this->app->books->countBy($this->app->ownerId, 'reading_status'),
            /* How many books carry a reading date, which decides whether
               "Zuletzt gelesen" is offered at all. */
            'datedCount'    => $this->app->books->countDated($this->app->ownerId),
            /* The address of this very page, so a tile press comes back to
               it rather than to an unfiltered shelf. Rebuilt on the way in
               rather than followed - see BookController::shelfUrl(). */
            'backQuery'     => http_build_query($query),
            'ratingCounts'  => $this->app->books->countByRating($this->app->ownerId),
            'reviewCounts'  => $this->app->books->countByReview($this->app->ownerId),
            'coverCounts'   => $this->app->books->countByCover($this->app->ownerId),
            'isbnCounts'    => $this->app->books->countByIsbn($this->app->ownerId),
            'filters'       => $filters,
            'hasFilters'    => array_diff_key(array_filter($filters), ['sort' => 1, 'dir' => 1]) !== [],
            'urlFor'        => $urlFor,
            'pageUrl'       => $pageUrl,
            'page'          => $page,
            'pages'         => $pages,
            'pageNumbers'   => self::pageNumbers($page, $pages),
            'perPage'       => self::PER_PAGE,
            'view'          => $this->app->view,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'   => $body,
            'title'     => self::documentTitle($filters, $heading, $this->app->siteName()),
            'current'   => $current,
            'canonical' => $this->app->url('/'),
            'jsonLd'    => $this->collectionJsonLd($result['total']),
            /* Only for somebody who can change anything. A visitor gets the
               shelf without a script on it, which is also what the tiles
               look like: the switch is not rendered either. */
            'scripts'   => $signedIn ? ['/js/shelf.js'] : [],
        ]));
    }

    /**
     * Every genre there is.
     *
     * The sidebar lists the biggest fourteen because a sidebar has to stop
     * somewhere, which left the rest of them reachable only by guessing the
     * URL. This is where the heading points.
     */
    public function genres(): Response
    {
        $rows = $this->app->tags->listAllByName($this->app->ownerId, TagRepository::KIND_GENRE);

        return $this->renderFacets(
            t('filter.genre'),
            'genres',
            array_map(static fn (array $tag): array => [
                'label' => $tag['name'],
                'sort'  => $tag['name'],
                'count' => (int) $tag['book_count'],
                'url'   => '/?tag=' . rawurlencode($tag['slug']),
            ], $rows)
        );
    }

    /**
     * Every series, with how much of each is on the shelf.
     */
    public function seriesIndex(): Response
    {
        $rows = $this->app->series->listForOwner($this->app->ownerId);

        /* Filed without the leading article, the way every library files a
           title: "Die Chronik der Drachenlanze" under C, not under D with
           every other series that happens to begin with "Die". The name
           shown is untouched - only where it lands changes. */
        $entries = array_map(static fn (array $series): array => [
            'label' => $series['name'],
            'sort'  => Text::filingName($series['name']),
            'count' => (int) $series['owned'],
            'url'   => '/reihe/' . rawurlencode($series['slug']),
        ], $rows);

        // And ordered by the same key, or C would be right and the order
        // inside it would still be the one the database chose.
        usort($entries, static fn (array $a, array $b): int => strcoll($a['sort'], $b['sort']));

        return $this->renderFacets(t('series.title'), 'series', $entries);
    }

    /**
     * One series, in volume order, saying which volumes are missing.
     *
     * The gaps are the reason this page is worth having. A grid of the six
     * volumes somebody owns looks complete; "Band 4 fehlt" is the sentence
     * they came for.
     */
    public function series(Request $request, array $params): Response
    {
        $series = $this->app->series->bySlug($this->app->ownerId, (string) ($params['slug'] ?? ''));
        if ($series === null) {
            return $this->app->notFound();
        }

        $volumes = $this->app->series->volumes($this->app->ownerId, (int) $series['id']);
        $ids = array_map(static fn (array $b): int => (int) $b['id'], $volumes);

        $body = $this->app->view->render('shelf.series', [
            'signedIn'    => $this->app->auth->isSignedIn(),
            'series'      => $series,
            'volumes'     => $volumes,
            'gaps'        => SeriesRepository::gaps($volumes, $series['total'] === null ? null : (int) $series['total']),
            'covers'      => $this->app->covers->bestForMany($ids, $this->app->auth->isSignedIn()),
            'authorLines' => $this->authorLines($ids),
            'view'        => $this->app->view,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'   => $body,
            'title'     => $series['name'],
            'current'   => 'shelf',
            'canonical' => $this->app->url('/reihe/' . $series['slug']),
        ]));
    }

    /**
     * Change a series, or take it away.
     *
     * Both live on the series page itself, because that is where somebody is
     * standing when they notice - a name typed wrong, a total worth writing
     * down, or a series that only exists because a finger slipped in the
     * edit form and made "d".
     *
     * Removing one is not like removing a book. Nothing is lost: the volumes
     * stay on the shelf and simply stop belonging to a series, which is why
     * this asks for a click and not for a word typed out.
     */
    public function editSeries(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $series = $this->app->series->bySlug($this->app->ownerId, (string) ($params['slug'] ?? ''));
        if ($series === null) {
            return $this->app->notFound();
        }

        $body = $this->app->view->render('shelf.series_edit', [
            'series' => $series,
            'owned'  => SeriesRepository::countVolumes(
                $this->app->series->volumes($this->app->ownerId, (int) $series['id'])
            ),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => $series['name'],
            'current' => 'shelf',
            'noIndex' => true,
        ]))->noIndex();
    }

    public function saveSeries(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $series = $this->app->series->bySlug($this->app->ownerId, (string) ($params['slug'] ?? ''));
        if ($series === null) {
            return $this->app->notFound();
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->app->notFound();
        }

        /* postBool, not post() !== null. post() answers '' for a key that is
           not there and never null, so "was the remove button the one that
           was pressed" was true every time - and Speichern deleted the series
           it was meant to save, taking six books' volume numbers with it. */
        if ($request->postBool('remove')) {
            $this->app->series->delete($this->app->ownerId, (int) $series['id']);

            return Response::redirect('/series');
        }

        $this->app->series->update($this->app->ownerId, (int) $series['id'], [
            'name'  => (string) $request->post('name'),
            /* A total nobody knows is not a total. Empty means "as many as
               there are", and "Band 3 von 3" for a septology would be worse
               than saying nothing. */
            'total' => Input::int($request->post('total'), 1, 9999),
            'note'  => Input::text($request->post('note'), 255),
        ]);

        $fresh = $this->app->series->find($this->app->ownerId, (int) $series['id']);

        return Response::redirect('/reihe/' . ($fresh['slug'] ?? $series['slug']));
    }

    /**
     * Everything that is not a genre.
     *
     * Age ranges, bindings, subjects, shop categories - useful to filter by,
     * useless in a list meant to answer "what kind of book is this". Same
     * page, same filter, its own address.
     */
    public function labels(): Response
    {
        $rows = $this->app->tags->listAllByName($this->app->ownerId, TagRepository::KIND_LABEL);

        return $this->renderFacets(
            t('filter.label'),
            'labels',
            array_map(static fn (array $tag): array => [
                'label' => $tag['name'],
                'sort'  => $tag['name'],
                'count' => (int) $tag['book_count'],
                'url'   => '/?tag=' . rawurlencode($tag['slug']),
            ], $rows)
        );
    }

    /** Everyone, sorted by surname the way a shelf is. */
    public function authors(): Response
    {
        $rows = $this->app->authors->listAllByName($this->app->ownerId);

        return $this->renderFacets(
            t('filter.author'),
            'authors',
            array_map(static fn (array $person): array => [
                'label' => $person['name'],
                'sort'  => $person['sort_name'] !== '' ? $person['sort_name'] : $person['name'],
                'count' => (int) $person['book_count'],
                'url'   => '/?author=' . rawurlencode($person['name']),
            ], $rows)
        );
    }

    /**
     * One template for both lists: a long alphabet is a long alphabet.
     *
     * Grouped by initial rather than printed as one run, because seventeen
     * hundred names in a single column is a list nobody reads to the end.
     *
     * @param list<array{label: string, sort: string, count: int, url: string}> $entries
     */
    private function renderFacets(string $heading, string $current, array $entries): Response
    {
        $groups = [];
        foreach ($entries as $entry) {
            // The sort key decides, but it is derived data and can be wrong:
            // eleven authors imported with a role marker sort as "(Ill.),
            // Eva Gebhardt" and would file under # rather than G. Where the
            // key yields no letter, the name shown to the reader does - a
            // name that genuinely starts with something else, like a handle,
            // still lands in the last group.
            $letter = self::initial($entry['sort']);
            if ($letter === self::OTHER) {
                $letter = self::initial($entry['label']);
            }
            $groups[$letter][] = $entry;
        }
        ksort($groups, SORT_LOCALE_STRING);
        if (isset($groups[self::OTHER])) {
            $other = $groups[self::OTHER];
            unset($groups[self::OTHER]);
            $groups[self::OTHER] = $other;
        }

        $body = $this->app->view->render('shelf.facets', [
            'heading' => $heading,
            'groups'  => $groups,
            'total'   => count($entries),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'   => $body,
            'title'     => $heading,
            'current'   => $current,
            'canonical' => $this->app->url('/' . $current),
        ]));
    }

    /** Where anything that does not begin with a letter is collected. */
    private const OTHER = '#';

    /**
     * The letter something files under.
     *
     * Accents are folded, so Ärger sits with A rather than in a group of its
     * own at the end of the alphabet; anything that does not start with a
     * letter is collected under a single heading instead of scattering.
     */
    private static function initial(string $value): string
    {
        $first = mb_strtoupper(mb_substr(trim($value), 0, 1), 'UTF-8');
        $folded = strtr($first, [
            'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U',
            'Ç' => 'C', 'Ñ' => 'N', 'Š' => 'S', 'Ž' => 'Z',
        ]);

        return preg_match('/^\p{L}$/u', $folded) === 1 ? $folded : '#';
    }

    /**
     * Which page numbers to show, with gaps.
     *
     * Fifty-one numbers in a row is not a control, it is a wall. First and
     * last are always there, plus a window around where you are; null marks a
     * gap the template renders as an ellipsis.
     *
     * @return list<?int>
     */
    private static function pageNumbers(int $page, int $pages, int $window = 2): array
    {
        $wanted = [1, $pages];
        for ($n = $page - $window; $n <= $page + $window; $n++) {
            $wanted[] = $n;
        }
        $wanted = array_values(array_unique(array_filter(
            $wanted,
            static fn (int $n): bool => $n >= 1 && $n <= $pages
        )));
        sort($wanted);

        $out = [];
        $previous = 0;
        foreach ($wanted as $number) {
            if ($previous !== 0 && $number > $previous + 1) {
                $out[] = null;
            }
            $out[] = $number;
            $previous = $number;
        }

        return $out;
    }

    public function detail(Request $request, array $params): Response
    {
        $book = $this->app->books->findBySlug($this->app->ownerId, $params['slug'] ?? '');
        if ($book === null) {
            return $this->app->notFound();
        }

        $bookId = (int) $book['id'];
        $signedIn = $this->app->auth->isSignedIn();
        $contributors = $this->contributors([$bookId])[$bookId] ?? [];
        $cover = $this->app->covers->bestFor($bookId, $signedIn);

        /* Authors, and then everybody else by what they did.
         *
         * They used to share one line, with the role in brackets after the
         * name - which reads as though a translator wrote the book and
         * happened to translate it too. A translator, an illustrator and the
         * voice on an audiobook are credited for a book; they are not its
         * authors, and a byline that says otherwise is wrong about the one
         * thing a byline is for.
         *
         * A book with no author at all is a real thing - an anthology with an
         * editor, a picture book credited to its illustrator - so in that
         * case the others move up rather than leaving nobody named. */
        $authors = array_values(array_filter(
            $contributors,
            static fn (array $p): bool => $p['role'] === 'author'
        ));
        $others = [];
        foreach ($contributors as $person) {
            if ($person['role'] !== 'author') {
                $others[$person['role']][] = $person;
            }
        }
        if ($authors === [] && $others !== []) {
            $authors = array_merge(...array_values($others));
            $others = [];
        }

        $authorLine = implode(', ', array_column($authors, 'name'));

        /* The series, and the two books either side of it in that series.
           
           Which is the whole point of recording a volume number: from a book
           in hand, the next one is one click away instead of a search. Only
           volumes on this shelf are offered - a link to a book somebody does
           not own would be a link to nothing. */
        $series = null;
        $neighbours = ['previous' => null, 'next' => null];
        if (isset($book['series_id'])) {
            $series = $this->app->series->find($this->app->ownerId, (int) $book['series_id']);
        }
        if ($series !== null) {
            $volumes = $this->app->series->volumes($this->app->ownerId, (int) $series['id']);
            foreach ($volumes as $position => $volume) {
                if ((int) $volume['id'] !== $bookId) {
                    continue;
                }
                $neighbours['previous'] = $volumes[$position - 1] ?? null;
                $neighbours['next'] = $volumes[$position + 1] ?? null;
                break;
            }
            $series['owned'] = SeriesRepository::countVolumes($volumes);
        }

        $body = $this->app->view->render('shelf.detail', [
            'series'        => $series,
            'neighbours'    => $neighbours,
            'book'          => $book,
            'cover'         => $cover,
            'contributors'  => $contributors,
            'authors'       => $authors,
            'otherRoles'    => $others,
            'authorLine'    => $authorLine,
            'tags'          => $this->tagsFor($bookId),
            'isbnFormatted' => $book['isbn13'] !== null ? Isbn::format((string) $book['isbn13']) : '',
            'coverLink'     => \App\Core\CoverImage::attributionLink($cover, $book['isbn13'] ?? null),
            'duration'      => $this->duration($book['audio_minutes'] ?? null),
            'view'          => $this->app->view,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'         => $body,
            'title'           => (string) $book['title'],
            'current'         => 'shelf',
            'canonical'       => $this->app->url('/book/' . $book['slug']),
            'metaDescription' => $this->description($book, $authorLine),
            'ogType'          => 'book',
            'ogImage'         => $this->publicOgImage($bookId),
            'jsonLd'          => $this->bookJsonLd($book, $contributors),
        ]));
    }

    /**
     * Only a cover we host ourselves may be used as a social preview image.
     * A hotlinked third-party cover would be fetched by every service that
     * unfurls the link, and it is not ours to republish.
     */
    private function publicOgImage(int $bookId): ?string
    {
        $cover = $this->app->covers->bestFor($bookId, false);
        if ($cover === null || ($cover['path'] ?? null) === null) {
            return null;
        }

        return $this->app->url('/covers/' . $cover['path']);
    }

    /**
     * The stored spelling of a name, so the heading shows what she wrote
     * rather than whatever spelling happened to be in the link.
     */
    private function authorName(string $name): string
    {
        $statement = $this->app->pdo->prepare(
            'SELECT name FROM authors WHERE owner_id = ? AND match_key = ? LIMIT 1'
        );
        $statement->execute([$this->app->ownerId, \App\Core\Text::authorMatchKey($name)]);
        $found = $statement->fetchColumn();

        return $found === false ? $name : (string) $found;
    }

    /** @return array<int,string> book id => "Author, Author" */
    private function authorLines(array $bookIds): array
    {
        $lines = [];
        foreach ($this->contributors($bookIds) as $bookId => $people) {
            $names = array_column(
                array_filter($people, static fn (array $p): bool => $p['role'] === 'author'),
                'name'
            );
            $lines[$bookId] = implode(', ', array_slice($names, 0, 2));
        }

        return $lines;
    }

    /** @return array<int,list<array{name: string, role: string}>> */
    private function contributors(array $bookIds): array
    {
        if ($bookIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));
        $statement = $this->app->pdo->prepare(
            "SELECT ba.book_id, a.name, ba.role
               FROM book_authors ba JOIN authors a ON a.id = ba.author_id
              WHERE ba.book_id IN ($placeholders)
              ORDER BY ba.position ASC, a.sort_name ASC"
        );
        $statement->execute(array_values($bookIds));

        $byBook = [];
        foreach ($statement->fetchAll() as $row) {
            $byBook[(int) $row['book_id']][] = ['name' => (string) $row['name'], 'role' => (string) $row['role']];
        }

        return $byBook;
    }

    /** @return list<array{name: string, slug: string}> */
    /**
     * A book's tags split into the two kinds, genres first.
     *
     * Static and separate for the same reason headingFor() is: it is a
     * decision about what the page says, and it can be checked without a
     * database standing behind it.
     *
     * @param  list<array{name: string, slug: string, kind?: string}> $tags
     * @return array{genre: list<array<string,mixed>>, label: list<array<string,mixed>>}
     */
    public static function tagGroups(array $tags): array
    {
        $groups = ['genre' => [], 'label' => []];
        foreach ($tags as $tag) {
            $groups[($tag['kind'] ?? '') === 'genre' ? 'genre' : 'label'][] = $tag;
        }

        return $groups;
    }

    private function tagsFor(int $bookId): array
    {
        // Genres before labels, and each group alphabetical inside itself:
        // the page shows them under their own headings when a book carries
        // both, so the order has to arrive already grouped.
        $statement = $this->app->pdo->prepare(
            "SELECT t.name, t.slug, t.kind FROM tags t JOIN book_tags bt ON bt.tag_id = t.id
              WHERE bt.book_id = ?
              ORDER BY CASE WHEN t.kind = 'genre' THEN 0 ELSE 1 END, t.name"
        );
        $statement->execute([$bookId]);

        return $statement->fetchAll();
    }

    private function duration(mixed $minutes): string
    {
        $total = (int) ($minutes ?? 0);
        if ($total <= 0) {
            return '';
        }

        return sprintf('%d:%02d h', intdiv($total, 60), $total % 60);
    }

    private function description(array $book, string $authorLine): string
    {
        $parts = array_filter([
            $authorLine,
            $book['publisher'] ?? null,
            $book['published_year'] !== null ? (string) $book['published_year'] : null,
            $book['page_count'] !== null ? t('book.pages.n', ['count' => (int) $book['page_count']]) : null,
        ]);

        return $book['title'] . ($parts === [] ? '' : ' – ' . implode(' · ', $parts));
    }

    /** @return array<string,mixed> */
    private function bookJsonLd(array $book, array $contributors): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type'    => 'Book',
            'name'     => $book['title'],
            'url'      => $this->app->url('/book/' . $book['slug']),
        ];
        if ($book['isbn13'] !== null) {
            $data['isbn'] = Isbn::format((string) $book['isbn13']);
        }
        if (($book['publisher'] ?? null) !== null) {
            $data['publisher'] = ['@type' => 'Organization', 'name' => $book['publisher']];
        }
        if ($book['published_year'] !== null) {
            $data['datePublished'] = (string) $book['published_year'];
        }
        if ($book['page_count'] !== null) {
            $data['numberOfPages'] = (int) $book['page_count'];
        }
        $authors = array_column(
            array_filter($contributors, static fn (array $p): bool => $p['role'] === 'author'),
            'name'
        );
        if ($authors !== []) {
            $data['author'] = array_map(
                static fn (string $name): array => ['@type' => 'Person', 'name' => $name],
                $authors
            );
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function collectionJsonLd(int $total): array
    {
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'CollectionPage',
            'name'            => $this->app->config->str('site_name'),
            'url'             => $this->app->url('/'),
            'inLanguage'      => $this->app->translator->locale(),
            'numberOfItems'   => $total,
        ];
    }

    /**
     * A query-string filter: one of a fixed list, or "" when it is not set.
     *
     * The empty string rather than NULL, because "" is what an absent query
     * parameter already is and what buildWhere() reads as "no filter". That
     * is the only difference from Input::oneOf, which this defers to.
     *
     * @param list<string> $allowed
     */
    private function oneOf(string $value, array $allowed, string $default = ''): string
    {
        return Input::oneOf($value, $allowed, $default) ?? $default;
    }
}
