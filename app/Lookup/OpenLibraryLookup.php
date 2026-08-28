<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Isbn;

/**
 * Open Library (Internet Archive).
 *
 * First in line for English ISBNs, last for German ones - it answered about
 * two in five German titles when tested against the real collection, but does
 * noticeably better on English ones.
 *
 * Its covers are meant to be displayed on public websites; a link back is
 * asked for as a courtesy and given. Bulk downloading is explicitly not
 * wanted, which is another reason the images are linked rather than copied.
 */
final class OpenLibraryLookup implements LookupSource
{
    private const ENDPOINT = 'https://openlibrary.org/api/books';

    public function __construct(private readonly HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'openlibrary';
    }

    public function find(string $isbn13): ?BookData
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'bibkeys' => 'ISBN:' . $isbn13,
            'format'  => 'json',
            'jscmd'   => 'data',
        ]);

        $response = $this->http->get($url);
        if ($response['status'] !== 200) {
            return null;
        }

        return $this->parse($response['body'], $isbn13);
    }

    public function parse(string $json, string $isbn13): ?BookData
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $record = $data['ISBN:' . $isbn13] ?? null;
        if (!is_array($record) || !isset($record['title'])) {
            return null;
        }

        // Open Library author entries are not reliably one person each -
        // "Sarah Crossan,Sarah Crossan" is a real record.
        $names = [];
        foreach ((array) ($record['authors'] ?? []) as $author) {
            $names[] = (string) ($author['name'] ?? '');
        }
        $authors = Contributors::normalize($names);

        $publisher = null;
        foreach ((array) ($record['publishers'] ?? []) as $entry) {
            $publisher = trim((string) ($entry['name'] ?? '')) ?: null;
            if ($publisher !== null) {
                break;
            }
        }

        $cover = $this->cover($record['cover'] ?? null);

        return new BookData(
            source:        $this->name(),
            isbn13:        $isbn13,
            isbn10:        Isbn::to10($isbn13),
            title:         trim((string) $record['title']) ?: null,
            subtitle:      isset($record['subtitle']) ? (trim((string) $record['subtitle']) ?: null) : null,
            authors:       $authors,
            publisher:     $publisher,
            publishedYear: $this->year($record['publish_date'] ?? null),
            pageCount:     $this->positiveInt($record['number_of_pages'] ?? null),
            language:      null,
            binding:       null,
            price:         null,
            tags:          $this->tags($record['subjects'] ?? []),
            coverUrl:      $cover,
            attribution:   $cover !== null ? 'Cover: Open Library' : null,
        );
    }

    private function cover(mixed $cover): ?string
    {
        if (!is_array($cover)) {
            return null;
        }
        foreach (['large', 'medium', 'small'] as $size) {
            if (isset($cover[$size]) && is_string($cover[$size]) && $cover[$size] !== '') {
                return str_replace('http://', 'https://', $cover[$size]);
            }
        }

        return null;
    }

    /** publish_date is free text: "2017", "March 2017", "Mar 14, 2017". */
    private function year(mixed $raw): ?int
    {
        if (is_string($raw) && preg_match('/\b(1\d{3}|20\d{2})\b/', $raw, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $number = is_numeric($value) ? (int) $value : 0;

        return $number > 0 ? $number : null;
    }

    /**
     * Open Library subjects are crowd-sourced and run long - a single book can
     * carry dozens. Capped so one noisy record cannot flood the tag list.
     *
     * @return list<string>
     */
    private function tags(mixed $subjects): array
    {
        $tags = [];
        foreach ((array) $subjects as $subject) {
            $name = trim((string) ($subject['name'] ?? (is_string($subject) ? $subject : '')));
            if ($name !== '' && mb_strlen($name) <= 60) {
                $tags[] = $name;
            }
            if (count($tags) >= 8) {
                break;
            }
        }

        return array_values(array_unique($tags));
    }
}
