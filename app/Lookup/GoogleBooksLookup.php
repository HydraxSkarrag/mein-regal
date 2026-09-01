<?php
declare(strict_types=1);

namespace App\Lookup;

use App\Core\Isbn;

/**
 * Google Books.
 *
 * Second in line for German titles, high in line for English ones. Covers are
 * shown with an attribution and a link back to Google, never passed off as
 * our own. They are fetched once and served from this server rather than
 * embedded from Google's - see CoverStorage for why that trade is made.
 *
 * Without a key the quota is shared per IP and runs out quickly; a free key
 * raises it to roughly a thousand calls a day.
 */
final class GoogleBooksLookup implements LookupSource
{
    private const ENDPOINT = 'https://www.googleapis.com/books/v1/volumes';

    /**
     * How wide a cover to ask for.
     *
     * The thumbnail the API hands out is 128 pixels wide - a third of what the
     * shelf grid needs and a seventh of the detail page, so every Google cover
     * arrived upscaled and soft. The same image address serves a larger
     * rendition when asked: fife=w900 returns the biggest one Google holds up
     * to that width, which measured over twelve covers was 900 where a full
     * scan exists and 300 where it does not - larger every single time.
     *
     * zoom=0 looks like the obvious lever and is the wrong one: for two thirds
     * of the same sample it answered with the grey "image not available"
     * filler rather than the cover. This asks for a size, not a zoom level.
     */
    private const RENDITION_WIDTH = 900;

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
            return self::renditionUrl($imageLinks[$key]);
        }

        return null;
    }

    /**
     * The address a cover is actually fetched from.
     *
     * Public and static because covers recorded before this existed are
     * refreshed from their stored address by bin/covers.php, and both paths
     * have to arrive at the same URL - one rule, one place.
     */
    public static function renditionUrl(string $thumbnail): string
    {
        // Google still hands out http:// links; loading those on an https
        // page would be blocked as mixed content.
        $url = str_replace('http://', 'https://', $thumbnail);

        // "edge=curl" paints a fake page curl onto the image. It looks
        // like a rendering artefact next to real cover photos.
        $url = str_replace('&edge=curl', '', $url);

        // Idempotent: refreshing an already upgraded address must not append
        // a second size, which would leave the first one to win.
        $url = preg_replace('/([?&])fife=[^&]*/', '$1fife=w' . self::RENDITION_WIDTH, $url, 1, $count) ?? $url;
        if ($count === 0) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'fife=w' . self::RENDITION_WIDTH;
        }

        return $url;
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
