<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\CoverStorage;
use App\Core\Isbn;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Http\Application;
use App\Lookup\OpenLibraryLookup;
use App\Repository\CoverRepository;
use Throwable;

/**
 * Scanning a barcode and putting the book on the shelf.
 *
 * The lookup endpoint is throttled per address. Without that, this server
 * becomes a free proxy to the DNB and to Google Books - on our address and
 * against our daily quota.
 */
final class ScanController
{
    private const LOOKUPS_PER_MINUTE = 20;

    public function __construct(private readonly Application $app)
    {
    }

    public function page(): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $body = $this->app->view->render('scan.index', [
            'csrfField' => $this->app->csrf->field(),
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('scan.title'),
            'current' => 'scan',
            'noIndex' => true,
            'narrow'  => true,
            'scripts' => ['/js/scanner.js'],
        ]))->noIndex();
    }

    /** POST /api/lookup - what do we know about this ISBN? */
    public function lookup(Request $request): Response
    {
        $guard = $this->requireSignedInJson();
        if ($guard !== null) {
            return $guard;
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return Response::json(['error' => t('error.csrf')], 400);
        }
        if (!$this->withinRateLimit($request->ip())) {
            return Response::json(['error' => 'rate_limited'], 429);
        }

        $isbn = Isbn::normalize($request->post('isbn'));
        if ($isbn === null) {
            $raw = preg_replace('/\D/', '', $request->post('isbn')) ?? '';

            return Response::json([
                'error' => strlen($raw) === 13 ? t('scan.not.a.book') : t('scan.invalid.isbn'),
            ], 422);
        }

        // Already on the shelf? Say so before spending a request on it.
        $existing = $this->app->books->findByIsbn($this->app->ownerId, $isbn);
        if ($existing !== null) {
            return Response::json([
                'duplicate' => true,
                'message'   => t('scan.duplicate'),
                'book'      => ['title' => $existing['title'], 'slug' => $existing['slug']],
            ]);
        }

        $outcome = $this->app->lookup->find($isbn);
        $found = $outcome['result'];

        if ($found === null) {
            return Response::json([
                'found'   => false,
                'isbn'    => $isbn,
                'message' => t('scan.nothing'),
                'tried'   => $outcome['tried'],
            ]);
        }

        $data = $found->toArray();

        // The best source for a German book is the DNB, and it has no covers
        // at all. Ask Open Library's cover service directly rather than
        // showing a bare placeholder - the same step took the nightly job's
        // cover rate from 8% to 40%.
        if ($data['cover_url'] === null) {
            $probe = $this->openLibraryCover($isbn);
            if ($probe !== null) {
                $data['cover_url'] = $probe;
                $data['cover_source'] = CoverRepository::SOURCE_OPENLIBRARY;
                $data['attribution'] = 'Cover: Open Library';
            }
        }

        $data['isbn_formatted'] = Isbn::format($isbn);
        $data['source_label'] = t('scan.found.via', ['source' => $this->sourceLabel($found->source)]);

        return Response::json(['found' => true, 'book' => $data, 'tried' => $outcome['tried']]);
    }

    /** POST /api/buch - store what the lookup found, once confirmed. */
    public function store(Request $request): Response
    {
        $guard = $this->requireSignedInJson();
        if ($guard !== null) {
            return $guard;
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return Response::json(['error' => t('error.csrf')], 400);
        }

        $isbn = Isbn::normalize($request->post('isbn'));
        $title = trim($request->post('title'));
        if ($title === '') {
            return Response::json(['error' => t('scan.invalid.isbn')], 422);
        }

        if ($isbn !== null && $this->app->books->findByIsbn($this->app->ownerId, $isbn) !== null) {
            return Response::json(['error' => t('scan.duplicate')], 409);
        }

        $this->app->pdo->beginTransaction();
        try {
            $bookId = $this->app->books->insert($this->app->ownerId, [
                'isbn13'         => $isbn,
                'isbn10'         => $isbn !== null ? Isbn::to10($isbn) : null,
                'title'          => mb_substr($title, 0, 500),
                'subtitle'       => $this->orNull($request->post('subtitle'), 500),
                'publisher'      => $this->orNull($request->post('publisher'), 255),
                'published_year' => $this->intOrNull($request->post('published_year'), 1400, 2100),
                'page_count'     => $this->intOrNull($request->post('page_count'), 1, 30000),
                'language'       => $this->orNull($request->post('language'), 3),
                'binding'        => $this->oneOf($request->post('binding'), ['hardcover', 'paperback', 'ebook', 'audiobook']),
                'price'          => $this->priceOrNull($request->post('price')),
                'acquisition_type' => $this->oneOf(
                    $request->post('acquisition_type'),
                    ['purchase', 'review_copy', 'gift', 'prize', 'loan', 'swap']
                ),
                'acquired_at'    => (new \DateTimeImmutable())->format('Y-m-d'),
                'reading_status' => $this->oneOf($request->post('reading_status'), ['read', 'unread', 'abandoned', 'reading']) ?? 'unread',
            ]);

            foreach ($this->decodeAuthors($request->post('authors')) as $position => $person) {
                $authorId = $this->app->authors->findOrCreate($this->app->ownerId, $person['name']);
                $this->app->authors->link($bookId, $authorId, $person['role'], $position);
            }

            foreach ($this->decodeTags($request->post('tags')) as $tagName) {
                $tagId = $this->app->tags->findOrCreate($this->app->ownerId, $tagName);
                $this->app->tags->link($bookId, $tagId);
            }

            $this->app->pdo->commit();
        } catch (Throwable $e) {
            if ($this->app->pdo->inTransaction()) {
                $this->app->pdo->rollBack();
            }
            error_log('[regal] store failed: ' . $e->getMessage());

            return Response::json(['error' => t('error.500.title')], 500);
        }

        // Outside the transaction on purpose: the book is catalogued either
        // way, and a slow or missing cover must not undo that.
        $coverUrl = $request->post('cover_url');
        $coverSource = $this->oneOf($request->post('cover_source'), ['google', 'openlibrary']);
        if ($coverUrl !== '' && $coverSource !== null) {
            $this->fetchCover($bookId, $coverUrl, $coverSource, $isbn, $request->post('cover_attribution'));
        }

        $book = $this->app->books->findByIsbn($this->app->ownerId, (string) $isbn);

        return Response::json([
            'saved'   => true,
            'id'      => $bookId,
            'slug'    => $book['slug'] ?? '',
            'message' => t('scan.saved', ['title' => $title]),
        ]);
    }

    /**
     * POST /api/cover - the photograph taken at the same moment as the scan.
     *
     * This is the cover source that always works and is unambiguously ours to
     * publish; the file is re-encoded on the way in, which also removes the
     * GPS coordinates a phone writes into every photo.
     */
    public function uploadCover(Request $request): Response
    {
        $guard = $this->requireSignedInJson();
        if ($guard !== null) {
            return $guard;
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return Response::json(['error' => t('error.csrf')], 400);
        }

        $bookId = (int) $request->post('book_id');
        $statement = $this->app->pdo->prepare('SELECT id, isbn13, slug FROM books WHERE id = ? AND owner_id = ?');
        $statement->execute([$bookId, $this->app->ownerId]);
        $book = $statement->fetch();
        if ($book === false) {
            return Response::json(['error' => t('error.404.title')], 404);
        }

        $upload = $request->file('cover');
        if ($upload === null) {
            return Response::json(['error' => 'no_file'], 422);
        }

        try {
            $storage = new CoverStorage(PROJECT_ROOT . '/public/covers');
            $stored = $storage->storeUpload(
                $upload,
                (string) ($book['isbn13'] ?? $book['slug'])
            );
        } catch (Throwable $e) {
            error_log('[regal] cover upload failed: ' . $e->getMessage());

            return Response::json(['error' => 'bad_image'], 422);
        }

        $this->app->covers->save(
            $bookId,
            CoverRepository::SOURCE_OWN,
            $stored['path'],
            null,
            null,
            $stored['width'],
            $stored['height']
        );

        return Response::json(['saved' => true, 'url' => '/covers/' . $stored['path']]);
    }

    /**
     * Download a cover once and serve it from here afterwards.
     *
     * Copying rather than embedding is what lets every visitor see covers
     * without a consent banner - no visitor ever contacts Google or the
     * Internet Archive. Failure is logged and ignored: a book without a cover
     * is still a catalogued book.
     */
    private function fetchCover(int $bookId, string $url, string $source, ?string $isbn, string $attribution): void
    {
        try {
            $storage = new CoverStorage(PROJECT_ROOT . '/public/covers');
            $stored = $storage->storeRemote($url, (string) ($isbn ?? $bookId));
            $this->app->covers->save(
                $bookId,
                $source,
                $stored['path'],
                $url,
                $this->orNull($attribution, 255),
                $stored['width'],
                $stored['height']
            );
        } catch (Throwable $e) {
            error_log('[regal] cover fetch failed for ' . $url . ': ' . $e->getMessage());
        }
    }

    /** POST /api/cover/loeschen - discard a cover taken moments ago. */
    public function deleteCover(Request $request): Response
    {
        $guard = $this->requireSignedInJson();
        if ($guard !== null) {
            return $guard;
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return Response::json(['error' => t('error.csrf')], 400);
        }

        $bookId = (int) $request->post('book_id');
        $statement = $this->app->pdo->prepare('SELECT id FROM books WHERE id = ? AND owner_id = ?');
        $statement->execute([$bookId, $this->app->ownerId]);
        if ($statement->fetch() === false) {
            return Response::json(['error' => t('error.404.title')], 404);
        }

        $paths = $this->app->covers->remove($bookId);
        $storage = new CoverStorage(PROJECT_ROOT . '/public/covers');
        foreach ($paths as $path) {
            $storage->delete($path);
        }

        return Response::json(['removed' => true]);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Is there a cover for this ISBN, and only then its address.
     *
     * Checked rather than assumed: handing the page a URL that answers 404
     * would put a broken image where the cover belongs. "default=false" is
     * what makes the service say no instead of returning a blank placeholder.
     */
    private function openLibraryCover(string $isbn13): ?string
    {
        $url = OpenLibraryLookup::coverUrl($isbn13);

        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'Buecherregal/1.0 (private library catalogue)',
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return $status === 200 ? $url : null;
    }

    private function requireSignedInJson(): ?Response
    {
        return $this->app->auth->isSignedIn()
            ? null
            : Response::json(['error' => t('auth.required')], 401);
    }

    /**
     * A short window counted per address. Generous enough that a real
     * scanning session never notices, tight enough that the endpoint is not
     * worth borrowing.
     */
    private function withinRateLimit(?string $ip): bool
    {
        if ($ip === null) {
            return true;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        $since = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        $count = $this->app->pdo->prepare('SELECT COUNT(*) FROM lookup_hits WHERE ip = ? AND hit_at > ?');
        $count->execute([$packed, $since]);
        if ((int) $count->fetchColumn() >= self::LOOKUPS_PER_MINUTE) {
            return false;
        }

        $this->app->pdo->prepare('INSERT INTO lookup_hits (ip) VALUES (?)')->execute([$packed]);

        return true;
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'dnb'         => 'Deutsche Nationalbibliothek',
            'google'      => 'Google Books',
            'openlibrary' => 'Open Library',
            default       => $source,
        };
    }

    /** @return list<array{name: string, role: string}> */
    private function decodeAuthors(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $people = [];
        foreach (array_slice($decoded, 0, 20) as $entry) {
            $name = Text::tidyName((string) ($entry['name'] ?? ''));
            if ($name === '' || Text::isPlaceholderName($name)) {
                continue;
            }
            $role = (string) ($entry['role'] ?? 'author');
            $people[] = [
                'name' => mb_substr($name, 0, 255),
                'role' => in_array($role, ['author', 'illustrator', 'translator', 'editor', 'narrator'], true) ? $role : 'author',
            ];
        }

        return $people;
    }

    /** @return list<string> */
    private function decodeTags(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($tag): string => mb_substr(trim((string) $tag), 0, 190),
            array_slice($decoded, 0, 8)
        )));
    }

    private function orNull(string $value, int $maxLength): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function intOrNull(string $value, int $min, int $max): ?int
    {
        if (!ctype_digit($value)) {
            return null;
        }
        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }

    private function priceOrNull(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $price = (float) $value;

        return $price > 0 ? round($price, 2) : null;
    }

    /** @param list<string> $allowed */
    private function oneOf(string $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }
}
