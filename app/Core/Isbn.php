<?php
declare(strict_types=1);

namespace App\Core;

/**
 * ISBN parsing, validation and conversion.
 *
 * The registration group matters beyond bookkeeping: it decides which
 * metadata source is asked first. Testing against the real collection showed
 * the DNB answering every German ISBN and none of the English ones, so asking
 * in the wrong order is the difference between a hit and a miss.
 */
final class Isbn
{
    /** Strip separators and upper-case the X check digit. Returns null if it is not an ISBN. */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $clean = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw) ?? '');
        if ($clean === '') {
            return null;
        }
        if (strlen($clean) === 13 && self::isValid13($clean)) {
            return $clean;
        }
        if (strlen($clean) === 10 && self::isValid10($clean)) {
            return self::to13($clean);
        }

        // Some shop exports pad an ISBN-10 with leading zeros to EAN length:
        // "0003548365914" is really 3548365914. Only attempted when the code
        // starts with zeros, so genuine product EANs are not mangled into
        // books by accident.
        if (strlen($clean) === 13 && str_starts_with($clean, '000')) {
            $candidate = substr($clean, 3);
            if (self::isValid10($candidate)) {
                return self::to13($candidate);
            }
        }

        return null;
    }

    /**
     * A barcode scanner reads every EAN-13, not just books. Requiring the
     * Bookland prefix is what keeps a cereal box from becoming a shelf entry:
     * 4005556022946 is a perfectly valid EAN with a correct check digit, but
     * it is a boxed game - and it does appear in the export.
     */
    public static function isValid13(string $isbn): bool
    {
        return self::isBookland($isbn) && self::hasValidEan13Checksum($isbn);
    }

    public static function isBookland(string $code): bool
    {
        return strlen($code) === 13
            && ctype_digit($code)
            && (str_starts_with($code, '978') || str_starts_with($code, '979'));
    }

    public static function hasValidEan13Checksum(string $code): bool
    {
        if (strlen($code) !== 13 || !ctype_digit($code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $code[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $code[12];
    }

    public static function isValid10(string $isbn): bool
    {
        if (strlen($isbn) !== 10 || !preg_match('/^\d{9}[\dX]$/', $isbn)) {
            return false;
        }
        // "0000000000" satisfies the check digit but is not an assignable
        // number. Without this guard a zero-padded placeholder would be
        // silently accepted as a book.
        if (substr($isbn, 0, 9) === '000000000') {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $isbn[$i] * (10 - $i);
        }
        $check = $isbn[9] === 'X' ? 10 : (int) $isbn[9];

        return ($sum + $check) % 11 === 0;
    }

    public static function to13(string $isbn10): ?string
    {
        if (!self::isValid10($isbn10)) {
            return null;
        }
        $core = '978' . substr($isbn10, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $core[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return $core . ((10 - $sum % 10) % 10);
    }

    /** Only 978-prefixed ISBN-13 have an ISBN-10 equivalent; 979 never does. */
    public static function to10(string $isbn13): ?string
    {
        if (!self::isValid13($isbn13) || !str_starts_with($isbn13, '978')) {
            return null;
        }
        $core = substr($isbn13, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $core[$i] * (10 - $i);
        }
        $check = (11 - $sum % 11) % 11;

        return $core . ($check === 10 ? 'X' : (string) $check);
    }

    /**
     * Language area of the registration group, as far as it can be told from
     * the prefix. Only the groups that actually occur in this collection are
     * distinguished; everything else stays unknown rather than guessing.
     */
    public static function languageArea(string $isbn13): string
    {
        if (!self::isValid13($isbn13)) {
            return 'unknown';
        }
        $body = str_starts_with($isbn13, '978') || str_starts_with($isbn13, '979')
            ? substr($isbn13, 3)
            : $isbn13;

        // 0 and 1 are the English language group, 3 is the German one.
        return match ($body[0]) {
            '0', '1' => 'english',
            '3'      => 'german',
            default  => 'unknown',
        };
    }

    /** Hyphenate for display: 978-3-473-40806-1. Only the parts we can place safely. */
    public static function format(string $isbn13): string
    {
        if (!self::isValid13($isbn13)) {
            return $isbn13;
        }
        $area = substr($isbn13, 3, 1);
        if (!in_array($area, ['0', '1', '3'], true)) {
            return $isbn13;
        }

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($isbn13, 0, 3),
            $area,
            substr($isbn13, 4, 3),
            substr($isbn13, 7, 5),
            substr($isbn13, 12, 1)
        );
    }
}
