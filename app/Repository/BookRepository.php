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
     * @param array{search?: string, status?: string, tag?: string, author?: string, rating?: int, language?: string, cover?: string, isbn?: string, missing?: string, sort?: string, dir?: string} $filters
     * @return array{rows: list<array<string,mixed>>, total: int}
     */
    /**
     * What each sort is, which way it runs by default, and what to do with
     * books that have nothing in that column.
     *
     * The natural direction is the one somebody means when they pick the
     * sort without saying more: newest first for a date, A to Z for a title.
     * It can be turned round, and turning it round must not drag the books
     * with an empty column to the top - "no rating" is not the best rating,
     * and 229 undated books at the head would bury what the sort is about.
     *
     * @var array<string, array{0: string, 1: string, 2: ?string, 3: string}>
     *      column, natural direction, "is empty" test, tie-breaker
     */
    private const SORTS = [
        'recent'   => ['b.created_at', 'DESC', null, 'b.id DESC'],
        'acquired' => ['b.acquired_at', 'DESC', 'b.acquired_at IS NULL', 'b.title ASC'],
        'title'    => ['b.title', 'ASC', null, 'b.id ASC'],
        'year'     => ['b.published_year', 'DESC', 'b.published_year IS NULL', 'b.title ASC'],
        'rating'   => ['b.rating', 'DESC', 'b.rating IS NULL', 'b.title ASC'],
        'read'     => ['b.finished_at', 'DESC', 'b.finished_at IS NULL', 'b.title ASC'],
    ];

    /** @return list<string> the sorts the shelf offers, in the order it offers them */
    public static function sorts(): array
    {
        return array_keys(self::SORTS);
    }

    /** Which way a sort runs when nobody has said otherwise. */
    public static function naturalDirection(string $sort): string
    {
        return strtolower(self::SORTS[$sort][1] ?? 'DESC');
    }

    private static function orderBy(string $sort, string $direction): string
    {
        [$column, $natural, $emptyTest, $tieBreak] = self::SORTS[$sort] ?? self::SORTS['recent'];

        $direction = strtoupper($direction) === 'ASC' || strtoupper($direction) === 'DESC'
            ? strtoupper($direction)
            : $natural;

        // The empty test first and always ascending: 0 before 1 puts the
        // books that have a value ahead of the ones that do not, whichever
        // way the values themselves are being read.
        $parts = $emptyTest === null ? [] : [$emptyTest . ' ASC'];
        $parts[] = $column . ' ' . $direction;
        $parts[] = $tieBreak;

        return implode(', ', $parts);
    }

    public function search(int $ownerId, array $filters = [], int $limit = 60, int $offset = 0): array
    {
        [$where, $parameters] = $this->buildWhere($ownerId, $filters);

        $countStatement = $this->pdo->prepare("SELECT COUNT(DISTINCT b.id) FROM books b {$where['join']} WHERE {$where['sql']}");
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();

        $order = self::orderBy((string) ($filters['sort'] ?? 'recent'), (string) ($filters['dir'] ?? ''));

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
        if (($filters['language'] ?? '') !== '') {
            $conditions[] = 'b.language = ?';
            $parameters[] = $filters['language'];
        }
        if ((int) ($filters['rating'] ?? 0) > 0) {
            $conditions[] = 'b.rating >= ?';
            $parameters[] = (int) $filters['rating'];
        }
        // Everything by one person. Matched on the folded key rather than
        // the name, so "Flechsig, Dorothea" and "Dorothea Flechsig" - which
        // are one person in the database - stay one person in the URL too.
        if (($filters['author'] ?? '') !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM book_authors ba3 JOIN authors a3 ON a3.id = ba3.author_id'
                . '            WHERE ba3.book_id = b.id AND a3.match_key = ?)';
            $parameters[] = Text::authorMatchKey((string) $filters['author']);
        }
        if (($filters['tag'] ?? '') !== '') {
            // A tag taken out of use stops being a filter too, or its address
            // would keep working long after it left every list.
            $join = 'JOIN book_tags bt ON bt.book_id = b.id JOIN tags t ON t.id = bt.tag_id';
            $conditions[] = 't.slug = ? AND t.dropped_at IS NULL';
            $parameters[] = $filters['tag'];
        }
        /* The gaps, as a filter.
         *
         * One parameter with a name for each gap rather than three yes/no
         * ones: nobody ever wants "books that do have an author", and the
         * dashboard's counts and these lists have to mean the same thing -
         * a number linking to a list that says something else is worse than
         * no link.
         *
         * "Unrated" means read and unrated. A book nobody has read yet is
         * not missing its rating; it is waiting.
         */
        $missing = $filters['missing'] ?? '';
        if ($missing === 'genre') {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM book_tags bt4 JOIN tags t4 ON t4.id = bt4.tag_id'
                . "            WHERE bt4.book_id = b.id AND t4.kind = 'genre' AND t4.dropped_at IS NULL)";
        } elseif ($missing === 'author') {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM book_authors ba4 WHERE ba4.book_id = b.id)';
        } elseif ($missing === 'rating') {
            $conditions[] = "b.rating IS NULL AND b.reading_status = 'read'";
        }

        // The hundred books with no ISBN are exactly the ones that cannot be
        // looked up automatically, so being able to list them is what makes
        // them findable at all.
        if (($filters['isbn'] ?? '') === 'yes') {
            $conditions[] = 'b.isbn13 IS NOT NULL';
        } elseif (($filters['isbn'] ?? '') === 'no') {
            $conditions[] = 'b.isbn13 IS NULL';
        }
        // Which books have been written about. The blog is the reason the
        // shelf exists, so "what have I already reviewed" and "what is still
        // waiting to be written up" are the two halves of a working queue.
        // An empty string counts as no review: the edit form stores NULL, but
        // an import or a hand-edited row need not have.
        if (($filters['review'] ?? '') === 'yes') {
            $conditions[] = "b.review_url IS NOT NULL AND b.review_url <> ''";
        } elseif (($filters['review'] ?? '') === 'no') {
            $conditions[] = "(b.review_url IS NULL OR b.review_url = '')";
        }
        // Covers arrive gradually, so "show me the ones that have one" and
        // "show me what still needs one" are both worth asking for.
        if (($filters['cover'] ?? '') === 'yes') {
            $conditions[] = 'EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id AND c.rejected_at IS NULL)';
        } elseif (($filters['cover'] ?? '') === 'no') {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id AND c.rejected_at IS NULL)';
        }

        return [['sql' => implode(' AND ', $conditions), 'join' => $join], $parameters];
    }

    /** @return array{with: int, without: int} */
    /** How many books there are at all, filters aside. */
    public function countAll(int $ownerId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM books WHERE owner_id = ?');
        $statement->execute([$ownerId]);

        return (int) $statement->fetchColumn();
    }

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
    public function countByReview(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                 SUM(CASE WHEN review_url IS NOT NULL AND review_url <> '' THEN 1 ELSE 0 END) AS with_review,
                 SUM(CASE WHEN review_url IS NULL OR review_url = '' THEN 1 ELSE 0 END) AS without_review
               FROM books WHERE owner_id = ?"
        );
        $statement->execute([$ownerId]);
        $row = $statement->fetch() ?: [];

        return [
            'with'    => (int) ($row['with_review'] ?? 0),
            'without' => (int) ($row['without_review'] ?? 0),
        ];
    }

    /** @return array{with: int, without: int} */
    public function countByCover(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                 SUM(CASE WHEN EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id AND c.rejected_at IS NULL) THEN 1 ELSE 0 END) AS with_cover,
                 SUM(CASE WHEN EXISTS (SELECT 1 FROM covers c WHERE c.book_id = b.id AND c.rejected_at IS NULL) THEN 0 ELSE 1 END) AS without_cover
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

    /** Fields a tag may be folded into. Anything else is not a field. */
    public const FILLABLE_FROM_TAG = ['binding', 'language'];

    /**
     * Write a value into one field for a set of books, where it is still empty.
     *
     * For the tags that are really a field in disguise: "Taschenbücher" says
     * what binding says, and 90 of its 92 books already say paperback. The
     * two that say hardcover are not corrected - a shop category is not
     * better evidence than the record - they are counted and reported, so
     * somebody can look.
     *
     * @param  list<int> $bookIds
     * @param  bool      $dryRun count what would happen and write nothing
     * @return array{filled: int, already: int, conflicting: int}
     */
    public function fillFieldFor(
        int $ownerId,
        array $bookIds,
        string $field,
        string $value,
        bool $dryRun = false
    ): array
    {
        if (!in_array($field, self::FILLABLE_FROM_TAG, true)) {
            throw new \InvalidArgumentException('Not a fillable field: ' . $field);
        }
        if ($bookIds === []) {
            return ['filled' => 0, 'already' => 0, 'conflicting' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));

        $read = $this->pdo->prepare(
            "SELECT id, $field AS value FROM books WHERE owner_id = ? AND id IN ($placeholders)"
        );
        $read->execute([$ownerId, ...$bookIds]);

        $empty = [];
        $already = 0;
        $conflicting = 0;
        foreach ($read->fetchAll() as $row) {
            $current = $row['value'];
            if ($current === null || $current === '') {
                $empty[] = (int) $row['id'];
            } elseif ((string) $current === $value) {
                $already++;
            } else {
                $conflicting++;
            }
        }

        if ($empty !== [] && !$dryRun) {
            $slots = implode(',', array_fill(0, count($empty), '?'));
            $write = $this->pdo->prepare(
                "UPDATE books SET $field = ? WHERE owner_id = ? AND id IN ($slots)"
            );
            $write->execute([$value, $ownerId, ...$empty]);
        }

        return ['filled' => count($empty), 'already' => $already, 'conflicting' => $conflicting];
    }

    /**
     * Everything the review matcher needs.
     *
     * Two queries and a loop rather than one query with GROUP_CONCAT: that
     * function takes its separator differently in MySQL and in SQLite, and
     * this project runs on both.
     *
     * @return list<array{id: int, isbn13: ?string, title: string, review_url: ?string, authors: list<string>}>
     */
    public function forReviewMatching(int $ownerId): array
    {
        $people = [];
        $names = $this->pdo->prepare(
            'SELECT ba.book_id, a.name
               FROM book_authors ba
               JOIN authors a ON a.id = ba.author_id
               JOIN books b ON b.id = ba.book_id
              WHERE b.owner_id = ?
           ORDER BY ba.position'
        );
        $names->execute([$ownerId]);
        foreach ($names->fetchAll() as $row) {
            $people[(int) $row['book_id']][] = (string) $row['name'];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, isbn13, title, review_url FROM books WHERE owner_id = ?'
        );
        $statement->execute([$ownerId]);

        return array_map(
            static fn (array $row): array => [
                'id'         => (int) $row['id'],
                'isbn13'     => $row['isbn13'],
                'title'      => (string) $row['title'],
                'review_url' => $row['review_url'],
                'authors'    => $people[(int) $row['id']] ?? [],
            ],
            $statement->fetchAll()
        );
    }

    /** @return int 1 when the row changed */
    public function setReviewUrl(int $ownerId, int $bookId, string $url): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE books SET review_url = ?, updated_at = ? WHERE id = ? AND owner_id = ?'
        );
        $statement->execute([$url, date('Y-m-d H:i:s'), $bookId, $ownerId]);

        return $statement->rowCount();
    }

    /**
     * Write an ISBN a book has been missing.
     *
     * Only ever into an empty field, and only the 13-digit form plus the 10
     * derived from it, so a book found through its review ends up looking
     * exactly like one found through a scanner.
     */
    public function setIsbnIfEmpty(int $ownerId, int $bookId, string $isbn13, ?string $isbn10): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE books SET isbn13 = ?, isbn10 = ?, updated_at = ?
              WHERE id = ? AND owner_id = ? AND (isbn13 IS NULL OR isbn13 = ?)'
        );
        $statement->execute([$isbn13, $isbn10, date('Y-m-d H:i:s'), $bookId, $ownerId, '']);

        return $statement->rowCount();
    }
}
