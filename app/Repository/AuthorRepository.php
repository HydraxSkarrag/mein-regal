<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Dialect;
use App\Core\Text;
use PDO;

/**
 * People. Every lookup goes through the match key, which is what makes
 * "Flechsig, Dorothea" and "Dorothea Flechsig" one person rather than two.
 */
final class AuthorRepository
{
    private readonly Dialect $dialect;

    public function __construct(private readonly PDO $pdo)
    {
        $this->dialect = new Dialect($pdo);
    }

    /**
     * Returns the id, creating the person on first sight.
     *
     * @param bool|null $created set to true when a new person was inserted,
     *                           so callers can report it without counting rows
     */
    public function findOrCreate(int $ownerId, string $name, ?bool &$created = null): int
    {
        $created = false;
        $name = Text::tidyName($name);
        $key = Text::authorMatchKey($name);

        $existing = $this->findIdByKey($ownerId, $key);
        if ($existing !== null) {
            return $existing;
        }
        $created = true;

        $statement = $this->pdo->prepare(
            'INSERT INTO authors (owner_id, name, sort_name, match_key) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$ownerId, $name, Text::sortName($name), $key]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findIdByKey(int $ownerId, string $matchKey): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM authors WHERE owner_id = ? AND match_key = ?'
        );
        $statement->execute([$ownerId, $matchKey]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function link(int $bookId, int $authorId, string $role, int $position): void
    {
        $sql = $this->dialect->insertIgnore(
            'book_authors',
            ['book_id', 'author_id', 'role', 'position']
        );
        $this->pdo->prepare($sql)->execute([$bookId, $authorId, $role, $position]);
    }

    /** @return list<array{id: int, name: string, sort_name: string, book_count: int}> */
    public function listWithCounts(int $ownerId, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.name, a.sort_name, COUNT(ba.book_id) AS book_count
               FROM authors a
               JOIN book_authors ba ON ba.author_id = a.id
              WHERE a.owner_id = ?
              GROUP BY a.id, a.name, a.sort_name
              ORDER BY book_count DESC, a.sort_name ASC
              LIMIT ' . (int) $limit
        );
        $statement->execute([$ownerId]);

        /** @var list<array{id: int, name: string, sort_name: string, book_count: int}> */
        return $statement->fetchAll();
    }

    public function count(int $ownerId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM authors WHERE owner_id = ?');
        $statement->execute([$ownerId]);

        return (int) $statement->fetchColumn();
    }
}
