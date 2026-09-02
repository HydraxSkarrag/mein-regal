<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\CoverStorage;
use App\Core\Isbn;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Http\Application;
use App\Lookup\CoverFinder;
use App\Lookup\LookupChain;
use App\Repository\CoverRepository;
use Throwable;

/**
 * Editing a book.
 *
 * The dashboard reports how complete the records are, and that figure is only
 * useful if it can be acted on - a page that tells you 6% of your books name
 * a publisher, with no way to add one, is a complaint rather than a tool.
 *
 * Every field the import touched can be corrected here, including the ones
 * the metadata sources cannot supply: what the book cost, where it came from,
 * and whether the blog has a review of it.
 */
final class BookController
{
    private const STATUSES  = ['read', 'unread', 'abandoned', 'reading'];
    private const BINDINGS  = ['hardcover', 'paperback', 'ebook', 'audiobook'];

    /** The two worth offering unprompted; the rest come from the shelf itself. */
    private const LANGUAGES = ['ger', 'eng'];
    private const ACQUIRED  = ['purchase', 'review_copy', 'gift', 'prize', 'loan', 'swap'];
    private const ROLES     = ['author', 'illustrator', 'translator', 'editor', 'narrator'];

    public function __construct(private readonly Application $app)
    {
    }

    public function form(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $book = $this->app->books->findBySlug($this->app->ownerId, $params['slug'] ?? '');
        if ($book === null) {
            return $this->app->notFound();
        }

        return $this->render($book);
    }

    public function save(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $book = $this->app->books->findBySlug($this->app->ownerId, $params['slug'] ?? '');
        if ($book === null) {
            return $this->app->notFound();
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            /* An upload larger than post_max_size makes PHP discard the whole
               body, token included. Saying "the form went stale" would send
               someone to try the same oversized picture again. */
            $tooBig = $request->allPost() === []
                && ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

            return $this->render($book, $tooBig ? t('edit.cover.toolarge') : t('error.csrf'));
        }

        $title = trim($request->post('title'));
        if ($title === '') {
            return $this->render($book, t('edit.title.required'));
        }

        $bookId = (int) $book['id'];

        /* The ISBN is editable because the hundred books that have none are
           exactly the ones that need it most: without it there is nothing to
           look a cover up by, and nothing to ask the DNB about. */
        $rawIsbn = trim($request->post('isbn13'));
        $isbn13 = null;
        if ($rawIsbn !== '') {
            $isbn13 = Isbn::normalize($rawIsbn);
            if ($isbn13 === null) {
                /* A product barcode has a perfectly good check digit - it is
                   simply not a book. Telling someone their check digit is
                   wrong sends them hunting for a typo that is not there. */
                $digits = preg_replace('/\D/', '', $rawIsbn) ?? '';
                $message = strlen($digits) === 13 && Isbn::hasValidEan13Checksum($digits)
                    ? t('edit.isbn.notabook')
                    : t('edit.isbn.invalid');

                return $this->render($book, $message);
            }
            // Two books claiming the same ISBN makes the duplicate check
            // during scanning point at whichever the database returns first.
            $existing = $this->app->books->findByIsbn($this->app->ownerId, $isbn13);
            if ($existing !== null && (int) $existing['id'] !== $bookId) {
                return $this->render($book, t('edit.isbn.taken', ['title' => $existing['title']]));
            }
        }

        try {
            $this->app->pdo->beginTransaction();

            $this->app->books->update($this->app->ownerId, $bookId, [
                'isbn13'           => $isbn13,
                'isbn10'           => $isbn13 !== null ? Isbn::to10($isbn13) : null,
                'title'            => mb_substr($title, 0, 500),
                'subtitle'         => $this->orNull($request->post('subtitle'), 500),
                'publisher'        => $this->orNull($request->post('publisher'), 255),
                'published_year'   => $this->intOrNull($request->post('published_year'), 1400, 2100),
                'page_count'       => $this->intOrNull($request->post('page_count'), 1, 30000),
                'language'         => $this->oneOf(
                    $request->post('language'),
                    $this->languageOptions($book['language'] ?? null)
                ),
                'binding'          => $this->oneOf($request->post('binding'), self::BINDINGS),
                'price'            => $this->priceOrNull($request->post('price')),
                'acquisition_type' => $this->oneOf($request->post('acquisition_type'), self::ACQUIRED),
                'acquired_at'      => $this->dateOrNull($request->post('acquired_at')),
                'reading_status'   => $this->oneOf($request->post('reading_status'), self::STATUSES) ?? 'unread',
                'started_at'       => $this->dateOrNull($request->post('started_at')),
                'finished_at'      => $this->dateOrNull($request->post('finished_at')),
                'rating'           => $this->ratingOrNull($request->post('rating')),
                'notes'            => $this->orNull($request->post('notes'), 65535),
                'review_url'       => $this->urlOrNull($request->post('review_url')),
            ]);

            $this->app->books->replaceAuthors(
                $this->app->ownerId,
                $bookId,
                $this->parseContributors($request),
                $this->app->authors
            );

            $this->app->books->replaceTags(
                $this->app->ownerId,
                $bookId,
                $this->parseTags($request->post('tags')),
                $this->app->tags
            );

            $this->app->pdo->commit();
        } catch (Throwable $e) {
            if ($this->app->pdo->inTransaction()) {
                $this->app->pdo->rollBack();
            }
            error_log('[regal] edit failed: ' . $e->getMessage());

            return $this->render($book, t('error.500.title'));
        }

        /* A cover uploaded alongside the edit. Handled after the commit so a
           bad image cannot undo a good edit.
           
           Every outcome except "no file was chosen" has to say something.
           Skipping quietly on an error code is how a three-megabyte photo
           came to vanish without a word: PHP rejects it before any of this
           runs, and the page then reported a successful save. */
        $upload = $request->file('cover');
        $uploadError = $upload === null
            ? UPLOAD_ERR_NO_FILE
            : (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError === UPLOAD_ERR_OK) {
            $this->storeCover($bookId, $upload, (string) ($book['isbn13'] ?? $book['slug']));
        } elseif ($uploadError !== UPLOAD_ERR_NO_FILE) {
            $this->app->session->flash($this->uploadMessage($uploadError), 'error');
        }

        $this->app->session->flash(t('edit.saved'), 'ok');

        return Response::redirect('/book/' . $book['slug']);
    }

    /**
     * Give a book away.
     *
     * POST only and behind a CSRF token, because a link that deletes on GET
     * is one prefetching browser away from an empty shelf. The typed
     * confirmation is not ceremony either: without it the delete button sits
     * a mis-tap away from Save on a phone.
     */
    public function delete(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $book = $this->app->books->findBySlug($this->app->ownerId, $params['slug'] ?? '');
        if ($book === null) {
            return $this->app->notFound();
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->render($book, t('error.csrf'));
        }
        $confirm = mb_strtoupper(trim($request->post('confirm')), 'UTF-8');
        if (!in_array($confirm, ['LOESCHEN', 'LÖSCHEN', 'DELETE'], true)) {
            return $this->render($book, t('delete.confirm.missing'));
        }

        $title = (string) $book['title'];

        try {
            $this->app->pdo->beginTransaction();
            $result = $this->app->books->delete($this->app->ownerId, (int) $book['id']);
            $this->app->pdo->commit();
        } catch (Throwable $e) {
            if ($this->app->pdo->inTransaction()) {
                $this->app->pdo->rollBack();
            }
            error_log('[regal] delete failed: ' . $e->getMessage());

            return $this->render($book, t('error.500.title'));
        }

        // Files go only after the rows are safely gone, so a failure here
        // leaves an orphaned image rather than a book pointing at nothing.
        if ($result['deleted']) {
            $storage = new CoverStorage(PROJECT_ROOT . '/public/covers');
            foreach ($result['coverPaths'] as $path) {
                $storage->delete($path);
            }
        }

        $this->app->session->flash(t('delete.done', ['title' => $title]), 'ok');

        return Response::redirect('/');
    }

    /**
     * Throw a cover away.
     *
     * Wanted more often than it sounds: a cover fetched from elsewhere can be
     * the wrong edition, and a photograph can simply be a bad one. Without
     * this the only way back is to delete the book.
     */
    public function deleteCover(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $book = $this->app->books->findBySlug($this->app->ownerId, $params['slug'] ?? '');
        if ($book === null) {
            return $this->app->notFound();
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->render($book, t('error.csrf'));
        }

        $paths = $this->app->covers->remove((int) $book['id']);

        $storage = new CoverStorage(PROJECT_ROOT . '/public/covers');
        foreach ($paths as $path) {
            $storage->delete($path);
        }

        $this->app->session->flash(t('cover.removed'), 'ok');

        return Response::redirect('/book/' . $book['slug'] . '/edit');
    }

    /**
     * Go and look for a cover for this one book.
     *
     * The nightly job works through the backlog on its own, but for a book
     * in front of you right now, waiting until tomorrow is silly.
     */
    public function findCover(Request $request, array $params): Response
    {
        $guard = $this->app->requireSignIn();
        if ($guard !== null) {
            return $guard;
        }

        $book = $this->app->books->findBySlug($this->app->ownerId, $params['slug'] ?? '');
        if ($book === null) {
            return $this->app->notFound();
        }
        if (!$this->app->csrf->isValid($request->allPost())) {
            return $this->render($book, t('error.csrf'));
        }

        $isbn = $book['isbn13'] ?? null;
        if ($isbn === null) {
            $this->app->session->flash(t('cover.search.no.isbn'), 'error');

            return Response::redirect('/book/' . $book['slug'] . '/edit');
        }

        $finder = new CoverFinder(
            $this->app->lookup,
            $this->app->covers,
            new CoverStorage(PROJECT_ROOT . '/public/covers')
        );
        $result = $finder->findFor((int) $book['id'], (string) $isbn);

        if ($result['stored']) {
            $this->app->session->flash(t('cover.search.found'), 'ok');

            return Response::redirect('/book/' . $book['slug'] . '/edit');
        }

        // "No cover found" is only true when every source was actually asked.
        $this->app->session->flash(match (LookupChain::verdict($result['failures'])) {
            'quota'       => t('cover.search.quota'),
            'unreachable' => t('cover.search.unreachable'),
            default       => t('cover.search.none'),
        }, 'error');

        return Response::redirect('/book/' . $book['slug'] . '/edit');
    }

    /**
     * Which languages the dropdown may offer.
     *
     * It used to offer German and English and nothing else, while the DNB
     * hands out chi, fre, gmh, mul and zxx as readily as ger. A book in one
     * of those had no matching option, so the browser showed the empty one -
     * and saving the form, without anyone touching that field, wiped the
     * language. A list that cannot show a value must not be allowed to
     * decide it, so whatever the book carries is always among the options.
     *
     * @return list<string>
     */
    private function languageOptions(?string $current): array
    {
        $codes = self::LANGUAGES;
        foreach (array_keys($this->app->books->countBy($this->app->ownerId, 'language')) as $code) {
            if ($code !== '' && !in_array($code, $codes, true)) {
                $codes[] = (string) $code;
            }
        }
        if ($current !== null && $current !== '' && !in_array($current, $codes, true)) {
            $codes[] = $current;
        }

        return $codes;
    }

    /** Why the file did not arrive, in words the person can act on. */
    private function uploadMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => t('edit.cover.toolarge'),
            UPLOAD_ERR_PARTIAL                        => t('edit.cover.partial'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION
                                                      => t('edit.cover.failed'),
            default                                   => t('edit.cover.failed'),
        };
    }

    private function storeCover(int $bookId, array $upload, string $key): void
    {
        try {
            $storage = new CoverStorage(PROJECT_ROOT . '/public/covers');
            $stored = $storage->storeUpload($upload, $key . '-own');
            $this->app->covers->save(
                $bookId,
                CoverRepository::SOURCE_OWN,
                $stored['path'],
                null,
                null,
                $stored['width'],
                $stored['height']
            );
        } catch (Throwable $e) {
            error_log('[regal] cover upload during edit failed: ' . $e->getMessage());
            $this->app->session->flash(
                $e->getMessage() === 'too_large'
                    ? t('edit.cover.toolarge')
                    : t('edit.cover.failed'),
                'error'
            );
        }
    }

    private function render(array $book, string $error = ''): Response
    {
        $bookId = (int) $book['id'];

        $statement = $this->app->pdo->prepare(
            'SELECT a.name, ba.role FROM book_authors ba JOIN authors a ON a.id = ba.author_id
              WHERE ba.book_id = ? ORDER BY ba.position ASC'
        );
        $statement->execute([$bookId]);
        $contributors = $statement->fetchAll();

        $tagStatement = $this->app->pdo->prepare(
            'SELECT t.name FROM tags t JOIN book_tags bt ON bt.tag_id = t.id
              WHERE bt.book_id = ? ORDER BY t.name'
        );
        $tagStatement->execute([$bookId]);

        $body = $this->app->view->render('shelf.edit', [
            'book'         => $book,
            'contributors' => $contributors,
            'tagList'      => implode(', ', array_column($tagStatement->fetchAll(), 'name')),
            'knownTags'    => $this->app->tags->allForOwner($this->app->ownerId),
            'cover'        => $this->app->covers->bestFor($bookId, true),
            'statuses'     => self::STATUSES,
            'bindings'     => self::BINDINGS,
            'languages'    => $this->languageOptions($book['language'] ?? null),
            'acquired'     => self::ACQUIRED,
            'roles'        => self::ROLES,
            'error'        => $error,
            'csrfField'    => $this->app->csrf->field(),
            'isbnFormatted'=> $book['isbn13'] !== null ? Isbn::format((string) $book['isbn13']) : '',
            'view'         => $this->app->view,
        ]);

        return Response::html($this->app->view->render('layout.base', [
            'content' => $body,
            'title'   => t('book.edit') . ' – ' . $book['title'],
            'current' => 'shelf',
            'noIndex' => true,
            'scripts' => ['/js/tags.js', '/js/edit.js'],
        ]), $error === '' ? 200 : 422)->noIndex();
    }

    /**
     * Contributors come in as two parallel lists, so a name keeps its role.
     *
     * @return list<array{name: string, role: string}>
     */
    private function parseContributors(Request $request): array
    {
        $post = $request->allPost();
        $names = is_array($post['author_name'] ?? null) ? $post['author_name'] : [];
        $roles = is_array($post['author_role'] ?? null) ? $post['author_role'] : [];

        $people = [];
        $seen = [];
        foreach (array_slice($names, 0, 20) as $index => $raw) {
            $name = Text::tidyName((string) $raw);
            if ($name === '' || Text::isPlaceholderName($name)) {
                continue;
            }
            $key = Text::authorMatchKey($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $role = (string) ($roles[$index] ?? 'author');
            $people[] = [
                'name' => mb_substr($name, 0, 255),
                'role' => in_array($role, self::ROLES, true) ? $role : 'author',
            ];
        }

        return $people;
    }

    /** @return list<string> */
    private function parseTags(string $raw): array
    {
        $tags = [];
        foreach (explode(',', $raw) as $piece) {
            $name = trim($piece);
            if ($name !== '') {
                $tags[] = mb_substr($name, 0, 190);
            }
            if (count($tags) >= 12) {
                break;
            }
        }

        return array_values(array_unique($tags));
    }

    private function orNull(string $value, int $maxLength): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /** Only http(s), so a stored link cannot become a javascript: URL. */
    private function urlOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? mb_substr($value, 0, 500) : null;
    }

    /** Half steps from 0.5 to 5.0; anything else is discarded. */
    private function ratingOrNull(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $rating = round((float) $value * 2) / 2;

        return $rating >= 0.5 && $rating <= 5.0 ? $rating : null;
    }

    private function intOrNull(string $value, int $min, int $max): ?int
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }

    private function dateOrNull(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
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
