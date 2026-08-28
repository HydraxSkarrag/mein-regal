<?php
declare(strict_types=1);

namespace App\Lookup;

/**
 * Maps the wording the sources use for a book's physical form onto the four
 * values the application stores. The DNB is the reason this exists: it spells
 * the binding out in German inside a free-text identifier field
 * ("Festeinband", "Kartoniert"), and that is where the export's "Buchart"
 * column can be reconstructed from.
 */
final class Binding
{
    public const HARDCOVER = 'hardcover';
    public const PAPERBACK = 'paperback';
    public const EBOOK     = 'ebook';
    public const AUDIOBOOK = 'audiobook';
    public const UNKNOWN   = 'unknown';

    private const KEYWORDS = [
        self::HARDCOVER => [
            'festeinband', 'gebunden', 'hardcover', 'pappband', 'leinen',
            'halbleinen', 'pappbilderbuch', 'hardback',
        ],
        self::PAPERBACK => [
            'kartoniert', 'broschur', 'broschiert', 'paperback', 'taschenbuch',
            'geheftet', 'softcover', 'klappenbroschur',
        ],
        self::EBOOK => ['e-book', 'ebook', 'epub', 'online-ressource', 'kindle'],
        self::AUDIOBOOK => ['hörbuch', 'audio-cd', 'audiobook', 'mp3', 'tonträger'],
    ];

    public static function fromText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $haystack = mb_strtolower($text, 'UTF-8');
        foreach (self::KEYWORDS as $binding => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $binding;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::HARDCOVER, self::PAPERBACK, self::EBOOK, self::AUDIOBOOK, self::UNKNOWN];
    }
}
