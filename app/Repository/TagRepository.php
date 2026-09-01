<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Dialect;
use App\Core\Text;
use PDO;

/**
 * Genres and categories, kept as free tags.
 *
 * The export carries 385 distinct strings, part real genres ("Fantasy") and
 * part shop categories ("Taschenbücher"). Forcing them into a fixed vocabulary
 * would throw information away, so they are stored as given and tidied later
 * from the dashboard.
 */
final class TagRepository
{
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
     * @return list<array{id: int, name: string, slug: string, book_count: int}>
     */
    public function listAllByName(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name, t.slug, COUNT(bt.book_id) AS book_count
               FROM tags t
               JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ?
              GROUP BY t.id, t.name, t.slug
              ORDER BY t.name ASC'
        );
        $statement->execute([$ownerId]);

        /** @var list<array{id: int, name: string, slug: string, book_count: int}> */
        return $statement->fetchAll();
    }

    /** @return list<array{id: int, name: string, slug: string, book_count: int}> */
    public function listWithCounts(int $ownerId, int $limit = 40): array
    {
        $statement = $this->pdo->prepare(
            'SELECT t.id, t.name, t.slug, COUNT(bt.book_id) AS book_count
               FROM tags t
               JOIN book_tags bt ON bt.tag_id = t.id
              WHERE t.owner_id = ?
              GROUP BY t.id, t.name, t.slug
              ORDER BY book_count DESC, t.name ASC
              LIMIT ' . (int) $limit
        );
        $statement->execute([$ownerId]);

        /** @var list<array{id: int, name: string, slug: string, book_count: int}> */
        return $statement->fetchAll();
    }
}
