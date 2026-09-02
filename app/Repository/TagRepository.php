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

    /**
     * Put a tag on a book - unless the tag has been thrown out.
     *
     * The check sits here rather than in the importer because every path
     * leads through this method: import, scanner, and the edit form. A tag
     * removed by hand would otherwise come back on the next import, along
     * with the books that carried it, which is exactly what the removal was
     * meant to stop.
     */
    public function link(int $bookId, int $tagId): void
    {
        $dropped = $this->pdo->prepare('SELECT 1 FROM tags WHERE id = ? AND dropped_at IS NOT NULL');
        $dropped->execute([$tagId]);
        if ($dropped->fetchColumn() !== false) {
            return;
        }

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
              WHERE t.owner_id = ? AND t.dropped_at IS NULL
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
              WHERE t.owner_id = ? AND t.dropped_at IS NULL'
                . ($kind === null ? '' : ' AND t.kind = ?') . '
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
                  WHERE t.owner_id = ? AND t.dropped_at IS NULL'
                . ($kind === null ? '' : ' AND t.kind = ?') . '
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
              WHERE t.owner_id = ? AND t.dropped_at IS NULL'
                . ($kind === null ? '' : ' AND t.kind = ?') . '
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
     * Removed tags are listed too, marked as such: the point of keeping the
     * row is that somebody can change their mind, and a list that hides them
     * offers no way back.
     *
     * @return list<array{id: int, name: string, kind: string, dropped_at: ?string, book_count: int}>
     */
    public function listForSorting(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name, t.kind, t.dropped_at, COUNT(bt.book_id) AS book_count
               FROM tags t
          LEFT JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ?
              GROUP BY t.id, t.name, t.kind, t.dropped_at
              ORDER BY book_count DESC, t.name ASC'
        );
        $statement->execute([$ownerId]);

        /** @var list<array{id: int, name: string, kind: string, dropped_at: ?string, book_count: int}> */
        return $statement->fetchAll();
    }

    /**
     * Write the kind of every tag the caller names.
     *
     * A map of id to "is a genre", and only those ids are touched. The
     * earlier version took the ticked boxes alone and demoted everything
     * else, which is correct exactly as long as the form carries every tag
     * there is - and quietly wrong the day somebody filters or pages that
     * screen. The form says what it means about each tag instead.
     *
     * @param  array<int, bool> $genreById
     * @return int how many tags are genres afterwards
     */
    public function setKinds(int $ownerId, array $genreById): int
    {
        $wanted = [self::KIND_GENRE => [], self::KIND_LABEL => []];
        foreach ($genreById as $id => $isGenre) {
            $wanted[$isGenre ? self::KIND_GENRE : self::KIND_LABEL][] = (int) $id;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($wanted as $kind => $ids) {
                if ($ids === []) {
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $statement = $this->pdo->prepare(
                    "UPDATE tags SET kind = ? WHERE owner_id = ? AND kind <> ? AND id IN ($placeholders)"
                );
                $statement->execute([$kind, $ownerId, $kind, ...$ids]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        /* How many are genres now, not how many rows an update touched: the
         * drivers disagree about whether an unchanged row counts as touched,
         * and the number somebody wants to read back is the size of their
         * genre list. */
        return $this->count($ownerId, self::KIND_GENRE);
    }

    /** One tag with its book count, dropped or not. */
    public function find(int $ownerId, int $tagId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name, t.slug, t.kind, t.dropped_at, COUNT(bt.book_id) AS book_count
               FROM tags t
          LEFT JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ? AND t.id = ?
              GROUP BY t.id, t.name, t.slug, t.kind, t.dropped_at'
        );
        $statement->execute([$ownerId, $tagId]);

        return $statement->fetch() ?: null;
    }

    /**
     * Take a tag out of use without destroying anything.
     *
     * The links in book_tags stay exactly as they are; only the tag is
     * marked. Nothing shows it any more, no import re-links it, and putting
     * it back is one update - which is the whole reason for not deleting.
     */
    public function drop(int $ownerId, int $tagId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tags SET dropped_at = ? WHERE owner_id = ? AND id = ? AND dropped_at IS NULL'
        );
        $statement->execute([date('Y-m-d H:i:s'), $ownerId, $tagId]);
    }

    public function restore(int $ownerId, int $tagId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tags SET dropped_at = NULL WHERE owner_id = ? AND id = ?'
        );
        $statement->execute([$ownerId, $tagId]);
    }

    /**
     * Fold one tag into another.
     *
     * The links are COPIED rather than moved, and the source is then dropped.
     * Moving them would make the merge the one step here that cannot be
     * undone; copying leaves the source exactly as it was, so restoring it
     * brings its books back with it. What stays behind after such an undo is
     * that the target keeps the copies - which is what undoing it by hand
     * would have left too.
     *
     * @return array{moved: int, already: int} how many gained the target tag,
     *                                         and how many already had it
     */
    public function merge(int $ownerId, int $fromId, int $intoId): array
    {
        if ($fromId === $intoId) {
            return ['moved' => 0, 'already' => 0];
        }

        $this->pdo->beginTransaction();
        try {
            $books = $this->pdo->prepare(
                'SELECT bt.book_id FROM book_tags bt
                   JOIN tags t ON t.id = bt.tag_id
                  WHERE bt.tag_id = ? AND t.owner_id = ?'
            );
            $books->execute([$fromId, $ownerId]);
            $bookIds = array_map('intval', $books->fetchAll(PDO::FETCH_COLUMN));

            $has = $this->pdo->prepare('SELECT 1 FROM book_tags WHERE book_id = ? AND tag_id = ?');
            $insert = $this->pdo->prepare(
                $this->dialect->insertIgnore('book_tags', ['book_id', 'tag_id'])
            );

            $moved = 0;
            $already = 0;
            foreach ($bookIds as $bookId) {
                $has->execute([$bookId, $intoId]);
                if ($has->fetchColumn() !== false) {
                    $already++;
                    continue;
                }
                $insert->execute([$bookId, $intoId]);
                $moved++;
            }

            $this->pdo->prepare(
                'UPDATE tags SET dropped_at = ? WHERE owner_id = ? AND id = ?'
            )->execute([date('Y-m-d H:i:s'), $ownerId, $fromId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['moved' => $moved, 'already' => $already];
    }

    /** The books carrying a tag, for a preview before anything is written. */
    public function bookIdsFor(int $ownerId, int $tagId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bt.book_id FROM book_tags bt
               JOIN books b ON b.id = bt.book_id
              WHERE bt.tag_id = ? AND b.owner_id = ?'
        );
        $statement->execute([$tagId, $ownerId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
