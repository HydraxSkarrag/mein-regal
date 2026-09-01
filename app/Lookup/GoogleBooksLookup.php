<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Isbn;

/**
 * Google Books.
 *
 * Second in line for German titles, high in line for English ones. Displaying
 * the returned thumbnail is permitted as long as it is linked back to Google
 * and not passed off as our own - so the cover is LINKED, never copied, and
 * carries an attribution.
 *
 * Without a key the quota is shared per IP and runs out quickly; a free key
 * raises it to roughly a thousand calls a day.
 */
final class GoogleBooksLookup implements LookupSource
{
    private const ENDPOINT = 'https://www.googleapis.com/books/v1/volumes';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $apiKey = '',
    ) {
    }

    public function name(): string
    {
        return 'google';
    }

    public function find(string $isbn13): ?BookData
    {
        $query = ['q' => 'isbn:' . $isbn13, 'maxResults' => 1];
        if ($this->apiKey !== '') {
            $query['key'] = $this->apiKey;
        }

        $response = $this->http->getRetrying(self::ENDPOINT . '?' . http_build_query($query));

        /* A non-answer is not an answer of "no".
         *
         * Returning null here for every status was the bug: null is the
         * contract's way of saying "no record for this ISBN", so a throttled
         * or stumbling Google was written down as a settled miss and the book
         * was left alone for a month. */
        if ($response['status'] === 429) {
            throw LookupUnavailable::quota($this->name(), self::reason($response['body']));
        }
        if ($response['status'] !== 200) {
            throw LookupUnavailable::unreachable(
                $this->name(),
                'HTTP ' . $response['status']
                    . ($response['attempts'] > 1 ? ' after ' . $response['attempts'] . ' attempts' : '')
            );
        }

        return $this->parse($response['body'], $isbn13);
    }

    /** Google states the reason in the body; it is worth passing on. */
    private static function reason(string $body): string
    {
        $data = json_decode($body, true);
        $message = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';

        return $message === '' ? '' : '(' . mb_substr($message, 0, 80) . ')';
    }

    public function parse(string $json, string $isbn13): ?BookData
    {
        $data = json_decode($json, true);
        if (!is_array($data) || (int) ($data['totalItems'] ?? 0) === 0) {
            return null;
        }
        $info = $data['items'][0]['volumeInfo'] ?? null;
        if (!is_array($info) || !isset($info['title'])) {
            return null;
        }

        $authors = Contributors::normalize((array) ($info['authors'] ?? []));

        $cover = $this->cover($info['imageLinks'] ?? null);

        return new BookData(
            source:        $this->name(),
            isbn13:        $isbn13,
            isbn10:        Isbn::to10($isbn13),
            title:         trim((string) $info['title']) ?: null,
            subtitle:      isset($info['subtitle']) ? (trim((string) $info['subtitle']) ?: null) : null,
            authors:       $authors,
            publisher:     isset($info['publisher']) ? (trim((string) $info['publisher']) ?: null) : null,
            publishedYear: $this->year($info['publishedDate'] ?? null),
            pageCount:     $this->positiveInt($info['pageCount'] ?? null),
            language:      $this->language($info['language'] ?? null),
            binding:       null,
            price:         null,
            tags:          $this->tags($info['categories'] ?? []),
            coverUrl:      $cover,
            attribution:   $cover !== null ? 'Cover: Google Books' : null,
            coverSource:   $cover !== null ? $this->name() : null,
        );
    }

    private function cover(mixed $imageLinks): ?string
    {
        if (!is_array($imageLinks)) {
            return null;
        }
        foreach (['thumbnail', 'smallThumbnail'] as $key) {
            if (!isset($imageLinks[$key]) || !is_string($imageLinks[$key])) {
                continue;
            }
            // Google still hands out http:// links; loading those on an https
            // page would be blocked as mixed content.
            $url = str_replace('http://', 'https://', $imageLinks[$key]);

            // "edge=curl" paints a fake page curl onto the image. It looks
            // like a rendering artefact next to real cover photos.
            return str_replace('&edge=curl', '', $url);
        }

        return null;
    }

    /** publishedDate is "2017", "2017-03" or "2017-03-14". */
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

    /** Google reports two-letter codes; the rest of the app stores three. */
    private function language(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $code = strtolower(trim($raw));

        return match ($code) {
            'de' => 'ger',
            'en' => 'eng',
            'fr' => 'fre',
            'es' => 'spa',
            'it' => 'ita',
            'nl' => 'dut',
            default => preg_match('/^[a-z]{3}$/', $code) === 1 ? $code : null,
        };
    }

    /** @return list<string> */
    private function tags(mixed $categories): array
    {
        $tags = [];
        foreach ((array) $categories as $category) {
            // "Juvenile Fiction / Fantasy & Magic" - each level is a tag.
            foreach (explode('/', (string) $category) as $part) {
                $clean = trim($part);
                if ($clean !== '') {
                    $tags[] = $clean;
                }
            }
        }

        return array_values(array_unique($tags));
    }
}
