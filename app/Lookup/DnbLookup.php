<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Isbn;
use App\Core\Text;
use SimpleXMLElement;

/**
 * Deutsche Nationalbibliothek, via the free SRU interface.
 *
 * The primary source for German titles: every German ISBN tested against the
 * real collection was answered, because German publishers are required by law
 * to deposit their output here. No key, no quota, metadata under CC0.
 *
 * It carries no cover images - that gap is filled elsewhere.
 */
final class DnbLookup implements LookupSource
{
    private const ENDPOINT = 'https://services.dnb.de/sru/dnb';

    /**
     * Titles that are only a volume designation.
     *
     * The simple oai_dc view of a multi-volume work drops the work's own
     * title and keeps the volume label, so "Erdsee. 1. Trilogie" arrives as
     * "1. Trilogie" - a book nobody can find again by searching for it. When
     * this matches, the fuller MARC record is fetched, where field 245 keeps
     * the title in $a and the volume in $n.
     */
    private const VOLUME_ONLY = '/^(\d+\.?\s*(Trilogie|Teil|Band|Bd\.?|Folge|Staffel|Buch)'
        . '|(Trilogie|Teil|Band|Bd\.?|Folge|Staffel|Buch)\s*\d+'
        . '|\[\d+\])\s*$/iu';

    /**
     * DNB spells the contributor's role out in brackets after the name.
     *
     * The keys are written in their FOLDED form - Text::fold() turns "ü" into
     * "ue", so a key of "übersetzer" would never match and every translator
     * would silently be filed as an author.
     */
    private const ROLES = [
        'verfasser'    => 'author',
        'autor'        => 'author',
        'illustrator'  => 'illustrator',
        'illustration' => 'illustrator',
        'uebersetzer'  => 'translator',
        'herausgeber'  => 'editor',
        'erzaehler'    => 'narrator',
        'sprecher'     => 'narrator',
        'mitwirkender' => 'author',
    ];

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'dnb';
    }

    public function find(string $isbn13): ?BookData
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'version'        => '1.1',
            'operation'      => 'searchRetrieve',
            'query'          => 'NUM=' . $isbn13,
            'recordSchema'   => 'oai_dc',
            'maximumRecords' => 1,
        ]);

        $response = $this->http->getRetrying($url);
        if ($response['status'] !== 200 || $response['body'] === '') {
            throw LookupUnavailable::unreachable($this->name(), 'HTTP ' . $response['status']);
        }

        /* An SRU service answers "I have no such record" and "I cannot serve
           you right now" with the same 200, and until now both arrived here
           as null - which the chain reads as "nowhere has this book" and
           reports as "nichts gefunden". The catalogue is then blamed for not
           holding a book it holds, and the nightly job writes the miss down
           for a month. What the answer actually says is in it: a diagnostics
           block, or a record count that does not match what came back. */
        self::refuseIfNotAnAnswer($response['body'], $this->name());

        $book = $this->parse($response['body'], $isbn13);
        if ($book === null) {
            return $book;
        }

        /* The plain view is enough for most records, but for a volume of a
           multi-part work it is badly lossy: it keeps the volume label
           instead of the title, and it can list the translator while
           dropping the author entirely. Either symptom earns the second,
           heavier request. */
        if (self::isVolumeOnly($book->title) || !self::hasAuthor($book->authors)) {
            $fuller = $this->fromMarc($isbn13);
            if ($fuller !== null) {
                return new BookData(
                    source:        $book->source,
                    isbn13:        $book->isbn13,
                    isbn10:        $book->isbn10,
                    title:         $fuller['title'] ?? $book->title,
                    subtitle:      $fuller['subtitle'] ?? $book->subtitle,
                    authors:       $fuller['authors'] !== [] ? $fuller['authors'] : $book->authors,
                    publisher:     $book->publisher ?? $fuller['publisher'],
                    publishedYear: $book->publishedYear,
                    pageCount:     $book->pageCount,
                    language:      $book->language,
                    binding:       $book->binding,
                    price:         $book->price,
                    priceCurrency: $book->priceCurrency,
                    tags:          $book->tags,
                    coverUrl:      $book->coverUrl,
                    attribution:   $book->attribution,
                    coverSource:   $book->coverSource,
                );
            }
        }

        return $book;
    }

    public static function isVolumeOnly(?string $title): bool
    {
        return $title !== null && preg_match(self::VOLUME_ONLY, trim($title)) === 1;
    }

    /** @param list<array{name: string, role: string}> $people */
    public static function hasAuthor(array $people): bool
    {
        foreach ($people as $person) {
            if ($person['role'] === 'author') {
                return true;
            }
        }

        return false;
    }

    /**
     * The richer record: title from 245, contributors from 100 and 700.
     *
     * @return array{title: ?string, subtitle: ?string, authors: list<array{name: string, role: string}>, publisher: ?string}|null
     */
    private function fromMarc(string $isbn13): ?array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'version'        => '1.1',
            'operation'      => 'searchRetrieve',
            'query'          => 'NUM=' . $isbn13,
            'recordSchema'   => 'MARC21-xml',
            'maximumRecords' => 1,
        ]);

        // Deliberately quiet: this second request only fills in fields the
        // first one left, so losing it costs a detail rather than the record.
        $response = $this->http->getRetrying($url, 2);
        if ($response['status'] !== 200 || $response['body'] === '') {
            return null;
        }

        $title = self::parseMarcTitle($response['body']);
        $authors = self::parseMarcContributors($response['body']);
        $publisher = self::parseMarcPublisher($response['body']);

        if ($title === null && $authors === [] && $publisher === null) {
            return null;
        }

        return [
            'title'     => $title['title'] ?? null,
            'subtitle'  => $title['subtitle'] ?? null,
            'authors'   => $authors,
            'publisher' => $publisher,
        ];
    }

    /**
     * Publisher from MARC 264 $b (or the older 260).
     *
     * The records that lose their title also tend to have no dc:publisher,
     * and the request has already been made by the time we get here.
     */
    public static function parseMarcPublisher(string $xml): ?string
    {
        foreach (['264', '260'] as $tag) {
            $pattern = sprintf(
                '~<(?:\w+:)?datafield[^>]*tag="%s"[^>]*>(.*?)</(?:\w+:)?datafield>~s',
                $tag
            );
            if (preg_match($pattern, $xml, $field) !== 1) {
                continue;
            }
            if (preg_match('~<(?:\w+:)?subfield[^>]*code="b"[^>]*>([^<]*)</~', $field[1], $sub) !== 1) {
                continue;
            }
            $name = trim(self::normalise(html_entity_decode($sub[1], ENT_QUOTES, 'UTF-8')), " \t\n\r,;:");
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * Contributors from MARC 100 (main entry) and 700 (added entries).
     *
     * The role sits in $e, spelled the way oai_dc spells it in brackets, so
     * the existing mapping applies unchanged.
     *
     * @return list<array{name: string, role: string}>
     */
    public static function parseMarcContributors(string $xml): array
    {
        $people = [];

        foreach (['100', '700'] as $tag) {
            $pattern = sprintf(
                '~<(?:\\w+:)?datafield[^>]*tag="%s"[^>]*>(.*?)</(?:\\w+:)?datafield>~s',
                $tag
            );
            if (preg_match_all($pattern, $xml, $fields) === 0) {
                continue;
            }

            foreach ($fields[1] as $field) {
                preg_match_all(
                    '~<(?:\\w+:)?subfield[^>]*code="(\\w)"[^>]*>([^<]*)</~',
                    $field,
                    $subfields,
                    PREG_SET_ORDER
                );

                $name = null;
                $role = 'author';
                foreach ($subfields as $subfield) {
                    $value = self::normalise(html_entity_decode(trim($subfield[2]), ENT_QUOTES, 'UTF-8'));
                    if ($subfield[1] === 'a' && $name === null) {
                        $name = $value;
                    } elseif ($subfield[1] === 'e') {
                        $role = self::ROLES[Text::fold($value)] ?? 'author';
                    }
                }

                if ($name === null || $name === '' || Text::isPlaceholderName($name)) {
                    continue;
                }

                /* MARC $a is a controlled field, always "Last, First". The
                   heuristic used for free-text author fields is wrong here
                   and actively harmful: it reads "Le Guin, Ursula K." as two
                   people, because both halves happen to contain a space. */
                $comma = strpos($name, ',');
                if ($comma !== false) {
                    $name = trim(substr($name, $comma + 1)) . ' ' . trim(substr($name, 0, $comma));
                }

                $people[] = ['name' => Text::tidyName($name), 'role' => $role];
            }
        }

        return Contributors::dedupe($people);
    }

    /**
     * Composed Unicode.
     *
     * The DNB hands MARC over decomposed, so "Nölle" arrives as an "o"
     * followed by a combining diaeresis. It looks identical and compares
     * unequal - which would file the same translator twice and make the
     * role lookup for "Übersetzer" miss, quietly turning every translator
     * into an author.
     */
    private static function normalise(string $value): string
    {
        /* Every value from this catalogue passes here, which is the reason
           the non-sorting marks are taken out at this point rather than in
           each parser: they turn up in titles and in the names of series
           alike, and one of the two would have been forgotten. */
        $value = Text::withoutSortMarks($value);

        if (class_exists(\Normalizer::class)) {
            $composed = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($composed)) {
                return $composed;
            }
        }

        // Without intl, fold the handful of combining marks German uses.
        return strtr($value, [
            "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü',
            "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
            "e\u{0301}" => 'é', "e\u{0300}" => 'è', "a\u{0301}" => 'á',
            "c\u{0327}" => 'ç', "n\u{0303}" => 'ñ',
        ]);
    }

    /**
     * Title from MARC 245, where a volume of a series keeps its own name.
     *
     *   $a  the title proper - for a series, the name of the series
     *   $n  which part: "1.", "Teil 2.", "Bd. 1.", "1/2."
     *   $p  the name of that part - which is what the book is called
     *   $b  the rest of the title, what a cover prints under the name
     *   $c  who wrote it, which comes from 100 and 700 instead
     *
     * $p used to be ignored, and that is how two volumes of one series
     * arrived on the shelf as the same book: both called "Die Chronik der
     * Drachenlanze", telling apart only by their ISBN in the address. The
     * names were in the record all along - Drachenzwielicht and
     * Drachenjäger, one subfield further along.
     *
     * So when there is a part name, it is the title, and the series and the
     * number become the subtitle. Read off seventy-three records carrying a
     * $p: it is the book's own name in nearly all of them. The exceptions
     * are edition words - "Gesamtausgabe" - where this comes out as a title
     * nobody would choose. Nothing in the field distinguishes those, they
     * were three of the seventy-three, and the alternative is the bug: six
     * volumes of a series filed under one name. Nothing is lost either way,
     * the series moves to the subtitle.
     *
     * @return array{title: string, subtitle: ?string, series: ?string, seriesIndex: ?float}|null
     */
    public static function parseMarcTitle(string $xml): ?array
    {
        if (preg_match('~<(?:\w+:)?datafield[^>]*tag="245"[^>]*>(.*?)</(?:\w+:)?datafield>~s', $xml, $field) !== 1) {
            return null;
        }
        preg_match_all(
            '~<(?:\w+:)?subfield[^>]*code="(\w)"[^>]*>([^<]*)</~',
            $field[1],
            $subfields,
            PREG_SET_ORDER
        );

        $parts = [];
        foreach ($subfields as $subfield) {
            $parts[$subfield[1]] = self::normalise(html_entity_decode(trim($subfield[2]), ENT_QUOTES, 'UTF-8'));
        }

        /* The statement of responsibility can appear inside any of these,
           after " / ", and "[u.a.]" marks a record that stands for several
           volumes at once. Neither belongs in a title. */
        $tidy = static function (?string $value): string {
            $value = (string) $value;
            $slash = strpos($value, ' / ');
            if ($slash !== false) {
                $value = substr($value, 0, $slash);
            }
            $value = str_replace('[u.a.]', '', $value);

            return trim($value, " \t\n\r\0\x0B.,:;/");
        };

        $series = $tidy($parts['a'] ?? '');
        $number = $tidy($parts['n'] ?? '');
        $part   = $tidy($parts['p'] ?? '');
        $rest   = $tidy($parts['b'] ?? '');

        // A part name often carries its own subtitle: "Die Sucherin : Roman".
        $partExtra = '';
        if ($part !== '' && ($colon = strpos($part, ' : ')) !== false) {
            $partExtra = trim(substr($part, $colon + 3), " \t\n\r\0\x0B.,:;/");
            $part = trim(substr($part, 0, $colon), " \t\n\r\0\x0B.,:;/");
        }

        /* A part name means $a is the series and not the title, which is the
           same fact the series reading needs - so both come out of here and
           cannot disagree. Without a part name there is no series here: $a is
           simply the title. */
        if ($part !== '') {
            $title = $part;
            $pieces = [trim($series . ' ' . $number), $partExtra, $rest];
            $seriesName = $series !== '' ? $series : null;
            $seriesIndex = self::volumeNumber($number);
        } elseif ($series !== '') {
            $title = $series;
            $pieces = [$number, $rest];
            $seriesName = null;
            $seriesIndex = null;
        } else {
            return null;
        }

        $subtitle = implode('. ', array_filter($pieces, static fn (string $v): bool => $v !== ''));

        return [
            'title'       => $title,
            'subtitle'    => $subtitle === '' ? null : $subtitle,
            'series'      => $seriesName,
            'seriesIndex' => $seriesIndex,
        ];
    }

    /**
     * Tell an empty answer apart from a broken one, and refuse the broken one.
     *
     * Three shapes, all of them HTTP 200:
     *
     *   a diagnostics block          the service says why it will not answer
     *   nothing that parses as XML   something in front of it answered
     *   n records promised, none in  the answer was cut off on the way
     *
     * None of those is "this ISBN is unknown", and only numberOfRecords=0 is.
     *
     * @throws LookupUnavailable
     */
    public static function refuseIfNotAnAnswer(string $xml, string $source): void
    {
        if (str_contains($xml, 'diagnostic')) {
            $why = preg_match('#<[^>]*message>([^<]{1,120})<#i', $xml, $m)
                ? trim($m[1])
                : 'the service returned a diagnostic';

            throw LookupUnavailable::unreachable($source, $why);
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw LookupUnavailable::unreachable($source, 'the answer was not XML');
        }

        if (!preg_match('#<[^>]*numberOfRecords>\s*(\d+)#i', $xml, $count)) {
            // No count at all is not the shape of an SRU answer either.
            throw LookupUnavailable::unreachable($source, 'the answer had no record count');
        }

        // Zero is a real answer, and the only one that means "unknown here".
        if ((int) $count[1] > 0 && !str_contains($xml, 'recordData')) {
            throw LookupUnavailable::unreachable(
                $source,
                $count[1] . ' records promised, none delivered'
            );
        }
    }

    public function parse(string $xml, string $isbn13): ?BookData
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            return null;
        }

        $document->registerXPathNamespace('srw', 'http://www.loc.gov/zing/srw/');
        $records = $document->xpath('//srw:recordData') ?: [];
        if ($records === []) {
            return null;
        }

        $dc = $records[0]->children('http://purl.org/dc/elements/1.1/');
        if ($dc === null || $dc->count() === 0) {
            // Some responses nest one level deeper inside <dc>.
            $inner = $records[0]->children();
            $dc = $inner->count() > 0
                ? $inner[0]->children('http://purl.org/dc/elements/1.1/')
                : null;
        }
        if ($dc === null || $dc->count() === 0) {
            return null;
        }

        $titleRaw = $this->first($dc, 'title');
        if ($titleRaw === null) {
            return null;
        }

        [$title, $subtitle] = $this->splitTitle($titleRaw);

        $identifiers = $this->allValues($dc, 'identifier');
        $bindingAndPrice = $this->identifierWithPrice($identifiers);
        $people = $this->contributors($dc);

        return new BookData(
            source:        $this->name(),
            isbn13:        $isbn13,
            isbn10:        Isbn::to10($isbn13),
            title:         $title,
            subtitle:      $subtitle,
            authors:       $people,
            publisher:     $this->publisher($this->first($dc, 'publisher')),
            publishedYear: $this->year($this->first($dc, 'date')),
            pageCount:     $this->pages($this->first($dc, 'format')),
            language:      $this->language($this->first($dc, 'language')),
            binding:       Binding::fromText($bindingAndPrice) ?? self::bindingFromRoles($people),
            price:         $this->price($bindingAndPrice),
            priceCurrency: 'EUR',
            tags:          $this->subjects($dc),
        );
    }

    /**
     * Which series a record puts the book in, and where in it.
     *
     * MARC has two places for this and they mean different things.
     *
     * 490/830 is the series statement, and it is right for a work series -
     * "Die Sturmlicht-Chroniken $v 8" - and wrong for a publisher's numbered
     * line, which lives in the same field: "Goldmann $v 24510 : Fantasy" is
     * Goldmann's stock number, not the position in the Drachenlanze.
     *
     * What tells them apart is not the number. Measured over a few hundred
     * records, work numbers run 1 to 13 and stock numbers 24000 upwards - but
     * a cap would be a guess, and Perry Rhodan would break it. The structural
     * test is the name: a publisher's line is named after the publisher, and
     * the record says who that is a field away. Goldmann published by
     * Goldmann is a stock number; "Die Sturmlicht-Chroniken" published by
     * Heyne is a series.
     *
     * The number itself comes in five shapes, all measured: "8", "Band 13",
     * "8. Roman", "3/4" for a volume holding two parts, and "24229 :
     * Blanvalet : Fantasy" for the kind being rejected. A range takes its
     * first number, which is where that volume starts.
     *
     * Not in the record the rest of this class reads. oai_dc has no series
     * field at all - measured on a book that plainly is in one - so this is
     * its own request against MARC21, made when somebody is adding a book
     * rather than on every pass of the nightly job.
     *
     * @return array{name: string, index: ?float}|null
     */
    public function seriesFor(string $isbn13): ?array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'version'        => '1.1',
            'operation'      => 'searchRetrieve',
            'query'          => 'NUM=' . $isbn13,
            'recordSchema'   => 'MARC21-xml',
            'maximumRecords' => 1,
        ]);

        $response = $this->http->getRetrying($url, 2);
        if ($response['status'] !== 200 || $response['body'] === '') {
            return null;
        }

        return self::parseMarcSeries($response['body']);
    }

    /**
     * @return array{name: string, index: ?float}|null
     */
    public static function parseMarcSeries(string $xml): ?array
    {
        $publisher = self::parseMarcPublisher($xml);

        foreach (['490', '830'] as $tag) {
            $pattern = sprintf(
                '~<(?:\w+:)?datafield[^>]*tag="%s"[^>]*>(.*?)</(?:\w+:)?datafield>~s',
                $tag
            );
            if (preg_match($pattern, $xml, $field) !== 1) {
                continue;
            }
            $parts = [];
            preg_match_all(
                '~<(?:\w+:)?subfield[^>]*code="(\w)"[^>]*>([^<]*)</~',
                $field[1],
                $subfields,
                PREG_SET_ORDER
            );
            foreach ($subfields as $subfield) {
                $parts[$subfield[1]] ??= Text::withoutSortMarks(
                    self::normalise(html_entity_decode(trim($subfield[2]), ENT_QUOTES, 'UTF-8'))
                );
            }

            $name = trim((string) ($parts['a'] ?? ''), " \t\n\r.,:;/");
            $volume = (string) ($parts['v'] ?? '');

            if ($name === '' || self::isPublisherLine($name, $publisher, $volume)) {
                continue;
            }

            return ['name' => $name, 'index' => self::volumeNumber($volume)];
        }

        /* No series statement, but 245 may still hold one: "$a Die Chronik
           der Drachenlanze $n 1. $p Drachenzwielicht" is a series, a volume
           and a title in one field. That is also where the title itself comes
           from, so the two readings have to agree. */
        $title = self::parseMarcTitle($xml);
        if ($title === null || ($title['series'] ?? null) === null) {
            return null;
        }

        return ['name' => $title['series'], 'index' => $title['seriesIndex']];
    }

    /**
     * Is this the publisher's own numbered line rather than a work series?
     *
     * The two live in the same MARC field. "Goldmann $v 24510 : Fantasy" is
     * Goldmann's stock number for a Drachenlanze volume; "Die Sturmlicht-
     * Chroniken $v 8" is the eighth book of a series.
     *
     * The number does not tell them apart - work numbers ran 1 to 13 and
     * stock numbers from 24000 in a sample of a few hundred, but a cap would
     * be a guess and a long-running series would break it. The name does: a
     * publisher's line is named after the publisher, and the record says who
     * that is a field away.
     *
     * The colon is the second tell, and only ever appears on the stock
     * numbers, which carry the imprint and the trade category after it.
     */
    private static function isPublisherLine(string $name, ?string $publisher, string $volume): bool
    {
        if (str_contains($volume, ':')) {
            return true;
        }
        if ($publisher === null || $publisher === '') {
            return false;
        }

        $series = Text::fold($name);
        $house = Text::fold($publisher);

        return $series !== '' && (str_contains($house, $series) || str_contains($series, $house));
    }

    /**
     * The number out of "8", "Band 13", "8. Roman" or "3/4".
     *
     * All four are real, all four measured. A range is a single volume
     * holding two parts, and it starts at the first of them.
     */
    private static function volumeNumber(string $volume): ?float
    {
        $volume = preg_replace('/^\s*(Band|Bd\.?|Vol\.?|Teil)\s*/iu', '', trim($volume)) ?? $volume;

        return preg_match('/^(\d+(?:[.,]5)?)/', $volume, $m) === 1
            ? (float) str_replace(',', '.', $m[1])
            : null;
    }

    /**
     * A book with somebody reading it aloud is an audiobook.
     *
     * The DNB spells the binding out in a free-text identifier field -
     * "Festeinband", "Kartoniert" - and for an audiobook it usually says
     * nothing at all. The record does carry the evidence, one field further
     * on: a contributor with the role Erzähler. A printed book has no reader.
     *
     * dc:type would be the obvious place to look and is the wrong one. For
     * the audiobook that prompted this it says "Online-Ressource", which the
     * keyword list reads as an e-book - the same word for a download of a
     * novel and a download of a recording of one.
     *
     * Only where the text said nothing. An explicit binding is evidence and
     * this is an inference, and evidence wins.
     *
     * @param list<array{name: string, role: string}> $people
     */
    private static function bindingFromRoles(array $people): ?string
    {
        foreach ($people as $person) {
            if (($person['role'] ?? '') === 'narrator') {
                return Binding::AUDIOBOOK;
            }
        }

        return null;
    }

    /**
     * "Milla und das erfundene Glück / Rüdiger Bertram" - the slash separates
     * the statement of responsibility, which is not part of the title. A colon
     * separates a subtitle.
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitTitle(string $raw): array
    {
        $raw = trim($raw);

        // The statement of responsibility after " / " is not part of the title.
        $slash = strrpos($raw, ' / ');
        if ($slash !== false) {
            $raw = trim(substr($raw, 0, $slash));
        }

        // A translated work is filed under its original title in brackets,
        // followed by the German one: "[How to do the work] ; Heile - Dich -
        // Selbst". The German title is the one on the cover in her hands.
        if (preg_match('/^\[[^\]]*\]\s*;\s*(.+)$/us', $raw, $match) === 1) {
            $raw = trim($match[1]);
        }

        $colon = strpos($raw, ' : ');
        if ($colon !== false) {
            return [
                trim(substr($raw, 0, $colon)),
                trim(substr($raw, $colon + 3)) ?: null,
            ];
        }

        return [$raw, null];
    }

    /** @return list<array{name: string, role: string}> */
    private function contributors(SimpleXMLElement $dc): array
    {
        $people = [];
        foreach (['creator', 'contributor'] as $element) {
            foreach ($this->allValues($dc, $element) as $value) {
                // One contributor can carry several role brackets, as in
                // "Krause, Ute [Verfasser] [Illustrator]". Take the first
                // recognised role and strip every bracket from the name.
                $role = 'author';
                if (preg_match_all('/\[([^\]]+)\]/u', $value, $matches) > 0) {
                    foreach ($matches[1] as $label) {
                        $mapped = self::ROLES[Text::fold(trim($label))] ?? null;
                        if ($mapped !== null) {
                            $role = $mapped;
                            break;
                        }
                    }
                    $value = preg_replace('/\[[^\]]*\]/u', '', $value) ?? $value;
                }
                $name = Text::tidyName(rtrim($value, ' ,;'));
                if ($name === '' || Text::isPlaceholderName($name)) {
                    continue;
                }
                // DNB writes "Bertram, Rüdiger" - store it the way people read it.
                if (str_contains($name, ',')) {
                    $split = Text::splitAuthors($name);
                    $name = $split['names'][0] ?? $name;
                }
                $people[] = ['name' => $name, 'role' => $role];
            }
        }

        return Contributors::dedupe($people);
    }

    /** "Ravensburg : Ravensburger Buchverlag" - the place is not the publisher. */
    private function publisher(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $colon = strpos($raw, ' : ');
        $name = $colon !== false ? substr($raw, $colon + 3) : $raw;

        return trim($name) ?: null;
    }

    private function year(?string $raw): ?int
    {
        if ($raw !== null && preg_match('/\b(1\d{3}|20\d{2})\b/', $raw, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /** dc:format reads "189 Seiten". */
    private function pages(?string $raw): ?int
    {
        if ($raw !== null && preg_match('/(\d+)\s*(seiten|s\.|pages|p\.)/iu', $raw, $m) === 1) {
            $pages = (int) $m[1];

            return $pages > 0 ? $pages : null;
        }

        return null;
    }

    private function language(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $code = strtolower(trim($raw));

        return preg_match('/^[a-z]{3}$/', $code) === 1 ? $code : null;
    }

    /** @return list<string> */
    private function subjects(SimpleXMLElement $dc): array
    {
        $tags = [];
        foreach ($this->allValues($dc, 'subject') as $value) {
            /* The DNB writes its subject groups with the notation in front -
               "K Kinder- und Jugendliteratur", "830 Deutsche Literatur", "07
               Kinder- und Jugendliteratur" - and the notation is a code, not
               part of the name. The shapes it comes in are more varied than
               they look, so the rule lives in Text where it can be measured
               and where bin/tags.php reads the same one to clean up what got
               through before. */
            $clean = Text::withoutClassification($value);
            if ($clean !== null) {
                $tags[] = $clean;
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * The ISBN identifier carries binding and retail price as free text:
     * "978-3-473-40806-1 Festeinband : EUR 12.99 (DE), EUR 13.40 (AT)".
     *
     * @param list<string> $identifiers
     */
    private function identifierWithPrice(array $identifiers): ?string
    {
        foreach ($identifiers as $identifier) {
            if (str_contains($identifier, 'EUR') || preg_match('/\d[\d.\-]{8,}\s+\D/', $identifier) === 1) {
                return $identifier;
            }
        }

        return $identifiers[0] ?? null;
    }

    /** Prefer the German retail price where several are listed. */
    private function price(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        if (preg_match('/EUR\s*([\d]+[.,]\d{2})\s*\(DE\)/u', $raw, $m) === 1) {
            return (float) str_replace(',', '.', $m[1]);
        }
        if (preg_match('/EUR\s*([\d]+[.,]\d{2})/u', $raw, $m) === 1) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    private function first(SimpleXMLElement $dc, string $element): ?string
    {
        $values = $this->allValues($dc, $element);

        return $values[0] ?? null;
    }

    /** @return list<string> */
    private function allValues(SimpleXMLElement $dc, string $element): array
    {
        $values = [];
        foreach ($dc->{$element} as $node) {
            $value = trim((string) $node);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
