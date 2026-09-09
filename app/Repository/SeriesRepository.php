<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Text;
use PDO;

/**
 * Book series.
 *
 * A book is in at most one, so this hangs off books by a foreign key and
 * needs no join table - which is the one structural difference from tags, and
 * the reason none of the merge machinery over there is repeated here.
 *
 * It is a table rather than a column on books because two things belong to
 * the series and to no book in it. How many volumes there are: "Band 6 von
 * 12" needs a twelve, and owning three of a trilogy looks exactly like owning
 * three of a septology from the inside. And which counting applies: the
 * catalogue does not agree with itself - "Der Rhythmus des Krieges" is volume
 * 7 in the Heyne records and 8 in the audio and e-book ones, same text - so
 * there has to be one place where the owner decides rather than whichever
 * record answered first.
 *
 * The third reason is the one this project already learned: a name that is
 * typed is a name that gets typed twice, and 385 genre strings are the local
 * evidence. Everything that writes a series goes through findOrCreate.
 */
final class SeriesRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The series under this name, if the shelf already keeps one.
     *
     * Never makes one. The catalogue's series statement is not evidence
     * enough to found a series with: MARC 490 holds the publisher's own
     * numbered line in the same field as a work series, and "dtv 13697" for
     * an ISBN ending 13697-6 came through as a series called dtv. A shelf
     * grows names it was never told about that way, and the owner is left
     * deleting them.
     *
     * So a record may put a book into a series that is already there, and
     * only a person may start one.
     */
    public function findByName(int $ownerId, string $name): ?int
    {
        $slug = Text::slug(Text::tidyName($name), 190);
        if ($slug === '') {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT id FROM series WHERE owner_id = ? AND slug = ?');
        $statement->execute([$ownerId, $slug]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * The series under this name, made if it is not there yet.
     *
     * Matched on the slug, so "Die Sturmlicht-Chroniken" and "Die
     * Sturmlicht Chroniken" are the same series and not two.
     */
    public function findOrCreate(int $ownerId, string $name, ?bool &$created = null): ?int
    {
        $created = false;
        $name = Text::tidyName($name);
        if ($name === '') {
            return null;
        }
        $slug = Text::slug($name, 190);
        if ($slug === '') {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT id FROM series WHERE owner_id = ? AND slug = ?');
        $statement->execute([$ownerId, $slug]);
        $id = $statement->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $created = true;
        $this->pdo->prepare('INSERT INTO series (owner_id, name, slug) VALUES (?, ?, ?)')
            ->execute([$ownerId, $name, $slug]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{id: int, name: string, slug: string, total: ?int, note: ?string}|null */
    public function find(int $ownerId, int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, slug, total, note FROM series WHERE owner_id = ? AND id = ?'
        );
        $statement->execute([$ownerId, $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array{id: int, name: string, slug: string, total: ?int, note: ?string}|null */
    public function bySlug(int $ownerId, string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, slug, total, note FROM series WHERE owner_id = ? AND slug = ?'
        );
        $statement->execute([$ownerId, $slug]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every series with how many books of it are on the shelf.
     *
     * Left joined: a series whose last book was given away still exists and
     * should say so, rather than disappearing from the list the moment it
     * empties. That is how the tag list used to hide its own orphans.
     *
     * @return list<array{id: int, name: string, slug: string, total: ?int, owned: int}>
     */
    public function listForOwner(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            /* The same count as countVolumes(), in SQL, because this page
               asks it of every series at once. A Sammelband counts for the
               volumes it holds; a book with no numbers counts for itself. */
            'SELECT s.id, s.name, s.slug, s.total,
                    COALESCE(SUM(CASE
                        WHEN b.id IS NULL THEN 0
                        WHEN b.series_index IS NOT NULL AND b.series_index_end IS NOT NULL
                            THEN b.series_index_end - b.series_index + 1
                        ELSE 1
                    END), 0) AS owned
               FROM series s
          LEFT JOIN books b ON b.series_id = s.id AND b.owner_id = s.owner_id
              WHERE s.owner_id = ?
           GROUP BY s.id, s.name, s.slug, s.total
           ORDER BY s.name ASC'
        );
        $statement->execute([$ownerId]);

        /** @var list<array{id: int, name: string, slug: string, total: ?int, owned: int}> */
        return $statement->fetchAll();
    }

    /**
     * The volumes of one series, in volume order.
     *
     * Unnumbered ones last rather than first: a book somebody has not got
     * round to numbering belongs at the end of the list, not in front of
     * volume one.
     *
     * @return list<array<string, mixed>>
     */
    public function volumes(int $ownerId, int $seriesId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM books
              WHERE owner_id = ? AND series_id = ?
           ORDER BY series_index IS NULL, series_index ASC, title ASC'
        );
        $statement->execute([$ownerId, $seriesId]);

        return $statement->fetchAll();
    }

    /** Rename, or set the total and the note. Only what is given is written. */
    public function update(int $ownerId, int $id, array $data): bool
    {
        $assignments = [];
        $values = [];

        if (array_key_exists('name', $data)) {
            $name = Text::tidyName((string) $data['name']);
            if ($name === '') {
                return false;
            }
            $assignments[] = 'name = ?';
            $values[] = $name;
            $assignments[] = 'slug = ?';
            $values[] = Text::slug($name, 190);
        }
        foreach (['total', 'note'] as $column) {
            if (array_key_exists($column, $data)) {
                $assignments[] = $column . ' = ?';
                $values[] = $data[$column];
            }
        }
        if ($assignments === []) {
            return false;
        }

        $values[] = $id;
        $values[] = $ownerId;
        $statement = $this->pdo->prepare(
            'UPDATE series SET ' . implode(', ', $assignments) . ' WHERE id = ? AND owner_id = ?'
        );
        $statement->execute($values);

        return $statement->rowCount() > 0;
    }

    /**
     * Forget a series. The books stay, they are simply no longer in one.
     *
     * No tombstone, unlike a tag: nothing recreates a series behind the
     * owner's back, because nothing writes one except a form somebody filled
     * in or a catalogue record somebody accepted.
     */
    public function delete(int $ownerId, int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE books SET series_id = NULL, series_index = NULL, series_index_end = NULL
                  WHERE owner_id = ? AND series_id = ?'
            )->execute([$ownerId, $id]);
            $this->pdo->prepare('DELETE FROM series WHERE owner_id = ? AND id = ?')
                ->execute([$ownerId, $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * How many volumes stand on the shelf, which is not how many books do.
     *
     * The label says "Bände", and one Sammelband is several of them. Counting
     * books would have a complete ten volume series report five, with nothing
     * missing underneath it - two numbers on one page contradicting each
     * other, and neither of them wrong on its own terms.
     *
     * @param list<array<string, mixed>> $volumes
     */
    public static function countVolumes(array $volumes): int
    {
        $count = 0;
        foreach ($volumes as $volume) {
            $index = $volume['series_index'] ?? null;
            $end = $volume['series_index_end'] ?? null;
            $count += $index !== null && $end !== null
                ? max(1, (int) floor((float) $end) - (int) floor((float) $index) + 1)
                : 1;
        }

        return $count;
    }

    /**
     * Which volume numbers are missing between the ones on the shelf.
     *
     * The useful thing a series page can say. Counted up to the stated total
     * where there is one, and otherwise only between the volumes actually
     * held - without a total, "what comes after the last one I own" is not a
     * gap, it is simply the future.
     *
     * Half steps are skipped: 4.5 exists where a novella does, and its
     * absence is not a hole in the numbering.
     *
     * @param  list<array<string, mixed>> $volumes
     * @return list<int>
     */
    public static function gaps(array $volumes, ?int $total): array
    {
        $have = [];
        $highest = 0;
        foreach ($volumes as $volume) {
            $index = $volume['series_index'] ?? null;
            if ($index === null) {
                continue;
            }
            /* A Sammelband is one book and several volumes, and every volume
               between its two numbers is on the shelf. Entering only the
               first of them was what made this list name a book that is
               standing right there. */
            $end = $volume['series_index_end'] ?? null;
            $highest = max($highest, (int) floor((float) ($end ?? $index)));
            if ((float) $index != (int) (float) $index) {
                continue;
            }
            $first = (int) (float) $index;
            $last = $end !== null ? (int) floor((float) $end) : $first;
            for ($held = $first; $held <= $last; $held++) {
                $have[$held] = true;
            }
        }
        if ($have === [] && $highest === 0) {
            return [];
        }

        /* The half steps count towards how far the shelf reaches even though
           they are never missing themselves. Owning 1, 2 and the novella at
           4.5 means 3 and 4 are holes - reading only the whole numbers would
           stop at 2 and report a complete run. */
        $last = $total !== null && $total > 0 ? $total : $highest;

        $missing = [];
        for ($volume = 1; $volume <= $last; $volume++) {
            if (!isset($have[$volume])) {
                $missing[] = $volume;
            }
        }

        return $missing;
    }
}
