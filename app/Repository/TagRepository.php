<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Dialect;
use App\Core\Text;
use PDO;

/**
 * Genres and labels, both kept as free tags.
 *
 * The export carries 381 distinct strings, part real genres ("Fantasy") and
 * part everything else: age ranges, bindings, shop categories, and English
 * BISAC headings that arrived with Google's records. Forcing them into a
 * fixed vocabulary would throw information away, so they are all stored as
 * given and told apart by a kind.
 *
 * A tag is a label until somebody calls it a genre. That direction matters:
 * the other way round, every import would quietly grow the genre list again,
 * and a list of three hundred and eighty is not a filter but a haystack.
 */
final class TagRepository
{
    public const KIND_GENRE = 'genre';
    public const KIND_LABEL = 'label';

    private readonly Dialect $dialect;

    public function __construct(private readonly PDO $pdo)
    {
        $this->dialect = new Dialect($pdo);
    }

    /** @param bool|null $created set to true when the tag was newly inserted */
    public function findOrCreate(int $ownerId, string $name, ?bool &$created = null): int
    {
        $created = false;
        $name = trim($name);
        $slug = Text::slug($name, 190);

        $statement = $this->pdo->prepare('SELECT id FROM tags WHERE owner_id = ? AND slug = ?');
        $statement->execute([$ownerId, $slug]);
        $id = $statement->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $created = true;

        $insert = $this->pdo->prepare('INSERT INTO tags (owner_id, name, slug) VALUES (?, ?, ?)');
        $insert->execute([$ownerId, $name, $slug]);

        return (int) $this->pdo->lastInsertId();
    }

    public function link(int $bookId, int $tagId): void
    {
        $sql = $this->dialect->insertIgnore('book_tags', ['book_id', 'tag_id']);
        $this->pdo->prepare($sql)->execute([$bookId, $tagId]);
    }

    /**
     * Every tag this owner has, with how often it is used.
     *
     * Fed to the editor so a genre is picked from what already exists rather
     * than retyped. With 382 of them, one typo quietly makes it 383, and the
     * count is what tells "Fantasy, 173 books" apart from a near-miss that
     * someone created by accident last Tuesday.
     *
     * @return list<array{name: string, slug: string, n: int}>
     */
    public function allForOwner(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.name, t.slug, COUNT(bt.book_id) AS n
               FROM tags t LEFT JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ?
              GROUP BY t.id, t.name, t.slug
              ORDER BY n DESC, t.name ASC'
        );
        $statement->execute([$ownerId]);

        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'n'    => (int) $row['n'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * Every genre, in reading order rather than by size.
     *
     * The sidebar shows the biggest fourteen, which is the right answer for a
     * sidebar and the wrong one for "what is actually in here" - three hundred
     * and seventy of them were invisible. Sorted by name, because this list is
     * for finding a particular genre; the sidebar already answers which are
     * the common ones.
     *
     * @param  ?string $kind genre or label; null for both
     * @return list<array{id: int, name: string, slug: string, book_count: int}>
     */
    public function listAllByName(int $ownerId, ?string $kind = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name, t.slug, COUNT(bt.book_id) AS book_count
               FROM tags t
               JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ?' . ($kind === null ? '' : ' AND t.kind = ?') . '
              GROUP BY t.id, t.name, t.slug
              ORDER BY t.name ASC'
        );
        $statement->execute($kind === null ? [$ownerId] : [$ownerId, $kind]);

        /** @var list<array{id: int, name: string, slug: string, book_count: int}> */
        return $statement->fetchAll();
    }

    /** Tags that are actually on a book; an orphan tag is not one. */
    public function count(int $ownerId, ?string $kind = null): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM (
                 SELECT t.id FROM tags t
                   JOIN book_tags bt ON bt.tag_id = t.id
                  WHERE t.owner_id = ?' . ($kind === null ? '' : ' AND t.kind = ?') . '
                  GROUP BY t.id
             ) AS used'
        );
        $statement->execute($kind === null ? [$ownerId] : [$ownerId, $kind]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array{id: int, name: string, slug: string, book_count: int}> */
    public function listWithCounts(int $ownerId, int $limit = 40, ?string $kind = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name, t.slug, COUNT(bt.book_id) AS book_count
               FROM tags t
               JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ?' . ($kind === null ? '' : ' AND t.kind = ?') . '
              GROUP BY t.id, t.name, t.slug
              ORDER BY book_count DESC, t.name ASC
              LIMIT ' . (int) $limit
        );
        $statement->execute($kind === null ? [$ownerId] : [$ownerId, $kind]);

        /** @var list<array{id: int, name: string, slug: string, book_count: int}> */
        return $statement->fetchAll();
    }

    /**
     * Everything, with its kind, for the screen where the two are told apart.
     *
     * Ordered by how many books hang on it, because that is the order in
     * which the work pays off: the twenty largest tags carry more than half
     * of all the links, and the long tail of one-book tags can be left alone
     * for good.
     *
     * @return list<array{id: int, name: string, kind: string, book_count: int}>
     */
    public function listForSorting(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name, t.kind, COUNT(bt.book_id) AS book_count
               FROM tags t
          LEFT JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ?
              GROUP BY t.id, t.name, t.kind
              ORDER BY book_count DESC, t.name ASC'
        );
        $statement->execute([$ownerId]);

        /** @var list<array{id: int, name: string, kind: string, book_count: int}> */
        return $statement->fetchAll();
    }

    /**
     * Set exactly these tags to genre and every other one to label.
     *
     * The whole screen is saved at once rather than a tag at a time: a form
     * sends the boxes that are ticked and says nothing about the ones that
     * are not, so anything left out has to be read as "not a genre" - and
     * unticking would otherwise never take effect.
     *
     * @param  list<int> $genreIds
     * @return int how many tags are genres afterwards
     */
    public function setGenres(int $ownerId, array $genreIds): int
    {
        $genreIds = array_values(array_unique(array_map('intval', $genreIds)));

        $this->pdo->beginTransaction();
        try {
            $reset = $this->pdo->prepare('UPDATE tags SET kind = ? WHERE owner_id = ? AND kind <> ?');
            $reset->execute([self::KIND_LABEL, $ownerId, self::KIND_LABEL]);

            if ($genreIds !== []) {
                $placeholders = implode(',', array_fill(0, count($genreIds), '?'));
                $mark = $this->pdo->prepare(
                    "UPDATE tags SET kind = ? WHERE owner_id = ? AND id IN ($placeholders)"
                );
                $mark->execute([self::KIND_GENRE, $ownerId, ...$genreIds]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        /* How many are genres now, not how many rows the update touched:
         * every save rewrites the lot, and the drivers disagree about
         * whether an unchanged row counts as touched. The number somebody
         * wants to read back is the size of their genre list. */
        return $this->count($ownerId, self::KIND_GENRE);
    }
}
