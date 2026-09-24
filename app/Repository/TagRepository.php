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
    /**
     * @param string $kindIfNew what to create it as, if it does not exist yet.
     *                          Only ever applies to a tag being created: one
     *                          that is already on the shelf keeps the kind it
     *                          was given, whatever a form says today. That is
     *                          the whole rule - the question only exists at
     *                          the moment something new is made, which is why
     *                          the edit page only asks then.
     */
    public function findOrCreate(
        int $ownerId,
        string $name,
        ?bool &$created = null,
        string $kindIfNew = self::KIND_LABEL
    ): int {
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

        $insert = $this->pdo->prepare('INSERT INTO tags (owner_id, name, slug, kind) VALUES (?, ?, ?, ?)');
        $insert->execute([
            $ownerId,
            $name,
            $slug,
            $kindIfNew === self::KIND_GENRE ? self::KIND_GENRE : self::KIND_LABEL,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Put a tag on a book, honouring what was decided about that tag.
     *
     * The check sits here rather than in the importer because every path
     * leads through this method: import, scanner, and the edit form. A tag
     * removed by hand would otherwise come back on the next import, along
     * with the books that carried it, which is exactly what the removal was
     * meant to stop.
     *
     * A merged tag is not refused but followed: a book arriving with "Comic"
     * gets "Comics", because that is what the merge said should happen to
     * everything carrying the old name - not only to the books that happened
     * to be in the shelf that afternoon.
     */
    public function link(int $bookId, int $tagId): void
    {
        $tagId = $this->resolve($tagId);
        if ($tagId === null) {
            return;
        }

        $sql = $this->dialect->insertIgnore('book_tags', ['book_id', 'tag_id']);
        $this->pdo->prepare($sql)->execute([$bookId, $tagId]);
    }

    /**
     * Which tag a link should really point at: null when it was removed.
     *
     * Merges can chain - A into B, later B into C - so this follows the
     * trail, with a limit in case one ever points back at itself.
     */
    private function resolve(int $tagId): ?int
    {
        $statement = $this->pdo->prepare('SELECT dropped_at, merged_into FROM tags WHERE id = ?');

        for ($hops = 0; $hops < 10; $hops++) {
            $statement->execute([$tagId]);
            $row = $statement->fetch();
            if ($row === false) {
                return null;
            }
            if ($row['dropped_at'] === null) {
                return $tagId;
            }
            if ($row['merged_into'] === null) {
                return null;
            }
            $tagId = (int) $row['merged_into'];
        }

        return null;
    }

    /**
     * Every tag this owner has, with how often it is used.
     *
     * Fed to the editor so a genre is picked from what already exists rather
     * than retyped. With 382 of them, one typo quietly makes it 383, and the
     * count is what tells "Fantasy, 173 books" apart from a near-miss that
     * someone created by accident last Tuesday.
     *
     * @return list<array{name: string, slug: string, kind: string, n: int}>
     */
    public function allForOwner(int $ownerId): array
    {
        // kind travels with the name: the suggestion list used to show both
        // sorts side by side without saying which was which, so picking one
        // told you nothing about what you were picking.
        $statement = $this->pdo->prepare(
            'SELECT t.name, t.slug, t.kind, COUNT(bt.book_id) AS n
               FROM tags t LEFT JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ? AND t.dropped_at IS NULL
              GROUP BY t.id, t.name, t.slug, t.kind
              ORDER BY n DESC, t.name ASC'
        );
        $statement->execute([$ownerId]);

        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'kind' => (string) $row['kind'],
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

    /**
     * Put a tag back into use, and stop it forwarding.
     *
     * A restored tag stands on its own again: leaving merged_into set would
     * mean a name that is visible in the list while everything mentioning it
     * quietly lands somewhere else.
     */
    public function restore(int $ownerId, int $tagId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tags SET dropped_at = NULL, merged_into = NULL WHERE owner_id = ? AND id = ?'
        );
        $statement->execute([$ownerId, $tagId]);
    }

    /**
     * Delete a tag for good, with its links.
     *
     * The one thing here that cannot be undone, and the one thing that does
     * not survive an import: the name is free again afterwards, so an export
     * mentioning it creates a new tag. Right for something that was never
     * meant to exist, wrong for anything that was.
     */
    public function purge(int $ownerId, int $tagId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM book_tags WHERE tag_id IN (SELECT id FROM tags WHERE id = ? AND owner_id = ?)'
            )->execute([$tagId, $ownerId]);
            $this->pdo->prepare('UPDATE tags SET merged_into = NULL WHERE merged_into = ? AND owner_id = ?')
                ->execute([$tagId, $ownerId]);
            $this->pdo->prepare('DELETE FROM tags WHERE id = ? AND owner_id = ?')
                ->execute([$tagId, $ownerId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
                'UPDATE tags SET dropped_at = ?, merged_into = ? WHERE owner_id = ? AND id = ?'
            )->execute([date('Y-m-d H:i:s'), $intoId, $ownerId, $fromId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['moved' => $moved, 'already' => $already];
    }

    /**
     * Give a tag a different name, keeping every book on it.
     *
     * Only for correcting the name itself - a catalogue notation that should
     * never have been part of it. Where a tag under the corrected name
     * already exists this refuses rather than guessing, because two tags
     * cannot share a slug and merging is a different act with different
     * consequences: the caller decides which of the two it is doing.
     *
     * @return bool false when the name is taken
     */
    public function rename(int $ownerId, int $tagId, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }
        $slug = Text::slug($name, 190);

        $taken = $this->pdo->prepare('SELECT id FROM tags WHERE owner_id = ? AND slug = ? AND id <> ?');
        $taken->execute([$ownerId, $slug, $tagId]);
        if ($taken->fetchColumn() !== false) {
            return false;
        }

        $this->pdo->prepare('UPDATE tags SET name = ?, slug = ? WHERE owner_id = ? AND id = ?')
            ->execute([$name, $slug, $ownerId, $tagId]);

        return true;
    }

    /**
     * The tags a book shows: in use only, genres first, each kind by name.
     *
     * One query for the book page and the edit form, because both had their
     * own and neither asked whether a tag had been removed. A removed tag
     * keeps its links - that is what makes restoring it real - so a query
     * that reads book_tags alone hands it back: "Kinder- und
     * Jugendliteratur", removed from the whole shelf, stood on a book page
     * under the genres that replaced it.
     *
     * @return list<array{name: string, slug: string, kind: string}>
     */
    public function forBook(int $ownerId, int $bookId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT t.name, t.slug, t.kind
               FROM tags t
               JOIN book_tags bt ON bt.tag_id = t.id
              WHERE bt.book_id = ? AND t.owner_id = ? AND t.dropped_at IS NULL
              ORDER BY CASE WHEN t.kind = 'genre' THEN 0 ELSE 1 END, t.name"
        );
        $statement->execute([$bookId, $ownerId]);

        /** @var list<array{name: string, slug: string, kind: string}> */
        return $statement->fetchAll();
    }

    /**
     * Every tag this owner has, removed ones included, with what state each
     * is in. For a change to the whole list, which has to know about a
     * removed name before it tries to create it again.
     *
     * @return array<int, array{id: int, name: string, slug: string, kind: string, dropped_at: ?string}> by id
     */
    public function allWithState(int $ownerId): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, slug, kind, dropped_at FROM tags WHERE owner_id = ?');
        $statement->execute([$ownerId]);

        $tags = [];
        foreach ($statement->fetchAll() as $tag) {
            $tags[(int) $tag['id']] = $tag;
        }

        return $tags;
    }

    /**
     * Every link of every book, the links of removed tags included - a tag
     * that comes back brings them back with it.
     *
     * @return array<int, list<int>> tag ids by book id
     */
    public function linksByBook(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bt.book_id, bt.tag_id FROM book_tags bt JOIN books b ON b.id = bt.book_id WHERE b.owner_id = ?'
        );
        $statement->execute([$ownerId]);

        $links = [];
        foreach ($statement->fetchAll() as $link) {
            $links[(int) $link['book_id']][] = (int) $link['tag_id'];
        }

        return $links;
    }

    /**
     * The names that were removed, and for a merged one the tag it leads to.
     *
     * For the tag field of the edit form. A removed name typed there looked
     * like a new label, and saving took it off again without a word - link()
     * refuses a removed tag, which is right, and the form said nothing, which
     * was not. A merged name is not refused but followed, so the form can say
     * where it will land.
     *
     * @return list<array{name: string, slug: string, into: ?string}>
     */
    public function removedNames(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, slug, dropped_at, merged_into FROM tags WHERE owner_id = ? ORDER BY name'
        );
        $statement->execute([$ownerId]);
        $all = [];
        foreach ($statement->fetchAll() as $tag) {
            $all[(int) $tag['id']] = $tag;
        }

        $removed = [];
        foreach ($all as $tag) {
            if ($tag['dropped_at'] === null) {
                continue;
            }
            // The same trail resolve() follows, read from what is loaded.
            $into = null;
            $next = $tag['merged_into'];
            for ($hops = 0; $hops < 10 && $next !== null && isset($all[(int) $next]); $hops++) {
                $target = $all[(int) $next];
                if ($target['dropped_at'] === null) {
                    $into = (string) $target['name'];
                    break;
                }
                $next = $target['merged_into'];
            }
            $removed[] = ['name' => (string) $tag['name'], 'slug' => (string) $tag['slug'], 'into' => $into];
        }

        return $removed;
    }

    /**
     * Which of these names a book cannot be given, because they were removed
     * and lead nowhere.
     *
     * @param list<string> $names
     * @return list<string> the names as they were typed
     */
    public function refusedAmong(int $ownerId, array $names): array
    {
        $refused = [];
        foreach ($this->removedNames($ownerId) as $tag) {
            if ($tag['into'] === null) {
                $refused[$tag['slug']] = true;
            }
        }

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => isset($refused[Text::slug($name, 190)])
        ));
    }

    /**
     * One book's links as they stand right now, and whether each tag is removed.
     *
     * @return list<array{tag_id: int, dropped: bool}>
     */
    public function linksOf(int $ownerId, int $bookId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bt.tag_id, t.dropped_at FROM book_tags bt JOIN tags t ON t.id = bt.tag_id
              WHERE bt.book_id = ? AND t.owner_id = ?'
        );
        $statement->execute([$bookId, $ownerId]);

        return array_map(
            static fn (array $row): array => ['tag_id' => (int) $row['tag_id'], 'dropped' => $row['dropped_at'] !== null],
            $statement->fetchAll()
        );
    }

    /** Take one tag off one book. */
    public function unlink(int $ownerId, int $bookId, int $tagId): void
    {
        $this->pdo->prepare(
            'DELETE FROM book_tags WHERE book_id = ? AND tag_id IN (SELECT id FROM tags WHERE id = ? AND owner_id = ?)'
        )->execute([$bookId, $tagId, $ownerId]);
    }

    /**
     * Make one tag a genre or a label.
     *
     * setKinds() does the same for many at once inside a transaction of its
     * own, which cannot be used from inside another.
     */
    public function setKind(int $ownerId, int $tagId, string $kind): void
    {
        $this->pdo->prepare('UPDATE tags SET kind = ? WHERE owner_id = ? AND id = ?')->execute([
            $kind === self::KIND_GENRE ? self::KIND_GENRE : self::KIND_LABEL,
            $ownerId,
            $tagId,
        ]);
    }

    /**
     * Run a piece of work all at once or not at all.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function atomically(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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

    /** The name behind a tag's address, while the tag is in use. */
    public function nameBySlug(int $ownerId, string $slug): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT name FROM tags WHERE owner_id = ? AND slug = ? AND dropped_at IS NULL LIMIT 1'
        );
        $statement->execute([$ownerId, $slug]);
        $found = $statement->fetchColumn();

        return $found === false ? null : (string) $found;
    }

    /**
     * Genres and labels per book, and only the ones in use.
     *
     * A removed tag keeps its links - that is what makes removing it
     * reversible - so asking book_tags alone hands them all back. They came
     * out in every export: "collection:Forgotten Realms", taken off the shelf
     * by hand, stood at its book again in the backup, and after a merge a book
     * carried the old name beside the new one, because merging copies the
     * links and drops the source.
     *
     * @return array<int, array{genres?: list<string>, labels?: list<string>}>
     */
    public function namesByBook(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bt.book_id, t.name, t.kind
               FROM book_tags bt
               JOIN tags t ON t.id = bt.tag_id
               JOIN books b ON b.id = bt.book_id
              WHERE b.owner_id = ? AND t.dropped_at IS NULL
              ORDER BY bt.book_id ASC, t.name ASC'
        );
        $statement->execute([$ownerId]);

        $byBook = [];
        foreach ($statement->fetchAll() as $row) {
            $kind = $row['kind'] === self::KIND_GENRE ? 'genres' : 'labels';
            $byBook[(int) $row['book_id']][$kind][] = (string) $row['name'];
        }

        return $byBook;
    }
}
