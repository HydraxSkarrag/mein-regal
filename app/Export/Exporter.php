<?php
declare(strict_types=1);

namespace App\Export;

use App\Core\Isbn;
use DateTimeImmutable;
use PDO;

/**
 * Getting the collection back out.
 *
 * This exists because of how the project started: a service shut down and
 * left a CSV file behind. The whole catalogue has to be readable without this
 * application, in a format that outlives it - and it has to be possible to
 * check that, rather than assume it.
 *
 * Three shapes, for three reasons:
 *
 *   bookstats  the original export's 19 columns, Latin-1 and semicolons and
 *              all. Lossy, since it predates half these fields, but it reads
 *              back in through this application's own importer - which is
 *              what makes it a way out rather than a claim.
 *   full       every column, UTF-8. The everyday backup.
 *   json       everything, including contributors, tags and cover sources.
 */
final class Exporter
{
    /** The original export's columns, in its order. */
    public const BOOKSTATS_COLUMNS = [
        'Titel', 'Autor(en)', 'ISBN', 'ASIN', 'Erscheinungsjahr', 'Genre',
        'Seitenanzahl', 'Dauer (Stunden)', 'Dauer (Minuten)', 'Buchart',
        'Preis', 'Erhalten als', 'Lesestatus', 'Lesebeginn', 'Leseende',
        'Bewertung', 'Kategorie', 'Notizen', 'Erhalten am',
    ];

    private const STATUS_OUT = [
        'read' => 'Gelesen', 'unread' => 'Ungelesen',
        'abandoned' => 'Abgebrochen', 'reading' => 'Am lesen',
    ];

    private const BINDING_OUT = [
        'hardcover' => 'Hardcover', 'paperback' => 'Taschenbuch',
        'ebook' => 'E-Book', 'audiobook' => 'Hörbuch',
    ];

    private const ACQUIRED_OUT = [
        'purchase' => 'Kauf', 'review_copy' => 'Rezensionsexemplar',
        'gift' => 'Geschenk', 'prize' => 'Gewinn',
        'loan' => 'Leihe', 'swap' => 'Tausch',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return iterable<array<string,mixed>> one row per book, with people and tags */
    public function books(int $ownerId): iterable
    {
        $statement = $this->pdo->prepare('SELECT * FROM books WHERE owner_id = ? ORDER BY id ASC');
        $statement->execute([$ownerId]);

        $people = $this->contributorsByBook($ownerId);
        $tags = $this->tagsByBook($ownerId);
        $covers = $this->coversByBook($ownerId);

        while (($book = $statement->fetch()) !== false) {
            $id = (int) $book['id'];
            $book['contributors'] = $people[$id] ?? [];
            $book['tags'] = $tags[$id] ?? [];
            $book['covers'] = $covers[$id] ?? [];

            yield $book;
        }
    }

    /**
     * The original format: Latin-1, semicolons, CRLF, everything quoted.
     *
     * Characters Latin-1 cannot carry are transliterated rather than dropped,
     * so an ellipsis becomes "..." instead of turning into a question mark
     * nobody notices.
     *
     * @param resource $handle
     */
    public function bookstatsCsv(int $ownerId, $handle): int
    {
        fwrite($handle, $this->latin1($this->csvLine(self::BOOKSTATS_COLUMNS)));

        $count = 0;
        foreach ($this->books($ownerId) as $book) {
            $authors = implode(', ', array_column(
                array_filter($book['contributors'], static fn (array $p): bool => $p['role'] === 'author'),
                'name'
            ));
            $audio = (int) ($book['audio_minutes'] ?? 0);

            fwrite($handle, $this->latin1($this->csvLine([
                (string) $book['title'],
                $authors,
                (string) ($book['isbn13'] ?? ''),
                (string) ($book['isbn10'] ?? ''),
                // The original wrote 0 for a missing value, and reading it
                // back relies on that: 0 means "not set", not zero.
                (string) ($book['published_year'] ?? 0),
                $book['tags'][0] ?? '',
                (string) ($book['page_count'] ?? 0),
                (string) intdiv($audio, 60),
                (string) ($audio % 60),
                self::BINDING_OUT[$book['binding'] ?? ''] ?? '',
                number_format((float) ($book['price'] ?? 0), 2, ',', ''),
                self::ACQUIRED_OUT[$book['acquisition_type'] ?? ''] ?? '',
                self::STATUS_OUT[$book['reading_status'] ?? ''] ?? '',
                $this->germanDate($book['started_at'] ?? null),
                $this->germanDate($book['finished_at'] ?? null),
                (string) ($book['rating'] ?? 0),
                '',
                (string) ($book['notes'] ?? ''),
                $this->germanDate($book['acquired_at'] ?? null),
            ])));
            $count++;
        }

        return $count;
    }

    /**
     * Every column, UTF-8 with a byte order mark so spreadsheets open it
     * without mangling the umlauts.
     *
     * @param resource $handle
     */
    public function fullCsv(int $ownerId, $handle): int
    {
        $columns = [
            'id', 'isbn13', 'isbn10', 'title', 'subtitle', 'authors', 'contributors',
            'publisher', 'published_year', 'page_count', 'language', 'binding',
            'price', 'price_currency', 'acquisition_type', 'acquired_at',
            'acquired_at_is_bulk', 'reading_status', 'started_at', 'finished_at',
            'rating', 'tags', 'notes', 'review_url', 'audio_minutes',
            'cover_sources', 'slug', 'created_at', 'updated_at',
        ];

        fwrite($handle, "\u{FEFF}");
        fwrite($handle, $this->csvLine($columns, ','));

        $count = 0;
        foreach ($this->books($ownerId) as $book) {
            $authors = array_filter($book['contributors'], static fn (array $p): bool => $p['role'] === 'author');
            $others = array_filter($book['contributors'], static fn (array $p): bool => $p['role'] !== 'author');

            $row = [];
            foreach ($columns as $column) {
                $row[] = match ($column) {
                    'authors'       => implode('; ', array_column($authors, 'name')),
                    'contributors'  => implode('; ', array_map(
                        static fn (array $p): string => $p['name'] . ' (' . $p['role'] . ')',
                        $others
                    )),
                    'tags'          => implode('; ', $book['tags']),
                    'cover_sources' => implode('; ', $book['covers']),
                    default         => (string) ($book[$column] ?? ''),
                };
            }
            fwrite($handle, $this->csvLine($row, ','));
            $count++;
        }

        return $count;
    }

    /** @return array{exported_at: string, count: int, books: list<array<string,mixed>>} */
    public function json(int $ownerId): array
    {
        $books = [];
        foreach ($this->books($ownerId) as $book) {
            unset($book['owner_id']);
            $book['isbn_formatted'] = $book['isbn13'] !== null
                ? Isbn::format((string) $book['isbn13'])
                : null;
            $books[] = $book;
        }

        return [
            'exported_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'count'       => count($books),
            'books'       => $books,
        ];
    }

    // ------------------------------------------------------------- helpers

    /** @return array<int, list<array{name: string, role: string}>> */
    private function contributorsByBook(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ba.book_id, a.name, ba.role
               FROM book_authors ba
               JOIN authors a ON a.id = ba.author_id
               JOIN books b ON b.id = ba.book_id
              WHERE b.owner_id = ?
              ORDER BY ba.book_id ASC, ba.position ASC'
        );
        $statement->execute([$ownerId]);

        $byBook = [];
        foreach ($statement->fetchAll() as $row) {
            $byBook[(int) $row['book_id']][] = [
                'name' => (string) $row['name'],
                'role' => (string) $row['role'],
            ];
        }

        return $byBook;
    }

    /** @return array<int, list<string>> */
    private function tagsByBook(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bt.book_id, t.name
               FROM book_tags bt
               JOIN tags t ON t.id = bt.tag_id
               JOIN books b ON b.id = bt.book_id
              WHERE b.owner_id = ?
              ORDER BY bt.book_id ASC, t.name ASC'
        );
        $statement->execute([$ownerId]);

        $byBook = [];
        foreach ($statement->fetchAll() as $row) {
            $byBook[(int) $row['book_id']][] = (string) $row['name'];
        }

        return $byBook;
    }

    /** @return array<int, list<string>> */
    private function coversByBook(int $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.book_id, c.source FROM covers c
               JOIN books b ON b.id = c.book_id
              WHERE b.owner_id = ? AND c.rejected_at IS NULL'
        );
        $statement->execute([$ownerId]);

        $byBook = [];
        foreach ($statement->fetchAll() as $row) {
            $byBook[(int) $row['book_id']][] = (string) $row['source'];
        }

        return $byBook;
    }

    /** @param list<string> $values */
    private function csvLine(array $values, string $delimiter = ';'): string
    {
        $quoted = array_map(
            static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"',
            $values
        );

        return implode($delimiter, $quoted) . "\r\n";
    }

    private function latin1(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);

        return is_string($converted) ? $converted : $value;
    }

    private function germanDate(?string $isoDate): string
    {
        if ($isoDate === null || $isoDate === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($isoDate, 0, 10));

        return $date === false ? '' : $date->format('d.m.Y');
    }
}
