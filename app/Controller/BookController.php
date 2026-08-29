<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\CoverStorage;
use App\Core\Isbn;
use App\Core\Request;
use App\Core\Response;
use App\Core\Text;
use App\Http\Application;
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
    private const BINDINGS  = ['hardcover', 'paperback', 'ebook', 'audiobook', 'unknown'];
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
            return $this->render($book, t('error.csrf'));
        }

        $title = trim($request->post('title'));
        if ($title === '') {
            return $this->render($book, t('edit.title.required'));
        }

        $bookId = (int) $book['id'];

        try {
            $this->app->pdo->beginTransaction();

            $this->app->books->update($this->app->ownerId, $bookId, [
                'title'            => mb_substr($title, 0, 500),
                'subtitle'         => $this->orNull($request->post('subtitle'), 500),
                'publisher'        => $this->orNull($request->post('publisher'), 255),
                'published_year'   => $this->intOrNull($request->post('published_year'), 1400, 2100),
                'page_count'       => $this->intOrNull($request->post('page_count'), 1, 30000),
                'language'         => $this->orNull($request->post('language'), 3),
                'binding'          => $this->oneOf($request->post('binding'), self::BINDINGS),
                'price'            => $this->priceOrNull($request->post('price')),
                'acquisition_type' => $this->oneOf($request->post('acquisition_type'), self::ACQUIRED),
                'acquired_at'      => $this->dateOrNull($request->post('acquired_at')),
                'reading_status'   => $this->oneOf($request->post('reading_status'), self::STATUSES) ?? 'unread',
                'started_at'       => $this->dateOrNull($request->post('started_at')),
                'finished_at'      => $this->dateOrNull($request->post('finished_at')),
                'rating'           => $this->intOrNull($request->post('rating'), 1, 5),
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

        // A cover uploaded alongside the edit. Handled after the commit so a
        // bad image cannot undo a good edit.
        $upload = $request->file('cover');
        if ($upload !== null && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $this->storeCover($bookId, $upload, (string) ($book['isbn13'] ?? $book['slug']));
        }

        $this->app->session->flash(t('edit.saved'), 'ok');

        return Response::redirect('/buch/' . $book['slug']);
    }

    private function storeCover(int $bookId, array $upload, string $key): void
    {
        try {
            $storage = new CoverStorage(PROJECT_ROOT . '/public/covers');
            $stored = $storage->storeUpload($upload, $key);
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
            $this->app->session->flash(t('edit.cover.failed'), 'error');
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
            'cover'        => $this->app->covers->bestFor($bookId, true),
            'statuses'     => self::STATUSES,
            'bindings'     => self::BINDINGS,
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
