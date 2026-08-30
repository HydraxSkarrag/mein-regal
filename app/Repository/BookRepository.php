<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Dialect;
use App\Core\Text;
use PDO;

/**
 * Books. Every query filters on owner_id - always, even while there is one
 * owner - so a second collection is a matter of inserting a user, not of
 * revisiting every query in the application.
 */
final class BookRepository
{
    public const STATUS_READ      = 'read';
    public const STATUS_UNREAD    = 'unread';
    public const STATUS_ABANDONED = 'abandoned';
    public const STATUS_READING   = 'reading';

    public const ACQUIRED_PURCHASE   = 'purchase';
    public const ACQUIRED_REVIEW     = 'review_copy';
    public const ACQUIRED_GIFT       = 'gift';
    public const ACQUIRED_PRIZE      = 'prize';
    public const ACQUIRED_LOAN       = 'loan';
    public const ACQUIRED_SWAP       = 'swap';

    private readonly Dialect $dialect;

    public function __construct(private readonly PDO $pdo)
    {
        $this->dialect = new Dialect($pdo);
    }

    /** @param array<string,mixed> $data */
    public function insert(int $ownerId, array $data): int
    {
        $data['owner_id'] = $ownerId;
        $data['slug'] = $this->uniqueSlug($data['title'] ?? '', $data['isbn13'] ?? null);

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO books (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->pdo->prepare($sql)->execute(array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update a book. Only the named columns are touched, and only for a book
     * this owner actually has - the owner check is in the WHERE clause rather
     * than left to the caller.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $ownerId, int $bookId, array $data): bool
    {
        $allowed = [
            'isbn13', 'isbn10', 'title', 'subtitle', 'publisher', 'published_year',
            'page_count', 'language', 'binding', 'price', 'acquisition_type',
            'acquired_at', 'reading_status', 'started_at', 'finished_at',
            'rating', 'notes', 'audio_minutes', 'review_url',
        ];
        $data = array_intersect_key($data, array_flip($allowed));
        if ($data === []) {
            return false;
        }

        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = $column . ' = ?';
        }
        $assignments[] = 'updated_at = ?';

        $values = array_values($data);
        $values[] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $values[] = $bookId;
        $values[] = $ownerId;

        $statement = $this->pdo->prepare(
            'UPDATE books SET ' . implode(', ', $assignments) . ' WHERE id = ? AND owner_id = ?'
        );
        $statement->execute($values);

        return $statement->rowCount() > 0;
    }

    /**
     * Remove a book and everything hanging off it.
     *
     * Returns the cover paths so the caller can delete the files - the
     * repository owns rows, not the filesystem, and a repository that
     * quietly unlinks files is a repository nobody can test.
     *
     * People and tags left without any book are cleared out too. Without
     * that, giving a book away leaves its author in the list forever, and the
     * genre sidebar slowly fills with entries that match nothing.
     *
     * @return array{deleted: bool, coverPaths: list<string>}
     */
    public function delete(int $ownerId, int $bookId): array
    {
        $book = $this->findById($ownerId, $bookId);
        if ($book === null) {
            return ['deleted' => false, 'coverPaths' => []];
        }

        $covers = $this->pdo->prepare('SELECT path FROM covers WHERE book_id = ? AND path IS NOT NULL');
        $covers->execute([$bookId]);
        $paths = array_values(array_filter(
            array_map(static fn (array $row): string => (string) $row['path'], $covers->fetchAll())
        ));

        $this->pdo->prepare('DELETE FROM covers WHERE book_id = ?')->execute([$bookId]);
        $this->pdo->prepare('DELETE FROM book_authors WHERE book_id = ?')->execute([$bookId]);
        $this->pdo->prepare('DELETE FROM book_tags WHERE book_id = ?')->execute([$bookId]);

        $statement = $this->pdo->prepare('DELETE FROM books WHERE id = ? AND owner_id = ?');
        $statement->execute([$bookId, $ownerId]);
        $deleted = $statement->rowCount() > 0;

        if ($deleted) {
            $this->pdo->prepare(
                'DELETE FROM authors WHERE owner_id = ?
                  AND NOT EXISTS (SELECT 1 FROM book_authors ba WHERE ba.author_id = authors.id)'
            )->execute([$ownerId]);
            $this->pdo->prepare(
                'DELETE FROM tags WHERE owner_id = ?
                  AND NOT EXISTS (SELECT 1 FROM book_tags bt WHERE bt.tag_id = tags.id)'
            )->execute([$ownerId]);
        }

        return ['deleted' => $deleted, 'coverPaths' => $paths];
    }

    public function findById(int $ownerId, int $bookId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM books WHERE id = ? AND owner_id = ?');
        $statement->execute([$bookId, $ownerId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** Replace the whole contributor list for a book. */
    public function replaceAuthors(int $ownerId, int $bookId, array $people, AuthorRepository $authors): void
    {
        $this->pdo->prepare('DELETE FROM book_authors WHERE book_id = ?')->execute([$bookId]);
        foreach ($people as $position => $person) {
            $authorId = $authors->findOrCreate($ownerId, $person['name']);
            $authors->link($bookId, $authorId, $person['role'], $position);
        }
    }

    /** Replace the whole tag list for a book. */
    public function replaceTags(int $ownerId, int $bookId, array $names, TagRepository $tags): void
    {
        $this->pdo->prepare('DELETE FROM book_tags WHERE book_id = ?')->execute([$bookId]);
        foreach ($names as $name) {
            $tagId = $tags->findOrCreate($ownerId, $name);
            $tags->link($bookId, $tagId);
        }
    }

    public function findByIsbn(int $ownerId, string $isbn13): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM books WHERE owner_id = ? AND isbn13 = ?');
        $statement->execute([$ownerId, $isbn13]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findBySlug(int $ownerId, string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM books WHERE owner_id = ? AND slug = ?');
        $statement->execute([$ownerId, $slug]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * A title alone is not unique - six titles repeat in the collection - so
     * the ISBN is appended when one is available, and a counter otherwise.
     */
    public function uniqueSlug(string $title, ?string $isbn13): string
    {
        $base = Text::slug($title, 120);
        $candidate = $isbn13 !== null ? $base . '-' . $isbn13 : $base;

        $statement = $this->pdo->prepare('SELECT 1 FROM books WHERE slug = ?');
        $statement->execute([$candidate]);
        if ($statement->fetchColumn() === false) {
            return $candidate;
        }

        for ($suffix = 2; $suffix < 500; $suffix++) {
            $next = $candidate . '-' . $suffix;
            $statement->execute([$next]);
            if ($statement->fetchColumn() === false) {
                return $next;
            }
        }

        return $candidate . '-' . bin2hex(random_bytes(4));
    }

    /**
     * The shelf listing.
     *
     * @param array{search?: string, status?: string, tag?: string, binding?: string, rating?: int, language?: string, cover?: string, isbn?: string, sort?: string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    public function search(int $ownerId, array $filters = [], int $limit = 60, int $offset = 0): array
    {
        [$where, $parameters] = $this->buildWhere($ownerId, $filters);

        $countStatement = $this->pdo->prepare("SELECT COUNT(DISTINCT b.id) FROM books b {$where['join']} WHERE {$where['sql']}");
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();

        $order = match ($filters['sort'] ?? 'recent') {
            'title'  => 'b.title ASC',
            'year'   => 'b.published_year DESC, b.title ASC',
            'rating' => 'b.rating DESC, b.title ASC',
            'read'   => 'b.finished_at DESC, b.title ASC',
            default  => 'b.created_at DESC, b.id DESC',
        };

        $sql = "SELECT DISTINCT b.* FROM books b {$where['join']} WHERE {$where['sql']}
                ORDER BY {$order} LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0: array{sql: string, join: string}, 1: list<mixed>}
     */
    private function buildWhere(int $ownerId, array $filters): array
    {
        $conditions = ['b.owner_id = ?'];
        $parameters = [$ownerId];
        $join = '';

        if (($filters['search'] ?? '') !== '') {
            $term = '%' . Dialect::escapeLike((string) $filters['search']) . '%';
            // The ISBN is matched without separators, the way it is stored.
            $conditions[] = "(b.title LIKE ? ESCAPE '\\' OR b.subtitle LIKE ? ESCAPE '\\' OR b.isbn13 LIKE ? ESCAPE '\\'"
                . ' OR EXISTS (SELECT 1 FROM book_authors ba2 JOIN authors a2 ON a2.id = ba2.author_id'
                . "            WHERE ba2.book_id = b.id AND a2.name LIKE ? ESCAPE '\\'))";
            array_push($parameters, $term, $term, $term, $term);
        }
        if (($filters['status'] ?? '') !== '') {
            $conditions[] = 'b.reading_status = ?';
            $parameters[] = $filters['status'];
        }
        if (($filters['binding'] ?? '') !== '') {
            $conditions[] = 'b.binding = ?';
            $parameters[] = $filters['binding'];
        }
        if (($filters['language'] ?? '') !== '') {
            $conditions[] = 'b.language = ?';
            $parameters[] = $filters['language'];
        }
        if ((int) ($filters['rating'] ?? 0) > 0) {
            $conditions[] = 'b.rating >= ?';
            $parameters[] = (int) $filters['rating'];
        }
        if (($filters['tag'] ?? '') !== '') {
            $join = 'JOIN book_tags bt ON bt.book_id = b.id JOIN tags t ON t.id = bt.tag_id';
            $conditions[] = 't.slug = ?';
            $parameters[] = $filters['tag'];
        }
        // The hundred books with no ISBN are exactly the ones that cannot be
        // looked up automatically, so being able to list them is what makes
        // them findable at all.
        if (($filters['isbn'] ?? '') === 'yes') {
            $conditions[] = 'b.isbn13 IS NOT NULL';
        } elseif (($filters['isbn'] ?? '') === 'no') {
            $conditions[] = 'b.isbn13 IS NULL';
        }
        // Covers arrive gradually, so "show me the ones that have one" and
        // "show me what still needs one" are both worth asking for.
        if (($filters['cover'] ?? '') === 'yes') {
            $conditions[] = 'EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id)';
        } elseif (($filters['cover'] ?? '') === 'no') {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id)';
        }

        return [['sql' => implode(' AND ', $conditions), 'join' => $join], $parameters];
    }

    /** @return array{with: int, without: int} */
    public function countByIsbn(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                 SUM(CASE WHEN isbn13 IS NOT NULL THEN 1 ELSE 0 END) AS with_isbn,
                 SUM(CASE WHEN isbn13 IS NULL THEN 1 ELSE 0 END) AS without_isbn
               FROM books WHERE owner_id = ?'
        );
        $statement->execute([$ownerId]);
        $row = $statement->fetch() ?: [];

        return [
            'with'    => (int) ($row['with_isbn'] ?? 0),
            'without' => (int) ($row['without_isbn'] ?? 0),
        ];
    }

    /** @return array{with: int, without: int} */
    public function countByCover(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                 SUM(CASE WHEN EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id) THEN 1 ELSE 0 END) AS with_cover,
                 SUM(CASE WHEN EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id) THEN 0 ELSE 1 END) AS without_cover
               FROM books b WHERE b.owner_id = ?'
        );
        $statement->execute([$ownerId]);
        $row = $statement->fetch() ?: [];

        return [
            'with'    => (int) ($row['with_cover'] ?? 0),
            'without' => (int) ($row['without_cover'] ?? 0),
        ];
    }

    /** @return array<string,int> */
    public function countBy(int $ownerId, string $column): array
    {
        $allowed = ['reading_status', 'binding', 'acquisition_type', 'rating', 'published_year', 'language'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Not a groupable column: ' . $column);
        }

        $statement = $this->pdo->prepare(
            "SELECT {$column} AS bucket, COUNT(*) AS n FROM books WHERE owner_id = ? GROUP BY {$column} ORDER BY n DESC"
        );
        $statement->execute([$ownerId]);

        $counts = [];
        foreach ($statement->fetchAll() as $row) {
            $counts[(string) ($row['bucket'] ?? '')] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return array<string,int|float|null> */
    public function totals(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)               AS books,
                    SUM(page_count)        AS pages,
                    SUM(price)             AS spend,
                    AVG(rating)            AS avg_rating,
                    COUNT(isbn13)          AS with_isbn,
                    COUNT(rating)          AS with_rating
               FROM books WHERE owner_id = ?'
        );
        $statement->execute([$ownerId]);

        /** @var array<string,int|float|null> */
        return $statement->fetch() ?: [];
    }

    /**
     * Books finished per calendar year. Reads the date column directly rather
     * than using a date function, because MySQL and SQLite spell those
     * differently and the format stored is a plain ISO date either way.
     *
     * @return array<int,int>
     */
    public function finishedPerYear(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT SUBSTR(finished_at, 1, 4) AS y, COUNT(*) AS n
               FROM books
              WHERE owner_id = ? AND finished_at IS NOT NULL AND finished_at <> \'\'
              GROUP BY y ORDER BY y ASC'
        );
        $statement->execute([$ownerId]);

        $years = [];
        foreach ($statement->fetchAll() as $row) {
            $years[(int) $row['y']] = (int) $row['n'];
        }

        return $years;
    }

    /** How complete the catalogue is, field by field - the dashboard's to-do list. */
    public function fieldCoverage(int $ownerId): array
    {
        $fields = [
            'isbn13', 'subtitle', 'publisher', 'published_year', 'page_count',
            'language', 'binding', 'price', 'acquisition_type', 'acquired_at',
            'started_at', 'finished_at', 'rating', 'notes',
        ];
        $selects = ['COUNT(*) AS total'];
        foreach ($fields as $field) {
            $selects[] = sprintf('COUNT(%s) AS %s', $field, $field);
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . implode(', ', $selects) . ' FROM books WHERE owner_id = ?'
        );
        $statement->execute([$ownerId]);

        return array_map('intval', $statement->fetch() ?: []);
    }
}
