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

    /**
     * Everyone, sorted the way a shelf is: by surname.
     *
     * sort_name is what the normalisation produced when the CSV was read, so
     * "Flechsig, Dorothea" and "Dorothea Flechsig" - one person - sort to one
     * place instead of to F and to D.
     *
     * @return list<array{id: int, name: string, sort_name: string, book_count: int}>
     */
    public function listAllByName(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.name, a.sort_name, COUNT(ba.book_id) AS book_count
               FROM authors a
               JOIN book_authors ba ON ba.author_id = a.id
              WHERE a.owner_id = ?
              GROUP BY a.id, a.name, a.sort_name
              ORDER BY a.sort_name ASC'
        );
        $statement->execute([$ownerId]);

        /** @var list<array{id: int, name: string, sort_name: string, book_count: int}> */
        return $statement->fetchAll();
    }

    /**
     * The biggest authors, for the sidebar facet that is headed with that word.
     *
     * Same rule as count(), and the count beside each name is books they
     * wrote - a translator with forty translations does not belong at the top
     * of a list called authors, and the number next to somebody who both
     * wrote and translated has to mean one thing or the other.
     *
     * Reaching a translator's books stays possible: the filter behind these
     * links matches a person whatever they did on the book, so the link on a
     * book page still works. It is the list that is about authors.
     *
     * @return list<array{id: int, name: string, sort_name: string, book_count: int}>
     */
    public function listWithCounts(int $ownerId, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.id, a.name, a.sort_name, COUNT(ba.book_id) AS book_count
               FROM authors a
               JOIN book_authors ba ON ba.author_id = a.id AND ba.role = 'author'
              WHERE a.owner_id = ?
              GROUP BY a.id, a.name, a.sort_name
              ORDER BY book_count DESC, a.sort_name ASC
              LIMIT " . (int) $limit
        );
        $statement->execute([$ownerId]);

        /** @var list<array{id: int, name: string, sort_name: string, book_count: int}> */
        return $statement->fetchAll();
    }

    /**
     * How many people wrote something on this shelf.
     *
     * Authors, and only authors. The table holds everyone who worked on a
     * book - translators, illustrators, editors - and counting the lot under
     * a heading that says "Autor:innen" is simply a wrong number. On a large
     * shelf it hides: 1,777 people, 1,769 of them authors, and nobody was
     * ever going to notice the eight. On a new one it is the whole number -
     * one book with an author and a translator reported two authors.
     *
     * Somebody who translated one book and wrote another counts once, as an
     * author, which is what the EXISTS is for.
     */
    public function count(int $ownerId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM authors a
              WHERE a.owner_id = ?
                AND EXISTS (
                      SELECT 1 FROM book_authors ba
                       WHERE ba.author_id = a.id AND ba.role = 'author'
                    )"
        );
        $statement->execute([$ownerId]);

        return (int) $statement->fetchColumn();
    }
}
