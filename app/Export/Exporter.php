<?php
declare(strict_types=1);

namespace App\Export;

use App\Core\Formatter;
use App\Core\Isbn;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\CoverRepository;
use App\Repository\SeriesRepository;
use App\Repository\TagRepository;
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
 *   full       every column, UTF-8, one row per book. Where a book has
 *              several of something - people, genres, labels - they share
 *              a cell, separated by semicolons.
 *   json       the same fields, with those as lists and the series as one
 *              object. For programs rather than spreadsheets.
 *
 * The two fuller ones carry the same information on purpose. They drifted
 * apart once: the CSV had a column list of its own, the series arrived after
 * it was written, and only the JSON - which writes whatever the table has -
 * picked it up. And even there as a bare series_id, a number that means
 * nothing once this database is gone.
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

    private readonly BookRepository $books;
    private readonly AuthorRepository $authors;
    private readonly TagRepository $tags;
    private readonly CoverRepository $covers;
    private readonly SeriesRepository $series;

    /* Handed the connection rather than five repositories, because every
       caller - the data page, bin/export.php, the nightly backup, the
       tests - has exactly that to hand. What it asks goes through the
       repositories all the same. */
    public function __construct(PDO $pdo)
    {
        $this->books = new BookRepository($pdo);
        $this->authors = new AuthorRepository($pdo);
        $this->tags = new TagRepository($pdo);
        $this->covers = new CoverRepository($pdo);
        $this->series = new SeriesRepository($pdo);
    }

    /** @return iterable<array<string,mixed>> one row per book, with people and tags */
    public function books(int $ownerId): iterable
    {
        $people = $this->authors->contributorsByBook($ownerId);
        $tags = $this->tags->namesByBook($ownerId);
        $covers = $this->covers->sourcesByBook($ownerId);
        $series = $this->series->namesById($ownerId);

        foreach ($this->books->eachForOwner($ownerId) as $book) {
            $id = (int) $book['id'];
            $book['contributors'] = $people[$id] ?? [];
            $book['genres'] = $tags[$id]['genres'] ?? [];
            $book['labels'] = $tags[$id]['labels'] ?? [];
            $book['covers'] = $covers[$id] ?? [];
            $book['series_name'] = $series[(int) ($book['series_id'] ?? 0)] ?? null;

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
                // One column for what is now two kinds. A genre if there is
                // one, since that is what the column is called.
                $book['genres'][0] ?? $book['labels'][0] ?? '',
                (string) ($book['page_count'] ?? 0),
                (string) intdiv($audio, 60),
                (string) ($audio % 60),
                self::BINDING_OUT[$book['binding'] ?? ''] ?? '',
                number_format((float) ($book['price'] ?? 0), 2, ',', ''),
                self::ACQUIRED_OUT[$book['acquisition_type'] ?? ''] ?? '',
                self::STATUS_OUT[$book['reading_status'] ?? ''] ?? '',
                $this->germanDate($book['started_at'] ?? null),
                $this->germanDate($book['finished_at'] ?? null),
                // "4" and "3,5", with the comma the price column uses. Not the
                // value as the database hands it over: MySQL gives a DECIMAL
                // back as "4.0", and the importer took only whole numbers, so
                // on the live servers this file lost every rating on the way
                // back in. SQLite said 4, which is why no test ever noticed.
                Formatter::stars($book['rating'] ?? null)['text'] ?? '0',
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
            'id', 'isbn13', 'isbn10', 'title', 'subtitle',
            'series', 'series_index', 'series_index_end',
            'authors', 'contributors',
            'publisher', 'published_year', 'page_count', 'language', 'binding',
            'price', 'price_currency', 'acquisition_type', 'acquired_at',
            'acquired_at_is_bulk', 'reading_status', 'started_at', 'finished_at',
            'rating', 'genres', 'labels', 'notes', 'review_url', 'audio_minutes',
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
                    'series'           => (string) ($book['series_name'] ?? ''),
                    'series_index'     => self::volume($book['series_index'] ?? null),
                    'series_index_end' => self::volume($book['series_index_end'] ?? null),
                    'genres'           => implode('; ', $book['genres']),
                    'labels'           => implode('; ', $book['labels']),
                    'cover_sources'    => implode('; ', $book['covers']),
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
            /* The series as one thing with a name, in place of the three
               columns it is stored in. The id is this database's business,
               like the owner's. */
            $book['series'] = $book['series_name'] === null ? null : [
                'name'      => $book['series_name'],
                'index'     => self::volumeNumber($book['series_index'] ?? null),
                'index_end' => self::volumeNumber($book['series_index_end'] ?? null),
            ];
            unset($book['owner_id'], $book['series_id'], $book['series_index'], $book['series_index_end'], $book['series_name']);
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

    /**
     * A volume as a spreadsheet should read it: "5", "5.5", never "5.0".
     *
     * DECIMAL comes back from MySQL as a string with its scale attached, so
     * every whole volume would otherwise carry a ".0" nobody wrote.
     */
    private static function volume(mixed $value): string
    {
        $number = self::volumeNumber($value);

        return $number === null ? '' : (string) $number;
    }

    private static function volumeNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return floor($number) === $number ? (int) $number : $number;
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
