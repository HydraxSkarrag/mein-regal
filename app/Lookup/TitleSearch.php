<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Isbn;
use SimpleXMLElement;

/**
 * Finding a book by its title, for the ones a barcode cannot reach.
 *
 * Every other lookup here starts from an ISBN, which is fine until the book
 * is older than the number. A shelf's Fontane in five volumes and its picture
 * book from 1988 have no barcode to hold up, and the shelf that this was
 * written for holds 82 of them.
 *
 * The German National Library is the right place to ask: the legal deposit
 * means it holds German publishing back to 1913, so it knows the editions
 * Google and Open Library have never seen. Measured on real titles from the
 * shelf - "Der kleine Häwelmann" 220 records, "Alfons Zitterbacke" with its
 * author 57, a title with a year 6.
 *
 * What comes back is a shortlist and nothing more. Most records carry an
 * ISBN, and once one is picked the ordinary machinery takes over - the same
 * chain, the same cover services, the same parsing that every scanned book
 * goes through. Only a record with no ISBN at all is taken at face value,
 * which is exactly the case this exists for.
 */
final class TitleSearch
{
    private const ENDPOINT = 'https://services.dnb.de/sru/dnb';

    /** More than fits on a phone is not a shortlist any more. */
    public const LIMIT = 10;

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @return list<array{title: string, authors: list<string>, publisher: ?string,
     *                    year: ?int, pages: ?int, language: ?string, isbn13: ?string}>
     */
    public function find(string $title, string $author = '', int $limit = self::LIMIT): array
    {
        $query = self::query($title, $author);
        if ($query === null) {
            return [];
        }

        $response = $this->http->getRetrying(self::ENDPOINT . '?' . http_build_query([
            'version'        => '1.1',
            'operation'      => 'searchRetrieve',
            'query'          => $query,
            'recordSchema'   => 'oai_dc',
            'maximumRecords' => max(1, min($limit, 50)),
        ]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            throw LookupUnavailable::unreachable('dnb', 'HTTP ' . $response['status']);
        }

        return self::parse($response['body']);
    }

    /**
     * The CQL the catalogue is asked in.
     *
     * Both terms are quoted and every quote in what was typed is dropped
     * first. Not escaping: a double quote inside a CQL phrase has no meaning
     * worth keeping here, and the alternative - building a query out of
     * somebody's punctuation - is how a search box turns into an injection.
     *
     * Null when there is nothing to search for, so an empty form does not
     * fetch the first ten books in Germany.
     */
    public static function query(string $title, string $author = ''): ?string
    {
        $title = trim(str_replace('"', ' ', $title));
        $author = trim(str_replace('"', ' ', $author));

        if ($title === '' && $author === '') {
            return null;
        }

        $parts = [];
        if ($title !== '') {
            $parts[] = 'TIT="' . $title . '"';
        }
        if ($author !== '') {
            $parts[] = 'PER="' . $author . '"';
        }

        return implode(' and ', $parts);
    }

    /**
     * @return list<array{title: string, authors: list<string>, publisher: ?string,
     *                    year: ?int, pages: ?int, language: ?string, isbn13: ?string}>
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            return [];
        }

        $document->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');
        $found = [];

        foreach ($document->xpath('//srw:recordData') ?: [] as $record) {
            $dc = $record->children('http://www.openarchives.org/OAI/2.0/oai_dc/')->dc
                ?? $record->children();
            if ($dc === null) {
                continue;
            }
            $candidate = self::one($dc->children('http://purl.org/dc/elements/1.1/'));
            if ($candidate !== null) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    /**
     * @return ?array{title: string, authors: list<string>, publisher: ?string,
     *                year: ?int, pages: ?int, language: ?string, isbn13: ?string}
     */
    private static function one(SimpleXMLElement $dc): ?array
    {
        $title = trim((string) ($dc->title[0] ?? ''));
        if ($title === '') {
            return null;
        }

        /* The catalogue's title field is a whole citation:
         *
         *   [Series] ; Title : subtitle / Author ; illustrated by Somebody
         *
         * The slash goes first, because everything after it is the statement
         * of responsibility and it contains semicolons of its own - taking
         * the last "; " before dropping it returned "mit Federzeichnungen von
         * Rolf von Hoerschelmann" and "Aufnahme: 22.2.1968" as book titles.
         *
         * The series prefix is then only stripped when it is bracketed, which
         * is how the catalogue marks it. A bare "; " in what is left belongs
         * to the title and stays. */
        $title = trim(explode(' / ', $title)[0]);
        if (preg_match('/^\[[^\]]*\]\s*;\s*(.+)$/u', $title, $parts) === 1) {
            $title = trim($parts[1]);
        }

        $authors = [];
        foreach ($dc->creator ?? [] as $creator) {
            // "Storm, Theodor [Verfasser]" - the role in brackets is shown in
            // the list only as noise, and the role itself is settled later by
            // the real lookup or by hand.
            $name = trim(preg_replace('/\s*\[[^\]]*\]\s*/', '', (string) $creator) ?? '');
            if ($name !== '') {
                $authors[] = $name;
            }
        }

        // "Oebisfelde : Alfa-Veda" - place first, publisher after the colon.
        $publisher = trim((string) ($dc->publisher[0] ?? ''));
        if (str_contains($publisher, ' : ')) {
            $publisher = trim(substr($publisher, (int) strpos($publisher, ' : ') + 3));
        }

        $year = null;
        if (preg_match('/\b(1[4-9]\d{2}|20\d{2})\b/', (string) ($dc->date[0] ?? ''), $m) === 1) {
            $year = (int) $m[1];
        }

        $pages = null;
        if (preg_match('/(\d+)\s*Seiten/u', (string) ($dc->format[0] ?? ''), $m) === 1) {
            $pages = (int) $m[1];
        }

        return [
            'title'     => mb_substr($title, 0, 500),
            'authors'   => array_values(array_unique($authors)),
            'publisher' => $publisher !== '' ? mb_substr($publisher, 0, 255) : null,
            'year'      => $year,
            'pages'     => $pages,
            'language'  => self::language($dc),
            'isbn13'    => self::isbn($dc),
        ];
    }

    /**
     * The ISBN, if the record has one.
     *
     * This is what makes a pick cheap: with a number in hand the book goes
     * through the ordinary chain and comes back with a cover, a price and a
     * binding, none of which this shortlist carries. Without one - the whole
     * reason for searching by title - what is shown is what there is.
     */
    private static function isbn(SimpleXMLElement $dc): ?string
    {
        foreach ($dc->identifier ?? [] as $identifier) {
            /* Only the ones the record calls an ISBN.
             *
             * Every record also carries a dnb:IDN - the library's own
             * accession number, ten digits long. Reading the digits out of
             * whatever identifier came first handed those to Isbn::normalize,
             * which accepted the ones whose last digit happened to check out
             * and turned them into a plausible, entirely fictional ISBN:
             * German records came back as 978-1-290-92206-7. */
            $type = (string) ($identifier->attributes('http://www.w3.org/2001/XMLSchema-instance')->type ?? '');
            if (!str_ends_with(strtoupper($type), ':ISBN')) {
                continue;
            }

            // "978-3-86472-619-4 : EUR 5.00 (DE)" - hyphens, and a price
            // hanging off the end.
            $digits = preg_replace('/[^0-9X]/i', '', explode(':', (string) $identifier)[0]) ?? '';
            $normalised = Isbn::normalize($digits);
            if ($normalised !== null) {
                return $normalised;
            }
        }

        return null;
    }

    private static function language(SimpleXMLElement $dc): ?string
    {
        $language = trim((string) ($dc->language[0] ?? ''));

        return preg_match('/^[a-z]{3}$/', $language) === 1 ? $language : null;
    }
}
