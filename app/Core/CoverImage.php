<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Decides what to draw where a cover goes.
 *
 * Cover coverage starts patchy, so the stand-in matters: a generated spine in
 * a muted colour reads as a book, while a broken image reads as a fault. The
 * colour is derived from the book's own identifier, so the same book always
 * looks the same and a shelf does not reshuffle its colours on every load.
 *
 * The palette is deliberately dark and desaturated - these sit next to real
 * cover photographs and should not shout over them. It lives in the
 * stylesheet, so a theme can restate it along with everything else.
 */
final class CoverImage
{
    /**
     * How many grounds a placeholder can have.
     *
     * The colours themselves are in the stylesheet as --placeholder-1 to -16
     * and reached through the class this returns. They belong there and not
     * here because a theme has to be able to restate them: sixteen muted
     * purples chosen to sit under a white page look wrong on a black one,
     * and no amount of PHP can know which page it is.
     */
    private const PLACEHOLDER_GROUNDS = 16;

    /**
     * The class carrying this book's placeholder ground.
     *
     * Derived from the book's own identifier, so the same book always looks
     * the same and a shelf does not reshuffle its colours on every load.
     */
    public static function placeholderClass(string $seed): string
    {
        $hash = crc32($seed !== '' ? $seed : 'ohne-isbn');

        return 'ph-' . ($hash % self::PLACEHOLDER_GROUNDS + 1);
    }

    /**
     * Where the credit for a cover should point.
     *
     * Open Library asks for a link back as a courtesy and it costs nothing to
     * give; Google's terms ask for one outright. Naming the source without
     * linking it is the half-measure that satisfies neither.
     *
     * @param array{source: string, external_url?: ?string}|null $cover
     */
    public static function attributionLink(?array $cover, ?string $isbn13): ?string
    {
        if ($cover === null || $isbn13 === null || $isbn13 === '') {
            return null;
        }

        return match ($cover['source'] ?? '') {
            'openlibrary' => 'https://openlibrary.org/isbn/' . rawurlencode($isbn13),
            'google'      => 'https://books.google.com/books?vid=ISBN' . rawurlencode($isbn13),
            default       => null,
        };
    }

    /**
     * The URL to render for a stored cover row, or null when there is none.
     *
     * Pass $small for the shelf grid: a full-size cover per tile would be a
     * lot of mobile data for something rendered at 128 pixels wide.
     *
     * @param array{source: string, path: ?string, external_url: ?string}|null $cover
     */
    public static function url(?array $cover, bool $small = false, string $basePath = '/covers/'): ?string
    {
        if ($cover === null) {
            return null;
        }
        $path = $cover['path'] ?? null;
        if ($path !== null && $path !== '') {
            if ($small) {
                $path = preg_replace('/\.webp$/', '-klein.webp', $path) ?? $path;
            }

            return $basePath . $path;
        }

        // Only ever an image the signed-in owner sees; see CoverRepository.
        return $cover['external_url'] ?? null;
    }
}
