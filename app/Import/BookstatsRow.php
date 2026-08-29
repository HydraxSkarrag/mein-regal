<?php
declare(strict_types=1);

namespace App\Import;

use App\Core\Isbn;
use App\Core\Text;
use App\Lookup\Binding;
use App\Repository\BookRepository;

/**
 * One row of the Bookstats export, translated into what this application
 * stores.
 *
 * The export has three habits worth knowing about:
 *
 *   * "0" and "0,00" mean "not set", not zero. A book rated 0 is unrated, not
 *     rated badly; a price of 0,00 is unknown, not free. Reading them as
 *     numbers would invent 2,075 one-star ratings.
 *   * Dates are German (23.02.2022) and decimals use a comma (12,90).
 *   * "Erhalten am" is largely not an acquisition date. 431 books carry
 *     23.02.2022 and hundreds more share a handful of dates in early 2022 -
 *     those are the days the shelf was typed into Bookstats. They are kept but
 *     flagged, so no chart claims she bought 431 books in one afternoon.
 */
final class BookstatsRow
{
    /** Days on which so many books were "received" that it must be a bulk entry. */
    public const BULK_THRESHOLD = 25;

    private const STATUS = [
        'gelesen'     => BookRepository::STATUS_READ,
        'ungelesen'   => BookRepository::STATUS_UNREAD,
        'abgebrochen' => BookRepository::STATUS_ABANDONED,
        'am lesen'    => BookRepository::STATUS_READING,
    ];

    private const ACQUISITION = [
        'kauf'               => BookRepository::ACQUIRED_PURCHASE,
        'rezensionsexemplar' => BookRepository::ACQUIRED_REVIEW,
        'geschenk'           => BookRepository::ACQUIRED_GIFT,
        'gewinn'             => BookRepository::ACQUIRED_PRIZE,
        'leihe'              => BookRepository::ACQUIRED_LOAN,
        'tausch'             => BookRepository::ACQUIRED_SWAP,
    ];

    private const BINDINGS = [
        'hardcover'   => Binding::HARDCOVER,
        'taschenbuch' => Binding::PAPERBACK,
        'e-book'      => Binding::EBOOK,
        'ebook'       => Binding::EBOOK,
        'hörbuch'     => Binding::AUDIOBOOK,
        // "UNDEFINED" means the binding was never recorded, which is NULL.
    ];

    /** @param array<string,string> $raw */
    public function __construct(private readonly array $raw)
    {
    }

    public function title(): string
    {
        $title = trim($this->raw['Titel'] ?? '');

        return $title !== '' ? $title : '(ohne Titel)';
    }

    public function isbn13(): ?string
    {
        return Isbn::normalize($this->raw['ISBN'] ?? null);
    }

    /** The export's ISBN column may hold something that is not an ISBN at all. */
    public function rawIsbn(): string
    {
        return trim($this->raw['ISBN'] ?? '');
    }

    public function asin(): ?string
    {
        $asin = trim($this->raw['ASIN'] ?? '');

        return $asin !== '' ? $asin : null;
    }

    /** @return array{names: list<string>, ambiguous: bool} */
    public function authors(): array
    {
        $field = trim($this->raw['Autor(en)'] ?? '');
        if ($field === '' || Text::isPlaceholderName($field)) {
            return ['names' => [], 'ambiguous' => false];
        }

        return Text::splitAuthors($field);
    }

    public function genre(): ?string
    {
        $genre = trim($this->raw['Genre'] ?? '');
        if ($genre === '' || self::looksLikeIdentifier($genre)) {
            return null;
        }

        return $genre;
    }

    /** True when the genre field holds a machine identifier rather than a genre. */
    public function genreIsIdentifier(): bool
    {
        return self::looksLikeIdentifier(trim($this->raw['Genre'] ?? ''));
    }

    /**
     * Nineteen rows of the export carry something like
     * "7c9a6c79-19ea-4dea-90da-d7d47042d341_1001" in the genre column - a
     * shop's internal category id that leaked through whatever Bookstats
     * scraped. Importing those faithfully produces tags nobody can read and a
     * filter list nobody can use.
     *
     * The test is deliberately narrow, because real genres also contain
     * hyphens: "Science-Fiction" and "New Adult" must survive it.
     */
    public static function looksLikeIdentifier(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        // A UUID anywhere in the value.
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value) === 1) {
            return true;
        }
        // A slug: no spaces, several separators, and at least one digit.
        if (!str_contains($value, ' ')
            && preg_match_all('/[-_]/', $value) >= 3
            && preg_match('/\d/', $value) === 1) {
            return true;
        }

        return false;
    }

    public function publishedYear(): ?int
    {
        $year = $this->positiveInt($this->raw['Erscheinungsjahr'] ?? null);

        return $year !== null && $year > 1400 && $year < 2100 ? $year : null;
    }

    public function pageCount(): ?int
    {
        return $this->positiveInt($this->raw['Seitenanzahl'] ?? null);
    }

    public function rating(): ?int
    {
        $rating = $this->positiveInt($this->raw['Bewertung'] ?? null);

        return $rating !== null && $rating <= 5 ? $rating : null;
    }

    /** "12,90" is 12.90; "0,00" is unknown, not free. */
    public function price(): ?float
    {
        $raw = trim($this->raw['Preis'] ?? '');
        if ($raw === '') {
            return null;
        }
        $value = (float) str_replace(',', '.', $raw);

        return $value > 0.0 ? $value : null;
    }

    public function audioMinutes(): ?int
    {
        $hours = $this->positiveInt($this->raw['Dauer (Stunden)'] ?? null) ?? 0;
        $minutes = $this->positiveInt($this->raw['Dauer (Minuten)'] ?? null) ?? 0;
        $total = $hours * 60 + $minutes;

        return $total > 0 ? $total : null;
    }

    public function binding(): ?string
    {
        return self::BINDINGS[Text::fold($this->raw['Buchart'] ?? '')] ?? null;
    }

    public function readingStatus(): string
    {
        return self::STATUS[Text::fold($this->raw['Lesestatus'] ?? '')]
            ?? BookRepository::STATUS_UNREAD;
    }

    public function acquisitionType(): ?string
    {
        return self::ACQUISITION[Text::fold($this->raw['Erhalten als'] ?? '')] ?? null;
    }

    public function startedAt(): ?string
    {
        return self::germanDate($this->raw['Lesebeginn'] ?? null);
    }

    public function finishedAt(): ?string
    {
        return self::germanDate($this->raw['Leseende'] ?? null);
    }

    public function acquiredAt(): ?string
    {
        return self::germanDate($this->raw['Erhalten am'] ?? null);
    }

    public function notes(): ?string
    {
        $notes = trim($this->raw['Notizen'] ?? '');

        return $notes !== '' ? $notes : null;
    }

    /** "23.02.2022" to "2022-02-23". Invalid or empty gives null. */
    public static function germanDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || !preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m)) {
            return null;
        }
        [, $day, $month, $year] = $m;
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    private function positiveInt(mixed $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $number = (int) $raw;

        return $number > 0 ? $number : null;
    }
}
