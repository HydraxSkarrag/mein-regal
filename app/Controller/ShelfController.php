<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Isbn;
use App\Core\Request;
use App\Core\Response;
use App\Http\Application;

/**
 * The shelf and a single book - the public half of the site.
 */
final class ShelfController
{
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
            'author'   => $request->query('author'),
            'binding'  => $this->oneOf($request->query('binding'), ['hardcover', 'paperback', 'ebook', 'audiobook']),
            'review'   => $this->oneOf($request->query('review'), ['yes', 'no']),
            'cover'    => $this->oneOf($request->query('cover'), ['yes', 'no']),
            'isbn'     => $this->oneOf($request->query('isbn'), ['yes', 'no']),
            'sort'     => $this->oneOf($request->query('sort'), ['recent', 'title', 'year', 'rating', 'read'], 'recent'),
        ];

        $heading = $filters['author'] !== ''
            ? $this->authorName($filters['author'])
            : t('shelf.title');

        return $this->renderShelf($request, $filters, $heading, 'shelf');
    }

    /** The pile of unread books - its own view because it is looked at often. */
    public function unread(Request $request): Response
    {
        $filters = [
            'search' => $request->query('q'),
            'status' => 'unread',
            'tag'    => $request->query('tag'),
            'sort'   => $this->oneOf($request->query('sort'), ['recent', 'title', 'year', 'rating', 'read'], 'recent'),
        ];

        return $this->renderShelf($request, $filters, t('nav.unread'), 'unread');
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

        $nextUrl = null;
        if ($offset + count($books) < $result['total']) {
            $nextUrl = '/?' . http_build_query(array_merge(array_filter($query), ['page' => $page + 1]));
        }

        $body = $this->app->view->render('shelf.index', [
            'heading'       => $heading,
            'books'         => $books,
            'total'         => $result['total'],
            'offset'        => $offset,
            'covers'        => $this->app->covers->bestForMany($ids, $signedIn),
            'authorLines'   => $this->authorLines($ids),
            'tags'          => $this->app->tags->listWithCounts($this->app->ownerId, 14),
            'tagTotal'      => $this->app->tags->count($this->app->ownerId),
            'topAuthors'    => $this->app->authors->listWithCounts($this->app->ownerId, 12),
            'authorTotal'   => $this->app->authors->count($this->app->ownerId),
            'statusCounts'  => $this->app->books->countBy($this->app->ownerId, 'reading_status'),
            'bindingCounts' => $this->app->books->countBy($this->app->ownerId, 'binding'),
            'reviewCounts'  => $this->app->books->countByReview($this->app->ownerId),
            'coverCounts'   => $this->app->books->countByCover($this->app->ownerId),
            'isbnCounts'    => $this->app->books->countByIsbn($this->app->ownerId),
            'filters'       => $filters,
            'hasFilters'    => array_filter($filters) !== ['sort' => 'recent'] && array_filter($filters) !== [],
            'urlFor'        => $urlFor,
            'nextUrl'       => $nextUrl,
            'view'          => $this->app->view,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content'   => $body,
            'title'     => $heading,
            'current'   => $current,
            'canonical' => $this->app->url($current === 'unread' ? '/unread' : '/'),
            'jsonLd'    => $this->collectionJsonLd($result['total']),
        ]));
    }

    /**
     * Every genre there is.
     *
     * The sidebar lists the biggest fourteen because a sidebar has to stop
     * somewhere, which left three hundred and sixty-seven of them reachable
     * only by guessing the URL. This is where the heading points.
     */
    public function genres(): Response
    {
        $rows = $this->app->tags->listAllByName($this->app->ownerId);

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

        $authorLine = implode(', ', array_column(
            array_filter($contributors, static fn (array $p): bool => $p['role'] === 'author'),
            'name'
        ));

        $body = $this->app->view->render('shelf.detail', [
            'book'          => $book,
            'cover'         => $cover,
            'contributors'  => $contributors,
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
    private function tagsFor(int $bookId): array
    {
        $statement = $this->app->pdo->prepare(
            'SELECT t.name, t.slug FROM tags t JOIN book_tags bt ON bt.tag_id = t.id
              WHERE bt.book_id = ? ORDER BY t.name'
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

    /** @param list<string> $allowed */
    private function oneOf(string $value, array $allowed, string $default = ''): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
