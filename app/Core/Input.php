<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Turning a form field into a value the database can hold.
 *
 * Every one of these answers the same question - "is there something usable
 * here, and if not, is that a NULL?" - and every one of them was written
 * twice: once in the scanner, once in the edit form. That is how the two
 * drifted apart. `intOrNull` trimmed its input in one place and not in the
 * other, so a page count typed with a trailing space was kept by the edit
 * form and silently dropped by the scanner.
 *
 * A field cleaner is exactly the kind of code that must not exist twice:
 * it is short enough to look harmless, and it decides what gets stored.
 *
 * An empty field is NULL, never 0 or "". The Bookstats export made the
 * opposite choice and the import had to undo it for every column: a price of
 * 0.00 read as "free", a year of 0 as a year.
 */
final class Input
{
    /** Trimmed and cut to length, or NULL when nothing was typed. */
    public static function text(string $value, int $maxLength): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    /** A whole number inside its range, or NULL. Digits only: no "12abc". */
    public static function int(string $value, int $min, int $max): ?int
    {
        $value = trim($value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }

    /**
     * A price. The comma is a decimal separator here, because that is what a
     * German keyboard produces and what every price in the export looks like.
     */
    public static function price(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $price = (float) $value;

        return $price > 0 ? round($price, 2) : null;
    }

    /** Half steps from 0.5 to 5.0; anything else is discarded. */
    /**
     * Which volume of a series, as somebody would type it.
     *
     * Halves are real and not a rounding artefact: a novella published
     * between two novels is 4.5 in every list that has one, and forcing it to
     * 4 or 5 puts it in the wrong place. A comma is accepted because a German
     * keyboard offers one first.
     *
     * Anything finer than a half is rounded to one - "Band 3,7" is not a
     * thing anybody means, and the column stores one decimal.
     */
    /** Beyond this a span is a slip of the finger, not a binding. */
    private const MAX_SPAN = 100;

    public static function volume(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $volume = round((float) $value * 2) / 2;

        return $volume > 0 && $volume <= 9999 ? $volume : null;
    }

    /**
     * A volume, or the span of volumes one book holds.
     *
     * A Sammelband is one book and several volumes. Entering only the first
     * of them makes the series page report the rest as missing for good -
     * which is the one thing that page is for. So the field takes a span:
     * "5-6", "5/6" as a catalogue writes it, or an en dash as this page
     * prints it back.
     *
     * A span that runs backwards or stands still is not one, and neither is
     * a hundred volumes in one binding - that is a typo, and marking a
     * hundred volumes as owned is a worse answer than ignoring the second
     * number.
     *
     * @return array{0: ?float, 1: ?float} the volume, and the last it covers
     */
    public static function volumeSpan(string $value): array
    {
        $parts = preg_split('/\s*[-\x{2013}\x{2014}\/]\s*/u', trim($value), 2);
        if ($parts === false || $parts === []) {
            return [null, null];
        }

        $start = self::volume($parts[0]);
        if ($start === null) {
            return [null, null];
        }

        $end = isset($parts[1]) ? self::volume($parts[1]) : null;
        if ($end === null || $end <= $start || $end - $start > self::MAX_SPAN) {
            return [$start, null];
        }

        return [$start, $end];
    }

    public static function rating(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $rating = round((float) $value * 2) / 2;

        return $rating >= 0.5 && $rating <= 5.0 ? $rating : null;
    }

    /** An ISO date as the browser's date input sends it, or NULL. */
    public static function date(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * A link, and only ever one a browser may follow back to a website.
     *
     * Without the scheme check a stored address could be a javascript: URL,
     * and the shelf renders it as a link for every visitor.
     */
    public static function url(string $value, int $maxLength = 500): ?string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? mb_substr($value, 0, $maxLength) : null;
    }

    /**
     * One of a fixed list, or the fallback.
     *
     * Controlled vocabularies - reading status, binding, sort order - reach
     * the database as stable English keys, and this is the gate they pass
     * through. A value that is not on the list is not stored, which is also
     * what keeps a hand-written query string out of an ORDER BY.
     *
     * @param list<string> $allowed
     */
    public static function oneOf(string $value, array $allowed, ?string $default = null): ?string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
